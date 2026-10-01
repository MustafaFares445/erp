<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyFailureCategory;
use App\Enums\WarrantyLineCategory;
use App\Enums\WarrantyStatus;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\ProductVariant;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Inventory\PriceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class WarrantyClaimService
{
    public function __construct(private PriceResolver $priceResolver) {}

    /** @param array<string, mixed> $data */
    public function recordDiagnosis(MaintenanceRecord $record, array $data, User $actor): MaintenanceRecord
    {
        Gate::forUser($actor)->authorize('diagnose', $record);
        $this->assertOpenForAssessment($record);

        $summary = $this->requiredText($data['diagnosis_summary'] ?? null, 'diagnosis_summary', 'Diagnosis findings are required.');
        $rootCause = $this->requiredText($data['root_cause'] ?? null, 'root_cause', 'Root cause is required.');
        $category = $this->failureCategory($data['failure_category'] ?? null);

        return DB::transaction(function () use ($record, $summary, $rootCause, $category, $actor): MaintenanceRecord {
            $old = $record->only(['diagnosis_summary', 'root_cause', 'failure_category', 'diagnosed_at', 'diagnosed_by']);

            $record->update([
                'diagnosis_summary' => $summary,
                'root_cause' => $rootCause,
                'failure_category' => $category,
                'diagnosed_at' => now(),
                'diagnosed_by' => $actor->getKey(),
                'coverage_decision' => WarrantyClaimDecision::PendingDiagnosis,
                'coverage_source' => null,
                'coverage_reason' => null,
                'customer_coverage_explanation' => null,
                'coverage_decided_at' => null,
                'coverage_decided_by' => null,
                'status' => $record->status === MaintenanceStatus::Open
                    ? MaintenanceStatus::Diagnosing
                    : $record->status,
            ]);

            $record->coverageLines()->delete();

            activity()
                ->performedOn($record)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $old,
                    'attributes' => $record->only(['diagnosis_summary', 'root_cause', 'failure_category', 'diagnosed_at', 'diagnosed_by']),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.maintenance_record.diagnosed');

            return $record->refresh();
        });
    }

    /**
     * @return list<array{
     *   category:string,description:string,source_type:string|null,source_id:int|null,
     *   amount_minor:int,coverage_percent:float,coverage_source:string|null
     * }>
     */
    public function suggestedCoverageLines(MaintenanceRecord $record): array
    {
        $record->loadMissing([
            'customer.user',
            'serviceRecords.parts.productVariant',
            'labourEntries',
            'thirdPartyCosts',
            'serializedInventoryUnit.warrantyEntitlements',
        ]);

        $entitlement = $record->serializedInventoryUnit?->warrantyEntitlements
            ->where('customer_id', $record->customer_id)
            ->sortByDesc('id')
            ->first();

        $legacyEligible = $record->warranty_status === WarrantyStatus::Covered;
        $lines = [];

        foreach ($record->serviceRecords as $task) {
            foreach ($task->parts as $part) {
                if ($part->reversed_at !== null) {
                    continue;
                }

                $variant = $part->productVariant;
                if (! $variant instanceof ProductVariant) {
                    continue;
                }

                $resolved = $this->priceResolver->resolve($variant, $record->customer?->user);
                $amountMinor = (int) round($resolved->amount * (float) $part->quantity * 100);
                $covered = $entitlement instanceof WarrantyEntitlement
                    ? $entitlement->covers_parts
                    : $legacyEligible;

                $lines[] = [
                    'category' => WarrantyLineCategory::Part->value,
                    'description' => $variant->name !== '' ? $variant->name : $variant->sku,
                    'source_type' => ServiceRecordPart::class,
                    'source_id' => $part->id,
                    'amount_minor' => max(0, $amountMinor),
                    'coverage_percent' => $covered ? 100.0 : 0.0,
                    'coverage_source' => $covered ? WarrantyCoverageSource::SellerWarranty->value : WarrantyCoverageSource::CustomerPaid->value,
                ];
            }
        }

        $labourSum = $record->labourEntries->sum('total_cost_minor');
        $labourMinor = is_numeric($labourSum) ? (int) $labourSum : 0;
        if ($labourMinor > 0) {
            $covered = $entitlement instanceof WarrantyEntitlement
                ? $entitlement->covers_labour
                : $legacyEligible;

            $lines[] = [
                'category' => WarrantyLineCategory::Labour->value,
                'description' => 'Technician labour',
                'source_type' => 'maintenance_labour',
                'source_id' => null,
                'amount_minor' => $labourMinor,
                'coverage_percent' => $covered ? 100.0 : 0.0,
                'coverage_source' => $covered ? WarrantyCoverageSource::SellerWarranty->value : WarrantyCoverageSource::CustomerPaid->value,
            ];
        }

        foreach ($record->thirdPartyCosts as $cost) {
            $covered = $entitlement instanceof WarrantyEntitlement
                ? $entitlement->covers_third_party
                : false;

            $lines[] = [
                'category' => WarrantyLineCategory::ThirdParty->value,
                'description' => $cost->description,
                'source_type' => MaintenanceThirdPartyCost::class,
                'source_id' => $cost->id,
                'amount_minor' => (int) $cost->amount_minor,
                'coverage_percent' => $covered ? 100.0 : 0.0,
                'coverage_source' => $covered ? WarrantyCoverageSource::SellerWarranty->value : WarrantyCoverageSource::CustomerPaid->value,
            ];
        }

        return $lines;
    }

    /** @param array<string, mixed> $data */
    public function decideCoverage(MaintenanceRecord $record, array $data, User $actor): MaintenanceRecord
    {
        Gate::forUser($actor)->authorize('decideCoverage', $record);
        $this->assertOpenForAssessment($record);

        if ($record->diagnosed_at === null) {
            throw ValidationException::withMessages([
                'coverage_decision' => 'Record the diagnosis before deciding warranty coverage.',
            ]);
        }

        $decision = $this->claimDecision($data['coverage_decision'] ?? null);
        if ($decision === WarrantyClaimDecision::PendingDiagnosis) {
            throw ValidationException::withMessages([
                'coverage_decision' => 'Choose a final coverage decision.',
            ]);
        }

        $reason = $this->requiredText($data['coverage_reason'] ?? null, 'coverage_reason', 'A coverage decision reason is required.');
        $explanation = $this->nullableText($data['customer_coverage_explanation'] ?? null);

        if (in_array($decision, [WarrantyClaimDecision::Rejected, WarrantyClaimDecision::PartiallyCovered], true)
            && $explanation === null) {
            throw ValidationException::withMessages([
                'customer_coverage_explanation' => 'Explain the customer responsibility for rejected or partial coverage.',
            ]);
        }

        if (in_array($decision, [WarrantyClaimDecision::FullyCovered, WarrantyClaimDecision::PartiallyCovered], true)
            && $record->warranty_status !== WarrantyStatus::Covered) {
            throw ValidationException::withMessages([
                'coverage_decision' => 'Seller warranty coverage requires an active warranty entitlement. Use goodwill, service contract, or third-party warranty when appropriate.',
            ]);
        }

        $source = $this->coverageSourceFor($decision, $data['coverage_source'] ?? null);
        $lineInput = $data['coverage_lines'] ?? null;
        $lines = is_array($lineInput) ? array_values($lineInput) : $this->suggestedCoverageLines($record);

        return DB::transaction(function () use ($record, $decision, $source, $reason, $explanation, $lines, $actor): MaintenanceRecord {
            $locked = MaintenanceRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $locked->coverageLines()->delete();

            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $this->persistCoverageLine(
                    $locked,
                    $this->stringKeyedData($line),
                    $decision,
                    $source,
                    $actor,
                );
            }

            $old = $locked->only([
                'coverage_decision',
                'coverage_source',
                'coverage_reason',
                'customer_coverage_explanation',
                'coverage_decided_at',
                'coverage_decided_by',
            ]);

            $nextStatus = $locked->status;
            if (in_array($locked->status, [
                MaintenanceStatus::Open,
                MaintenanceStatus::Diagnosing,
                MaintenanceStatus::AwaitingApproval,
                MaintenanceStatus::ReadyForRepair,
            ], true)) {
                $nextStatus = in_array($decision, [
                    WarrantyClaimDecision::Rejected,
                    WarrantyClaimDecision::PartiallyCovered,
                ], true)
                    ? MaintenanceStatus::AwaitingApproval
                    : MaintenanceStatus::ReadyForRepair;
            }

            $locked->update([
                'coverage_decision' => $decision,
                'coverage_source' => $source,
                'coverage_reason' => $reason,
                'customer_coverage_explanation' => $explanation,
                'coverage_decided_at' => now(),
                'coverage_decided_by' => $actor->getKey(),
                'status' => $nextStatus,
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $old,
                    'attributes' => [
                        'coverage_decision' => $decision->value,
                        'coverage_source' => $source?->value,
                        'coverage_reason' => $reason,
                        'customer_coverage_explanation' => $explanation,
                    ],
                ])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'ip_address' => request()->ip(),
                    'customer_responsibility_minor' => $this->coverageSummary($locked)['customer_amount_minor'],
                ])
                ->log('support.maintenance_record.coverage_decided');

            return $locked->refresh();
        });
    }

    /** @return array{total_amount_minor:int,covered_amount_minor:int,customer_amount_minor:int} */
    public function coverageSummary(MaintenanceRecord $record): array
    {
        return [
            'total_amount_minor' => (int) $record->coverageLines()->sum('amount_minor'),
            'covered_amount_minor' => (int) $record->coverageLines()->sum('covered_amount_minor'),
            'customer_amount_minor' => (int) $record->coverageLines()->sum('customer_amount_minor'),
        ];
    }

    /** @param array<string, mixed> $line */
    private function persistCoverageLine(
        MaintenanceRecord $record,
        array $line,
        WarrantyClaimDecision $decision,
        ?WarrantyCoverageSource $decisionSource,
        User $actor,
    ): void {
        $categoryRaw = $line['category'] ?? null;
        $category = is_string($categoryRaw) ? WarrantyLineCategory::tryFrom($categoryRaw) : null;
        if (! $category instanceof WarrantyLineCategory) {
            throw ValidationException::withMessages(['coverage_lines' => 'Every coverage line requires a valid category.']);
        }

        $description = $this->requiredText($line['description'] ?? null, 'coverage_lines', 'Every coverage line requires a description.');
        $amountRaw = $line['amount_minor'] ?? null;
        if (! is_numeric($amountRaw) || (int) $amountRaw < 0) {
            throw ValidationException::withMessages(['coverage_lines' => 'Every coverage line requires a non-negative amount.']);
        }

        $amountMinor = (int) $amountRaw;
        $percent = match ($decision) {
            WarrantyClaimDecision::FullyCovered,
            WarrantyClaimDecision::Goodwill,
            WarrantyClaimDecision::ThirdPartyWarranty,
            WarrantyClaimDecision::ServiceContract => 100.0,
            WarrantyClaimDecision::Rejected => 0.0,
            WarrantyClaimDecision::PartiallyCovered => $this->coveragePercent($line['coverage_percent'] ?? null),
            WarrantyClaimDecision::PendingDiagnosis => 0.0,
        };

        $coveredMinor = (int) round($amountMinor * $percent / 100);
        $customerMinor = max(0, $amountMinor - $coveredMinor);
        $lineSource = match ($decision) {
            WarrantyClaimDecision::Goodwill,
            WarrantyClaimDecision::ThirdPartyWarranty,
            WarrantyClaimDecision::ServiceContract => $decisionSource ?? WarrantyCoverageSource::CustomerPaid,
            WarrantyClaimDecision::FullyCovered => WarrantyCoverageSource::SellerWarranty,
            WarrantyClaimDecision::Rejected => WarrantyCoverageSource::CustomerPaid,
            WarrantyClaimDecision::PartiallyCovered => $this->lineCoverageSource(
                $line['coverage_source'] ?? null,
                $percent,
                $decisionSource,
            ),
            WarrantyClaimDecision::PendingDiagnosis => WarrantyCoverageSource::CustomerPaid,
        };

        MaintenanceCoverageLine::query()->create([
            'maintenance_record_id' => $record->getKey(),
            'category' => $category,
            'description' => $description,
            'source_type' => isset($line['source_type']) && is_string($line['source_type']) ? $line['source_type'] : null,
            'source_id' => isset($line['source_id']) && is_numeric($line['source_id']) ? (int) $line['source_id'] : null,
            'amount_minor' => $amountMinor,
            'coverage_percent' => $percent,
            'covered_amount_minor' => $coveredMinor,
            'customer_amount_minor' => $customerMinor,
            'coverage_source' => $lineSource,
            'notes' => $this->nullableText($line['notes'] ?? null),
            'decided_by' => $actor->getKey(),
        ]);
    }

    private function coverageSourceFor(WarrantyClaimDecision $decision, mixed $raw): ?WarrantyCoverageSource
    {
        return match ($decision) {
            WarrantyClaimDecision::FullyCovered,
            WarrantyClaimDecision::PartiallyCovered => WarrantyCoverageSource::SellerWarranty,
            WarrantyClaimDecision::Rejected => WarrantyCoverageSource::CustomerPaid,
            WarrantyClaimDecision::Goodwill => WarrantyCoverageSource::Goodwill,
            WarrantyClaimDecision::ServiceContract => WarrantyCoverageSource::ServiceContract,
            WarrantyClaimDecision::ThirdPartyWarranty => $this->thirdPartySource($raw),
            WarrantyClaimDecision::PendingDiagnosis => null,
        };
    }

    private function thirdPartySource(mixed $raw): WarrantyCoverageSource
    {
        $source = is_string($raw) ? WarrantyCoverageSource::tryFrom($raw) : null;

        if (! in_array($source, [
            WarrantyCoverageSource::ManufacturerWarranty,
            WarrantyCoverageSource::SupplierWarranty,
        ], true)) {
            throw ValidationException::withMessages([
                'coverage_source' => 'Choose manufacturer or supplier warranty as the coverage source.',
            ]);
        }

        return $source;
    }

    private function lineCoverageSource(mixed $raw, float $percent, ?WarrantyCoverageSource $fallback): WarrantyCoverageSource
    {
        if ($percent <= 0) {
            return WarrantyCoverageSource::CustomerPaid;
        }

        $source = is_string($raw) ? WarrantyCoverageSource::tryFrom($raw) : null;

        return $source ?? $fallback ?? WarrantyCoverageSource::SellerWarranty;
    }

    private function coveragePercent(mixed $raw): float
    {
        if (! is_numeric($raw)) {
            throw ValidationException::withMessages(['coverage_lines' => 'Coverage percentage is required for each partial-coverage line.']);
        }

        $percent = round((float) $raw, 2);
        if ($percent < 0 || $percent > 100) {
            throw ValidationException::withMessages(['coverage_lines' => 'Coverage percentage must be between 0 and 100.']);
        }

        return $percent;
    }

    private function claimDecision(mixed $raw): WarrantyClaimDecision
    {
        $decision = is_string($raw) ? WarrantyClaimDecision::tryFrom($raw) : null;

        if (! $decision instanceof WarrantyClaimDecision) {
            throw ValidationException::withMessages(['coverage_decision' => 'Choose a valid coverage decision.']);
        }

        return $decision;
    }

    private function failureCategory(mixed $raw): WarrantyFailureCategory
    {
        $category = is_string($raw) ? WarrantyFailureCategory::tryFrom($raw) : null;

        if (! $category instanceof WarrantyFailureCategory) {
            throw ValidationException::withMessages(['failure_category' => 'Choose a valid failure category.']);
        }

        return $category;
    }

    private function assertOpenForAssessment(MaintenanceRecord $record): void
    {
        if (in_array($record->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'record' => 'Closed or cancelled maintenance requests cannot be reassessed.',
            ]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private function stringKeyedData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function requiredText(mixed $raw, string $field, string $message): string
    {
        $value = $this->nullableText($raw);

        if ($value === null) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $value;
    }

    private function nullableText(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = mb_trim($raw);

        return $value === '' ? null : $value;
    }
}
