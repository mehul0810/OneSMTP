# AGENTS.md

Guidance for AI coding agents working in the OneSMTP plugin repository.

## Project Snapshot
- Plugin: `OneSMTP`
- Stack:
  - PHP 8.1+ WordPress plugin under `OneSMTP\\`
  - PHPUnit, PHPCS/WPCS, PHPStan
  - Minimal Node-based build tooling for packaging and future assets
- Current remote branch layout:
  - `main` is production release space only
  - `develop` is the development integration branch
  - `release/0.1.0` is the active milestone stabilization branch
  - Re-verify live Git and GitHub state before assuming any future `release/*` branch exists

## Branch And PR Workflow
- Rehydrate branch state before changes:
  - `git status --short --branch`
  - `git branch -a`
  - verify the GitHub default branch and open PR base branches live
- Create one focused work branch per issue or direct fix:
  - issue work: `issue/<issue-number>-<short-slug>`
  - docs/workflow updates: `docs/<short-slug>`
- Never rely on GitHub's default PR base. Set the base branch explicitly when opening every PR.
- Target 0.1.0 milestone work to `release/0.1.0`.
- Target every milestone-assigned issue or PR to its specific `release/<milestone-number>` branch.
- Use `develop` only for unmilestoned development integration or as the verified source for creating a missing milestone branch when repo policy supports it.
- Do not open implementation PRs against `main`.
- When a new milestone stabilization branch is needed, name it `release/<milestone-number>` and create it from the verified development base before opening milestone work.
- If branch policy becomes ambiguous or multiple candidate bases exist, stop and tag `@mehul0810` rather than guessing.

## Release Workflow
- Keep release approval manual and explicit.
- Agents may prepare docs, changelog, packaging, and validation work, but must not create releases, publish tags, or merge production release PRs without current maintainer approval.
- The existing release automation lives in `.github/workflows/release.yml` and publishes artifacts only from version tags.
- After a production release, sync the released changes back into `develop` before starting unrelated future milestone work.
- Before any release recommendation, verify:
  - milestone scope is resolved or explicitly deferred
  - `CHANGELOG.md` is ready
  - CI is green for the candidate branch
  - package verification still succeeds

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
- CI and performance smoke should run for pull requests and for pushes to `main`, `develop`, and `release/**`.
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
- Escalate only hard gates to `@mehul0810`: production or beta releases, tags, publishing, issue closure, milestone due-date or ambiguous retargeting, destructive migrations, pricing/licensing, privacy/security, public API/schema, broad positioning, or genuinely ambiguous tradeoffs.
