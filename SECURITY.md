# Aculect Mail Security Policy

## Reporting a vulnerability

Do not disclose suspected vulnerabilities in public issues or pull requests. Contact the maintainer privately through the repository's configured security reporting channel. If private vulnerability reporting is unavailable, contact the maintainer before public disclosure.

Include:

- A concise impact summary.
- A reproducible proof of concept or exact reproduction steps.
- Affected version, commit, or release branch.
- WordPress, PHP, and browser versions when relevant.
- Logs or screenshots with secrets, tokens, personal data, and private payloads removed.

## Security baseline

- Enforce capabilities on every admin write, export, diagnostic, REST, and CLI operation.
- Verify nonces for browser state changes and use `permission_callback` for every REST route.
- Sanitize input at the boundary and escape output at the sink.
- Use prepared SQL for dynamic values and avoid arbitrary option or table access.
- Never log provider credentials, API keys, tokens, authorization headers, message bodies, raw headers, or private provider payloads.
- Keep stored secrets masked and preserve them on blank-field updates unless the user explicitly replaces or removes them.
- Use HTTPS for external provider and webhook communication where supported.
- Prefer fail-closed behavior when a scheduler, provider, authorization check, or validation dependency is unavailable.

## Supported versions

Security fixes target the current production release and the active stabilization branch. Older releases may require an upgrade before a fix can be provided.

## Disclosure

The maintainer will acknowledge credible private reports as soon as practical, investigate them privately, and coordinate a fix or advisory when appropriate. Do not access, alter, export, or retain data that is not needed to demonstrate the issue.
