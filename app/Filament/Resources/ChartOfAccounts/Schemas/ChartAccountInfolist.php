<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChartOfAccounts\Schemas;

use App\Filament\Resources\ChartOfAccounts\RelationManagers\LedgerRelationManager;
use App\Models\ChartAccount;
use App\Services\Accounting\AccountBalanceService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The read view of one account. Its posted lines are not here but in
 * {@see LedgerRelationManager}, which paginates them.
 */
final class ChartAccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('code')->label(__('admin.accounting.fields.code')),
                TextEntry::make('name')->label(__('admin.accounting.fields.account_name')),
                TextEntry::make('account_type')
                    ->label(__('admin.accounting.fields.account_type'))
                    ->state(fn (ChartAccount $record): ?string => $record->accountType?->name->label())
                    ->badge(),
                TextEntry::make('parent.code')
                    ->label(__('admin.accounting.fields.parent'))
                    ->placeholder('—'),
                IconEntry::make('is_postable')
                    ->label(__('admin.accounting.fields.is_postable'))
                    ->boolean(),
                IconEntry::make('is_active')
                    ->label(__('admin.accounting.fields.is_active'))
                    ->boolean(),
                TextEntry::make('balance')
                    ->label(__('admin.accounting.fields.balance'))
                    ->state(fn (ChartAccount $record): string => app(AccountBalanceService::class)->balanceFor($record)),
            ]),
        ]);
    }
}
