<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomFieldDataType;
use App\Enums\CustomFieldEntityType;
use App\Models\Concerns\TracksBlameable;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'entity_type', 'code', 'name', 'description', 'data_type', 'options',
    'is_required', 'is_active', 'sort_order',
])]
final class CustomFieldDefinition extends Model
{
    use SoftDeletes;
    use TracksBlameable;

    #[\Override]
    protected static function booted(): void
    {
        self::saving(function (self $definition): void {
            $definition->code = mb_strtolower(mb_trim((string) $definition->code));

            if ($definition->data_type === CustomFieldDataType::Select) {
                $rawOptions = $definition->getAttribute('options');
                if (! is_array($rawOptions) || $rawOptions === []) {
                    throw new DomainException('Select custom fields require at least one option.');
                }

                $definition->options = array_values(array_unique($definition->optionValues()));
            } else {
                $definition->options = null;
            }
        });

        self::updating(function (self $definition): void {
            if (! $definition->isDirty(['entity_type', 'data_type', 'options']) || ! $definition->values()->exists()) {
                return;
            }

            if ($definition->isDirty(['entity_type', 'data_type'])) {
                throw new DomainException('Entity type and data type cannot change after custom field values exist.');
            }

            if ($definition->data_type === CustomFieldDataType::Select) {
                $used = $definition->values()
                    ->whereNotNull('value_text')
                    ->distinct()
                    ->pluck('value_text')
                    ->filter(static fn (mixed $value): bool => is_string($value))
                    ->map(static fn (mixed $value): string => (string) $value)
                    ->values()
                    ->all();
                if (array_diff($used, $definition->optionValues()) !== []) {
                    throw new DomainException('Select options in use cannot be removed from a custom field.');
                }
            }
        });
    }

    /** @return list<string> */
    public function optionValues(): array
    {
        $options = $this->options ?? [];

        return array_values(array_filter(array_map(
            static fn (mixed $option): string => is_string($option) ? mb_trim($option) : '',
            $options,
        ), static fn (string $option): bool => $option !== ''));
    }

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'entity_type' => CustomFieldEntityType::class,
            'data_type' => CustomFieldDataType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<CustomFieldValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
