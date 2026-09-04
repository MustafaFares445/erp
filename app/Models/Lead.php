<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadDisqualificationReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'source', 'source_detail', 'campaign_id', 'first_name', 'last_name', 'company_name', 'job_title',
    'email', 'phone', 'preferred_language', 'assigned_to',
])]
final class Lead extends Model
{
    use SoftDeletes;

    protected $attributes = ['status' => 'new', 'preferred_language' => 'en'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'source' => LeadSource::class,
            'disqualified_reason' => LeadDisqualificationReason::class,
            'converted_at' => 'datetime',
            'last_interaction_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'converted_customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return MorphMany<Interaction, $this> */
    public function interactions(): MorphMany
    {
        return $this->morphMany(Interaction::class, 'subject')->latest('occurred_at');
    }

    /** @return HasMany<LeadStageTransition, $this> */
    public function stageTransitions(): HasMany
    {
        return $this->hasMany(LeadStageTransition::class)->latest('id');
    }

    /**
     * @param Builder<Lead> $query
     * @return Builder<Lead>
     */
    public function scopeDormant(Builder $query, int $days = 14): Builder
    {
        return $query
            ->whereNotIn('status', [LeadStatus::Converted->value, LeadStatus::Disqualified->value])
            ->where(function (Builder $query) use ($days): void {
                $query->whereNull('last_interaction_at')
                    ->orWhere('last_interaction_at', '<', now()->subDays($days));
            });
    }

    public function displayName(): string
    {
        $firstName = is_string($this->first_name) ? $this->first_name : null;
        $lastName = is_string($this->last_name) ? $this->last_name : null;
        $person = mb_trim(implode(' ', array_filter([$firstName, $lastName])));

        if ($person !== '') {
            return $person;
        }

        if (is_string($this->company_name) && $this->company_name !== '') {
            return $this->company_name;
        }

        return is_string($this->lead_number) ? $this->lead_number : '';
    }
}
