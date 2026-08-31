<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Schemas;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\InventoryOperation;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

final class OperationStageBar
{
    public static function make(): Placeholder
    {
        return Placeholder::make('operation_stage_bar')
            ->label('')
            ->content(function (?InventoryOperation $record): HtmlString {
                if (! $record instanceof InventoryOperation) {
                    return new HtmlString('');
                }

                $stages = [OperationStage::Draft, OperationStage::Waiting, OperationStage::Ready];

                if ($record->operation_type === OperationType::InternalTransfer) {
                    $stages[] = OperationStage::InTransit;
                    $stages[] = OperationStage::PartiallyReceived;
                }

                $stages[] = OperationStage::Done;
                $stages[] = OperationStage::Canceled;

                $labels = array_map(
                    function (OperationStage $stage) use ($record): string {
                        $label = $stage === OperationStage::Done && $record->operation_type === OperationType::Delivery
                            ? __('admin.inventory.operation.stages.delivered')
                            : $stage->label();

                        return $stage === $record->stage
                            ? '<strong>'.$label.'</strong>'
                            : e($label);
                    },
                    $stages,
                );

                return new HtmlString(implode(' &rarr; ', $labels));
            })
            ->columnSpanFull();
    }
}
