# Command Reference

---
status: canonical
owner: engineering
last_verified: 2026-10-02
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
- Due CRM campaign dispatch every minute without overlap.
- Overdue invoice, expiring-lot, pending-approval and visit-due notifications daily.
- Failed notification retry hourly.
- Preventive maintenance schedule generation daily.

Production infrastructure must invoke Laravel's scheduler externally (normally once per minute). The repository schedules jobs but does not itself create the host cron/systemd timer.
