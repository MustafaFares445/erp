<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TaxRecognitionEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['tax_date', 'direction', 'tax_type', 'tax_amount', 'source_type', 'source_id'])]
final class TaxRecognitionEntry extends Model
{
    /** @use HasFactory<TaxRecognitionEntryFactory> */
    use HasFactory;

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'tax_date' => 'date',
            'tax_amount' => 'decimal:2',
        ];
    }
}
