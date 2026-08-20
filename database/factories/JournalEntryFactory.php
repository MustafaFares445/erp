<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\JournalEntryStatus;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntry>
 */
final class JournalEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiscal_period_id' => null,
            'entry_date' => now()->toDateString(),
            'description' => fake()->optional()->sentence(),
            'status' => JournalEntryStatus::Draft,
        ];
    }

    /**
     * Two balanced lines against two fresh postable accounts.
     *
     * Lines are attached with `afterCreating` while the entry is still a draft,
     * which matters: {@see JournalEntryLine}'s guard refuses a line on a posted
     * entry, so `balanced()` must run before {@see self::posted()} in the state
     * chain. {@see self::postedAndBalanced()} composes them in the right order.
     */
    public function balanced(string $amount = '100.00'): self
    {
        return $this->afterCreating(function (JournalEntry $entry) use ($amount): void {
            $debitAccount = ChartAccount::factory()->create();
            $creditAccount = ChartAccount::factory()->create();

            JournalEntryLine::factory()->for($entry)->create([
                'chart_account_id' => $debitAccount->getKey(),
                'debit' => $amount,
                'credit' => '0.00',
                'sort_order' => 1,
            ]);

            JournalEntryLine::factory()->for($entry)->create([
                'chart_account_id' => $creditAccount->getKey(),
                'debit' => '0.00',
                'credit' => $amount,
                'sort_order' => 2,
            ]);
        });
    }

    /**
     * Marks the entry posted directly, bypassing
     * {@see JournalPostingService}.
     *
     * Only for arranging test fixtures that need an already-posted row. Never a
     * substitute for exercising the service, whose validation this deliberately
     * skips.
     */
    public function posted(?FiscalPeriod $period = null): self
    {
        return $this->state(fn (): array => [
            'status' => JournalEntryStatus::Posted,
            'fiscal_period_id' => $period?->getKey() ?? self::currentPeriodId(),
        ]);
    }

    /**
     * A posted entry with two balanced lines, ordered so the lines are written
     * before the parent is posted.
     */
    public function postedAndBalanced(string $amount = '100.00', ?FiscalPeriod $period = null): self
    {
        return $this->balanced($amount)
            ->afterCreating(function (JournalEntry $entry) use ($period): void {
                // saveQuietly() bypasses the model's immutability guard, which
                // is correct here and only here: the guard exists to stop
                // application code rewriting posted history, and this is a
                // fixture arranging that history in the first place.
                $entry->forceFill([
                    'status' => JournalEntryStatus::Posted->value,
                    'fiscal_period_id' => $period?->getKey() ?? self::currentPeriodId(),
                ])->saveQuietly();
            });
    }

    /**
     * Reuses whichever period already exists rather than making a second one.
     *
     * Two default-state periods would collide on the unique `name` and would
     * cover the same dates, leaving an entry's period ambiguous. Tests that need
     * a specific period pass it in explicitly.
     */
    private static function currentPeriodId(): int
    {
        $existing = FiscalPeriod::query()->orderBy('id')->value('id');

        return is_numeric($existing)
            ? (int) $existing
            : FiscalPeriod::factory()->create()->id;
    }
}
