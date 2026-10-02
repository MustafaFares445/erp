<?php

declare(strict_types=1);

namespace App\Filament\Resources\BankStatements\Pages;

use App\Enums\AccountingPermission;
use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Filament\Resources\BankStatements\BankStatementResource;
use App\Models\BankStatement;
use App\Models\User;
use App\Services\Accounting\BankReconciliation\BankReconciliationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewBankStatement extends ViewRecord
{
    use InteractsWithAccountingServices;

    protected static string $resource = BankStatementResource::class;

    #[\Override]
    public function getTitle(): string
    {
        return 'Bank Statement '.$this->statement()->statement_number;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')
                ->label(__('Close reconciliation'))
                ->icon('heroicon-o-lock-closed')
                ->color('success')
                ->visible(fn (): bool => $this->statement()->status === 'open'
                    && (auth()->user()?->can(AccountingPermission::BankReconciliationManage->value) ?? false))
                ->requiresConfirmation()
                ->action(function (): void {
                    $actor = self::accountingActor();
                    if (! $actor instanceof User) {
                        return;
                    }

                    self::runAccountingOperation(
                        fn (): BankStatement => app(BankReconciliationService::class)->close($actor, $this->statement()),
                    );
                    $this->refreshFormData(['status', 'reconciled_at', 'reconciled_by']);
                    Notification::make()->success()->title(__('Bank statement reconciled'))->send();
                }),
        ];
    }

    private function statement(): BankStatement
    {
        $record = $this->getRecord();
        if (! $record instanceof BankStatement) {
            throw new \LogicException('Expected a BankStatement record.');
        }

        return $record;
    }
}
