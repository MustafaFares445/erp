<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasCustomFields
{
    /** @return MorphMany<CustomFieldValue, $this> */
    public function customFieldValues(): MorphMany
    {
        return $this->morphMany(CustomFieldValue::class, 'subject');
    }
}
