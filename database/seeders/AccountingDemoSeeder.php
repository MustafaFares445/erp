<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DashboardRole;
use App\Enums\UserType;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * Gives the accounting module a walkable ledger: twelve monthly periods for the
 * current year, several posted entries, one reversal, and one draft awaiting
 * review — so every state the Journal Entries and Chart of Accounts screens can
 * show has a record behind it, exactly like {@see SupportDemoSeeder}.
 *
 * Every write goes through {@see FiscalPeriodService} and
 * {@see JournalPostingService}, the same services Filament's own actions call, so
 * entry numbers, resolved periods, reversal morphs, and audit rows are all
 * internally consistent. Nothing here bypasses a domain rule to fabricate a state
 * the services would refuse.
 *
 * Idempotent: it returns immediately once its flagship entry exists, because the
 * records cross-reference each other (a reversal points at the entry it reverses)
 * and re-running only part of it would leave the ledger half-built.
 */
final class AccountingDemoSeeder extends Seeder
{
    /**
     * Marks the seeded set. Its presence means the whole set is present.
     */
    private const string FLAGSHIP_DESCRIPTION = 'Owner capital injection';

    public function run(): void
    {
        $this->call([AccountingPermissionSeeder::class, ChartOfAccountsSeeder::class]);

        if (JournalEntry::query()->where('description', self::FLAGSHIP_DESCRIPTION)->exists()) {
            return;
        }

        $chief = $this->dashboardUser('chief.accountant@ierp.com', 'Nadia Haddad', DashboardRole::ChiefAccountant);
        $accountant = $this->dashboardUser('accountant@ierp.com', 'Omar Sabbagh', DashboardRole::Accountant);

        $periods = $this->seedMonthlyPeriods($chief);

        $this->seedOpeningCapital($chief);
        $this->seedTradingEntries($accountant);
        $this->seedMispostingAndCorrection($chief, $accountant);
        $this->seedPendingDraft($accountant);

        // The oldest period is closed, so the Reopen action has a subject and a
        // backdated posting is genuinely refused (FR-016).
        app(FiscalPeriodService::class)->close($chief, $periods[0]);
    }

    private function seedOpeningCapital(User $chief): void
    {
        app(JournalPostingService::class)->postNew($chief, CarbonImmutable::now()->startOfYear()->addDays(2), [
            $this->debit('1110', '150000.00', 'Bank transfer received'),
            $this->credit('3100', '150000.00', 'Share capital issued'),
        ], self::FLAGSHIP_DESCRIPTION);
    }

    private function seedTradingEntries(User $accountant): void
    {
        $posting = app(JournalPostingService::class);
        $thisMonth = CarbonImmutable::now()->startOfMonth();

        $posting->postNew($accountant, $thisMonth->addDays(4), [
            $this->debit('1200', '18400.00', 'Invoice to Bright Orthodontics'),
            $this->credit('4100', '18400.00', 'Chair and scanner package'),
        ], 'Product sales invoiced');

        $posting->postNew($accountant, $thisMonth->addDays(9), [
            $this->debit('5200', '22750.00', 'Monthly payroll run'),
            $this->credit('1110', '22750.00', 'Paid from operating account'),
        ], 'Payroll for the month');
    }

    /**
     * A mistake, its reversal, and the corrected entry — so the Reverse action has
     * a subject and the reversed/reversal pair nets to zero (FR-028, SC-006).
     *
     * Reversed by the chief accountant rather than the accountant who posted it,
     * because the Accountant role deliberately lacks the reverse permission
     * (FR-040).
     */
    private function seedMispostingAndCorrection(User $chief, User $accountant): void
    {
        $posting = app(JournalPostingService::class);
        $thisMonth = CarbonImmutable::now()->startOfMonth();

        $misposted = $posting->postNew($accountant, $thisMonth->addDays(11), [
            $this->debit('5300', '9000.00', 'Booked to Rent by mistake'),
            $this->credit('1110', '9000.00', 'Paid from operating account'),
        ], 'Warehouse rent — misposted');

        $posting->reverse($chief, $misposted, $thisMonth->addDays(12));

        $posting->postNew($accountant, $thisMonth->addDays(12), [
            $this->debit('5400', '9000.00', 'Correctly booked to Utilities'),
            $this->credit('1110', '9000.00', 'Paid from operating account'),
        ], 'Utilities — corrected posting');
    }

    /**
     * Left unposted on purpose, so the Post action has a subject and the Draft
     * status has a row.
     */
    private function seedPendingDraft(User $accountant): void
    {
        app(JournalPostingService::class)->draft($accountant, CarbonImmutable::now(), [
            $this->debit('1300', '5600.00', 'Consumables received, awaiting invoice'),
            $this->credit('2100', '5600.00', 'Supplier accrual'),
        ], 'Stock accrual awaiting review');
    }

    /**
     * Twelve consecutive monthly periods for the current calendar year.
     *
     * Created through the service so the no-overlap rule (FR-015) is exercised
     * rather than assumed, and skipped individually when one already exists — a
     * period may have been created by another seeder or by hand.
     *
     * @return list<FiscalPeriod>
     */
    private function seedMonthlyPeriods(User $chief): array
    {
        $service = app(FiscalPeriodService::class);
        $month = CarbonImmutable::now()->startOfYear();
        $periods = [];

        for ($index = 0; $index < 12; $index++) {
            $start = $month->addMonthsNoOverflow($index);
            $existing = FiscalPeriod::query()->where('name', $start->format('F Y'))->first();

            $periods[] = $existing instanceof FiscalPeriod
                ? $existing
                : $service->create($chief, $start->format('F Y'), $start, $start->endOfMonth());
        }

        return $periods;
    }

    /**
     * @return array{chart_account_id: int, debit: string, credit: string, description: string}
     */
    private function debit(string $code, string $amount, string $description): array
    {
        return [
            'chart_account_id' => $this->accountId($code),
            'debit' => $amount,
            'credit' => '0.00',
            'description' => $description,
        ];
    }

    /**
     * @return array{chart_account_id: int, debit: string, credit: string, description: string}
     */
    private function credit(string $code, string $amount, string $description): array
    {
        return [
            'chart_account_id' => $this->accountId($code),
            'debit' => '0.00',
            'credit' => $amount,
            'description' => $description,
        ];
    }

    /**
     * Resolves a seeded account by its code.
     *
     * Throws rather than creating one: every code used above is a postable leaf
     * from {@see ChartOfAccountsSeeder}, so a miss means that chart changed and
     * the demo entries need revisiting — not that an account should be invented.
     */
    private function accountId(string $code): int
    {
        $id = ChartAccount::query()->where('code', $code)->value('id');

        if (! is_numeric($id)) {
            throw new LogicException(sprintf('The demo ledger expects a seeded account with code [%s].', $code));
        }

        return (int) $id;
    }

    private function dashboardUser(string $email, string $name, DashboardRole $role): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password'), 'user_type' => UserType::Admin],
        );

        if (! $user->hasRole($role->value)) {
            $user->assignRole($role->value);
        }

        return $user;
    }
}
