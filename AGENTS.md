# AGENTS.md

Guidance for AI coding agents working in the OneSMTP plugin repository.

## Project Snapshot
- Plugin: `OneSMTP`
- Stack:
  - PHP 8.1+ WordPress plugin under `OneSMTP\\`
  - PHPUnit, PHPCS/WPCS, PHPStan
  - Minimal Node-based build tooling for packaging and future assets
- Current remote branch layout:
  - `main` is the only verified long-lived branch in this repository today
  - Do not assume `develop` or `release/*` exists unless live Git and GitHub state confirm it in the current run

## Branch And PR Workflow
- Rehydrate branch state before changes:
  - `git status --short --branch`
  - `git branch -a`
  - verify the GitHub default branch and open PR base branches live
- Until a separate integration branch exists, treat `main` as both the default development branch and the production branch.
- Create one focused work branch per issue or direct fix:
  - issue work: `issue/<issue-number>-<short-slug>`
  - docs/workflow updates: `docs/<short-slug>`
- Never rely on GitHub's default PR base. Set the base branch explicitly when opening every PR.
- If a milestone-specific stabilization branch is created later, name it `release/<milestone>` and branch milestone work from that release branch instead of from `main`.
- If branch policy becomes ambiguous or multiple candidate bases exist, stop and tag `@mehul0810` rather than guessing.

## Release Workflow
- Keep release approval manual and explicit.
- Agents may prepare docs, changelog, packaging, and validation work, but must not create releases, publish tags, or merge production release PRs without current maintainer approval.
- The existing release automation lives in `.github/workflows/release.yml` and publishes artifacts only from version tags.
- Before any release recommendation, verify:
  - milestone scope is resolved or explicitly deferred
  - `CHANGELOG.md` is ready
  - CI is green for the candidate branch
  - package verification still succeeds
- If a future long-lived integration branch is introduced, document the post-release sync path in this file and related repo docs in the same change. Until then, there is no separate post-release sync step to assume.

## Validation Gates
- For PHP changes, prefer this minimum local gate:
  - `composer lint`
  - `composer test`
  - `composer analyze`
- For JS/build or packaging changes, also run:
  - `npm run build`
- Use the existing GitHub workflows as the durable CI source of truth:
  - `.github/workflows/ci.yml`
  - `.github/workflows/performance-smoke.yml`
  - `.github/workflows/release.yml`
- If a touched area cannot be validated locally, call out the gap clearly in the PR body.

## Docs And Changelog Rules
- Workflow, branch-policy, release, or maintainer-process learnings belong in `AGENTS.md` and closely related docs, not only in chat memory.
- Follow `docs/policies/docs-update-protocol.md` when behavior or operations change.
- Add user-visible changes to `CHANGELOG.md` under `Unreleased`.
- Keep documentation PRs focused and avoid mixing workflow-policy updates with unrelated code changes.

## Security And Operational Guardrails
- Never expose provider secrets, tokens, credentials, private logs, or sensitive payloads in repo docs, PR text, or fixtures.
- Preserve deterministic retry/failover behavior and existing retention constraints unless the task explicitly changes them.
- Treat release and packaging work as high-risk operational changes: document proof, rollback notes, and any unrun validation in the PR.

## When Unsure
- Prefer small docs-only or validation-only PRs over speculative workflow changes.
- Prefer live verification over static branch or milestone assumptions.
- Escalate ambiguous release-scope or branch-policy decisions to `@mehul0810`.
