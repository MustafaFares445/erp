<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SlaMilestoneKey;
use App\Enums\TicketPriority;
use App\Models\SlaCalendar;
use App\Models\SlaPolicy;
use Illuminate\Database\Seeder;

final class SlaPolicySeeder extends Seeder
{
    public function run(): void
    {
        $calendar = SlaCalendar::query()->updateOrCreate(
            ['name' => '24/7 Support'],
            [
                'timezone' => config()->string('app.timezone', 'UTC'),
                'is_24x7' => true,
                'is_default' => true,
                'is_active' => true,
            ],
        );

        foreach ([
            ['priority' => TicketPriority::Urgent, 'response' => 60, 'resolution' => 240],
            ['priority' => TicketPriority::High, 'response' => 240, 'resolution' => 1440],
            ['priority' => TicketPriority::Normal, 'response' => 480, 'resolution' => 2880],
            ['priority' => TicketPriority::Low, 'response' => 1440, 'resolution' => 4320],
        ] as $index => $defaults) {
            // Adopt the row the SLA v2 migration backfilled from the legacy one-policy-per-priority table
            // instead of creating a second policy for the same priority.
            $policy = SlaPolicy::query()->where('code', 'default-'.$defaults['priority']->value)->first()
                ?? SlaPolicy::query()
                    ->where('priority', $defaults['priority']->value)
                    ->where('code', 'like', 'legacy-%')
                    ->whereNull('ticket_type')
                    ->whereNull('service_path')
                    ->whereNull('support_service_level_id')
                    ->whereNull('customer_id')
                    ->whereNull('product_variant_id')
                    ->whereNull('support_team_id')
                    ->orderBy('id')
                    ->first()
                ?? new SlaPolicy;

            $policy->forceFill([
                'code' => $policy->code ?? 'default-'.$defaults['priority']->value,
                'name' => $policy->name ?? $defaults['priority']->label().' default SLA',
                'is_active' => true,
                'precedence' => $policy->precedence ?? 100 + $index,
                'sla_calendar_id' => $policy->sla_calendar_id ?? $calendar->getKey(),
                'priority' => $defaults['priority'],
                'response_target_minutes' => $defaults['response'],
                'resolution_target_minutes' => $defaults['resolution'],
            ])->save();

            $policy->milestones()->updateOrCreate(
                ['key' => SlaMilestoneKey::FirstResponse->value],
                [
                    'target_minutes' => $defaults['response'],
                    'at_risk_before_minutes' => min(30, max(1, (int) floor($defaults['response'] / 4))),
                    'pause_when_waiting_customer' => false,
                    'is_active' => true,
                    'sort_order' => 10,
                ],
            );

            $policy->milestones()->updateOrCreate(
                ['key' => SlaMilestoneKey::Resolution->value],
                [
                    'target_minutes' => $defaults['resolution'],
                    'at_risk_before_minutes' => min(120, max(15, (int) floor($defaults['resolution'] / 8))),
                    'pause_when_waiting_customer' => true,
                    'is_active' => true,
                    'sort_order' => 20,
                ],
            );
        }
    }
}
