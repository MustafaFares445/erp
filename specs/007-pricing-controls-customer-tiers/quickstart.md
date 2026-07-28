# Quickstart Validation: Pricing Controls and Customer Tiers

## Prerequisites

- Migrate the test database.
- Seed inventory permissions.
- Use an administrator with `inventory.catalog.manage`, `inventory.pricing.view`, and `inventory.pricing.manage`.

## Automated validation

```powershell
php artisan test --compact tests/Feature/ProductPricingServiceTest.php tests/Feature/Filament/PricingControlsResourceTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```

## Scenario 1: Derived pricing and history

1. Edit a variant cost to `80`, markup to `25`, and minimum to `90`.
2. Verify base price displays `100`.
3. Verify one price-history row and one audit row exist.
4. Save again without changes and verify counts remain unchanged.

## Scenario 2: Tier precedence and assignment

1. Create two active general tiers and one Customer account.
2. Assign the first tier, then assign the second.
3. Verify only the second assignment is active.
4. Create an active customer-specific tier and verify it resolves before the assigned general tier.

## Scenario 3: Floor approval and history access

1. Set a variant floor to `90`.
2. Approve an attempted price of `85` with a reason.
3. Verify the override captures the floor, approver, timestamp, and reason.
4. Verify the record can be viewed but not edited or deleted.
5. Repeat as a user without pricing permission and verify access is denied.
