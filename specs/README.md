# Spec Kit Workspace

This directory is reserved for **active** Spec Kit feature packages.

There are currently no numbered active Spec Kit packages in the working tree.

## Rules

- Start at [Docs/README.md](../Docs/README.md) for current project truth.
- A new numbered package belongs here only while the feature is actively being specified/implemented.
- Current code/tests and canonical documentation remain authoritative for already implemented behavior.
- When a feature is complete, merge durable rules into the owning canonical domain/product/architecture docs and relevant ADRs.
- Remove the completed numbered package from the working tree after live references to it are migrated.
- Git history is the archive for completed packages.

Do not restore old completed packages just to recover historical context; use Git history instead.
