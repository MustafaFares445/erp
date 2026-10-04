<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data backfill for the SLA v2 schema, kept apart from the DDL migration so a failed backfill can be
 * retried without hitting "table already exists" (MySQL DDL is not transactional).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $calendarId = DB::table('sla_calendars')->where('is_default', true)->value('id')
            ?? DB::table('sla_calendars')->insertGetId([
                'name' => '24/7 Support',
                'timezone' => config()->string('app.timezone', 'UTC'),
                'is_24x7' => true,
                'is_default' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('sla_policies')->whereNull('sla_calendar_id')->update(['sla_calendar_id' => $calendarId]);

        foreach (DB::table('sla_policies')->orderBy('id')->get() as $policy) {
            if (! is_numeric($policy->id)) {
                continue;
            }

            $policyId = (int) $policy->id;
            $responseTarget = is_numeric($policy->response_target_minutes) ? (int) $policy->response_target_minutes : 0;
            $resolutionTarget = is_numeric($policy->resolution_target_minutes) ? (int) $policy->resolution_target_minutes : 0;
            $priority = is_string($policy->priority) ? $policy->priority : 'default';
            DB::table('sla_policies')->where('id', $policyId)->update([
                'name' => $policy->name ?: ucfirst(str_replace('_', ' ', $priority)).' SLA',
                'code' => $policy->code ?: 'legacy-'.str_replace('_', '-', $priority).'-'.$policyId,
                'updated_at' => $now,
            ]);

            if (DB::table('sla_policy_milestones')->where('sla_policy_id', $policyId)->exists()) {
                continue;
            }

            DB::table('sla_policy_milestones')->insert([
                [
                    'sla_policy_id' => $policyId,
                    'key' => 'first_response',
                    'target_minutes' => $responseTarget,
                    'at_risk_before_minutes' => min(30, max(1, intdiv($responseTarget, 4))),
                    'pause_when_waiting_customer' => false,
                    'is_active' => true,
                    'sort_order' => 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'sla_policy_id' => $policyId,
                    'key' => 'resolution',
                    'target_minutes' => $resolutionTarget,
                    'at_risk_before_minutes' => min(120, max(15, intdiv($resolutionTarget, 8))),
                    'pause_when_waiting_customer' => true,
                    'is_active' => true,
                    'sort_order' => 20,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }

        // One lookup for the whole run: the lowest-precedence policy per legacy priority.
        $policyByPriority = DB::table('sla_policies')
            ->whereNotNull('priority')
            ->orderBy('precedence')
            ->orderBy('id')
            ->get(['id', 'priority'])
            ->unique('priority')
            ->pluck('id', 'priority');

        DB::table('tickets')
            ->whereNotNull('response_due_at')
            ->orderBy('id')
            ->chunkById(200, function ($tickets) use ($policyByPriority, $now): void {
                $milestones = [];

                foreach ($tickets as $ticket) {
                    $policyId = $policyByPriority[$ticket->priority] ?? null;
                    $started = $ticket->response_sla_started_at ?? $ticket->created_at;

                    DB::table('tickets')->where('id', $ticket->id)->update(['sla_policy_id' => $policyId]);

                    // A ticket that was resolved without a recorded first response must not show up as an
                    // open first-response milestone that the reconciler would later flag as breached.
                    $firstResponseCompletedAt = $ticket->first_response_at ?? $ticket->resolved_at;

                    $milestones[] = [
                        'ticket_id' => $ticket->id,
                        'sla_policy_id' => $policyId,
                        'key' => 'first_response',
                        'target_minutes' => $ticket->sla_response_target_minutes ?? 1,
                        'started_at' => $started,
                        'due_at' => $ticket->response_due_at,
                        'paused_at' => null,
                        'paused_seconds' => 0,
                        'completed_at' => $firstResponseCompletedAt,
                        'breached_at' => $ticket->response_breached ? ($ticket->first_response_at ?? $ticket->response_due_at) : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($ticket->live_at !== null && $ticket->resolution_due_at !== null) {
                        $milestones[] = [
                            'ticket_id' => $ticket->id,
                            'sla_policy_id' => $policyId,
                            'key' => 'resolution',
                            'target_minutes' => $ticket->sla_resolution_target_minutes ?? 1,
                            'started_at' => $ticket->live_at,
                            'due_at' => $ticket->resolution_due_at,
                            'paused_at' => $ticket->waiting_customer_since,
                            'paused_seconds' => $ticket->waiting_customer_accumulated_seconds ?? 0,
                            'completed_at' => $ticket->resolved_at,
                            'breached_at' => $ticket->resolution_breached ? ($ticket->resolved_at ?? $ticket->resolution_due_at) : null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                DB::table('ticket_sla_milestones')->insertOrIgnore($milestones);
            });
    }

    public function down(): void
    {
        // The v2 tables are dropped by the schema migration; nothing to undo here.
    }
};
