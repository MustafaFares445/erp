<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentExports;

use App\Filament\Resources\DocumentExports\Pages\ListDocumentExports;
use App\Models\DocumentExport;
use App\Models\User;
use App\Services\Exports\DocumentExportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

final class DocumentExportResource extends Resource
{
    protected static ?string $model = DocumentExport::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;
    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.system';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return 'Document exports';
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('created_by', $actor->getKey());
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('module')->badge()->sortable(),
                TextColumn::make('type')->searchable()->sortable(),
                TextColumn::make('format')->badge(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('row_count')->numeric()->sortable(),
                TextColumn::make('completed_at')->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('expires_at')->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('failure_reason')
                    ->limit(60)
                    ->placeholder('-')
                    ->tooltip(fn (DocumentExport $record): ?string => $record->failure_reason),
            ])
            ->filters([
                SelectFilter::make('module')->options([
                    'inventory' => 'Inventory',
                    'employees' => 'Employees',
                    'sales' => 'Sales',
                ]),
                SelectFilter::make('status')->options([
                    'queued' => 'Queued',
                    'processing' => 'Processing',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                    'expired' => 'Expired',
                ]),
            ])
            ->recordActions([
                Action::make('download')
                    ->icon(Heroicon::OutlinedDocumentChartBar)
                    ->visible(fn (DocumentExport $record): bool => $record->status === 'completed'
                        && $record->expires_at !== null
                        && $record->expires_at->isFuture())
                    ->action(function (DocumentExport $record): ?BinaryFileResponse {
                        $actor = auth()->user();

                        return $actor instanceof User
                            ? app(DocumentExportService::class)->download($record, $actor)
                            : null;
                    }),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ListDocumentExports::route('/')];
    }
}
