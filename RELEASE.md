# Aculect Mail Release Runbook

This is the production-release contract for Aculect Mail. Release actions remain manual and require maintainer approval.

## Branch and version flow

1. Confirm the live default branch and the target `release/<version>` branch.
2. Complete milestone scope or record explicit deferred issues.
3. Keep implementation changes on the milestone release branch; do not merge release work directly to `main`.
4. Update `CHANGELOG.md`, release notes, readme metadata, and any migration notes.
5. Validate the release candidate from a clean checkout at the exact candidate commit.
6. Obtain maintainer approval before tagging, publishing, or merging production release work.
7. Create the version tag using the repository's established `v<version>` convention.
8. Verify the GitHub release artifact, checksum, plugin header version, and package contents.
9. Sync the released commit back to the development branch before unrelated milestone work begins.

## Release gates

Use only `Pass`, `Risk`, `Blocked`, or `Not applicable - reason` in the candidate release record. A `Risk` requires named owner acceptance; a `Blocked` gate prevents merge, tag, and publication.

| Gate | Evidence | Required status |
| --- | --- | --- |
| Scope and source | Clean candidate tree, proven branch/base, resolved or explicitly deferred milestone work, and fresh source review | `Pass` |
| PHP and data behavior | PHPCS gate, PHPUnit, PHPStan, supported PHP matrix, migrations, queue/retry/failover tests | `Pass` |
| Admin and accessibility | JavaScript lint/build, seven-tab admin smoke, keyboard checks, and desktop/narrow screenshot evidence | `Pass` |
| Security and privacy | Plugin Check, dependency audits, secret scan, credential/log/export boundaries | `Pass` |
| Performance | Queue/retry benchmark, large-log paths, and documented bundle impact | `Pass` or owner-accepted `Risk` |
| Package and compatibility | Clean ZIP, checksum, install/activate smoke, supported WordPress/PHP matrix | `Pass` |
| Documentation | Changelog, release notes, metadata, upgrade guidance, known limitations, rollback notes | `Pass` |
| Live delivery | Owner-controlled provider send and controlled eligible-provider failover using disposable staging credentials | `Pass` |
| Owner approval | Explicit approval for production merge, tag, and publication | `Pass` |

## Rollback

If a release is defective, stop further promotion, identify the affected tag/package, and publish a maintainer-approved corrective release. Do not rewrite a published tag. Preserve logs and checksums needed to explain the incident without exposing private data.

## Release evidence

Record the candidate SHA, tag, package SHA-256, validation commands, environment versions, migration status, and owner approval in the release PR or release record.
