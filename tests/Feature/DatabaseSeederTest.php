<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\CrmPermission;
use App\Enums\EmployeePermission;
use App\Enums\InventoryPermission;
use App\Enums\PurchasePermission;
use App\Enums\SalesPermission;
use App\Enums\SupportPermission;
use App\Enums\SystemPermission;
use App\Models\CustomerProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\UnifiedDevelopmentSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('seeds configuration and permissions without demo accounts or transactions in testing', function (): void {
    $this->seed(DatabaseSeeder::class);

    $permissions = [
        ...InventoryPermission::values(),
        ...CrmPermission::values(),
        ...EmployeePermission::values(),
        ...SupportPermission::values(),
        ...AccountingPermission::values(),
        ...PurchasePermission::values(),
        ...SalesPermission::values(),
        ...SystemPermission::values(),
    ];

    expect(Permission::query()->where('guard_name', 'web')->pluck('name')->all())
        ->toEqualCanonicalizing($permissions)
        ->and(User::query()->count())->toBe(0)
        ->and(CustomerProfile::query()->count())->toBe(0)
        ->and(DB::table('journal_entries')->count())->toBe(0)
        ->and(DB::table('currencies')->count())->toBeGreaterThan(0);
});

it('calls the full unified demo from the default seeder in local development', function (): void {
    $this->app['env'] = 'local';
    $this->app->bind(UnifiedDevelopmentSeeder::class, static fn (): Seeder => new class extends Seeder
    {
        public function run(): void
        {
            app()->instance('test.unified-seeder-invoked', true);
        }
    });

    $this->seed(DatabaseSeeder::class);

    expect(app()->bound('test.unified-seeder-invoked'))->toBeTrue()
        ->and(app('test.unified-seeder-invoked'))->toBeTrue()
        ->and(DB::table('currencies')->count())->toBeGreaterThan(0);
});

it('rejects remote connections before writing even reference data in local mode', function (): void {
    $this->app['env'] = 'local';
    $originalConnection = DB::getDefaultConnection();
    $existingCurrencies = DB::table('currencies')->count();
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.host' => 'external-db.example.test',
    ]);

    try {
        expect(fn () => app(DatabaseSeeder::class)->setContainer(app())->run())
            ->toThrow(RuntimeException::class, 'outside a local database');
    } finally {
        config(['database.default' => $originalConnection]);
    }

    expect(DB::table('currencies')->count())->toBe($existingCurrencies);
});

it('never invokes unified demo data when the default seeder runs in production', function (): void {
    $this->app['env'] = 'production';
    $this->app->bind(UnifiedDevelopmentSeeder::class, static function (): never {
        throw new RuntimeException('Unexpected demo seeder in production.');
    });

    // Invoke the seeder directly: Laravel's "db:seed" CLI asks for confirmation
    // in production before it reaches the code being tested.
    app(DatabaseSeeder::class)->setContainer(app())->run();

    expect(User::query()->count())->toBe(0)
        ->and(CustomerProfile::query()->count())->toBe(0);
});
