<?php

declare(strict_types=1);

namespace App\Filament\Resources\Packages\Schemas;

use App\Enums\AdjustmentStatus;
use App\Enums\OperationStage;
use App\Filament\Components\InventoryViewDiagnostics;
use App\Models\Package;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Package View page: Operational health (PackageDiagnoser), usage figures and the package details.
 */
final class PackageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            InventoryViewDiagnostics::section(),
            InventoryViewDiagnostics::metrics(static fn (Package $record): array => self::metrics($record)),
            Section::make(__('inventory_view_ux.ui.package_details'))
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                ->schema([
                    TextEntry::make('name')->label(__('admin.package.fields.name')),
                    TextEntry::make('packageType.name')->label(__('admin.package.fields.package_type'))->placeholder(__('—')),
                    TextEntry::make('warehouse.name')->label(__('admin.package.fields.warehouse'))->placeholder(__('—')),
                    IconEntry::make('is_active')->label(__('admin.package.fields.is_active'))->boolean(),
                ]),
        ]);
    }

    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    private static function metrics(Package $record): array
    {
        $openOperations = $record->operationLines()
            ->whereHas('operation', static fn (Builder $operation): Builder => $operation->whereNotIn('stage', [
                OperationStage::Done->value,
                OperationStage::Canceled->value,
            ]))
            ->distinct()
            ->count('inventory_operation_id');
        $draftAdjustments = $record->adjustmentItems()
            ->whereHas('adjustment', static fn (Builder $adjustment): Builder => $adjustment->where('status', AdjustmentStatus::Draft->value))
            ->distinct()
            ->count('inventory_adjustment_id');
        $lastMovement = $record->movements()->max('created_at');

        return [
            [
                'label' => __('inventory_view_ux.ui.package_open_documents'),
                'value' => (string) ($openOperations + $draftAdjustments),
                'tone' => ! $record->is_active && $openOperations + $draftAdjustments > 0 ? 'warning' : null,
            ],
            [
                'label' => __('inventory_view_ux.ui.package_movements'),
                'value' => (string) $record->movements()->count(),
            ],
            [
                'label' => __('inventory_view_ux.ui.package_last_movement'),
                'value' => is_string($lastMovement) && $lastMovement !== '' ? substr($lastMovement, 0, 16) : __('—'),
            ],
        ];
    }
}
