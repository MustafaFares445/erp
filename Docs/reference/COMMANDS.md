# Command Reference

---
status: canonical
owner: engineering
last_verified: 2026-10-04
verified_against: composer.json, artisan command list and routes/console.php
---

## Composer

```bash
composer run setup
composer run dev
composer test:fast
composer test:lint
composer test:types
composer test:type-coverage
composer test:coverage
composer test
composer lint
```

See [Testing & Quality](../onboarding/TESTING_AND_QUALITY.md).

## Laravel

Common:

```bash
php artisan migrate
php artisan migrate:fresh --seed
php artisan route:list
php artisan schedule:list
php artisan queue:work
php artisan config:clear
```

## Demo Month Dataset (local only)

`database/seeders/Demo/` builds one deterministic month of company activity (2026-09-04 to 2026-10-03) through the real domain services. It is not part of `DatabaseSeeder`, refuses to run in production, and skips stages it has already seeded.

```bash
php artisan db:seed --class='Database\Seeders\Demo\DemoMonthSeeder'
php artisan db:seed --class='Database\Seeders\Demo\DemoVerificationSeeder'
```

Run it on a freshly migrated database (`migrate:fresh`): the legacy `PO-DEMO` rows from `PurchasingDemoSeeder` break purchase-order numbering, and the legacy demo journals prevent the receivables control account from reconciling. The month seeder now also runs `DemoSupportOperationsSeeder`, which adds realistic Support service levels/entitlement, three field-service visits and closed-ticket feedback on top of the existing tickets, maintenance, warranty, parts and billing history. It also runs `DemoInstallationSeeder`, which receives a dental sintering furnace, sells and delivers it to a customer, then installs, commissions and gets it accepted (with a commissioning-trigger warranty) through the same services as the UI. It then runs `DemoCalibrationSeeder`, which passes a furnace temperature calibration with a certificate and a six-monthly calibration schedule, and fails a handpiece speed calibration with a follow-up repair request. It then runs `DemoContinuitySeeder`, which gives the customer of the failed handpiece calibration an issued loaner handpiece (through Inventory) and approves a supplier repair; the seeder enables the loaner and supplier-repair flags only while it runs, so set `SUPPORT_LOANER_EQUIPMENT_ENABLED` and `SUPPORT_EXTERNAL_REPAIR_ENABLED` in `.env` to see them. It deliberately does not seed optional Teams/Queues/Automation/Knowledge fixtures. The dashboards read the real clock, so select the custom range 2026-09-04 to 2026-10-03 once the date has moved past 2026-10-03. Demo staff log in as `demo.*@ierp.test` (password `password`).

## Current Domain Commands

```bash
php artisan crm:campaigns:dispatch-due
php artisan inventory:alerts:reconcile
php artisan inventory:lots:reconcile
php artisan inventory:reservations:expire
php artisan maintenance:schedules:generate
php artisan notifications:expiring-lots
php artisan notifications:overdue-invoices
php artisan notifications:pending-approvals
php artisan notifications:retry-failed
php artisan notifications:visits-due
php artisan sales:quotations:expire
php artisan support:sla:reconcile
php artisan support:automation:stale
php artisan support:loaners:notify-overdue
php artisan support:warranties:backfill
```

Use `php artisan <command> --help` for current options.

## Scheduler

`routes/console.php` currently schedules:

- Inventory alert reconciliation daily.
- Incremental lot reconciliation daily at 01:30.
- Full lot reconciliation weekly Sunday at 02:30.
- Reservation expiry hourly.
- Quotation expiry daily.
- Support SLA reconciliation every five minutes.
- Stale waiting-customer Support automation hourly without overlap; it exits cleanly while Support automation is disabled.
- Due CRM campaign dispatch every minute without overlap.
- Overdue invoice, expiring-lot, pending-approval and visit-due notifications daily.
- Failed notification retry hourly.
- Preventive maintenance schedule generation daily.

Production infrastructure must invoke Laravel's scheduler externally (normally once per minute). The repository schedules jobs but does not itself create the host cron/systemd timer.
