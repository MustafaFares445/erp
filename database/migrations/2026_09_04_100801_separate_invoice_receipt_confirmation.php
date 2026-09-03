<?php

declare(strict_types=1);

use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('received_confirmation_type', 40)
                ->nullable()
                ->after('sent_at');
            $table->timestamp('received_confirmed_at')
                ->nullable()
                ->after('received_confirmation_type');
            $table->foreignId('received_confirmed_by')
                ->nullable()
                ->after('received_confirmed_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        $review = [];

        DB::table('invoices')
            ->orderBy('id')
            ->chunkById(200, function ($invoices) use (&$review): void {
                foreach ($invoices as $invoice) {
                    $oldStatus = is_string($invoice->status) ? $invoice->status : '';

                    $confirmations = DB::table('invoice_confirmations')
                        ->where('invoice_id', $invoice->id)
                        ->orderBy('confirmed_at')
                        ->orderBy('id')
                        ->get();

                    foreach ($confirmations as $confirmation) {
                        if (! is_string($confirmation->confirmation_type)
                            || InvoiceConfirmationType::tryFrom($confirmation->confirmation_type) === null) {
                            throw new \RuntimeException(sprintf(
                                'Invoice %s has unsupported receipt confirmation type %s.',
                                (string) $invoice->invoice_number,
                                (string) $confirmation->confirmation_type,
                            ));
                        }
                    }

                    $legacyReceipt = InvoiceConfirmationType::tryFrom($oldStatus);

                    if ($legacyReceipt instanceof InvoiceConfirmationType && $confirmations->isEmpty()) {
                        throw new \RuntimeException(sprintf(
                            'Invoice %s stores receipt status %s but has no authoritative invoice_confirmation row.',
                            (string) $invoice->invoice_number,
                            $oldStatus,
                        ));
                    }

                    $distinctTypes = $confirmations
                        ->pluck('confirmation_type')
                        ->filter(static fn (mixed $type): bool => is_string($type))
                        ->unique()
                        ->values();

                    if ($distinctTypes->count() > 1) {
                        $review[] = (string) $invoice->invoice_number;
                    }

                    $earliest = $confirmations->first();
                    $hasReceiptEvidence = $earliest !== null;

                    $lifecycle = match (true) {
                        $oldStatus === InvoiceStatus::Cancelled->value => InvoiceStatus::Cancelled,
                        $oldStatus === InvoiceStatus::WrittenOff->value => InvoiceStatus::WrittenOff,
                        $hasReceiptEvidence,
                        $legacyReceipt instanceof InvoiceConfirmationType,
                        $oldStatus === InvoiceStatus::Sent->value,
                        $invoice->sent_at !== null => InvoiceStatus::Sent,
                        $oldStatus === InvoiceStatus::Issued->value,
                        in_array($oldStatus, ['partially_paid', 'paid', 'credited', 'overdue'], true),
                        $invoice->issued_at !== null => InvoiceStatus::Issued,
                        default => InvoiceStatus::Draft,
                    };

                    DB::table('invoices')
                        ->where('id', $invoice->id)
                        ->update([
                            'status' => $lifecycle->value,
                            'received_confirmation_type' => $earliest?->confirmation_type,
                            'received_confirmed_at' => $earliest?->confirmed_at,
                            'received_confirmed_by' => $earliest?->confirmed_by_user_id,
                        ]);
                }
            });

        if ($review !== []) {
            Log::warning(
                'WP-1.8 invoice receipt backfill found invoices with both confirmation types; earliest confirmation retained.',
                ['invoice_numbers' => $review],
            );
        }

        $this->addChecks();
    }

    public function down(): void
    {
        $this->dropChecks();

        DB::table('invoices')
            ->orderBy('id')
            ->chunkById(200, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $total = (float) $invoice->total_amount;
                    $credited = (float) $invoice->credited_amount;
                    $claim = max(0.0, $total - $credited);
                    $paid = (float) $invoice->amount_paid;

                    $oldStatus = match (true) {
                        $invoice->status === InvoiceStatus::Cancelled->value => 'cancelled',
                        $total > 0.0 && $credited + 0.00001 >= $total => 'credited',
                        $claim > 0.0 && $paid + 0.00001 >= $claim => 'paid',
                        $paid > 0.0 => 'partially_paid',
                        is_string($invoice->received_confirmation_type)
                            && InvoiceConfirmationType::tryFrom($invoice->received_confirmation_type) !== null
                            => $invoice->received_confirmation_type,
                        $invoice->sent_at !== null => 'sent',
                        $invoice->issued_at !== null => 'issued',
                        default => 'draft',
                    };

                    DB::table('invoices')
                        ->where('id', $invoice->id)
                        ->update(['status' => $oldStatus]);
                }
            });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('received_confirmed_by');
            $table->dropColumn([
                'received_confirmation_type',
                'received_confirmed_at',
            ]);
        });
    }

    private function addChecks(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        $statuses = implode(', ', array_map(
            static fn (InvoiceStatus $status): string => DB::getPdo()->quote($status->value),
            InvoiceStatus::cases(),
        ));
        $types = implode(', ', array_map(
            static fn (InvoiceConfirmationType $type): string => DB::getPdo()->quote($type->value),
            InvoiceConfirmationType::cases(),
        ));

        DB::statement(sprintf(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN (%s))',
            $statuses,
        ));
        DB::statement(sprintf(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_received_confirmation_type_check CHECK (received_confirmation_type IS NULL OR received_confirmation_type IN (%s))',
            $types,
        ));
    }

    private function dropChecks(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE invoices DROP CHECK invoices_received_confirmation_type_check');
            DB::statement('ALTER TABLE invoices DROP CHECK invoices_status_check');

            return;
        }

        if (in_array($driver, ['mariadb', 'pgsql'], true)) {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_received_confirmation_type_check');
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_status_check');
        }
    }
};
