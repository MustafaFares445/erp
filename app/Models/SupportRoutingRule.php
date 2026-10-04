<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketServicePath;
use App\Enums\TicketType;
use App\Models\Concerns\TracksBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'precedence', 'is_active', 'ticket_type', 'service_path',
    'product_variant_id', 'product_category_id', 'customer_city',
    'support_team_id', 'required_skill_id', 'auto_assign',
])]
final class SupportRoutingRule extends Model
{
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return [
            'precedence' => 'integer',
            'is_active' => 'boolean',
            'auto_assign' => 'boolean',
            'ticket_type' => TicketType::class,
            'service_path' => TicketServicePath::class,
        ];
    }

    /** @return BelongsTo<SupportTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(SupportTeam::class, 'support_team_id');
    }

    /** @return BelongsTo<SupportSkill, $this> */
    public function requiredSkill(): BelongsTo
    {
        return $this->belongsTo(SupportSkill::class, 'required_skill_id');
    }
}
