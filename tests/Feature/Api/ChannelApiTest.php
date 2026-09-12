<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\VisitStatus;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Models\VisitGpsLog;
use App\Services\Sales\PriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('issues and revokes a customer Sanctum token while refusing dashboard administrators', function (): void {
    $customerUser = User::factory()->customer()->create([
        'email' => 'customer-channel@example.test',
        'password' => 'password',
    ]);
    CustomerProfile::factory()->for($customerUser, 'user')->create();

    $response = $this->postJson('/api/v1/auth/token', [
        'identifier' => 'customer-channel@example.test',
        'password' => 'password',
        'device_name' => 'pest-customer',
    ])->assertOk()
        ->assertJsonPath('data.channel', 'customer')
        ->assertJsonPath('data.token_type', 'Bearer');

    $token = $response->json('data.token');
    expect($token)->toBeString()->not->toBeEmpty();

    $this->withToken($token)
        ->deleteJson('/api/v1/auth/token')
        ->assertOk()
        ->assertJsonPath('message', 'Token revoked.');

    // The Sanctum guard resolved on the request above caches its user for the
    // remainder of this test's shared application instance; force it to
    // re-resolve so this call actually re-checks the token against the
    // database instead of reusing that cached (and since-revoked) result.
    app('auth')->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/v1/customer/orders')
        ->assertUnauthorized();

    $admin = User::factory()->admin()->create([
        'email' => 'dashboard-admin@example.test',
        'password' => 'password',
    ]);

    $this->postJson('/api/v1/auth/token', [
        'identifier' => $admin->email,
        'password' => 'password',
        'device_name' => 'admin-device',
    ])->assertForbidden();

    $this->postJson('/api/v1/auth/token', [
        'identifier' => 'customer-channel@example.test',
        'password' => 'wrong-password',
        'device_name' => 'bad-device',
    ])->assertUnprocessable()->assertJsonValidationErrors('identifier');
});

it('uses canonical resolved pricing for the customer catalogue and quotation request', function (): void {
    $user = User::factory()->customer()->create();
    $customer = CustomerProfile::factory()->for($user, 'user')->create();
    $variant = ProductVariant::factory()->create([
        'base_price' => '125.50',
        'min_price' => '100.00',
        'status' => ProductStatus::Active,
        'is_active' => true,
    ]);
    $token = $user->createToken('customer-api', ['customer'])->plainTextToken;
    $resolved = app(PriceResolver::class)->resolve($variant, $user);

    $this->withToken($token)
        ->getJson('/api/v1/customer/catalog')
        ->assertOk()
        ->assertJsonPath('data.0.id', $variant->getKey())
        ->assertJsonPath('data.0.price', number_format($resolved->amount, 2, '.', ''))
        ->assertJsonPath('data.0.price_source', $resolved->source->value);

    $this->withToken($token)
        ->postJson('/api/v1/customer/quotations', [
            'notes' => 'Mobile quotation request',
            'lines' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 2,
            ]],
        ])->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.lines.0.product_variant_id', $variant->getKey())
        ->assertJsonPath('data.lines.0.unit_price', number_format($resolved->amount, 2, '.', ''));

    $quotation = Quotation::query()->sole();
    expect($quotation->customer_id)->toBe($customer->getKey())
        ->and((float) $quotation->lines()->firstOrFail()->unit_price)->toBe($resolved->amount);
});

it('never exposes another customer records or an employee channel to a customer token', function (): void {
    $user = User::factory()->customer()->create();
    $profile = CustomerProfile::factory()->for($user, 'user')->create();
    $other = CustomerProfile::factory()->create();
    $otherOrder = Order::factory()->for($other, 'customer')->create();
    $ownOrder = Order::factory()->for($profile, 'customer')->create();
    $token = $user->createToken('customer-api', ['customer'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/customer/orders/'.$ownOrder->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', $ownOrder->getKey());

    $this->withToken($token)
        ->getJson('/api/v1/customer/orders/'.$otherOrder->getKey())
        ->assertNotFound();

    $this->withToken($token)
        ->getJson('/api/v1/employee/tasks')
        ->assertForbidden();
});

it('scopes employee visits and records GPS evidence on check in and check out', function (): void {
    $user = User::factory()->employee()->create();
    $employee = EmployeeProfile::factory()->for($user, 'user')->create();
    $otherEmployee = EmployeeProfile::factory()->create();
    $visit = CustomerVisit::factory()->for($employee, 'employee')->create([
        'status' => VisitStatus::Planned,
    ]);
    $otherVisit = CustomerVisit::factory()->for($otherEmployee, 'employee')->create();
    $token = $user->createToken('employee-api', ['employee'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/employee/visits/'.$otherVisit->getKey().'/check-in', [
            'latitude' => 33.5138,
            'longitude' => 36.2765,
        ])->assertNotFound();

    $this->withToken($token)
        ->postJson('/api/v1/employee/visits/'.$visit->getKey().'/check-in', [
            'latitude' => 33.5138,
            'longitude' => 36.2765,
        ])->assertOk()
        ->assertJsonPath('data.status', VisitStatus::InProgress->value);

    $this->withToken($token)
        ->postJson('/api/v1/employee/visits/'.$visit->getKey().'/check-out', [
            'latitude' => 33.5140,
            'longitude' => 36.2768,
            'outcome' => 'Follow-up quotation requested',
        ])->assertOk()
        ->assertJsonPath('data.status', VisitStatus::Completed->value)
        ->assertJsonPath('data.outcome', 'Follow-up quotation requested');

    $visit->refresh();
    expect($visit->status)->toBe(VisitStatus::Completed)
        ->and(VisitGpsLog::query()->where('customer_visit_id', $visit->getKey())->count())->toBe(2);
});
