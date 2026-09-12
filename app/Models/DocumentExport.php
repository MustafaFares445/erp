<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'module',
    'type',
    'format',
    'parameters',
    'row_count',
    'file_path',
    'status',
    'failure_reason',
    'created_by',
    'completed_at',
    'expires_at',
])]
final class DocumentExport extends Model
{
    #[\Override]
    public function casts(): array
    {
        return [
            'parameters' => 'array',
            'row_count' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return Attribute<array<string, mixed>, never> */
    protected function filters(): Attribute
    {
        return Attribute::get(function (): array {
            $parameters = is_array($this->parameters) ? $this->parameters : [];
            $rawFilters = $parameters['filters'] ?? [];

            if (! is_array($rawFilters)) {
                return [];
            }

            $filters = [];
            foreach ($rawFilters as $key => $value) {
                if (is_string($key)) {
                    $filters[$key] = $value;
                }
            }

            return $filters;
        });
    }
}
