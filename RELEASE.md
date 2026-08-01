# OneSMTP Release Runbook

This is the production-release contract for OneSMTP. Release actions remain manual and require maintainer approval.

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

- Working tree is clean and the candidate branch/base are proven.
- `composer lint`, `composer test`, and `composer analyze` pass, or approved baseline exceptions are recorded.
- `npm run build` passes when assets are present or changed.
- Performance smoke passes for queue/retry changes.
- Admin smoke covers the current six-tab IA: Overview, Providers, Routing, Delivery, Analytics, Settings.
- No secrets, test fixtures, vendor directories, node modules, or repository metadata are in the package.
- Package installs and activates on the supported PHP/WordPress matrix.
- Changelog and release notes describe user impact, compatibility, upgrade notes, and known limitations.

## Rollback

If a release is defective, stop further promotion, identify the affected tag/package, and publish a maintainer-approved corrective release. Do not rewrite a published tag. Preserve logs and checksums needed to explain the incident without exposing private data.

## Release evidence

Record the candidate SHA, tag, package SHA-256, validation commands, environment versions, migration status, and owner approval in the release PR or release record.
