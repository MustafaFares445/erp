# Backup and Recovery

---
status: canonical
owner: operations
last_verified: 2026-10-02
verified_against: current repository/deployment configuration
---

## Current Repository Status

No application-owned backup scheduler/provider/retention policy is defined in the checked-in repository.

Backups are therefore an infrastructure responsibility until an explicit backup implementation is added.

## What Must Be Protected

At minimum:
- relational database;
- uploaded/private business media;
- server environment/secrets through the deployment platform's secure secret-management process;
- any external object-storage bucket used for business media.

Source code itself is versioned in Git and should not be treated as the database/media backup.

## Recovery Requirements

A production operations plan should define:
- backup frequency;
- retention period;
- encryption;
- off-host/off-instance storage;
- restore ownership;
- recovery-point objective (RPO);
- recovery-time objective (RTO);
- regular restore testing.

## Accounting / Inventory Consideration

Database and media recovery must preserve one consistent business point in time.

Restoring only one subset of ERP data can break stock, payment, journal, document and media provenance.

## Deployment vs Backup

The AWS dev deployment performs migrations but does not create a pre-deploy backup.

Do not claim otherwise.

If production policy requires pre-migration snapshots, add that behavior explicitly to production infrastructure/workflow and update this document.
