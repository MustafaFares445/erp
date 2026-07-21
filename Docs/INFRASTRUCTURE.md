# Infrastructure Documentation

## 1. Infrastructure Overview

IERP should start with a practical Laravel deployment: one application server, one database, one queue worker, private file storage, scheduler, HTTPS, mail provider, Stripe webhook endpoint, and optional AI provider.

## 2. Required Services

| Service | Required | Purpose |
|---|---|---|
| Laravel API server | Yes | Backend APIs |
| Relational database | Yes | ERP data |
| Queue worker | Yes | Async jobs |
| Private file storage | Yes | PDFs, attachments, voice notes |
| Mail provider | Yes | Invoices and reminders |
| Stripe | Yes | Online payments |
| AI transcription provider | Required module, provider TBD | Voice notes |

## 3. Server Requirements

- PHP version compatible with selected Laravel version.
- Composer.
- Web server such as Nginx or Apache.
- Supervisor/systemd process for queue workers.
- Cron for Laravel scheduler.
- HTTPS certificate.

## 4. Database Requirements

- MySQL or PostgreSQL.
- Daily backups.
- Transaction support.
- Foreign keys enabled.
- Proper indexes for reports and search.

## 5. Storage Requirements

- Private storage for invoice PDFs, payment proofs, ticket files, visit attachments, and audio files.
- Backup storage.
- Signed download URLs or authorized download routes.

## 6. Queue Requirements

Queues required for:

- PDF generation.
- Email sending.
- Stripe webhook processing.
- Tax recognition.
- Journal posting.
- AI transcription.
- Notifications.
- Exports.

## 7. Cache Requirements

Use simple cache storage for settings and lookup data. Redis is optional, not mandatory for first deployment.

## 8. Mail Requirements

Transactional email provider must be configured for invoice PDFs, reminders, and support notifications.

## 9. Stripe Webhook Requirements

- Public HTTPS endpoint.
- Signature verification.
- Idempotency handling.
- Logging and alerting for failed processing.

## 10. AI Processing Requirements

- Private access to uploaded voice notes.
- Retryable transcription jobs.
- Failure states visible to admin.
- AI failure must not block visit completion.

## 11. Deployment Flow

1. Pull release code.
2. Install dependencies.
3. Configure environment.
4. Run migrations.
5. Run seeders for required defaults.
6. Clear/cache config.
7. Restart PHP/web server.
8. Restart queue workers.
9. Run smoke tests.

## 12. Backup Strategy

- Daily database backups.
- Regular file storage backups.
- Retain backups according to business policy.
- Test restore process before production launch.

## 13. Security Notes

- HTTPS only.
- Private files only.
- No secrets in code.
- Validate webhooks.
- Restrict dashboard APIs to admin users.
- Audit sensitive actions.

## 14. Open Questions

- Final hosting provider.
- Storage location: local private disk or object storage.
- Backup retention period.
