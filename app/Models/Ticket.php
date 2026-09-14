<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketEquipmentSource;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WarrantyStatus;
use App\Models\Concerns\TracksBlameable;
use App\Services\Support\SlaService;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'ticket_number',
    'customer_id',
    'assigned_employee_id',
    'type',
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
    'resolution_summary',
    'continued_from_ticket_id',
    'sla_response_target_minutes',
    'sla_resolution_target_minutes',
    'live_at',
    'response_due_at',
    'resolution_due_at',
    'first_response_at',
    'resolved_at',
    'response_breached',
    'resolution_breached',
    'waiting_customer_since',
    'waiting_customer_accumulated_seconds',
])]
final class Ticket extends Model implements HasMedia
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'type' => TicketType::class,
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'is_chargeable' => 'boolean',
            'equipment_source' => TicketEquipmentSource::class,
            'warranty_status' => WarrantyStatus::class,
            'warranty_expiry_date' => 'date',
            'service_path' => TicketServicePath::class,
            'triaged_at' => 'datetime',
            'live_at' => 'datetime',
            'response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'response_breached' => 'boolean',
            'resolution_breached' => 'boolean',
            'waiting_customer_since' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('ticket-attachments')->useDisk('local');
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

    /** @return HasMany<MaintenanceRecord, $this> */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
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
    public function scopeResponseBreached(Builder $query): Builder
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
    public function scopeResolutionBreached(Builder $query): Builder
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
