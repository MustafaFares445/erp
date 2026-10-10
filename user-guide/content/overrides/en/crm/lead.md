# Leads

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Find and qualify a lead” instructions below.
- Follow the documented “Lead list” instructions below.
- Follow the documented “Create a lead” instructions below.

<IerpNavigationPath :items='["CRM", "Pipeline", "Leads"]' />

Manage prospective customers from first contact through qualification and conversion. CRM owns the lead and its interaction evidence; customer provisioning follows the approved CRM onboarding path.

## Find and qualify a lead

1. Open **CRM → Leads** and search by lead reference, company or contact. Use the **Dormant 14+ days** filter to find records with no recent interaction.
2. Check **Source**, **Status**, assigned owner and **Last interaction**. Group by status, source or owner to organize follow-up.
3. Open **View** to inspect the contact, linked interactions and stage history before recording another interaction.
4. Use **Log Interaction** to record a call or meeting. Progressing from **New** to **Contacted** or **Qualified** requires the evidence accepted by the lead service; changing a status without sufficient evidence is not an alternative.
5. Use **Assign** to transfer responsibility; **Disqualify** when the opportunity is no longer viable, recording its reason; **Convert** only when the lead qualifies and required customer data is ready.

## Lead list

<IerpScreenshot id="crm-lead-index" title="Lead follow-up queue" description="Search by contact or company and inspect source, status, owner and last interaction." path="CRM → Leads" imageSrc="/images/en/crm/crm-lead-index.png" />

## Create a lead

<IerpScreenshot id="crm-lead-create" title="Lead capture form" description="Record contact identity, source, contact channels and optional responsible owner." path="CRM → Leads → Create" imageSrc="/images/en/crm/crm-lead-create.png" />

Enter first/last name, company when relevant, email/phone, source and source detail. Check the assignee and preferred language before saving. Creation does not automatically produce a quotation or customer account.

## Review the record and evidence

<IerpScreenshot id="crm-lead-view" title="Lead details and history" description="Inspect interaction evidence, stage transitions and any converted customer reference." path="CRM → Leads → View" />

The **Lead Interactions** and **Lead Stage History** areas record why status progressed. The detail view also identifies converted customers and any disqualification reason.

## Edit while eligible

<IerpScreenshot id="crm-lead-edit" title="Editable lead details" description="Correct contact and source fields only while the lead remains non-terminal." path="CRM → Leads → Edit" imageSrc="/images/en/crm/crm-lead-edit.png" />

**Converted** and **Disqualified** are terminal stages: the normal Edit action is hidden. Do not reopen historical leads by manipulating raw status values.

## Workflow boundaries and troubleshooting

- A lead follows **New → Contacted → Qualified → Converted** when supported by interactions; **Disqualified** is a terminal alternative.
- Conversion enters the CRM customer onboarding/provisioning workflow; formal quotations and orders remain Sales-owned.
- If **Edit**, **Convert** or **Disqualify** is unavailable, check status, permission and evidence first.
- Viewing, searching and grouping leads never posts Inventory movements or Accounting transactions.

Related: [Lead to Sale](/workflows/lead-to-sale), [Customers](/screens/crm/customer), [Sales Opportunities](/screens/crm/sales-opportunity).
