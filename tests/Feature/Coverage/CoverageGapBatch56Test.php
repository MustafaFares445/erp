<?php

declare(strict_types=1);

use App\Enums\CustomFieldDataType;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Policies\Concerns\ReadsPreloadedRelationState;
use App\Reporting\ReportDefinition;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

final class CoverageThrowingReportResource extends Resource
{
    public static function canAccess(): bool
    {
        throw new RuntimeException('coverage');
    }
}

it('covers every custom-field display value branch', function (): void {
    $value = new CustomFieldValue;
    $value->setRelation('definition', null);
    expect($value->displayValue())->toBe('—');

    $cases = [
        [CustomFieldDataType::Number, ['value_number' => '12.500000'], '12.500000'],
        [CustomFieldDataType::Number, ['value_number' => null], '—'],
        [CustomFieldDataType::Date, ['value_date' => '2026-10-05'], '2026-10-05'],
        [CustomFieldDataType::Date, ['value_date' => null], '—'],
        [CustomFieldDataType::Boolean, ['value_boolean' => true], __('Yes')],
        [CustomFieldDataType::Boolean, ['value_boolean' => false], __('No')],
        [CustomFieldDataType::Boolean, ['value_boolean' => null], '—'],
        [CustomFieldDataType::Text, ['value_text' => 'Dental lab'], 'Dental lab'],
        [CustomFieldDataType::Text, ['value_text' => null], '—'],
    ];

    foreach ($cases as [$type, $attributes, $expected]) {
        $definition = (new CustomFieldDefinition)->forceFill(['data_type' => $type]);
        $value = (new CustomFieldValue)->forceFill($attributes);
        $value->setRelation('definition', $definition);

        expect($value->displayValue())->toBe($expected);
    }
});

it('covers eager-loaded relation state and invalid relation handling', function (): void {
    $subject = new class extends Model
    {
        use ReadsPreloadedRelationState;

        public function check(string $relation): bool
        {
            return $this->hasRelated($this, $relation);
        }

        public function bogus(): string
        {
            return 'not-a-relation';
        }
    };

    $subject->setRelation('items', new EloquentCollection);
    expect($subject->check('items'))->toBeFalse();

    $subject->setRelation('items', new EloquentCollection([new CustomFieldValue]));
    expect($subject->check('items'))->toBeTrue();

    $subject->setRelation('owner', new CustomFieldValue);
    expect($subject->check('owner'))->toBeTrue();

    $subject->setRelation('owner', null);
    expect($subject->check('owner'))->toBeFalse();

    expect(fn () => $subject->check('bogus'))
        ->toThrow(LogicException::class, '[bogus] is not a relation');
});

it('returns null when report resource URL resolution throws', function (): void {
    $definition = new ReportDefinition(
        key: 'coverage',
        domain: 'coverage',
        category: 'coverage',
        label: 'Coverage',
        description: 'Coverage',
        resource: CoverageThrowingReportResource::class,
    );

    expect($definition->url())->toBeNull();
});
