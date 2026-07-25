<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryExports;

use App\Enums\InventoryExportType;
use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryExports\Pages\ManageInventoryExports;
use App\Models\InventoryExport;
use App\Models\User;
use App\Services\Inventory\InventoryExportService;
use App\Services\Inventory\InventoryReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class InventoryExportResource extends Resource
{
    protected static ?string $model = InventoryExport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('type')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('createdBy.name')->label('Created by')->sortable(),
            TextColumn::make('completed_at')->dateTime()->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
            TextColumn::make('failure_reason')->limit(60)->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('type')->options(InventoryExportType::options()),
            SelectFilter::make('status')->options(['queued' => 'Queued', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed']),
        ])->recordActions([
            Action::make('download')
                ->visible(fn (InventoryExport $record): bool => $record->status === 'completed' && self::canDownload($record))
                ->action(function (InventoryExport $record): ?BinaryFileResponse {
                    $actor = auth()->user();

                    return $actor instanceof User
                        ? app(InventoryExportService::class)->download($record, $actor)
                        : null;
                }),
        ]);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        $reportService = app(InventoryReportService::class);
        $allowed = collect(InventoryExportType::cases())
            ->filter(fn (InventoryExportType $type): bool => $actor->can(InventoryPermission::Export->value)
                && collect($type->reports())->every(
                    fn ($report): bool => $reportService->canView($actor, $report),
                ))
            ->map(fn (InventoryExportType $type): string => $type->value)
            ->all();

        return parent::getEloquentQuery()->whereIn('type', $allowed);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageInventoryExports::route('/')];
    }

    private static function canDownload(InventoryExport $export): bool
    {
        $actor = auth()->user();
        $type = InventoryExportType::tryFrom($export->type);

        if (! $actor instanceof User || ! $type instanceof InventoryExportType) {
            return false;
        }

        $reportService = app(InventoryReportService::class);

        return $actor->can(InventoryPermission::Export->value)
            && collect($type->reports())->every(
                fn ($report): bool => $reportService->canView($actor, $report),
            );
    }
}
