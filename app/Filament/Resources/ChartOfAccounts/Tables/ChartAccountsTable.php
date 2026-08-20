<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChartOfAccounts\Tables;

use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Models\AccountType;
use App\Models\ChartAccount;
use App\Models\User;
use App\Services\Accounting\AccountBalanceService;
use App\Services\Accounting\ChartOfAccountService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class ChartAccountsTable
{
    use InteractsWithAccountingServices;

    /**
     * Every account's balance for the current render, or null before the first
     * balance cell asks for one.
     *
     * @var array<int, string>|null
     */
    private static ?array $balances = null;

    public static function configure(Table $table): Table
    {
        // Cleared here rather than never, because `configure()` runs once per
        // request when the Livewire component builds its table — so the memo
        // below lives exactly as long as one render and can never serve a
        // balance from before a posting.
        self::$balances = null;

        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.accounting.fields.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('admin.accounting.fields.account_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('account_type')
                    ->label(__('admin.accounting.fields.account_type'))
                    ->state(fn (ChartAccount $record): ?string => $record->accountType?->name->label())
                    ->badge(),
                TextColumn::make('parent.code')
                    ->label(__('admin.accounting.fields.parent'))
                    ->placeholder('—'),
                IconColumn::make('is_postable')
                    ->label(__('admin.accounting.fields.is_postable'))
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label(__('admin.accounting.fields.is_active'))
                    ->boolean(),
                // Rolled up over descendants and signed by the account type's
                // normal balance, so a header account reads as the sum of its
                // subtree (FR-036, FR-037).
                TextColumn::make('balance')
                    ->label(__('admin.accounting.fields.balance'))
                    ->state(fn (ChartAccount $record): string => self::balanceOf($record))
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('account_type_id')
                    ->label(__('admin.accounting.fields.account_type'))
                    ->options(self::accountTypeOptions(...)),
                TernaryFilter::make('is_postable')
                    ->label(__('admin.accounting.fields.is_postable')),
                TernaryFilter::make('is_active')
                    ->label(__('admin.accounting.fields.is_active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    // Routed through the service so an account with children or
                    // posted history is refused with an explanation rather than a
                    // foreign-key error (FR-010, FR-011).
                    ->using(function (ChartAccount $record): bool {
                        $actor = self::accountingActor();

                        if (! $actor instanceof User) {
                            return false;
                        }

                        self::runAccountingOperation(
                            fn () => app(ChartOfAccountService::class)->delete($actor, $record),
                        );

                        return true;
                    }),
            ]);
    }

    /**
     * One aggregate query for the whole table instead of one per row: the
     * roll-up needs the entire tree anyway, so asking per account would repeat
     * the same work for every level of every branch.
     */
    private static function balanceOf(ChartAccount $account): string
    {
        self::$balances ??= app(AccountBalanceService::class)->balancesForAll();

        return self::$balances[$account->id] ?? '0.00';
    }

    /** @return array<int, string> */
    private static function accountTypeOptions(): array
    {
        $options = [];

        foreach (AccountType::query()->orderBy('id')->get() as $type) {
            $options[$type->id] = $type->name->label();
        }

        return $options;
    }
}
