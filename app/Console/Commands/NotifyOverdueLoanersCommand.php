<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EquipmentLoanStatus;
use App\Enums\NotificationEventKey;
use App\Events\SupportContinuityMilestone;
use App\Models\EquipmentLoan;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class NotifyOverdueLoanersCommand extends Command
{
    protected $signature = 'support:loaners:notify-overdue';

    protected $description = 'Notify once about each issued loaner that is past its expected return date.';

    public function handle(): int
    {
        if (! (bool) config('support.loaner_equipment_enabled', false)) {
            $this->components->info('Loaner equipment is disabled.');

            return self::SUCCESS;
        }

        $notified = 0;

        EquipmentLoan::query()
            ->where('status', EquipmentLoanStatus::Issued->value)
            ->where('expected_return_at', '<', now())
            ->whereNull('overdue_notified_at')
            ->orderBy('id')
            ->chunkById(100, function (EloquentCollection $loans) use (&$notified): void {
                foreach ($loans as $loan) {
                    $loan->forceFill(['overdue_notified_at' => now()])->save();
                    SupportContinuityMilestone::dispatch($loan->maintenanceRecord()->firstOrFail(), NotificationEventKey::LoanerOverdue, $loan->id);
                    $notified++;
                }
            });

        $this->components->info(sprintf('%d overdue loaner notification(s) sent.', $notified));

        return self::SUCCESS;
    }
}
