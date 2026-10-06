<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketCustomerImpact;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WarrantyStatus;
use App\Models\Concerns\Favoritable;
use App\Models\Concerns\HasCollaboration;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasFavorites;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable([
    'ticket_number',
    'customer_id',
    'assigned_employee_id',
    'support_team_id',
    'routed_at',
    'routed_by_rule_id',
    'type',
    'customer_impact',
    'priority',
    'title',
    'description',
    'status',
    'pending_reason',
    'is_chargeable',
    'equipment_source',
    'serialized_inventory_unit_id',
    'external_equipment_name',
    'external_equipment_model',
    'external_serial_number',
    'warranty_status',
    'warranty_expiry_date',
    'service_path',
    'triaged_at',
    'triaged_by',
    'charge_waived_reason',
    'diagnostic_fee_required',
    'diagnostic_fee_amount',
    'diagnostic_fee_currency',
    'resolution_summary',
    'continued_from_ticket_id',
    'sla_response_target_minutes',
    'sla_resolution_target_minutes',
    'sla_policy_id',
    'support_entitlement_id',
    'response_sla_started_at',
    'live_at',
    'response_due_at',
    'resolution_due_at',
    'first_response_at',
    'resolved_at',
    'closed_at',
    'reopened_count',
    'last_public_message_at',
    'last_customer_message_at',
    'last_agent_message_at',
    'last_activity_at',
    'response_breached',
    'resolution_breached',
    'waiting_customer_since',
    'waiting_customer_accumulated_seconds',
])]
final class Ticket extends Model implements Favoritable, HasMedia
{
    use HasCollaboration;
    use HasCustomFields;

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use HasFavorites;
    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'type' => TicketType::class,
            'customer_impact' => TicketCustomerImpact::class,
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'is_chargeable' => 'boolean',
            'diagnostic_fee_required' => 'boolean',
            'diagnostic_fee_amount' => 'decimal:2',
            'equipment_source' => TicketEquipmentSource::class,
            'warranty_status' => WarrantyStatus::class,
            'warranty_expiry_date' => 'date',
            'service_path' => TicketServicePath::class,
            'triaged_at' => 'datetime',
            'routed_at' => 'datetime',
            'response_sla_started_at' => 'datetime',
            'live_at' => 'datetime',
            'response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_count' => 'integer',
            'last_public_message_at' => 'datetime',
            'last_customer_message_at' => 'datetime',
            'last_agent_message_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'response_breached' => 'boolean',
            'resolution_breached' => 'boolean',
            'waiting_customer_since' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('ticket-attachments')->useDisk('local');
    }

    /**
     * Attachments the customer uploaded (or that were explicitly shared with them). Files staff attach to
     * a ticket stay internal: they carry no `visibility` property and never reach the customer API.
     *
     * @return Collection<int, Media>
     */
    public function customerVisibleAttachments(): Collection
    {
        return $this->getMedia('ticket-attachments')
            ->filter(static fn (Media $media): bool => $media->getCustomProperty('visibility') === 'customer')
            ->values();
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /** @return BelongsTo<SupportTeam, $this> */
    public function supportTeam(): BelongsTo
    {
        return $this->belongsTo(SupportTeam::class);
    }

    /** @return BelongsTo<SupportRoutingRule, $this> */
    public function routedByRule(): BelongsTo
    {
        return $this->belongsTo(SupportRoutingRule::class, 'routed_by_rule_id');
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function serializedInventoryUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function triagedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triaged_by');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function continuedFromTicket(): BelongsTo
    {
        return $this->belongsTo(self::class, 'continued_from_ticket_id');
    }

    /** @return HasMany<TicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at');
    }

    /** @return HasMany<TicketAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TicketAssignment::class)->orderBy('assigned_at');
    }

    /** @return HasOne<TicketPaymentLink, $this> */
    public function paymentLink(): HasOne
    {
        return $this->hasOne(TicketPaymentLink::class);
    }

    /** @return HasMany<TicketProductContext, $this> */
    public function productContexts(): HasMany
    {
        return $this->hasMany(TicketProductContext::class);
    }

    /** @return HasOne<TicketQualityResolution, $this> */
    public function qualityResolution(): HasOne
    {
        return $this->hasOne(TicketQualityResolution::class);
    }

    /** @return HasMany<MaintenanceRecord, $this> */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    /** @return HasMany<TicketKnowledgeArticle, $this> */
    public function knowledgeLinks(): HasMany
    {
        return $this->hasMany(TicketKnowledgeArticle::class);
    }

    /** @return HasOne<TicketSatisfactionResponse, $this> */
    public function satisfactionResponse(): HasOne
    {
        return $this->hasOne(TicketSatisfactionResponse::class);
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class);
    }

    /** @return BelongsTo<SupportEntitlement, $this> */
    public function supportEntitlement(): BelongsTo
    {
        return $this->belongsTo(SupportEntitlement::class);
    }

    /** @return HasMany<TicketSlaMilestone, $this> */
    public function slaMilestones(): HasMany
    {
        return $this->hasMany(TicketSlaMilestone::class);
    }

    public function isResponseBreached(): bool
    {
        if ($this->response_breached) {
            return true;
        }

        return $this->response_due_at !== null
            && $this->first_response_at === null
            && now()->gt($this->response_due_at);
    }

    public function isResolutionBreached(): bool
    {
        if ($this->resolution_breached) {
            return true;
        }

        return $this->resolution_due_at !== null
            && $this->resolved_at === null
            && now()->gt($this->resolution_due_at);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function responseBreached(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('response_breached', true)
                ->orWhere(function (Builder $query): void {
                    $query->whereNotNull('response_due_at')
                        ->whereNull('first_response_at')
                        ->where('response_due_at', '<', now());
                });
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function resolutionBreached(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('resolution_breached', true)
                ->orWhere(function (Builder $query): void {
                    $query->whereNotNull('resolution_due_at')
                        ->whereNull('resolved_at')
                        ->where('resolution_due_at', '<', now());
                });
        });
    }
}
