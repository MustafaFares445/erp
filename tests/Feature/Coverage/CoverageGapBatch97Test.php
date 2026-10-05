<?php

declare(strict_types=1);

use App\Filament\Resources\PurchaseAgreements\Pages\CreatePurchaseAgreement;
use App\Filament\Resources\PurchaseRfqs\Pages\CreatePurchaseRfq;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseRfq;
use App\Models\User;
use App\Services\Purchasing\PurchaseAgreementService;
use App\Services\Purchasing\PurchaseRfqService;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers purchase agreement page actor guard and line normalization', function (): void {
    $page = new ReflectionClass(CreatePurchaseAgreement::class)->newInstanceWithoutConstructor();
    $create = new ReflectionMethod(CreatePurchaseAgreement::class, 'handleRecordCreation');

    auth()->logout();
    expect(fn (): mixed => $create->invoke($page, []))
        ->toThrow(Halt::class);

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $fake = new class
    {
        /** @var array<string,mixed> */
        public array $data = [];

        /** @var list<array<string,mixed>> */
        public array $lines = [];

        public function create(User $actor, array $data, array $lines): PurchaseAgreement
        {
            $this->data = $data;
            $this->lines = $lines;

            return new PurchaseAgreement;
        }
    };

    app()->instance(PurchaseAgreementService::class, $fake);

    $result = $create->invoke($page, [
        'supplier_id' => '11',
        'currency_code' => 'AED',
        'starts_on' => '2026-10-05',
        'ends_on' => '',
        'notes' => null,
        'lines' => [
            'invalid-line',
            [
                'product_variant_id' => '21',
                'unit_id' => '22',
                'unit_price' => 42.5,
                'minimum_order_quantity' => '',
                'lead_time_days' => '7',
            ],
        ],
    ]);

    expect($result)->toBeInstanceOf(PurchaseAgreement::class)
        ->and($fake->data)->toBe([
            'supplier_id' => 11,
            'currency_code' => 'AED',
            'starts_on' => '2026-10-05',
            'ends_on' => null,
            'notes' => null,
        ])
        ->and($fake->lines)->toBe([[
            'product_variant_id' => 21,
            'unit_id' => 22,
            'unit_price' => '42.5',
            'minimum_order_quantity' => null,
            'lead_time_days' => 7,
        ]]);
});

it('covers purchase RFQ page actor guard supplier filtering and line normalization', function (): void {
    $page = new ReflectionClass(CreatePurchaseRfq::class)->newInstanceWithoutConstructor();
    $create = new ReflectionMethod(CreatePurchaseRfq::class, 'handleRecordCreation');

    auth()->logout();
    expect(fn (): mixed => $create->invoke($page, []))
        ->toThrow(Halt::class);

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $fake = new class
    {
        /** @var array<string,mixed> */
        public array $data = [];

        /** @var list<array<string,mixed>> */
        public array $lines = [];

        /** @var list<int> */
        public array $supplierIds = [];

        public function create(User $actor, array $data, array $lines, array $supplierIds): PurchaseRfq
        {
            $this->data = $data;
            $this->lines = $lines;
            $this->supplierIds = $supplierIds;

            return new PurchaseRfq;
        }
    };

    app()->instance(PurchaseRfqService::class, $fake);

    $result = $create->invoke($page, [
        'currency_code' => 'AED',
        'needed_by' => '',
        'closes_at' => null,
        'notes' => 123,
        'supplier_ids' => ['31', 'bad', 32],
        'lines' => [
            null,
            [
                'product_variant_id' => '41',
                'unit_id' => '42',
                'quantity' => 3,
                'notes' => '',
            ],
        ],
    ]);

    expect($result)->toBeInstanceOf(PurchaseRfq::class)
        ->and($fake->data)->toBe([
            'currency_code' => 'AED',
            'needed_by' => null,
            'closes_at' => null,
            'notes' => '123',
        ])
        ->and($fake->lines)->toBe([[
            'product_variant_id' => 41,
            'unit_id' => 42,
            'quantity' => '3',
            'notes' => null,
        ]])
        ->and($fake->supplierIds)->toBe([31, 32]);
});
