<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TicketStatus;
use App\Models\SupportQueue;
use Illuminate\Database\Seeder;

final class SupportQueueSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Needs Triage', 'sort' => 10, 'criteria' => ['statuses' => [TicketStatus::Pending->value]]],
            ['name' => 'Unassigned', 'sort' => 20, 'criteria' => ['statuses' => [TicketStatus::Live->value], 'unassigned' => true]],
            ['name' => 'SLA At Risk', 'sort' => 30, 'criteria' => ['sla_risk' => true]],
            ['name' => 'Waiting Customer > 24h', 'sort' => 40, 'criteria' => ['statuses' => [TicketStatus::WaitingCustomer->value], 'waiting_customer_hours' => 24]],
            ['name' => 'Pending Diagnostic Payment', 'sort' => 50, 'criteria' => ['statuses' => [TicketStatus::PendingPayment->value]]],
        ] as $definition) {
            SupportQueue::query()->updateOrCreate(
                ['name' => $definition['name'], 'is_system' => true],
                [
                    'support_team_id' => null,
                    'is_active' => true,
                    'sort_order' => $definition['sort'],
                    'criteria' => $definition['criteria'],
                ],
            );
        }
    }
}
