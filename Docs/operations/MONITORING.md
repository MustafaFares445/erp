# Monitoring

---
status: canonical
owner: operations
last_verified: 2026-10-02
verified_against: current Laravel logging/queue/scheduler/domain alert mechanisms
---

## What Exists in the Repository

The application has:
- standard Laravel logging configuration;
- failed-job infrastructure through Laravel queues;
- domain audit history through Spatie Activitylog;
- Inventory alert records/reconciliation;
- notification delivery status/retry behavior;
- domain reports/dashboards;
- scheduled reconciliation/reminder commands.

No specific hosted APM/error-monitoring vendor is established as canonical by the repository.

## Operational Signals to Watch

### Application
- exceptions/error log volume;
- authentication/authorization failures where security review is needed;
- queue failures/retry growth;
- scheduler not running;
- mail/notification delivery failures.

### Inventory
- reconciliation failures/divergence;
- low/expired stock alerts;
- transfer discrepancy;
- reservation expiry/backlog;
- canonical lot/condition/serialized-custody reconciliation.

### Accounting / Payments
- failed journal posting;
- period-close checklist failures;
- AR/AP reconciliation differences;
- failed provider reconciliation/refund settlement;
- notification/reminder failures around overdue invoices.

### Employees / AI
- transcription failed state;
- repeated provider failure;
- queued processing backlog when asynchronous;
- AI failure must remain isolated from manual visit completion.

### Support
- SLA breach flags;
- failed support payment settlement;
- overdue preventive maintenance occurrences.

## Scheduler Health

Because many reconciliation/reminder actions are scheduled, monitor that `schedule:run` is actually executing.

A healthy web process with a dead scheduler is not a healthy IERP deployment.

## Queue Health

The default application queue is database-backed unless changed by environment.

Monitor:
- failed jobs;
- worker availability;
- growing pending-job count;
- jobs repeatedly reaching retry limits.

## Alerts / APM

Sentry, Bugsnag, Horizon, Prometheus/Grafana or other monitoring tools are optional choices unless explicitly configured later.

Do not present an unconfigured provider as current infrastructure.
