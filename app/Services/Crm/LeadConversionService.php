<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\LeadStatus;
use App\Events\LeadConverted;
use App\Models\CustomerProfile;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\LeadStageTransition;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class LeadConversionService
{
    public function __construct(
        private CustomerOnboardingService $customers,
    ) {}

    /**
     * @param  array<string, mixed>  $customerData
     * @param  array<string, UploadedFile>  $documents
     */
    public function convert(Lead $lead, array $customerData, User $actor, array $documents = []): CustomerProfile
    {
        Gate::forUser($actor)->authorize('convert', $lead);

        return DB::transaction(function () use ($lead, $customerData, $actor, $documents): CustomerProfile {
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->sole();

            if ($locked->status !== LeadStatus::Qualified) {
                throw new DomainException('Only a qualified lead can be converted to a customer.');
            }

            if ($locked->converted_customer_id !== null) {
                throw new DomainException('This lead has already been converted.');
            }

            $this->assertNoExistingCustomer($locked);

            $interaction = Interaction::query()
                ->where('subject_type', $locked->getMorphClass())
                ->where('subject_id', $locked->getKey())
                ->latest('occurred_at')
                ->latest('id')
                ->first();

            if (! $interaction instanceof Interaction) {
                throw new DomainException('Lead conversion requires at least one recorded interaction.');
            }

            $customer = $this->customers->register($customerData, $documents);
            $customer->forceFill(['is_active' => true])->save();

            Interaction::query()
                ->where('subject_type', $locked->getMorphClass())
                ->where('subject_id', $locked->getKey())
                ->update([
                    'subject_type' => $customer->getMorphClass(),
                    'subject_id' => $customer->getKey(),
                    'updated_at' => now(),
                ]);

            $locked->forceFill([
                'status' => LeadStatus::Converted,
                'converted_customer_id' => $customer->getKey(),
                'converted_at' => now(),
            ])->save();

            LeadStageTransition::query()->create([
                'lead_id' => $locked->getKey(),
                'from_status' => LeadStatus::Qualified,
                'to_status' => LeadStatus::Converted,
                'interaction_id' => $interaction->getKey(),
                'reason' => 'Converted to customer '.$customer->customer_code,
                'actor_id' => $actor->getKey(),
            ]);

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => LeadStatus::Qualified->value],
                    'attributes' => [
                        'status' => LeadStatus::Converted->value,
                        'converted_customer_id' => $customer->getKey(),
                    ],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('crm.lead.converted');

            DB::afterCommit(static fn () => LeadConverted::dispatch(
                $locked->refresh(),
                $customer->refresh(),
            ));

            return $customer->refresh();
        }, attempts: 5);
    }

    private function assertNoExistingCustomer(Lead $lead): void
    {
        $email = is_string($lead->email) ? mb_strtolower(mb_trim($lead->email)) : '';

        if ($email === '') {
            return;
        }

        if (CustomerProfile::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw new DomainException('A customer with this email already exists. Record the lead activity on that customer instead.');
        }
    }
}
