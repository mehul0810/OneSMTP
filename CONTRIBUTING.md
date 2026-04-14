# Contributing to OneSMTP

This project aims for low operational overhead with strong reliability and clear docs.

## Workflow

1. Create a feature branch from the active development branch.
2. Keep pull requests focused on one feature/fix.
3. Include tests for behavior changes where practical.
4. Update docs for user-visible or operational changes.
5. Add a changelog entry in `CHANGELOG.md` under `Unreleased`.

## Pull Request Checklist

- [ ] Code follows `CODE_STANDARDS.md`
- [ ] Tests added or existing tests updated
- [ ] Docs updated (or marked "No Docs Impact")
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
