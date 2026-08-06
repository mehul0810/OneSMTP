# Aculect Mail Testing Contract

This is the contributor-facing testing contract. The detailed behavior matrix remains in [`docs/developer/testing.md`](docs/developer/testing.md); this file defines the release gate and the evidence expected for every change.

## Required checks by change type

| Change | Required checks |
| --- | --- |
| PHP behavior | `composer lint`, `composer test`, `composer analyze` |
| Admin UI or JavaScript | PHP checks plus `node --check` for touched scripts, `npm run build`, and component/accessibility smoke for any `@wordpress/*` surface |
| REST, provider, retry, or data behavior | Focused PHPUnit tests plus the full PHPUnit suite |
| Packaging or release | All checks, package build, checksum verification, and clean-install smoke test |
| Admin accessibility | Focused admin tests plus Playwright smoke when the browser fixture is available |
| Performance-sensitive code | `./scripts/benchmarks/run-baseline.sh smoke` and comparison with the documented baseline |

## Local commands

```bash
composer install --no-interaction --prefer-dist
composer lint
composer test
./vendor/bin/phpstan analyse --configuration=phpstan.neon --no-progress --memory-limit=512M
PATH=/path/to/node-20.19.0/bin:$PATH npm ci
npm run lint:js
npm run build
```

For the Studio site, use `studio --version`, `studio status`, and `studio wp` commands. Never use a bare `wp` command for this repository's local site.

## Evidence rules

- Tests must use synthetic recipients, provider fixtures, and non-secret values.
- Never commit provider credentials, API keys, tokens, private payloads, or production recipients.
- Record known baseline failures separately from regressions introduced by the change.
- A passing unit suite does not prove live provider delivery; state that boundary explicitly.
- Release evidence must include the commit SHA, branch, package checksum, PHP/WordPress versions tested, and any skipped checks.

## Screenshot evidence for visible changes

- Admin-shell, layout, responsive, component, or other visibly changed work requires fresh screenshots at desktop and a narrow WordPress admin width.
- Capture every affected tab and the important interaction state, including drawers, validation, empty states, and overflow-sensitive listings when applicable.
- Attach the screenshots to the implementation or release PR with the tested viewport sizes and candidate commit SHA.
- Screenshots complement keyboard/accessibility and browser smoke; they do not replace those checks.
- If the real admin cannot be captured, record `Screenshot blocked` with the exact environment or access reason. A blocked note is a release risk that requires explicit owner acceptance, not an automatic pass.

## Test layers

1. Static syntax and changed-file lint.
2. Unit tests for deterministic policies and value objects.
3. Integration tests for repositories, queues, logs, and provider boundaries.
4. Admin/browser smoke for navigation, forms, privacy-safe rendering, and responsive behavior.
5. Package/install smoke for the artifact that will be distributed.

Do not weaken a test or mark a check optional to make a release green. Fix the behavior, narrow the test to its real contract, or record an explicit maintainer-approved exception.
