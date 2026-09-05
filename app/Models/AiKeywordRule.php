<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Database\Factories\AiKeywordRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['keyword', 'product_id', 'product_variant_id', 'is_active'])]
final class AiKeywordRule extends Model
{
    /** @use HasFactory<AiKeywordRuleFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        // A soft delete never fires the `ai_keyword_rule_id` foreign key's
        // `nullOnDelete()` (the row is only updated, not removed), so the
        // reference has to be cleared explicitly here — mirroring the
        // retention guarantee WP-1.10 gives `voice_note_transcription_id`,
        // whose owning model is hard-deleted and so relies on the database
        // constraint alone.
        self::deleting(static function (self $rule): void {
            SalesOpportunity::query()
                ->where('ai_keyword_rule_id', $rule->getKey())
                ->update(['ai_keyword_rule_id' => null]);
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
