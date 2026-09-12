# WP-4.3 — Canonical retained document exports

WP-4.3 consolidates Inventory, Employee Reports, and Sales document exports onto one retained asynchronous workflow.

## Canonical workflow

All new export requests create `App\Models\DocumentExport` and dispatch `App\Jobs\GenerateDocumentExport`.

A retained export records:

- module and export type;
- output format;
- requester (`created_by`);
- normalized parameters needed to reproduce the selection;
- generated row count;
- status and failure reason;
- private file path;
- completion time;
- expiry time.

`App\Services\Exports\DocumentExportService` owns the common lifecycle: request, queue dispatch, generation state, requester authorization, download, expiry, and cleanup. Module services own only module-specific authorization and file contents.

## Module adapters

### Inventory

`InventoryExportService` now creates `DocumentExport` records and retains normalized report filters in `parameters.filters`. XLSX generation and report formatting remain in the Inventory module. Existing `inventory_exports` records are still readable/generatable through an explicit compatibility path, but no new request dispatches `GenerateInventoryExport`.

### Employee reports

`EmployeeReportExportService` now creates `DocumentExport` records and retains normalized report filters in `parameters.filters`. XLSX headings and row mapping remain in the Employees module. The old `employee_report_exports` table/model remains only for rollback compatibility.

### Sales

`ExportsSalesDocuments` no longer streams CSV synchronously. It snapshots the IDs returned by the current filtered Filament table query and stores them with the filter/search context. `SalesDocumentExportService` generates the CSV from that retained ID set in the queue, preserving the exact selected population even if the UI filters later change.

## Download ownership and retention

The Document Exports Filament resource is requester-scoped. A user can only list or download records they created, and module permission checks are repeated at download time. Export files expire seven days after request and `exports:cleanup` runs daily at 03:00 to delete expired private files while retaining the audit record as `expired`.

## Rollback posture

WP-4.3 intentionally does **not** drop `inventory_exports` or `employee_report_exports`. Their active request paths have been removed from the UI/services, but the existing models/tables remain non-destructive rollback evidence until a later cleanup release explicitly removes them.
