<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Services\Payments\Providers\FakeStripeClient;
use App\Services\Payments\Providers\StripeClientInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Shared scene clock, actor registry and fixture lookups for the one-month demo dataset.
 *
 * Every service stamps documents from now(), so each scene pins the clock with
 * {@see self::at()} and signs in the acting user with {@see self::as()}. The clock is
 * released by {@see self::finish()} so dashboards evaluate against the real date.
 */
final class DemoContext
{
    public const string PeriodStart = '2026-09-04 08:00:00';

    public const string PeriodEnd = '2026-10-03 18:00:00';

    public const string Password = 'password';

    /** @var array<string, string> actor key => login email */
    public const array Actors = [
        'admin' => 'demo.admin@ierp.test',
        'operations' => 'demo.operations@ierp.test',
        'sales_manager' => 'demo.sales.manager@ierp.test',
        'billing' => 'demo.billing@ierp.test',
        'chief_accountant' => 'demo.chief.accountant@ierp.test',
        'accountant' => 'demo.accountant@ierp.test',
        'purchasing_manager' => 'demo.purchasing.manager@ierp.test',
        'purchasing_officer' => 'demo.purchasing.officer@ierp.test',
        'crm_manager' => 'demo.crm.manager@ierp.test',
        'employee_manager' => 'demo.employee.manager@ierp.test',
        'payroll' => 'demo.payroll@ierp.test',
        'support_manager' => 'demo.support.manager@ierp.test',
    ];

    /** @var array<string, User> */
    private array $resolved = [];

    public static function make(): self
    {
        return app()->bound(self::class) ? app(self::class) : tap(new self, fn (self $c) => app()->instance(self::class, $c));
    }

    /** Pin the application clock to a scene moment (application timezone). */
    public function at(string $when): Carbon
    {
        $moment = Carbon::parse($when, config()->string('app.timezone'));
        Carbon::setTestNow($moment);

        return $moment;
    }

    /** Sign in the keyed demo actor and return it (blameable columns and audit causers follow). */
    public function as(string $key): User
    {
        $user = $this->actor($key);
        Auth::setUser($user);

        return $user;
    }

    public function actor(string $key): User
    {
        if (! isset($this->resolved[$key])) {
            $email = self::Actors[$key] ?? throw new LogicException("Unknown demo actor [{$key}].");
            $this->resolved[$key] = User::query()->where('email', $email)->firstOrFail();
        }

        return $this->resolved[$key];
    }

    /** The in-memory Stripe client, or null when a live provider is bound in this environment. */
    public static function fakeStripe(): ?FakeStripeClient
    {
        $client = app()->get(StripeClientInterface::class);

        return $client instanceof FakeStripeClient ? $client : null;
    }

    /** Narrow a loosely typed database value (pluck/value results) to an int id. */
    public static function intOf(mixed $value): int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && is_numeric($value) => (int) $value,
            default => throw new LogicException('Expected an integer value from the database.'),
        };
    }

    /** Narrow a loosely typed database aggregate (sum/value results) to a float; NULL aggregates read as zero. */
    public static function floatOf(mixed $value): float
    {
        return match (true) {
            is_float($value) => $value,
            is_int($value) => (float) $value,
            $value === null => 0.0,
            is_string($value) && is_numeric($value) => (float) $value,
            default => throw new LogicException('Expected a numeric value from the database.'),
        };
    }

    /** Integer primary key of a persisted demo record (every demo table uses auto-increment keys). */
    public static function keyOf(Model $model): int
    {
        $key = $model->getKey();

        if (! is_int($key) && (! is_string($key) || ! is_numeric($key))) {
            throw new LogicException(sprintf('Demo record [%s] has no integer key.', $model::class));
        }

        return (int) $key;
    }

    public function finish(): void
    {
        Carbon::setTestNow();
        Auth::logout();
    }
}
