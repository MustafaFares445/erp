<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportQueues;

use App\Filament\Concerns\RequiresSupportFeature;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\SupportQueues\Pages\CreateSupportQueue;
use App\Filament\Resources\SupportQueues\Pages\EditSupportQueue;
use App\Filament\Resources\SupportQueues\Pages\ListSupportQueues;
use App\Filament\Resources\SupportQueues\Schemas\SupportQueueForm;
use App\Filament\Resources\SupportQueues\Tables\SupportQueuesTable;
use App\Models\SupportQueue;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class SupportQueueResource extends Resource
{
    use RequiresSupportFeature;

    protected static function supportFeatureFlag(): string
    {
        return 'support.smart_routing_enabled';
    }

    protected static ?string $model = SupportQueue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 708;

    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.support_queues');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.support_queue');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.support_queues');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SupportQueueForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SupportQueuesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSupportQueues::route('/'),
            'create' => CreateSupportQueue::route('/create'),
            'edit' => EditSupportQueue::route('/{record}/edit'),
        ];
    }
}
