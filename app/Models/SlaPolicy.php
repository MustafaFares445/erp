<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketType;
use Database\Factories\SlaPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'code', 'is_active', 'precedence', 'sla_calendar_id',
    'priority', 'ticket_type', 'service_path', 'support_team_id', 'support_service_level_id',
    'customer_id', 'product_variant_id',
    'response_target_minutes', 'resolution_target_minutes', 'notes', 'updated_by',
])]
final class SlaPolicy extends Model
{
    /** @use HasFactory<SlaPolicyFactory> */
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $policy): void {
            if (auth()->check()) {
                $policy->setAttribute('updated_by', auth()->id());
            }
        });
    }

    #[\Override]
    public function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'ticket_type' => TicketType::class,
            'service_path' => TicketServicePath::class,
            'is_active' => 'boolean',
            'precedence' => 'integer',
        ];
    }

    /** @return BelongsTo<SlaCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(SlaCalendar::class, 'sla_calendar_id');
    }

    /** @return BelongsTo<SupportTeam, $this> */
    public function supportTeam(): BelongsTo
    {
        return $this->belongsTo(SupportTeam::class);
    }

    /** @return BelongsTo<SupportServiceLevel, $this> */
    public function serviceLevel(): BelongsTo
    {
        return $this->belongsTo(SupportServiceLevel::class, 'support_service_level_id');
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return HasMany<SlaPolicyMilestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(SlaPolicyMilestone::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function specificity(): int
    {
        return collect([
            $this->customer_id,
            $this->support_service_level_id,
            $this->product_variant_id,
            $this->support_team_id,
            $this->service_path,
            $this->ticket_type,
            $this->priority,
        ])->filter(static fn (mixed $value): bool => $value !== null)->count();
    }
}
