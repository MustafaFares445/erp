<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
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
        $moment = Carbon::parse($when, (string) config('app.timezone'));
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

    public function finish(): void
    {
        Carbon::setTestNow();
        Auth::logout();
    }
}
