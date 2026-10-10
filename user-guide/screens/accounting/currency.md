---
title: "Accounting Setup"
module: accounting
pages: ["index"]
screenshots: ["accounting-currency-index"]
lastVerified: 2026-10-07
---

# Currency catalog

<IerpPageSummary purpose="Inspect system currencies and the shared active/default-currency policy." useWhen="Checking available currencies before creating customer or supplier financial documents." owner="Shared Settings" />

## When to use this page

Checking available currencies before creating customer or supplier financial documents.

## Typical tasks

- Follow the documented “Currency catalog” instructions below.

<IerpScreenshot id="accounting-currency-index" title="Currency catalog" description="Available ISO currency codes and active/default indicators." imageSrc="/images/en/accounting/accounting-currency-index.png" />

Financial documents select active currency codes from this common catalog. The default currency is initially AED where configured; historical transactions retain their recorded codes even if a currency is later inactive. Check document currency before partial payments, supplier allocations and financial reports. A currency code change does not itself convert an amount or amend a posted journal. Only an authorized Settings actor should change catalog availability.
