# Quickstart Validation: CRM Customers and Product Subscriptions

This guide validates the implemented feature end to end. It does not contain
implementation code.

## Preconditions

- CRM Filament ADR and canonical documentation updates are approved.
- Migrations and seeders have been applied in the test environment.
- Existing customer/pricing tests are green before subscription work.
- A customer user/profile, product with active variants, base/minimum prices,
  and dashboard users for each fixed role are available through factories.

## Baseline Guard

Run the existing customer and pricing tests before and after implementation:

```powershell
php artisan test --compact tests/Feature/CustomerProfileResourceTest.php tests/Feature/PricingServiceTest.php tests/Feature/ProductPricingServiceTest.php tests/Feature/Filament/PricingControlsResourceTest.php
```

Expected:

- Existing customer routes and actions still behave as before for equivalent
  permissions.
- Existing tier precedence and base-price fallback remain unchanged when no
  eligible subscription exists.
- Existing price history and floor-override screens remain available.

## Scenario 1: Customer Lifecycle

1. Create a customer profile linked to a customer-channel user.
2. Verify unique user and customer-code enforcement.
3. Search by code, company, name, and email.
4. Deactivate the customer and confirm it cannot receive a new assignment.
5. Soft-delete and restore as System Admin.
6. Confirm audit entries for create, update, deactivate, delete, and restore.

Expected:

- No second customer identity is created.
- History remains after deactivation/deletion.
- Restore is System Admin only.

## Scenario 2: Subscription Definition

1. Create a 10% public subscription.
2. Create a fixed-amount restricted subscription.
3. Verify invalid percentages, zero fixed values, and inverted date ranges fail.
4. Attempt activation with no product and confirm rejection.
5. Link a product.
6. Attempt restricted activation without an active assigned customer and confirm
   rejection.
7. Assign an active customer and activate.

Expected:

- All successful mutations have audit entries.
- Public classification does not grant entitlement without assignment.

## Scenario 3: Product and Customer Links

1. Attach two products and two active customers.
2. Attempt each duplicate link.
3. Attempt to assign an inactive and a soft-deleted customer.
4. Detach one customer and verify the other remains entitled.
5. Deactivate an assigned customer without detaching it.

Expected:

- Database and UI both prevent duplicates.
- Inactive customer assignment remains visible but is ineligible.
- Relationship mutations are atomic and audited.

## Scenario 4: Pricing Precedence

For a variant with base price 120 and a safe minimum price:

1. Resolve with no customer: expect base 120.
2. Add a 5% general tier: expect 114.
3. Add an eligible 10% subscription: expect 108.
4. Add an eligible fixed 15 subscription: expect the 10% subscription at 108.
5. Change fixed discount to 12: both resolve to 108; expect earliest
   subscription ID.
6. Add an 8% customer-specific tier: expect 110.40 from the specific tier,
   because precedence wins over the numerically lower subscription price.
7. Deactivate or expire the subscription and remove the specific tier: expect
   the general tier again.

Expected:

- Exactly one source wins.
- No discount stacking occurs.
- The result exposes all pricing provenance fields.

## Scenario 5: Floor Approval

1. Configure a subscription candidate below the variant minimum price.
2. Preview as CRM Manager and Pricing Manager.
3. Attempt use without approval.
4. Attempt approval as CRM Manager.
5. Approve as System Admin with a reason.

Expected:

- Preview displays the floor warning.
- Unapproved use is blocked.
- Non-admin approval is denied.
- The approval row includes subscription provenance and the existing required
  fields.

## Scenario 6: Role and Bulk Authorization

Exercise the matrix in `contracts/dashboard-ui.md` for:

- Direct page access
- Record actions
- Relationship attach/detach
- Bulk delete/restore/detach
- Report and audit access
- Role assignment
- Floor approval

Expected:

- Every allowed path succeeds.
- Every prohibited path is forbidden even when invoked directly.

## Scenario 7: Reporting and Arabic

1. Filter subscriptions by activity, validity, near expiry, visibility,
   product, and customer.
2. Review customer assignments and subscription pricing outcomes through the
   existing report framework.
3. Filter audit history to the customer and subscription.
4. Switch the dashboard to Arabic.

Expected:

- Results are paginated and correctly constrained.
- Existing reports are extended rather than duplicated.
- New and touched labels/messages are translated and RTL is correct.

## Focused Automated Test Gate

Run the new feature groups plus the baseline:

```powershell
php artisan test --compact tests/Feature/CustomerProfileResourceTest.php tests/Feature/ProductSubscriptionServiceTest.php tests/Feature/SubscriptionPriceResolverTest.php tests/Feature/Filament/ProductSubscriptionResourceTest.php tests/Feature/Filament/CrmAuthorizationTest.php tests/Feature/InventoryReportServiceTest.php tests/Feature/Filament/PricingControlsResourceTest.php
```

Then format and statically verify changed PHP:

```powershell
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```

Final CI-equivalent gate:

```powershell
composer test
```

Expected:

- No new PHPStan baseline entries.
- No weakened architecture, type, test, or coverage thresholds.
- Existing unrelated worktree changes remain untouched.
