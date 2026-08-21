<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntryLine>
 */
final class JournalEntryLineFactory extends Factory
{
    /**
     * Defaults to a debit line, since a line carrying both sides or neither is
     * invalid at posting (FR-021) and a factory should not default to producing
     * a shape the application rejects.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'journal_entry_id' => JournalEntry::factory(),
            'chart_account_id' => ChartAccount::factory(),
            'debit' => '100.00',
            'credit' => '0.00',
            'description' => fake()->optional()->sentence(),
            'sort_order' => 1,
        ];
    }

    public function debit(string $amount): self
    {
        return $this->state(fn (): array => ['debit' => $amount, 'credit' => '0.00']);
    }

    public function credit(string $amount): self
    {
        return $this->state(fn (): array => ['debit' => '0.00', 'credit' => $amount]);
    }

    /** Both sides set — invalid at posting, for testing that rejection. */
    public function bothSides(): self
    {
        return $this->state(fn (): array => ['debit' => '10.00', 'credit' => '10.00']);
    }

    /** Neither side set — invalid at posting, for testing that rejection. */
    public function neitherSide(): self
    {
        return $this->state(fn (): array => ['debit' => '0.00', 'credit' => '0.00']);
    }
}
