<?php

declare(strict_types=1);

use App\Models\PaymentTerm;
use App\Services\Sales\PaymentTermService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(PaymentTermService::class);
});

it('creates a default payment term', function (): void {
    $term = $this->service->create(['name' => 'Net 30', 'due_days' => 30, 'is_default' => true]);

    expect($term->is_default)->toBeTrue();
});

it('clears the incumbent default when a second term is marked default (FR-009)', function (): void {
    $first = $this->service->create(['name' => 'Net 15', 'due_days' => 15, 'is_default' => true]);
    $second = $this->service->create(['name' => 'Net 30', 'due_days' => 30, 'is_default' => true]);

    expect($first->refresh()->is_default)->toBeFalse()
        ->and($second->refresh()->is_default)->toBeTrue()
        ->and(PaymentTerm::query()->where('is_default', true)->count())->toBe(1);
});

it('clears the incumbent default on update, not only on create', function (): void {
    $first = PaymentTerm::factory()->default()->create(['name' => 'Net 15']);
    $second = PaymentTerm::factory()->create(['name' => 'Net 30']);

    $this->service->update($second, ['is_default' => true]);

    expect($first->refresh()->is_default)->toBeFalse()
        ->and($second->refresh()->is_default)->toBeTrue();
});

it('derives an invoice due date from days and grace period', function (): void {
    $term = PaymentTerm::factory()->create(['due_days' => 30, 'grace_days' => 5]);
    $invoiceDate = CarbonImmutable::parse('2026-09-01');

    $dueDate = $term->dueDateFrom($invoiceDate);

    expect($dueDate->toDateString())->toBe('2026-10-01')
        ->and($term->isOverdueAt($dueDate, CarbonImmutable::parse('2026-10-04')))->toBeFalse()
        ->and($term->isOverdueAt($dueDate, CarbonImmutable::parse('2026-10-07')))->toBeTrue();
});

it('leaves an unrelated term untouched when a term with is_default false is saved', function (): void {
    $default = PaymentTerm::factory()->default()->create();
    // A name/days pair outside the factory's own {15,30,45,60,90} pool, so it
    // can never collide with whichever value the factory drew above.
    $plain = $this->service->create(['name' => 'Net 21', 'due_days' => 21, 'is_default' => false]);

    expect($default->refresh()->is_default)->toBeTrue()
        ->and($plain->is_default)->toBeFalse();
});
