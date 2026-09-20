<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\CustomerQuotationRequestStatus;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Models\CustomerQuotationRequest;
use App\Models\CustomerQuotationRequestLine;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Crm\Exceptions\InvalidCustomerQuotationRequestTransition;
use App\Services\Inventory\PriceResolver;
use App\Services\Sales\CustomerOrderingPolicyService;
use App\Services\Sales\DocumentNumberGenerator;
use App\Services\Sales\QuotationService;
use Illuminate\Support\Facades\DB;

/**
 * Owns the customer pre-quotation request lifecycle: submit, review, and
 * convert. Conversion delegates all pricing/tax/total logic to the existing
 * {@see QuotationService} — {@see PriceResolver} resolves the current price,
 * never a value stored on the request.
 */
final readonly class CustomerQuotationRequestService
{
    public function __construct(
        private DocumentNumberGenerator $numberGenerator,
        private QuotationService $quotationService,
        private CustomerOrderingPolicyService $orderingPolicy,
    ) {}

    /**
     * @param  list<array{product_variant_id:int, requested_quantity:float|int|string, requested_unit_id?:int|null, customer_note?:string|null}>  $lines
     */
    public function submit(
        CustomerProfile $customer,
        array $lines,
        ?CustomerDeliveryAddress $deliveryAddress = null,
        ?string $notes = null,
        string $sourceChannel = 'dashboard',
    ): CustomerQuotationRequest {
        if (! $this->orderingPolicy->canRequestQuotation($customer)) {
            throw new InvalidCustomerQuotationRequestTransition('Only an approved, active customer may submit a quote request.');
        }

        if ($lines === []) {
            throw new InvalidCustomerQuotationRequestTransition('A quote request requires at least one line.');
        }

        if ($deliveryAddress instanceof CustomerDeliveryAddress && $deliveryAddress->customer_profile_id !== $customer->getKey()) {
            throw new InvalidCustomerQuotationRequestTransition('The delivery address does not belong to this customer.');
        }

        return DB::transaction(function () use ($customer, $lines, $deliveryAddress, $notes, $sourceChannel): CustomerQuotationRequest {
            $request = CustomerQuotationRequest::query()->create([
                'request_number' => $this->numberGenerator->next(CustomerQuotationRequest::query(), 'request_number', 'QR-'),
                'customer_id' => $customer->getKey(),
                'customer_delivery_address_id' => $deliveryAddress?->getKey(),
                'notes' => $notes,
                'status' => CustomerQuotationRequestStatus::Submitted,
                'submitted_at' => now(),
                'source_channel' => $sourceChannel,
            ]);

            foreach ($lines as $index => $line) {
                $quantity = (float) $line['requested_quantity'];

                if ($quantity <= 0.0) {
                    throw new InvalidCustomerQuotationRequestTransition('Each requested quantity must be positive.');
                }

                $variant = ProductVariant::query()->findOrFail((int) $line['product_variant_id']);

                if (! $variant->isOperational()) {
                    throw new InvalidCustomerQuotationRequestTransition(sprintf('Variant "%s" is not available for ordering.', $variant->sku));
                }

                $request->lines()->create([
                    'product_variant_id' => $variant->getKey(),
                    'requested_quantity' => $line['requested_quantity'],
                    'requested_unit_id' => $line['requested_unit_id'] ?? null,
                    'customer_note' => $line['customer_note'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            return $request->refresh();
        });
    }

    public function startReview(User $actor, CustomerQuotationRequest $request): CustomerQuotationRequest
    {
        return DB::transaction(function () use ($actor, $request): CustomerQuotationRequest {
            $locked = $this->lockOpen($request);

            $locked->forceFill([
                'status' => CustomerQuotationRequestStatus::UnderReview,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->log($locked, $actor, 'customer.quotation_request.under_review');

            return $locked->refresh();
        });
    }

    public function reject(User $actor, CustomerQuotationRequest $request, string $reason): CustomerQuotationRequest
    {
        return DB::transaction(function () use ($actor, $request, $reason): CustomerQuotationRequest {
            $locked = $this->lockOpen($request);

            $locked->forceFill([
                'status' => CustomerQuotationRequestStatus::Rejected,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $reason,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->log($locked, $actor, 'customer.quotation_request.rejected');

            return $locked->refresh();
        });
    }

    public function convertToQuotation(User $actor, CustomerQuotationRequest $request, ?int $paymentTermId = null): Quotation
    {
        return DB::transaction(function () use ($actor, $request, $paymentTermId): Quotation {
            $locked = $this->lockOpen($request);
            $lines = $locked->lines()->with('productVariant')->get();

            if ($lines->isEmpty()) {
                throw new InvalidCustomerQuotationRequestTransition('A quote request requires at least one line before conversion.');
            }

            $quotation = $this->quotationService->create(
                attributes: [
                    'customer_id' => $locked->customer_id,
                    'employee_id' => $actor->employeeProfile?->id,
                    'payment_term_id' => $paymentTermId,
                    'notes' => $locked->notes,
                    'issue_date' => now()->toDateString(),
                ],
                lines: array_values($lines->map(fn (CustomerQuotationRequestLine $line): array => [
                    'product_variant_id' => $line->product_variant_id,
                    'quantity' => (string) $line->requested_quantity,
                    'unit_id' => $line->requested_unit_id,
                    'description' => $line->customer_note,
                ])->all()),
            );

            $locked->forceFill([
                'status' => CustomerQuotationRequestStatus::Quoted,
                'resulting_quotation_id' => $quotation->getKey(),
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => $locked->reviewed_at ?? now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->log($locked, $actor, 'customer.quotation_request.converted');

            return $quotation;
        });
    }

    private function lockOpen(CustomerQuotationRequest $request): CustomerQuotationRequest
    {
        /** @var CustomerQuotationRequest $locked */
        $locked = CustomerQuotationRequest::query()->whereKey($request->getKey())->lockForUpdate()->sole();

        if (! $locked->isOpen()) {
            throw new InvalidCustomerQuotationRequestTransition(sprintf('This request is already %s.', $locked->status->value));
        }

        return $locked;
    }

    private function log(CustomerQuotationRequest $request, User $actor, string $logName): void
    {
        activity()
            ->performedOn($request)
            ->causedBy($actor)
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log($logName);
    }
}
