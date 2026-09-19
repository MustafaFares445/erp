<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\InventoryOperation;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GeneratePackingListDocument implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $operationId,
        public int $actorId,
    ) {}

    public function handle(): void
    {
        /** @var InventoryOperation $operation */
        $operation = InventoryOperation::query()
            ->with([
                'customer',
                'sourceWarehouse',
                'lines.productVariant',
                'lines.unit',
                'lines.lot',
                'lines.serializedUnit',
            ])
            ->findOrFail($this->operationId);

        if ($operation->operation_type !== OperationType::Delivery
            || ! in_array($operation->stage, [OperationStage::Ready, OperationStage::Done], true)) {
            throw new DomainException('Only a delivery that is ready or done can generate a packing list.');
        }

        $pdf = Pdf::loadView('pdf.packing_list', ['delivery' => $operation]);
        $fileName = sprintf('%s-%s.pdf', $operation->operation_number ?? 'delivery', now()->format('Ymd-His-u'));

        $operation->addMediaFromString($pdf->output())
            ->usingFileName($fileName)
            ->toMediaCollection('packing-list-pdf');

        $actor = User::query()->find($this->actorId);
        $activity = activity()->performedOn($operation);

        if ($actor instanceof User) {
            $activity->causedBy($actor);
        }

        $activity
            ->withProperties([
                'source_channel' => 'dashboard',
                'file_name' => $fileName,
                'version_count' => $operation->getMedia('packing-list-pdf')->count(),
            ])
            ->log('inventory.delivery.packing_list_generated');
    }
}
