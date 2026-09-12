<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\SalesPermission;
use App\Models\CreditNote;
use App\Models\DocumentExport;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Exports\DocumentExportService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

final readonly class SalesDocumentExportService
{
    public function __construct(private DocumentExportService $documentExportService) {}

    /**
     * @param  list<int>  $recordIds
     * @param  array<string, mixed>  $context
     */
    public function request(string $type, array $recordIds, array $context, User $actor): DocumentExport
    {
        $this->assertCanExport($actor);
        $this->modelClass($type);

        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $recordIds),
            static fn (int $id): bool => $id > 0,
        )));

        $parameters = [
            'record_ids' => $ids,
            'filters' => is_array($context['filters'] ?? null) ? $context['filters'] : [],
            'search' => is_string($context['search'] ?? null) ? $context['search'] : null,
            'resource' => is_string($context['resource'] ?? null) ? $context['resource'] : null,
        ];

        $export = $this->documentExportService->request(
            module: 'sales',
            type: $type,
            format: 'csv',
            parameters: $parameters,
            actor: $actor,
        );

        activity()
            ->performedOn($export)
            ->causedBy($actor)
            ->withProperties([
                ...$parameters,
                'record_count' => count($ids),
                'ip_address' => request()->ip(),
            ])
            ->log($this->logName($type));

        return $export;
    }

    public function typeForModel(string $modelClass): string
    {
        return match ($modelClass) {
            Order::class => 'orders',
            Quotation::class => 'quotations',
            Invoice::class => 'invoices',
            Payment::class => 'payments',
            CreditNote::class => 'credit_notes',
            default => throw new DomainException('Unsupported sales document export type.'),
        };
    }

    public function authorize(DocumentExport $export, User $actor): void
    {
        if ($export->module !== 'sales') {
            throw new DomainException('Invalid sales document export.');
        }

        $this->modelClass($export->type);
        $this->assertCanExport($actor);
    }

    /** @return array{path: string, row_count: int} */
    public function write(DocumentExport $export): array
    {
        $actor = $export->createdBy;
        if (! $actor instanceof User) {
            throw new DomainException('The export requester no longer exists.');
        }

        $this->authorize($export, $actor);
        $ids = $this->recordIds($export);
        $modelClass = $this->modelClass($export->type);

        $records = $modelClass::query()
            ->with($this->relations($export->type))
            ->whereKey($ids)
            ->get()
            ->keyBy(static fn (Model $record): string => (string) $record->getKey());

        $path = sprintf('sales-exports/document-%d.csv', $this->exportId($export));
        $absolutePath = Storage::disk('local')->path($path);
        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new LogicException('Unable to create the private export directory.');
        }

        $handle = fopen($absolutePath, 'wb');
        if ($handle === false) {
            throw new LogicException('Unable to open the private export file.');
        }

        $rowCount = 0;

        try {
            fputcsv($handle, $this->headings($export->type), escape: '\\');

            foreach ($ids as $id) {
                $record = $records->get((string) $id);
                if (! $record instanceof Model) {
                    continue;
                }

                fputcsv($handle, $this->values($export->type, $record), escape: '\\');
                $rowCount++;
            }
        } catch (Throwable $throwable) {
            fclose($handle);
            Storage::disk('local')->delete($path);
            throw $throwable;
        }

        fclose($handle);

        return ['path' => $path, 'row_count' => $rowCount];
    }

    /** @return class-string<Model> */
    private function modelClass(string $type): string
    {
        return match ($type) {
            'orders' => Order::class,
            'quotations' => Quotation::class,
            'invoices' => Invoice::class,
            'payments' => Payment::class,
            'credit_notes' => CreditNote::class,
            default => throw new DomainException('Unsupported sales document export type.'),
        };
    }

    /** @return list<string> */
    private function relations(string $type): array
    {
        return match ($type) {
            'orders', 'quotations', 'invoices' => ['customer'],
            'payments' => ['customer', 'paymentMethod'],
            'credit_notes' => ['customer', 'invoice'],
            default => [],
        };
    }

    /** @return list<string> */
    private function headings(string $type): array
    {
        return match ($type) {
            'orders' => ['order_number', 'customer', 'status', 'grand_total', 'payment_status', 'created_at'],
            'quotations' => ['quotation_number', 'customer', 'status', 'issue_date', 'expires_at', 'grand_total'],
            'invoices' => ['invoice_number', 'customer', 'invoice_date', 'due_date', 'total_amount', 'amount_paid', 'credited_amount', 'status'],
            'payments' => ['payment_number', 'customer', 'payment_method', 'payment_date', 'amount', 'status', 'posted_at', 'reversed_at'],
            'credit_notes' => ['credit_note_number', 'customer', 'invoice_number', 'issue_date', 'grand_total', 'status'],
            default => throw new DomainException('Unsupported sales document export type.'),
        };
    }

    /** @return list<bool|float|int|string|null> */
    private function values(string $type, Model $record): array
    {
        return match ($type) {
            'orders' => $record instanceof Order ? [
                (string) $record->order_number,
                $record->customer?->company_name,
                (string) $record->status,
                $record->grand_total !== null ? (string) $record->grand_total : null,
                $record->payment_status?->value,
                $record->created_at?->toDateTimeString(),
            ] : [],
            'quotations' => $record instanceof Quotation ? [
                (string) $record->quotation_number,
                $record->customer?->company_name,
                $record->status->value,
                $record->issue_date->toDateString(),
                $record->expires_at?->toDateString(),
                (string) $record->grand_total,
            ] : [],
            'invoices' => $record instanceof Invoice ? [
                (string) $record->invoice_number,
                $record->customer?->company_name,
                $record->invoice_date->toDateString(),
                $record->due_date?->toDateString(),
                (string) $record->total_amount,
                (string) $record->amount_paid,
                (string) $record->credited_amount,
                $record->status->value,
            ] : [],
            'payments' => $record instanceof Payment ? [
                (string) $record->payment_number,
                $record->customer?->company_name,
                $record->paymentMethod?->name,
                $record->payment_date->toDateString(),
                (string) $record->amount,
                $record->status->value,
                $record->posted_at?->toDateTimeString(),
                $record->reversed_at?->toDateTimeString(),
            ] : [],
            'credit_notes' => $record instanceof CreditNote ? [
                (string) $record->credit_note_number,
                $record->customer?->company_name,
                $record->invoice?->invoice_number,
                $record->issue_date->toDateString(),
                (string) $record->grand_total,
                $record->status->value,
            ] : [],
            default => throw new DomainException('Unsupported sales document export type.'),
        };
    }

    private function logName(string $type): string
    {
        return match ($type) {
            'orders' => 'sales.order.exported',
            'quotations' => 'sales.quotation.exported',
            'invoices' => 'sales.invoice.exported',
            'payments' => 'sales.payment.exported',
            'credit_notes' => 'sales.credit_note.exported',
            default => throw new DomainException('Unsupported sales document export type.'),
        };
    }

    private function assertCanExport(User $actor): void
    {
        if (! $actor->can(SalesPermission::Export->value)) {
            throw new DomainException('You are not authorized to export sales documents.');
        }
    }

    /** @return list<int> */
    private function recordIds(DocumentExport $export): array
    {
        $parameters = is_array($export->parameters) ? $export->parameters : [];
        $recordIds = is_array($parameters['record_ids'] ?? null) ? $parameters['record_ids'] : [];

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $recordIds),
            static fn (int $id): bool => $id > 0,
        )));
    }

    private function exportId(DocumentExport $export): int
    {
        $key = $export->getKey();
        if (! is_int($key)) {
            throw new LogicException('Document exports must use integer identifiers.');
        }

        return $key;
    }
}
