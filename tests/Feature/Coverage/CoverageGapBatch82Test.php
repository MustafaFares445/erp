<?php

declare(strict_types=1);

use App\Enums\CustomFieldDataType;
use App\Enums\CustomFieldEntityType;
use App\Filament\RelationManagers\CustomFieldsRelationManager;
use App\Models\CustomerProfile;
use App\Models\CustomFieldDefinition;
use App\Models\User;
use App\Services\CustomFields\CustomFieldService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function coverage82Definition(
    string $code,
    CustomFieldDataType $type,
    bool $required = false,
    bool $active = true,
    ?array $options = null,
): CustomFieldDefinition {
    return CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityType::Customer,
        'code' => $code,
        'name' => ucfirst(str_replace('_', ' ', $code)),
        'description' => 'Coverage '.$code,
        'data_type' => $type,
        'is_required' => $required,
        'is_active' => $active,
        'options' => $options,
    ]);
}

it('covers custom-field service inactive filtering identifier guards and optional deletion', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $active = coverage82Definition('coverage_active', CustomFieldDataType::Text);
    $inactive = coverage82Definition('coverage_inactive', CustomFieldDataType::Text, active: false);

    $service = app(CustomFieldService::class);

    expect($service->definitionsFor($customer)->modelKeys())->toBe([$active->id])
        ->and($service->definitionsFor($customer, false)->modelKeys())->toContain($active->id, $inactive->id);

    expect(fn () => $service->sync($actor, $customer, ['invalid' => 'value']))
        ->toThrow(DomainException::class, 'identifiers must be numeric');

    expect(fn () => $service->sync($actor, $customer, [$inactive->id => 'value']))
        ->toThrow(DomainException::class, 'not active');

    $service->sync($actor, $customer, [$active->id => 'stored']);
    expect($customer->customFieldValues()->count())->toBe(1);

    $service->sync($actor, $customer, [$active->id => '   ']);
    expect($customer->customFieldValues()->count())->toBe(0);
});

it('covers every custom-field normalization guard and boolean representation', function (): void {
    $service = app(CustomFieldService::class);

    $text = coverage82Definition('coverage_text', CustomFieldDataType::Text);
    $longText = coverage82Definition('coverage_long_text', CustomFieldDataType::LongText);
    $number = coverage82Definition('coverage_number', CustomFieldDataType::Number);
    $date = coverage82Definition('coverage_date', CustomFieldDataType::Date);
    $boolean = coverage82Definition('coverage_boolean', CustomFieldDataType::Boolean);
    $select = coverage82Definition('coverage_select', CustomFieldDataType::Select, options: ['Gold', 'Silver']);

    $normalize = new ReflectionMethod(CustomFieldService::class, 'normalize');

    expect($normalize->invoke($service, $text, ' text '))->toBe(['value_text' => 'text'])
        ->and($normalize->invoke($service, $longText, ' long '))->toBe(['value_text' => 'long'])
        ->and($normalize->invoke($service, $number, '12.5'))->toBe(['value_number' => '12.500000'])
        ->and($normalize->invoke($service, $date, '2026-10-05'))->toBe(['value_date' => '2026-10-05'])
        ->and($normalize->invoke($service, $boolean, 1))->toBe(['value_boolean' => true])
        ->and($normalize->invoke($service, $boolean, '0'))->toBe(['value_boolean' => false])
        ->and($normalize->invoke($service, $select, 'Gold'))->toBe(['value_text' => 'Gold']);

    expect(fn () => $normalize->invoke($service, $text, 123))
        ->toThrow(DomainException::class, 'must be text')
        ->and(fn () => $normalize->invoke($service, $number, 'not-a-number'))
        ->toThrow(DomainException::class, 'must be numeric')
        ->and(fn () => $normalize->invoke($service, $date, 123))
        ->toThrow(DomainException::class, 'date string')
        ->and(fn () => $normalize->invoke($service, $boolean, 'yes'))
        ->toThrow(DomainException::class, 'true or false')
        ->and(fn () => $normalize->invoke($service, $select, 'Bronze'))
        ->toThrow(DomainException::class, 'invalid option');

    $stringValue = new ReflectionMethod(CustomFieldService::class, 'stringValue');
    expect(fn () => $stringValue->invoke($service, 'abcd', 3))
        ->toThrow(DomainException::class, 'cannot exceed 3');
});

it('covers relation-manager state schemas selection options permissions and actor guard', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();

    $definitions = [
        coverage82Definition('rm_text', CustomFieldDataType::Text, required: true),
        coverage82Definition('rm_long', CustomFieldDataType::LongText),
        coverage82Definition('rm_number', CustomFieldDataType::Number),
        coverage82Definition('rm_date', CustomFieldDataType::Date),
        coverage82Definition('rm_boolean', CustomFieldDataType::Boolean),
        coverage82Definition('rm_select', CustomFieldDataType::Select, options: ['A', 'B']),
    ];

    app(CustomFieldService::class)->sync($actor, $customer, [
        $definitions[0]->id => 'Value',
        $definitions[1]->id => 'Long value',
        $definitions[2]->id => '4.5',
        $definitions[3]->id => '2026-10-05',
        $definitions[4]->id => true,
        $definitions[5]->id => 'B',
    ]);

    $this->actingAs($actor);

    $manager = new CustomFieldsRelationManager;
    $manager->ownerRecord = $customer;

    $state = new ReflectionMethod(CustomFieldsRelationManager::class, 'currentState')->invoke($manager);
    expect($state['field_'.$definitions[0]->id])->toBe('Value')
        ->and($state['field_'.$definitions[2]->id])->toBe('4.500000')
        ->and($state['field_'.$definitions[3]->id])->toBe('2026-10-05')
        ->and($state['field_'.$definitions[4]->id])->toBeTrue()
        ->and($state['field_'.$definitions[5]->id])->toBe('B');

    $fields = new ReflectionMethod(CustomFieldsRelationManager::class, 'fieldSchema')->invoke($manager);
    expect($fields)->toHaveCount(6)
        ->and($fields[0])->toBeInstanceOf(TextInput::class)
        ->and($fields[1])->toBeInstanceOf(Textarea::class)
        ->and($fields[2])->toBeInstanceOf(TextInput::class)
        ->and($fields[3])->toBeInstanceOf(DatePicker::class)
        ->and($fields[4])->toBeInstanceOf(Toggle::class)
        ->and($fields[5])->toBeInstanceOf(Select::class);

    $options = new ReflectionMethod(CustomFieldsRelationManager::class, 'selectOptions')
        ->invoke($manager, $definitions[5]);
    expect($options)->toBe(['A' => 'A', 'B' => 'B'])
        ->and(new ReflectionMethod(CustomFieldsRelationManager::class, 'definitions')->invoke($manager))->toHaveCount(6)
        ->and(new ReflectionMethod(CustomFieldsRelationManager::class, 'canUpdateOwner')->invoke($manager))->toBeTrue()
        ->and(new ReflectionMethod(CustomFieldsRelationManager::class, 'actor')->invoke($manager)->is($actor))->toBeTrue();

    $action = $manager->table(Table::make($manager))->getHeaderActions()[0];
    ($action->getActionFunction())([...$state, 'field_'.$definitions[0]->id => 'Saved through action', 'unrelated' => 'ignored']);
    expect(app(CustomFieldService::class)->valuesFor($customer)->firstWhere('custom_field_definition_id', $definitions[0]->id)?->value_text)
        ->toBe('Saved through action');

    auth()->logout();
    expect(new ReflectionMethod(CustomFieldsRelationManager::class, 'canUpdateOwner')->invoke($manager))->toBeFalse()
        ->and(fn () => new ReflectionMethod(CustomFieldsRelationManager::class, 'actor')->invoke($manager))
        ->toThrow(LogicException::class, 'authenticated user');
});
