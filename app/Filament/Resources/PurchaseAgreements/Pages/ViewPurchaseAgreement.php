<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseAgreements\Pages;

use App\Enums\PurchaseAgreementStatus;
use App\Enums\PurchasePermission;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use App\Models\PurchaseAgreement;
use App\Models\User;
use App\Services\Purchasing\PurchaseAgreementService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewPurchaseAgreement extends ViewRecord
{
    use InteractsWithPurchasingServices;

    protected static string $resource = PurchaseAgreementResource::class;

    #[\Override]
    public function getTitle(): string
    {
        return 'Agreement '.$this->agreement()->agreement_number;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('activate')
                ->label(__('Activate agreement'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->agreement()->status === PurchaseAgreementStatus::Draft
                    && ($this->actor()?->can(PurchasePermission::AgreementManage->value) ?? false))
                ->requiresConfirmation()
                ->action(function (): void {
                    $actor = $this->actor();
                    if (! $actor instanceof User) {
                        return;
                    }

                    self::runPurchasingOperation(fn (): PurchaseAgreement => app(PurchaseAgreementService::class)->activate($actor, $this->agreement()));
                    $this->refreshFormData(['status']);
                    Notification::make()->success()->title(__('Purchase agreement activated'))->send();
                }),
            Action::make('expire')
                ->label(__('Mark expired'))
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->visible(fn (): bool => $this->agreement()->status === PurchaseAgreementStatus::Active
                    && $this->agreement()->ends_on !== null
                    && $this->agreement()->ends_on->lt(today())
                    && ($this->actor()?->can(PurchasePermission::AgreementManage->value) ?? false))
                ->requiresConfirmation()
                ->action(function (): void {
                    $actor = $this->actor();
                    if (! $actor instanceof User) {
                        return;
                    }

                    self::runPurchasingOperation(fn (): PurchaseAgreement => app(PurchaseAgreementService::class)->expire($actor, $this->agreement()));
                    $this->refreshFormData(['status']);
                    Notification::make()->success()->title(__('Purchase agreement expired'))->send();
                }),
            Action::make('cancel')
                ->label(__('Cancel agreement'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->agreement()->status, [PurchaseAgreementStatus::Draft, PurchaseAgreementStatus::Active], true)
                    && ($this->actor()?->can(PurchasePermission::AgreementManage->value) ?? false))
                ->requiresConfirmation()
                ->action(function (): void {
                    $actor = $this->actor();
                    if (! $actor instanceof User) {
                        return;
                    }

                    self::runPurchasingOperation(fn (): PurchaseAgreement => app(PurchaseAgreementService::class)->cancel($actor, $this->agreement()));
                    $this->refreshFormData(['status']);
                    Notification::make()->success()->title(__('Purchase agreement cancelled'))->send();
                }),
        ];
    }

    private function agreement(): PurchaseAgreement
    {
        $record = $this->getRecord();

        if (! $record instanceof PurchaseAgreement) {
            throw new \LogicException('Expected a PurchaseAgreement record.');
        }

        return $record;
    }

    private function actor(): ?User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : null;
    }
}
