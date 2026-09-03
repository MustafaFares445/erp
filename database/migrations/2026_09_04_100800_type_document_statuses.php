<?php

declare(strict_types=1);

use App\Enums\BillStatus;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\SupplierPaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertKnownStatuses('payments', array_map(
            static fn (PaymentStatus $status): string => $status->value,
            PaymentStatus::cases(),
        ));
        $this->assertKnownStatuses('bills', array_map(
            static fn (BillStatus $status): string => $status->value,
            BillStatus::cases(),
        ));
        $this->assertKnownStatuses('expenses', array_map(
            static fn (ExpenseStatus $status): string => $status->value,
            ExpenseStatus::cases(),
        ));
        $this->assertKnownStatuses('supplier_payments', array_map(
            static fn (SupplierPaymentStatus $status): string => $status->value,
            SupplierPaymentStatus::cases(),
        ));
        $this->assertKnownStatuses('refunds', array_map(
            static fn (RefundStatus $status): string => $status->value,
            RefundStatus::cases(),
        ));

        // Invoice is normalized by the following data-moving migration.
        $invoiceKnown = array_merge(
            array_map(static fn (InvoiceStatus $status): string => $status->value, InvoiceStatus::cases()),
            [
                'customer_received',
                'employee_confirmed_received',
                'partially_paid',
                'paid',
                'credited',
                'overdue',
            ],
        );
        $this->assertKnownStatuses('invoices', array_values(array_unique($invoiceKnown)));

        $this->addStatusCheck('payments', 'payments_status_check', array_map(
            static fn (PaymentStatus $status): string => $status->value,
            PaymentStatus::cases(),
        ));
        $this->addStatusCheck('bills', 'bills_status_check', array_map(
            static fn (BillStatus $status): string => $status->value,
            BillStatus::cases(),
        ));
        $this->addStatusCheck('expenses', 'expenses_status_check', array_map(
            static fn (ExpenseStatus $status): string => $status->value,
            ExpenseStatus::cases(),
        ));
        $this->addStatusCheck('supplier_payments', 'supplier_payments_status_check', array_map(
            static fn (SupplierPaymentStatus $status): string => $status->value,
            SupplierPaymentStatus::cases(),
        ));
        $this->addStatusCheck('refunds', 'refunds_status_check', array_map(
            static fn (RefundStatus $status): string => $status->value,
            RefundStatus::cases(),
        ));
    }

    public function down(): void
    {
        $this->dropCheck('refunds', 'refunds_status_check');
        $this->dropCheck('supplier_payments', 'supplier_payments_status_check');
        $this->dropCheck('expenses', 'expenses_status_check');
        $this->dropCheck('bills', 'bills_status_check');
        $this->dropCheck('payments', 'payments_status_check');
    }

    /** @param list<string> $allowed */
    private function assertKnownStatuses(string $table, array $allowed): void
    {
        $unknown = DB::table($table)
            ->select('status')
            ->distinct()
            ->pluck('status')
            ->filter(static fn (mixed $status): bool => is_string($status) && ! in_array($status, $allowed, true))
            ->values()
            ->all();

        if ($unknown !== []) {
            throw new RuntimeException(sprintf(
                '%s contains status values outside the approved lifecycle: %s',
                $table,
                implode(', ', $unknown),
            ));
        }
    }

    /** @param list<string> $allowed */
    private function addStatusCheck(string $table, string $name, array $allowed): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        $quoted = implode(', ', array_map(
            $this->quote(...),
            $allowed,
        ));

        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s CHECK (status IN (%s))',
            $table,
            $name,
            $quoted,
        ));
    }

    private function quote(string $value): string
    {
        $quoted = DB::getPdo()->quote($value);

        if (! is_string($quoted)) {
            throw new RuntimeException('The database driver could not quote a lifecycle value.');
        }

        return $quoted;
    }

    private function dropCheck(string $table, string $name): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement(sprintf('ALTER TABLE %s DROP CHECK %s', $table, $name));

            return;
        }

        if (in_array($driver, ['mariadb', 'pgsql'], true)) {
            DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $name));
        }
    }
};
