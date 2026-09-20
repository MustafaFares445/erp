<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\QuotationDecision;
use App\Enums\QuotationResponseType;
use App\Enums\QuotationStatus;
use App\Events\QuotationChangesRequested;
use App\Models\Quotation;
use App\Models\QuotationResponse;
use App\Models\User;
use App\Services\Sales\Exceptions\InvalidQuotationTransition;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Owns every customer decision recorded against a Sent quotation —
 * Accepted, Rejected, and ChangesRequested alike — and is the only writer of
 * the append-only {@see QuotationResponse} evidence trail.
 *
 * Accepted/Rejected still delegate their status transition and
 * opportunity close-won/close-lost behaviour to the existing
 * {@see QuotationService::recordDecision()} so that behaviour is defined in
 * exactly one place; this service adds the response evidence row and the
 * ChangesRequested path {@see QuotationService::recordDecision()} does not
 * cover. ChangesRequested never closes the linked opportunity lost — Sales
 * revises the quotation instead of losing the deal.
 */
final readonly class QuotationResponseService
{
    public function __construct(
        private QuotationService $quotationService,
    ) {}

    public function accept(
        Quotation $quotation,
        CarbonInterface $respondedAt,
        ?string $note,
        ?User $respondedBy,
        ?User $recordedBy,
        string $sourceChannel = 'dashboard',
    ): Quotation {
        return $this->recordDecisionResponse(
            $quotation,
            QuotationDecision::Accepted,
            QuotationResponseType::Accepted,
            $respondedAt,
            $note,
            $respondedBy,
            $recordedBy,
            $sourceChannel,
        );
    }

    public function reject(
        Quotation $quotation,
        CarbonInterface $respondedAt,
        string $reason,
        ?User $respondedBy,
        ?User $recordedBy,
        string $sourceChannel = 'dashboard',
    ): Quotation {
        if (mb_trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('admin.sales.errors.response_reason_required')]);
        }

        return $this->recordDecisionResponse(
            $quotation,
            QuotationDecision::Rejected,
            QuotationResponseType::Rejected,
            $respondedAt,
            $reason,
            $respondedBy,
            $recordedBy,
            $sourceChannel,
        );
    }

    /**
     * Marks the Sent quotation ChangesRequested without touching its frozen
     * lines/totals — Sales creates a revised Draft afterwards via
     * {@see QuotationService::requote()}, which re-resolves current prices.
     */
    public function requestChanges(
        Quotation $quotation,
        CarbonInterface $respondedAt,
        string $note,
        ?User $respondedBy,
        ?User $recordedBy,
        string $sourceChannel = 'dashboard',
    ): Quotation {
        if (mb_trim($note) === '') {
            throw ValidationException::withMessages(['note' => __('admin.sales.errors.response_reason_required')]);
        }

        $recorder = $recordedBy ?? $respondedBy;

        if (! $recorder instanceof User) {
            throw new InvalidArgumentException('At least one of $respondedBy or $recordedBy is required.');
        }

        return DB::transaction(function () use ($quotation, $respondedAt, $note, $respondedBy, $recordedBy, $recorder, $sourceChannel): Quotation {
            if ($quotation->status !== QuotationStatus::Sent) {
                throw InvalidQuotationTransition::notSentForResponse((string) $quotation->quotation_number);
            }

            $quotation->update([
                'status' => QuotationStatus::ChangesRequested,
                'decided_at' => $respondedAt->toDateString(),
                'decision_note' => $note,
                'decided_by' => $recorder->getKey(),
            ]);
            $quotation->refresh();

            $this->recordResponse($quotation, QuotationResponseType::ChangesRequested, $note, $respondedBy, $recordedBy, $sourceChannel);

            QuotationChangesRequested::dispatch($quotation);

            return $quotation;
        });
    }

    private function recordDecisionResponse(
        Quotation $quotation,
        QuotationDecision $decision,
        QuotationResponseType $responseType,
        CarbonInterface $respondedAt,
        ?string $note,
        ?User $respondedBy,
        ?User $recordedBy,
        string $sourceChannel,
    ): Quotation {
        $recorder = $recordedBy ?? $respondedBy;

        if (! $recorder instanceof User) {
            throw new InvalidArgumentException('At least one of $respondedBy or $recordedBy is required.');
        }

        return DB::transaction(function () use ($quotation, $decision, $responseType, $respondedAt, $note, $respondedBy, $recordedBy, $recorder, $sourceChannel): Quotation {
            $updated = $this->quotationService->recordDecision($quotation, $decision, $respondedAt, $note, $recorder);

            $this->recordResponse($updated, $responseType, $note, $respondedBy, $recordedBy, $sourceChannel);

            return $updated;
        });
    }

    private function recordResponse(
        Quotation $quotation,
        QuotationResponseType $type,
        ?string $note,
        ?User $respondedBy,
        ?User $recordedBy,
        string $sourceChannel,
    ): void {
        QuotationResponse::query()->create([
            'quotation_id' => $quotation->getKey(),
            'response_type' => $type,
            'note' => $note,
            'responded_by_user_id' => $respondedBy?->getKey(),
            'recorded_by_user_id' => $recordedBy?->getKey(),
            'responded_at' => now(),
            'source_channel' => $sourceChannel,
        ]);
    }
}
