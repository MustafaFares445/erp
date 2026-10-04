<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\SupportAutomationEvent;
use App\Enums\SupportAutomationRunStatus;
use App\Models\SupportAutomationRule;
use App\Models\SupportAutomationRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class SupportAutomationEngine
{
    public function __construct(
        private SupportAutomationConditionEvaluator $conditionEvaluator,
        private SupportAutomationActionExecutor $actionExecutor,
    ) {}

    /** @param array<string, mixed> $context */
    public function handle(
        SupportAutomationEvent $event,
        Model $subject,
        array $context = [],
        ?string $eventUuid = null,
    ): void {
        if (! config('support.support_automation_enabled', false)) {
            return;
        }

        $eventUuid ??= (string) Str::uuid();

        $rules = SupportAutomationRule::query()
            ->where('event_key', $event->value)
            ->where('is_active', true)
            ->orderBy('precedence')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $run = SupportAutomationRun::query()->firstOrCreate(
                [
                    'support_automation_rule_id' => $rule->getKey(),
                    'event_uuid' => $eventUuid,
                ],
                [
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subject->getKey(),
                    'status' => SupportAutomationRunStatus::Running,
                    'matched' => false,
                ],
            );

            if (! $run->wasRecentlyCreated) {
                continue;
            }

            try {
                $matched = $this->conditionEvaluator->matches($subject, $rule->conditions);

                if (! $matched) {
                    $run->forceFill([
                        'status' => SupportAutomationRunStatus::Skipped,
                        'matched' => false,
                        'executed_at' => now(),
                    ])->save();

                    continue;
                }

                // All actions of one rule succeed together or not at all, so a failing action never leaves a
                // half-applied rule behind.
                $executed = DB::transaction(function () use ($rule, $subject): array {
                    $executed = [];
                    foreach ($rule->actions as $action) {
                        if (! is_array($action)) {
                            continue;
                        }

                        $executed[] = $this->actionExecutor->execute($subject->refresh(), $action);
                    }

                    return $executed;
                });

                $run->forceFill([
                    'status' => SupportAutomationRunStatus::Succeeded,
                    'matched' => true,
                    'actions_executed' => $executed,
                    'executed_at' => now(),
                ])->save();

                activity()
                    ->performedOn($subject)
                    ->withProperties([
                        'source_channel' => 'automation',
                        'automation_rule_id' => $rule->getKey(),
                        'event_key' => $event->value,
                        'event_uuid' => $eventUuid,
                        'context' => $context,
                    ])
                    ->log('support.automation.executed');

                if ($rule->stop_processing) {
                    break;
                }
            } catch (Throwable $throwable) {
                $run->forceFill([
                    'status' => SupportAutomationRunStatus::Failed,
                    'matched' => true,
                    'error' => mb_substr($throwable->getMessage(), 0, 2000),
                    'executed_at' => now(),
                ])->save();
            }
        }
    }
}
