# Contributing to Aculect Mail

This project aims for low operational overhead with strong reliability and clear docs.

## Workflow

1. Create a feature branch from the currently verified base branch in `AGENTS.md` and live repo state.
   - Use the currently verified `release/<version>` branch for active milestone work (`release/0.3.0` at the time of this document update).
   - Use `release/<milestone-number>` for any issue or pull request assigned to a milestone after verifying that branch live.
   - Use `develop` only for unmilestoned development integration or as the source for creating a missing milestone branch.
   - Do not target implementation pull requests directly to `main`.
2. Keep pull requests focused on one feature/fix.
3. Include tests for behavior changes where practical.
4. Update docs for user-visible or operational changes.
5. Add a changelog entry in `CHANGELOG.md` under `Unreleased`.
6. Check `DESIGN.md` for admin UI, docs, and launch-asset expectations.

## Pull Request Checklist

- [ ] Code follows `CODE_STANDARDS.md`
- [ ] Changed PHP files pass `composer lint`
- [ ] Tests added or existing tests updated
- [ ] Docs updated (or marked "No Docs Impact")
- [ ] Design contract checked for admin UI, docs, or launch-asset changes
- [ ] Aculect brand tokens and six-tab IA checked for admin UI changes
- [ ] WordPress Design System and official `@wordpress/*` package guidance checked for interactive UI changes
- [ ] Security impact reviewed for provider, REST, CLI, export, or credential changes
- [ ] Changelog entry added
- [ ] Backward compatibility considered

## Definition of Done

A PR is done when:

- Feature behavior is implemented and reviewed
- Required test coverage is present
- Docs are updated across admin/developer surfaces as needed
- Operational behavior (retries, logs, retention) is documented if touched

## Docs Impact Rule

If a PR changes any of the following, docs update is mandatory:

- Provider routing/failover behavior
- Action Scheduler retry logic
- Custom database schema or storage fields
- Log retention policy/filter behavior
- Admin UI or settings labels/options

Use `docs/policies/docs-update-protocol.md` for the expected update format.
