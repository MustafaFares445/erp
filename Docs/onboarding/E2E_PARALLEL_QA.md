# Parallel E2E QA Infrastructure

Status: infrastructure-only; do not treat a guide image as approved from an E2E result.
Owner: infrastructure (Agent 0). Update this page when the runner contract changes.

## Safety model

The source checkout at C:\laragon\www\ierp-new stays on dev, including its modified and untracked content. Never run destructive QA Artisan commands from that source checkout. Every worker is a full file snapshot (not a Git worktree) under tmp/qa-workspaces/worker-0N, preserving modified and untracked files such as user-guide/. Its own .env uses SQLite, application key, session files, cache files, logs, uploads and port. Source .env is excluded. The source vendor/ and node_modules/ are read-only dependency junctions; never install or write packages through a worker. Never write to a shared manifest, guide public image, database, or build output.

The runner and Windows PHP launcher reject an invocation from the source checkout. The preflight scripts/qa-guard.php boots Laravel and checks effective DB driver, SQLite path, actual SQLite main file, APP_ENV and configuration cache before any migration or seeding. Never invoke Artisan migration/seed directly; use the guarded script. A worker can mutate only its own database and filesystem. The E2E WSL browser-facing ports are 8111..8118, with matching isolated Windows PHP upstream ports 9111..9118; source guide capture remains port 8011. `qa-worker.ps1` fails closed if the target application database is not the approved workspace SQLite file, and refuses a cached Laravel config.

**Create one worker snapshot after source edits are quiescent.** A snapshot refuses to overwrite an existing worker, so capture a new worker ID or archive existing work externally. Snapshot copies source files including untracked content, excluding .git, secrets (.env), dependencies, build caches, storage and tmp runtime data. Demo records are reconstructed inside each worker.

## Windows commands (PowerShell)

From C:\laragon\www\ierp-new:

    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\qa-worker.ps1 -Action snapshot -Worker worker-01
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\qa-worker.ps1 -Action verify -Worker worker-01
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\qa-worker.ps1 -Action prepare -Worker worker-01
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\qa-worker.ps1 -Action provision-admin -Worker worker-01
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\qa-worker.ps1 -Action status -Worker worker-01

Each additional worker uses worker-02 through worker-08 and never reuses a worker directory or port. Running prepare again destroys **only that worker SQLite dataset**; preserve evidence before doing so. For all write workflows, run verify before tests. The worker-specific PHP server is normally launched and stopped automatically by the WSL e2e runner. For troubleshooting, invoke Action serve and Action stop with the exact corresponding worker; never terminate a generic php.exe process.

## WSL Ubuntu commands

From a PowerShell host shell:

    wsl.exe -d Ubuntu -- bash -lc 'cd /mnt/c/laragon/www/ierp-new/tmp/qa-workspaces/worker-01 && bash scripts/e2e-wsl.sh run tests/admin-access.e2e.ts'
    wsl.exe -d Ubuntu -- bash -lc 'cd /mnt/c/laragon/www/ierp-new/tmp/qa-workspaces/worker-01 && bash scripts/e2e-wsl.sh run tests/example.e2e.ts'
    wsl.exe -d Ubuntu -- bash -lc 'cd /mnt/c/laragon/www/ierp-new/tmp/qa-workspaces/worker-01 && bash scripts/e2e-wsl.sh models openai'

The runner launches a dedicated Windows/Laragon PHP process bound to the WSL gateway on port 911N, proxies to WSL loopback on port 811N, and terminates only its owned Windows PID on shutdown. A collision or unowned process is a hard error. E2E output is in that worker's .e2e/report.json, artifacts/, logs/ and cache/. Reuse sessions only within that worker; do not share browser profiles, cookies, saved sessions or agent cache.

For the model-driven flows, run the interactive command in the worker WSL shell when authentication is absent:

    bash scripts/e2e-wsl.sh login openai

To explicitly verify AI inference, run `IERP_QA_MODEL_CHECK=1 bash scripts/e2e-wsl.sh run tests/qa-model-access.e2e.ts` inside the prepared worker's Ubuntu shell. On 2026-10-08 this call passed with `chatgpt.responses/gpt-6-luna`, 1 model call and 841 tokens. The previous zero-token timeout was transient; the initial overly specific assertion was corrected to require only visible email and password fields. This is verified model execution, not merely a model listing.

Then confirm the subscribed model appears with models openai. The configured model is chatgpt('gpt-6-luna'). A successful deterministic test without agent.* steps does **not** prove subscription authentication.

## Administrator authentication

Tests needing login declare session: 'qa-admin' and depend on tests/qa-admin.setup.e2e.ts. The guarded `prepare` action seeds the isolated database and privately provisions a worker-specific random administrator credential, and `provision-admin` can perform that operation later without re-seeding. The files `tmp/qa-secrets/admin-password` and `tmp/qa-secrets/admin-username` remain inside each worker, never under source or in Git; config resolves them through secret-aware E2E credential handles. Both authentication setup and session restoration passed in worker-02 (2/2). Livewire redirects are asynchronous: use browser.waitForURL with an explicit timeout before saving a session. Credentials must never be printed, committed, or inserted into agent prompts. The setup is registered only for a prepared worker with a private credential (or an explicitly injected override). E2E intentionally taints screenshots after secret fills, so guide capture must remain separate.

## Existing screenshot pipeline (separate trust boundary)

The established native-Windows Playwright capture uses user-guide/scripts/prepare-screenshot-demo.ps1, serve-screenshot-demo.ps1 and capture-screenshots.mjs, its own tmp/user-guide-demo.sqlite, and port 8011. These Windows scripts now validate the exact SQLite target first. Preserve the existing approved screenshots and the explicit IERP_GUIDE_CAPTURE_CONFIRM=1 gate; do not use E2E screen captures as approved guide assets. Domain workers may put candidate screenshots only under their private tmp/staged-screenshots directory. Operator/integration Agent 8 owns any final native-Windows capture, editorial review, and promotion into user-guide/public/images/<locale>/<module>/.

## Ownership and wave boundaries

- Agent 0: scripts/qa-*, scripts/e2e-serve-wsl.mjs, e2e.config.ts, tests/qa-admin*, and this infrastructure contract. No business module changes.
- Agent 1: Inventory Phase 4 only: Counts, Adjustments, Corrections, Returns, Condition Changes.
- Agent 2: Remaining Inventory guide/QA coverage; avoid Agent 1's specific flow files.
- Agent 3: Purchasing domain (PO, supplier confirmation, receiving handoff).
- Agent 4: Accounting domain (posting, supplier/customer payments, statements).
- Agent 5: CRM and Employees domains.
- Agent 6: Support and maintenance domain.
- Agent 7: Shared guide UX, links and bilingual presentation only; do not regenerate domain articles or screenshots.
- Agent 8: integration only, resolves overlaps and merges accepted results into source, owns all shared manifests/build/capture approvals.

Domain agents may edit only their own worker snapshot. They must **not** commit, push, stash, reset, update dev, regenerate shared guide manifests, or write directly into source. If a genuine defect requires a file owned by another worker, report a conflict request, do not overwrite their work. Prefer separate test files (tests/<domain>-*.e2e.ts), stage assets privately, and disclose any cross-domain application change.

## Required handoff

For each agent, write tmp/qa-handoff/<agent-id>.json or .md inside its worker folder with:
- worker ID, source branch, snapshot date, scope and owner;
- changed/added/deleted relative file paths, reason and any cross-owner conflicts;
- exact test commands, pass/fail counts and the worker-relative .e2e/report.json path;
- database preflight results, warnings, reproduction data and any unresolved defects;
- candidate screenshot paths with EN/AR locale, target IDs, viewport and review status (always pending);
- blocked steps, model-authentication status and any failed subprocess output redacted of credentials.

Agent 8 checks every changed file against the current source tree, selects accepted changes, resolves conflicts, regenerates shared manifests once, re-captures screenshots in the protected Windows pipeline and runs authoritative validation. A domain agent cannot mark a screenshot published or approved on its own.

## Verified baseline (2026-10-08)

`worker-02`: `SAFE_SQLITE_VERIFIED`; isolated Laravel migration and DemoMonth seeder completed. Default E2E suite initially passed the original 2/2 smoke tests. Following private admin provision, `tests/qa-admin.setup.e2e.ts` and `tests/qa-admin-access.e2e.ts` passed 2/2, including a real Livewire redirect and restored session. Direct E2E invocation from the source checkout failed `CONFIG_LOAD_FAILED` with no tests; effective MySQL connection and a database file outside the worker were rejected. ChatGPT model-based verification remains separate; see Agent 0's handoff.

Final continuation check (2026-10-08): worker-02 default suite 4/4 passed (qa-admin setup, admin-access, example, qa-admin-access), 0 failures, exit 0, runtime 39.13s. The opt-in ChatGPT model check passed 1/1 with one gpt-6-luna request and 841 tokens. Post-run status SERVER_NOT_RUNNING; SQLite guard SAFE_SQLITE_VERIFIED. No guide screenshots or shared manifests were modified. For per-domain worker reservations and copy-ready commands see Docs/onboarding/E2E_AGENT_START_HANDOFF.md.

## Negative checks

A worker must refuse a source-checkout e2e run, a cached config, a MySQL driver, a mismatched or missing SQLite path, a busy port, a foreign PHP PID, or an existing snapshot destination. Report any unexpected pass as a safety defect and stop all mutating QA.
