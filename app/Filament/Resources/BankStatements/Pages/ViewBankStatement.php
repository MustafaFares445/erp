<?php

declare(strict_types=1);

namespace App\Filament\Resources\BankStatements\Pages;

use App\Filament\Resources\BankStatements\Actions\BankStatementActions;
use App\Filament\Resources\BankStatements\BankStatementResource;
use App\Models\BankStatement;
use Filament\Resources\Pages\ViewRecord;

final class ViewBankStatement extends ViewRecord
{
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
            BankStatementActions::close()
                ->record(fn (): BankStatement => $this->statement())
                ->after(fn () => $this->refreshFormData(['status', 'reconciled_at', 'reconciled_by'])),
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
