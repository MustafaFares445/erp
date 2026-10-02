<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'custom_field_definition_id', 'subject_type', 'subject_id',
    'value_text', 'value_number', 'value_date', 'value_boolean',
])]
final class CustomFieldValue extends Model
{
    #[\Override]
    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:6',
            'value_date' => 'date',
            'value_boolean' => 'boolean',
        ];
    }

    /** @return BelongsTo<CustomFieldDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'custom_field_definition_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
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

    public function displayValue(): string
    {
        $definition = $this->definition;
        if (! $definition instanceof CustomFieldDefinition) {
            return '—';
        }

        return match ($definition->data_type->storageColumn()) {
            'value_number' => $this->value_number ?? '—',
            'value_date' => $this->value_date?->toDateString() ?? '—',
            'value_boolean' => $this->value_boolean === null ? '—' : ($this->value_boolean ? __('Yes') : __('No')),
            default => $this->value_text ?? '—',
        };
    }
}
