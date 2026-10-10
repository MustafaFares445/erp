# IERP E2E — Domain Agent Start Contract

Agent 0 infrastructure verified 2026-10-08. Do not start a domain agent against the source checkout.

## Assigned workers (reserved, not yet created)

| Agent | Worker | Ports (WSL / Windows PHP) | Exclusive ownership |
| --- | --- | --- | --- |
| 0 | worker-02 | 8112 / 9112 | E2E infrastructure; verified |
| 1 | worker-01 | 8111 / 9111 | Inventory Phase 4: Counts, Adjustments, Corrections, Returns, Condition Changes |
| 2 | worker-03 | 8113 / 9113 | Other Inventory screens and guide coverage; exclude Agent 1 paths |
| 3 | worker-04 | 8114 / 9114 | Purchasing, PO, confirmations, Inventory receiving handoff |
| 4 | worker-05 | 8115 / 9115 | Accounting, posting, payments and statements |
| 5 | worker-06 | 8116 / 9116 | CRM and Employees |
| 6 | worker-07 | 8117 / 9117 | Support and maintenance |
| 7 | worker-08 | 8118 / 9118 | Shared guide UX, navigation, bilingual display (no domain article rewrites) |
| 8 | Integration only | none | Integrate approved worker changes, resolve conflicts, approve screenshots, regenerate shared manifests once |

These reservations are exclusive. Do not overwrite worker-02 or any existing snapshot. Allocate workers to machine capacity and the planned wave order, not all at once by default.

## Setup on Windows

Use a dedicated PowerShell process working in C:\laragon\www\ierp-new and substitute the assigned worker ID. Perform one initial snapshot only. The snapshot is a copy of the current **modified and untracked** working files, not a Git worktree.

    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\qa-worker.ps1 -Action snapshot -Worker worker-01
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\qa-worker.ps1 -Action verify -Worker worker-01
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\qa-worker.ps1 -Action prepare -Worker worker-01

The prepare action validates the exact effective SQLite file, migrates, seeds DemoMonth and provisions a private randomized demo-admin credential. Do not manually run Artisan migrate/fresh/seed or install packages in workers. User secrets are stored only under the worker's tmp/qa-secrets directory; never display, copy to prompts, attach or commit them.

## E2E execution inside Ubuntu WSL

    cd /mnt/c/laragon/www/ierp-new/tmp/qa-workspaces/worker-01
    bash scripts/e2e-wsl.sh run
    bash scripts/e2e-wsl.sh run tests/admin-access.e2e.ts
    bash scripts/e2e-wsl.sh run tests/qa-admin-access.e2e.ts

The final two commands are focused checks. Each test run launches its own Windows Laragon PHP server and WSL localhost reverse proxy. Admin-session tests automatically use qa-admin setup and its redacted credential fixture. Screenshots captured by E2E are NOT guide-approved artifacts.

To explicitly run the confirmed subscription-backed model assertion (not part of default suite):

    IERP_QA_MODEL_CHECK=1 bash scripts/e2e-wsl.sh run tests/qa-model-access.e2e.ts

If the ChatGPT authentication becomes unavailable, run this interactively within WSL:

    bash scripts/e2e-wsl.sh login openai

Do not claim model success until a live agent.* test returns a passing verdict. The infrastructure validation returned 1 passing gpt-6-luna call with 841 tokens.

## Code ownership and handoff

- Do all mutating QA and application fixes only in the allocated worker snapshot. Never edit the live dev source; do not commit, push, stash, reset or create worktrees while working as a domain agent.
- Name E2E tests by domain under tests/. Do not change Agent 0's scripts, configurations or auth fixtures without a conflict request.
- No worker regenerates user-guide target manifests, shared images, shared nav, or the entire VitePress build.
- Store candidate EN/AR images only under worker tmp/staged-screenshots and label them PENDING. Do not copy E2E screenshots produced after a secret fill into the guide. Protected native Windows Playwright capture and image approval belong solely to Agent 8.
- Log changed relative paths, test command, passed/failed counts, exact report path, database guard output, issue IDs, screenshots with locale/viewport and open blockers in worker tmp/qa-handoff/AGENT_<N>.md.
- In case two agents would edit the same file, freeze that path and submit a conflict request to Agent 8. Never overwrite another agent's work.
- Agent 8 alone merges approved changes, regenerates manifests once, verifies bilingual documentation, runs authoritative tests and publishes reviewed screenshots.

See Docs/onboarding/E2E_PARALLEL_QA.md for the infrastructure implementation and the full evidence report at worker-02/tmp/qa-handoff/AGENT_0_INFRASTRUCTURE.md.
