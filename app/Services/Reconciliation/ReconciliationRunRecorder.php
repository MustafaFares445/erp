<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Enums\ReconciliationScope;
use App\Models\ReconciliationRun;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ReconciliationRunRecorder
{
    private const MAX_DETAIL_ERRORS = 100;

    /**
     * @param iterable<array{name:string, errors:list<string>}> $results
     */
    public function record(
        ReconciliationScope $scope,
        iterable $results,
        string $triggerSource,
        ?User $actor = null,
    ): void {
        if (! in_array($triggerSource, ['schedule', 'manual', 'period_close'], true)) {
            throw new DomainException('Unsupported reconciliation trigger source.');
        }

        $startedAt = now();
        $finishedAt = now();

        DB::transaction(function () use ($scope, $results, $triggerSource, $actor, $startedAt, $finishedAt): void {
            foreach ($results as $result) {
                $errors = array_values(array_filter(
                    $result['errors'],
                    static fn (mixed $error): bool => is_string($error) && $error !== '',
                ));

                $detail = array_slice($errors, 0, self::MAX_DETAIL_ERRORS);

                if (count($errors) > self::MAX_DETAIL_ERRORS) {
                    $detail[] = sprintf(
                        '[truncated: %d additional divergence(s)]',
                        count($errors) - self::MAX_DETAIL_ERRORS,
                    );
                }

                ReconciliationRun::query()->create([
                    'scope' => $scope,
                    'invariant' => $result['name'],
                    'passed' => $errors === [],
                    'divergence_count' => count($errors),
                    'detail' => $detail === [] ? null : $detail,
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'triggered_by' => $actor?->getKey(),
                    'trigger_source' => $triggerSource,
                ]);
            }
        });
    }
}
