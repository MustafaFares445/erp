<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Enums\PaymentLinkStatus;
use App\Http\Requests\Api\Customer\CreateSupportPaymentSessionRequest;
use App\Models\CustomerProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Payments\StripeCheckoutService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CustomerSupportPaymentController
{
    public function createSession(
        CreateSupportPaymentSessionRequest $request,
        Ticket $ticket,
        StripeCheckoutService $checkout,
    ): JsonResponse {
        $customer = $this->assertOwned($request, $ticket);
        $link = $ticket->paymentLink;

        if ($link === null || $link->status !== PaymentLinkStatus::Pending) {
            return response()->json(['message' => 'This support ticket has no pending diagnostic payment.'], 422);
        }

        try {
            $transaction = $checkout->createForTicket(
                $customer,
                $link,
                $request->string('success_url')->toString(),
                $request->string('cancel_url')->toString(),
            );
        } catch (DomainException $domainException) {
            return response()->json(['message' => $domainException->getMessage()], 422);
        }

        return response()->json([
            'transaction_id' => $transaction->getKey(),
            'provider' => $transaction->provider->value,
            'provider_status' => $transaction->status->value,
            'checkout_url' => $transaction->metadata['checkout_url'] ?? null,
            'amount_minor' => $transaction->amount_minor,
            'currency' => $transaction->currency,
        ], Response::HTTP_CREATED);
    }

    public function status(Request $request, Ticket $ticket): JsonResponse
    {
        $this->assertOwned($request, $ticket);
        $link = $ticket->paymentLink()->with('providerTransaction')->first();
        $provider = $link?->providerTransaction;

        return response()->json([
            'required' => $link !== null,
            'status' => $link?->status->value,
            'amount' => $link === null ? null : (float) $link->amount,
            'currency' => $link?->currency,
            'checkout_url' => $link?->payment_url,
            'provider' => $provider === null ? null : [
                'status' => $provider->status->value,
                'settlement_state' => $provider->settlementState()->value,
            ],
        ]);
    }

    private function assertOwned(Request $request, Ticket $ticket): CustomerProfile
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->customerProfile instanceof CustomerProfile, 403);

        $customer = $user->customerProfile;

        abort_unless($ticket->customer_id === $customer->getKey(), 404);

        return $customer;
    }
}
