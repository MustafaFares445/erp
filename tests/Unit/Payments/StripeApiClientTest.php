<?php

declare(strict_types=1);

use App\Services\Payments\Providers\StripeApiClient;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

final class StripeCoverageHttpClient implements ClientInterface
{
    /** @var list<array{method:string,url:string,headers:array,params:array}> */
    public array $requests = [];

    #[Override]
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = [
            'method' => (string) $method,
            'url' => (string) $absUrl,
            'headers' => $headers,
            'params' => $params,
        ];

        if (str_contains((string) $absUrl, '/v1/checkout/sessions')) {
            return [json_encode([
                'id' => 'cs_test_coverage',
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/session',
                'payment_intent' => 'pi_coverage',
                'status' => 'open',
            ], JSON_THROW_ON_ERROR), 200, []];
        }

        if (str_contains((string) $absUrl, '/v1/payment_intents/')) {
            return [json_encode([
                'id' => 'pi_coverage',
                'object' => 'payment_intent',
                'status' => 'requires_payment_method',
                'amount' => 12345,
                'currency' => 'aed',
                'latest_charge' => 'ch_coverage',
                'last_payment_error' => [
                    'code' => 'card_declined',
                    'message' => 'Coverage decline',
                ],
            ], JSON_THROW_ON_ERROR), 200, []];
        }

        if (str_contains((string) $absUrl, '/v1/refunds')) {
            return [json_encode([
                'id' => isset($params['amount']) ? 're_partial' : 're_full',
                'object' => 'refund',
                'status' => 'succeeded',
                'amount' => $params['amount'] ?? 12345,
            ], JSON_THROW_ON_ERROR), 200, []];
        }

        throw new RuntimeException('Unexpected Stripe coverage URL: '.$absUrl);
    }
}

afterEach(function (): void {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

it('maps checkout session requests and response data through the Stripe SDK adapter', function (): void {
    $http = new StripeCoverageHttpClient;
    ApiRequestor::setHttpClient($http);

    $adapter = new StripeApiClient(new StripeClient('sk_test_coverage'));

    $session = $adapter->createCheckoutSession([
        'currency' => 'AED',
        'amount_minor' => 12345,
        'customer_reference' => 'CUST-100',
        'success_url' => 'https://example.test/success',
        'cancel_url' => 'https://example.test/cancel',
        'metadata' => ['source' => 'coverage'],
        'idempotency_key' => 'idem-coverage',
    ]);

    expect($session->id)->toBe('cs_test_coverage')
        ->and($session->url)->toBe('https://checkout.stripe.test/session')
        ->and($session->paymentIntentId)->toBe('pi_coverage')
        ->and($session->status)->toBe('open')
        ->and($http->requests)->toHaveCount(1)
        ->and($http->requests[0]['params']['line_items'][0]['price_data']['currency'])->toBe('aed')
        ->and($http->requests[0]['params']['line_items'][0]['price_data']['unit_amount'])->toBe(12345)
        ->and($http->requests[0]['params']['client_reference_id'])->toBe('CUST-100')
        ->and($http->requests[0]['params']['metadata'])->toBe(['source' => 'coverage'])
        ->and(collect($http->requests[0]['headers'])->contains(
            static fn (string $header): bool => str_contains($header, 'Idempotency-Key: idem-coverage'),
        ))->toBeTrue();
});

it('normalizes payment intent data from Stripe', function (): void {
    $http = new StripeCoverageHttpClient;
    ApiRequestor::setHttpClient($http);

    $intent = new StripeApiClient(new StripeClient('sk_test_coverage'))
        ->retrievePaymentIntent('pi_coverage');

    expect($intent->id)->toBe('pi_coverage')
        ->and($intent->status)->toBe('requires_payment_method')
        ->and($intent->amountMinor)->toBe(12345)
        ->and($intent->currency)->toBe('AED')
        ->and($intent->latestChargeId)->toBe('ch_coverage')
        ->and($intent->failureCode)->toBe('card_declined')
        ->and($intent->failureMessage)->toBe('Coverage decline');
});

it('creates both partial and full refunds through Stripe', function (): void {
    $http = new StripeCoverageHttpClient;
    ApiRequestor::setHttpClient($http);

    $adapter = new StripeApiClient(new StripeClient('sk_test_coverage'));

    $partial = $adapter->createRefund('pi_coverage', 5000);
    $full = $adapter->createRefund('pi_coverage');

    expect($partial->id)->toBe('re_partial')
        ->and($partial->status)->toBe('succeeded')
        ->and($partial->amountMinor)->toBe(5000)
        ->and($full->id)->toBe('re_full')
        ->and($full->amountMinor)->toBe(12345)
        ->and($http->requests)->toHaveCount(2)
        ->and($http->requests[0]['params'])->toBe([
            'payment_intent' => 'pi_coverage',
            'amount' => 5000,
        ])
        ->and($http->requests[1]['params'])->toBe([
            'payment_intent' => 'pi_coverage',
        ]);
});
