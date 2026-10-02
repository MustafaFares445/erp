<?php

declare(strict_types=1);

namespace App\Services\CustomFields;

use App\Enums\CustomFieldDataType;
use App\Enums\CustomFieldEntityType;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class CustomFieldService
{
    /** @return Collection<int, CustomFieldDefinition> */
    public function definitionsFor(Model $subject, bool $activeOnly = true): Collection
    {
        $entityType = $this->entityType($subject);

        return CustomFieldDefinition::query()
            ->when($activeOnly, static fn ($query) => $query->where('is_active', true))
            ->where('entity_type', $entityType->value)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, CustomFieldValue> */
    public function valuesFor(Model $subject): Collection
    {
        return CustomFieldValue::query()
            ->with('definition')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $this->subjectKey($subject))
            ->whereHas('definition', fn ($query) => $query->where('entity_type', $this->entityType($subject)->value))
            ->get();
    }

    /**
     * @param  array<int|string, mixed>  $values  Definition id => submitted value.
     */
    public function sync(User $actor, Model $subject, array $values): void
    {
        Gate::forUser($actor)->authorize('update', $subject);
        $entityType = $this->entityType($subject);
        $subjectKey = $this->subjectKey($subject);

        $definitions = CustomFieldDefinition::query()
            ->where('entity_type', $entityType->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $submitted = [];
        foreach ($values as $definitionId => $value) {
            if (! is_numeric($definitionId)) {
                throw new DomainException('Custom field identifiers must be numeric.');
            }
            $submitted[(int) $definitionId] = $value;
        }

        foreach (array_keys($submitted) as $definitionId) {
            if (! $definitions->has($definitionId)) {
                throw new DomainException('A submitted custom field is not active for this record type.');
            }
        }

        DB::transaction(function () use ($actor, $subject, $subjectKey, $definitions, $submitted): void {
            foreach ($definitions as $definition) {
                $raw = $submitted[$definition->id] ?? null;

                if ($this->isBlank($definition, $raw)) {
                    if ($definition->is_required) {
                        throw new DomainException("Custom field [{$definition->name}] is required.");
                    }

                    CustomFieldValue::query()
                        ->where('custom_field_definition_id', $definition->id)
                        ->where('subject_type', $subject->getMorphClass())
                        ->where('subject_id', $subjectKey)
                        ->delete();

                    continue;
                }

                $payload = $this->normalize($definition, $raw);
                /** @var CustomFieldValue $value */
                $value = CustomFieldValue::query()->firstOrNew([
                    'custom_field_definition_id' => $definition->id,
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subjectKey,
                ]);

                $value->forceFill([
                    'value_text' => null,
                    'value_number' => null,
                    'value_date' => null,
                    'value_boolean' => null,
                    ...$payload,
                    'updated_by' => $actor->getKey(),
                    'created_by' => $value->exists ? $value->created_by : $actor->getKey(),
                ])->save();
            }

            activity()
                ->performedOn($subject)
                ->causedBy($actor)
                ->withProperties(['field_ids' => array_keys($submitted), 'source_channel' => 'dashboard'])
                ->log('custom_field.values.synced');
        });
    }

    /** @return array<string, mixed> */
    private function normalize(CustomFieldDefinition $definition, mixed $raw): array
    {
        return match ($definition->data_type) {
            CustomFieldDataType::Text => ['value_text' => $this->stringValue($raw, 10_000)],
            CustomFieldDataType::LongText => ['value_text' => $this->stringValue($raw, 50_000)],
            CustomFieldDataType::Number => ['value_number' => $this->numberValue($raw)],
            CustomFieldDataType::Date => ['value_date' => $this->dateValue($raw)],
            CustomFieldDataType::Boolean => ['value_boolean' => $this->booleanValue($raw)],
            CustomFieldDataType::Select => ['value_text' => $this->selectValue($definition, $raw)],
        };
    }

    private function entityType(Model $subject): CustomFieldEntityType
    {
        $entityType = CustomFieldEntityType::fromModel($subject);
        if (! $entityType instanceof CustomFieldEntityType) {
            throw new DomainException('Custom fields are not allowed on this record type.');
        }

        return $entityType;
    }

    private function subjectKey(Model $subject): int
    {
        $key = $subject->getKey();
        if (! is_int($key)) {
            throw new DomainException('Custom field subjects must have integer identifiers.');
        }

        return $key;
    }

    private function isBlank(CustomFieldDefinition $definition, mixed $value): bool
    {
        if ($definition->data_type === CustomFieldDataType::Boolean) {
            return $value === null || $value === '';
        }

        return $value === null || (is_string($value) && mb_trim($value) === '');
    }

    private function stringValue(mixed $value, int $maxLength): string
    {
        if (! is_string($value)) {
            throw new DomainException('Custom field value must be text.');
        }

        $value = mb_trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw new DomainException("Custom field text cannot exceed {$maxLength} characters.");
        }

        return $value;
    }

    /** @return numeric-string */
    private function numberValue(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new DomainException('Custom field value must be numeric.');
        }

        return bcadd((string) $value, '0', 6);
    }

    private function dateValue(mixed $value): string
    {
        if (! is_string($value)) {
            throw new DomainException('Custom field date must be a date string.');
        }

        return Carbon::parse($value)->toDateString();
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [0, 1, '0', '1'], true)) {
            return (bool) $value;
        }

        throw new DomainException('Custom field boolean value must be true or false.');
    }

    private function selectValue(CustomFieldDefinition $definition, mixed $value): string
    {
        $value = $this->stringValue($value, 10_000);
        $options = $definition->optionValues();

        if (! in_array($value, $options, true)) {
            throw new DomainException("Custom field [{$definition->name}] has an invalid option.");
        }

        return $value;
    }
}
