<?php

declare(strict_types=1);

namespace App\Filament\Resources\BankStatements\Actions;

use App\Enums\AccountingPermission;
use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Filament\Resources\BankStatements\BankStatementResource;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\User;
use App\Services\Accounting\BankReconciliation\BankReconciliationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use WeakMap;

final class BankStatementActions
{
    use InteractsWithAccountingServices;

    public static function close(): Action
    {
        return Action::make('close')
            ->label(__('Close reconciliation'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('success')
            ->visible(fn (BankStatement $record): bool => $record->status === 'open' && self::canManage())
            ->authorize(fn (): bool => self::canManage())
            ->requiresConfirmation()
            ->action(function (BankStatement $record): void {
                $actor = self::accountingActor();
                if (! $actor instanceof User) {
                    return;
                }

                self::runAccountingOperation(
                    fn (): BankStatement => app(BankReconciliationService::class)->close($actor, $record),
                );
                Notification::make()->success()->title(__('Bank statement reconciled'))->send();
            });
    }

    /** Primary row action while an open statement still has lines to reconcile. */
    public static function continueReconciliation(): Action
    {
        return Action::make('continue_reconciliation')
            ->label(__('Continue reconciliation'))
            ->icon(Heroicon::ArrowRight)
            ->button()
            ->color('primary')
            ->visible(fn (BankStatement $record): bool => $record->status === 'open' && ! self::isClosable($record) && self::canManage())
            ->url(fn (BankStatement $record): string => BankStatementResource::getUrl('view', ['record' => $record]));
    }

    /** Primary row action once the statement can be closed; mirrors the service's rejection rules. */
    public static function closeFromList(): Action
    {
        return self::close()
            ->button()
            ->visible(fn (BankStatement $record): bool => $record->status === 'open' && self::isClosable($record) && self::canManage());
    }

    /**
     * Mirrors {@see BankReconciliationService::close()}: open, and every line fully reconciled
     * (a statement without lines has nothing to close).
     */
    public static function isClosable(BankStatement $statement): bool
    {
        /** @var WeakMap<BankStatement, bool>|null $cache */
        static $cache = null;

        $cache ??= new WeakMap;

        if (isset($cache[$statement]) && $cache[$statement] === false) {
            unset($cache[$statement]);
        }

        if ($statement->status !== 'open') {
            return false;
        }

        $lines = $statement->lines()->with('matches')->get();

        return $lines->isNotEmpty()
            && ! $lines->contains(fn (BankStatementLine $line): bool => $line->remainingMinor() !== 0);
    }

    private static function canManage(): bool
    {
        return auth()->user()?->can(AccountingPermission::BankReconciliationManage->value) ?? false;
    }
}
