<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OpportunityStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sales_opportunity_id', 'from_stage', 'to_stage', 'interaction_id', 'actor_id', 'occurred_at'])]
final class OpportunityStageTransition extends Model
{
    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'from_stage' => OpportunityStage::class,
            'to_stage' => OpportunityStage::class,
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SalesOpportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(SalesOpportunity::class, 'sales_opportunity_id');
    }

    /** @return BelongsTo<Interaction, $this> */
    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interaction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
