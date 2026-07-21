# Monitoring and Metrics

## 1. Monitoring Overview

Monitoring should start simple: Laravel logs, failed job tracking, API error tracking, payment webhook logs, business metrics, and manual review dashboards.

## 2. Logs

Log:

- Authentication failures.
- Validation spikes.
- Payment webhook events.
- Failed jobs.
- AI transcription failures.
- Inventory movement failures.
- Accounting posting failures.

## 3. Error Tracking

Use Laravel logs by default. Optional: Sentry/Bugsnag for production error tracking.

## 4. Performance Metrics

- API response time.
- Queue processing time.
- PDF generation time.
- Report generation time.
- Stripe webhook processing time.
- AI transcription duration.

## 5. Business Metrics

- Quotations created/accepted/rejected.
- Delivery notes confirmed.
- Invoices issued/paid/overdue.
- Payments collected.
- Tax recognized.
- Tickets opened/closed.
- Maintenance records by status.
- Employee plan completion.
- Sales drafts detected.

## 6. Financial Monitoring

- Unbalanced journal entries must trigger alert.
- Failed tax recognition job must trigger alert.
- Payment without invoice allocation must be reviewed.
- Stripe payment succeeded but local posting failed must trigger alert.

## 7. Inventory Monitoring

- Negative available stock attempts.
- Failed stock movement jobs.
- Low stock below reorder level.
- Transfer mismatches.

## 8. Queue Monitoring

- Failed jobs count.
- Queue length.
- Long-running jobs.
- Repeated AI failures.

## 9. Payment Monitoring

- Stripe webhook failures.
- Duplicate webhook events.
- Manual payment records missing proof when required.
- Invoice paid amount mismatch.

## 10. AI Processing Monitoring

- Transcription failure rate.
- Average transcription processing time.
- Sales drafts detected per employee.
- Admin review backlog.

## 11. Alerts

Start with email or dashboard alerts for failed jobs, failed webhooks, accounting posting failures, and tax recognition failures.

## 12. Optional Tools

- Sentry or Bugsnag for errors.
- Laravel Horizon if Redis queues are used.
- Grafana/Prometheus only if infrastructure maturity requires it.

## 13. Open Questions

- Which monitoring provider will be used in production?
- Who receives critical payment/accounting alerts?
