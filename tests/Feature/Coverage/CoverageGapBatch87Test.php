<?php

declare(strict_types=1);

use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyLineCategory;
use App\Filament\Resources\MaintenanceRequests\Actions\WarrantyClaimActions;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\WarrantyClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('transforms partial coverage action payloads before calling the warranty service', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $record = MaintenanceRecord::factory()->covered()->create([
        'diagnosed_at' => now(),
    ]);

    $fake = new class
    {
        /** @var array<string, mixed>|null */
        public ?array $lastData = null;

        /** @return list<array<string, mixed>> */
        public function suggestedCoverageLines(MaintenanceRecord $record): array
        {
            return [];
        }

        /** @param array<string, mixed> $data */
        public function decideCoverage(MaintenanceRecord $record, array $data, User $actor): MaintenanceRecord
        {
            $this->lastData = $data;

            return $record;
        }
    };

    app()->instance(WarrantyClaimService::class, $fake);

    $action = collect(WarrantyClaimActions::make())->first(
        static fn ($action): bool => $action->getName() === 'determineCoverage',
    );
    expect($action)->not->toBeNull();

    $action->getActionFunction()($record, [
        'coverage_decision' => WarrantyClaimDecision::PartiallyCovered->value,
        'coverage_reason' => 'Coverage split',
        'customer_coverage_explanation' => 'Customer pays part.',
        'coverage_lines' => [
            'ignore-non-array',
            [
                'category' => WarrantyLineCategory::Part->value,
                'description' => 'Part',
                'amount' => '12.34',
                'coverage_percent' => 50,
            ],
            [
                'category' => WarrantyLineCategory::Labour->value,
                'description' => 'Labour',
                'amount' => 'invalid',
                'coverage_percent' => 25,
            ],
        ],
    ]);

    expect($fake->lastData)->not->toBeNull()
        ->and($fake->lastData['coverage_lines'])->toHaveCount(2)
        ->and($fake->lastData['coverage_lines'][0]['amount_minor'])->toBe(1234)
        ->and($fake->lastData['coverage_lines'][0]['coverage_source'])->toBe(WarrantyCoverageSource::SellerWarranty->value)
        ->and($fake->lastData['coverage_lines'][1]['amount_minor'])->toBe(0);

    $action->getActionFunction()($record, [
        'coverage_decision' => WarrantyClaimDecision::Rejected->value,
        'coverage_reason' => 'Rejected',
        'customer_coverage_explanation' => 'Customer pays.',
        'coverage_lines' => [['amount' => 99]],
    ]);

    expect($fake->lastData)->not->toHaveKey('coverage_lines');
});

it('covers coverage summary numeric clamping and malformed line handling', function (): void {
    $method = new ReflectionMethod(WarrantyClaimActions::class, 'coverageLineSummary');

    expect($method->invoke(null, null))->toBe(__('No coverage lines yet.'))
        ->and($method->invoke(null, []))->toBe(__('No coverage lines yet.'));

    $summary = $method->invoke(null, [
        'skip-me',
        ['amount' => 100, 'coverage_percent' => 150],
        ['amount' => 50, 'coverage_percent' => -25],
        ['amount' => 'invalid', 'coverage_percent' => 'invalid'],
        ['amount' => 40, 'coverage_percent' => 25],
    ]);

    expect($summary)->toContain('Repair amount 190.00')
        ->toContain('Coverage 110.00')
        ->toContain('Customer responsibility 80.00');
});

it('covers warranty action data normalization eligibility labels and unauthenticated actor guard', function (): void {
    $normalize = new ReflectionMethod(WarrantyClaimActions::class, 'stringKeyedData');

    expect($normalize->invoke(null, [
        'keep' => 'value',
        0 => 'discard',
        'also_keep' => 1,
    ]))->toBe([
        'keep' => 'value',
        'also_keep' => 1,
    ]);

    $eligibility = new ReflectionMethod(WarrantyClaimActions::class, 'eligibilityText');

    $covered = MaintenanceRecord::factory()->covered()->create();
    expect($eligibility->invoke(null, $covered))->toContain('Active until');

    $covered->forceFill(['warranty_expiry_date' => null])->setRawAttributes([
        ...$covered->getAttributes(),
        'warranty_expiry_date' => null,
    ]);
    expect($eligibility->invoke(null, $covered))->toBe(__('Active'));

    auth()->logout();
    expect(fn (): mixed => new ReflectionMethod(WarrantyClaimActions::class, 'currentActor')->invoke(null))
        ->toThrow(LogicException::class, 'authenticated user');
});
