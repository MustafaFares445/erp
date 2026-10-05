<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Filament\Components\WorkflowStepper;
use App\Filament\Resources\Quotations\Schemas\QuotationLinesRepeater;
use App\Models\ProductVariant;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function coverage113Property(object $object, string $name): mixed
{
    $reflection = new ReflectionClass($object);

    do {
        if ($reflection->hasProperty($name)) {
            return $reflection->getProperty($name)->getValue($object);
        }
    } while ($reflection = $reflection->getParentClass());

    throw new LogicException("Property {$name} not found.");
}

function coverage113FindComponent(iterable $components, string $name): mixed
{
    foreach ($components as $component) {
        if (method_exists($component, 'getName') && $component->getName() === $name) {
            return $component;
        }

        try {
            $sets = (array) coverage113Property($component, 'childComponents');
            foreach ($sets as $children) {
                if (! is_iterable($children)) {
                    continue;
                }

                $found = coverage113FindComponent($children, $name);
                if ($found !== null) {
                    return $found;
                }
            }
        } catch (LogicException) {
            // Leaf component.
        }
    }

    return null;
}

it('covers workflow stepper invalid steps scalar state terminal and missing-key branches', function (): void {
    $invalid = WorkflowStepper::make('workflow')->steps(static fn (): string => 'invalid');
    expect($invalid->getSteps())->toBe([]);

    $stepper = WorkflowStepper::make('workflow')
        ->steps([
            TicketStatus::Live->value => 'Live',
            TicketStatus::Assigned->value => 'Assigned',
            TicketStatus::Closed->value => 'Closed',
        ])
        ->terminalKeys([TicketStatus::Closed->value]);

    $stepper->state(TicketStatus::Closed);
    expect($stepper->currentKey())->toBe(TicketStatus::Closed->value)
        ->and($stepper->statusFor(TicketStatus::Closed->value))->toBe('terminal')
        ->and($stepper->statusFor(TicketStatus::Live->value))->toBe('upcoming');

    $stepper->state('unknown');
    expect($stepper->currentKey())->toBe('unknown')
        ->and($stepper->statusFor(TicketStatus::Live->value))->toBe('upcoming');

    $stepper->state(null);
    expect($stepper->currentKey())->toBeNull();
});

it('covers quotation variant update tier-price branch', function (): void {
    $variant = ProductVariant::factory()->create(['base_price' => '42.50']);

    $repeater = QuotationLinesRepeater::make();
    $sets = (array) coverage113Property($repeater, 'childComponents');
    $variantSelect = null;

    foreach ($sets as $children) {
        if (is_iterable($children)) {
            $variantSelect = coverage113FindComponent($children, 'product_variant_id');
            if ($variantSelect !== null) {
                break;
            }
        }
    }

    expect($variantSelect)->toBeInstanceOf(Select::class);

    $hooks = coverage113Property($variantSelect, 'afterStateUpdated');
    expect($hooks)->toBeArray()->not->toBeEmpty();

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(static fn (string $path): mixed => match ($path) {
        'use_tier_price' => true,
        '../../customer_id' => null,
        default => null,
    });

    $set = Mockery::mock(Set::class);
    $set->shouldReceive('__invoke')->with('unit_id', Mockery::any())->once();
    $set->shouldReceive('__invoke')->with('unit_price', Mockery::type('float'))->once();

    $hooks[0]($set, $get, $variant->id);
});

it('renders quotation product image preview when a variant has media', function (): void {
    Storage::fake('public');

    $variant = ProductVariant::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'coverage-113-').'.png';
    $image = new Imagick;
    $image->newImage(4, 4, new ImagickPixel('white'), 'png');
    $image->writeImage($path);
    $image->clear();

    $variant->addMedia($path)
        ->usingFileName('coverage-113.png')
        ->toMediaCollection('images', 'public');

    $preview = new ReflectionMethod(QuotationLinesRepeater::class, 'productImagePreview');
    $html = (string) $preview->invoke(null, $variant->id);

    expect($html)->toContain('<img', 'coverage-113');
});
