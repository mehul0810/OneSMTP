# Changelog Policy

Use Keep a Changelog format in `CHANGELOG.md`.

## Rules

1. Add all user-visible changes to `Unreleased`.
2. Use sections: Added, Changed, Fixed, Security, Deprecated, Removed.
3. Each entry should state impact, not vague implementation notes.
4. On release, move `Unreleased` entries under a version/date heading.

## Examples

Good: "Switched failed email retries to Action Scheduler-backed background jobs."

Bad: "Refactored retry module."
