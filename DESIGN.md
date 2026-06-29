# OneSMTP Design Contract

This document defines the product design contract for OneSMTP 0.1.0. It is a contributor guide for admin UI, setup, provider settings, logs, docs, and launch assets. It is not a full brand system or a promise of unshipped features.

## Product Posture

OneSMTP should feel like a reliable WordPress operations tool: calm, direct, and built for repeat use. Prioritize clear status, fast scanning, predictable controls, and recovery paths over decorative layouts.

- Keep admin screens dense but readable, with plain hierarchy and restrained visual styling.
- Use WordPress admin patterns first; introduce custom UI only when core controls cannot express the workflow clearly.
- Make reliability concepts visible through status, history, and next action, not promotional language.
- Avoid claims that imply public availability, paid plans, or provider behavior that is not implemented.

## Admin UI Principles

- Put operational state near the action it affects: provider health near provider controls, retry state near logs, and configuration warnings near the relevant field.
- Use familiar WordPress components for tables, notices, forms, tabs, filters, bulk actions, and settings sections.
- Keep primary actions specific and reversible where possible. Destructive or broad actions need confirmation text that names the affected object.
- Use icons only when they improve scanning and always pair unfamiliar icons with accessible labels or tooltips.
- Avoid nested cards, oversized hero sections, decorative backgrounds, and marketing-style composition inside wp-admin.

## Setup And Onboarding

The setup flow should get a site from inactive to first verified send with the fewest safe steps.

- Start with required configuration only: sender identity, provider selection, credentials, and test email.
- Show setup progress as concrete states: not configured, credentials saved, test pending, test failed, test passed.
- Explain failures with the next diagnostic action, not generic error copy.
- Do not hide advanced provider options, but keep them below the essential path.
- If a provider is unavailable or incomplete in the current release, mark it as future work rather than presenting it as ready.

## Provider And Settings Forms

Provider forms should make credential requirements, fallback behavior, and validation state easy to understand.

- Group fields by purpose: identity, authentication, connection, routing, and advanced options.
- Use explicit labels and helper text for provider-specific values.
- Mask secrets after save; never echo full credential values back into the UI.
- Keep primary and secondary provider choices visually distinct.
- Show validation results inline and in notices when the result affects the whole screen.
- Do not present future Pro-only routing, analytics, or automation as part of the free MVP unless clearly labeled as not available.

## Status, Errors, And Recovery

Every failure state should answer what happened, what OneSMTP will do next, and what the admin can do now.

- Use success, warning, error, and info states consistently with WordPress admin conventions.
- Include provider, attempt count, next retry time, and final failure state where available.
- Separate transient delivery failures from configuration failures.
- Prefer action-oriented error copy: retry, switch provider, edit credentials, inspect logs, or send test.
- Avoid blame language. State the condition and the recovery path.

## Logs And Resend

Logs are an operational surface, not a reporting dashboard.

- Optimize tables for scanning recent delivery events, provider outcomes, and retry status.
- Include filters for status, provider, date, and recipient when supported by the data model.
- Keep message content previews limited and avoid exposing sensitive email body data by default.
- Resend controls must show the selected provider path and whether the resend is manual or retry-driven.
- Manual resend should not imply that a failed provider is healthy unless a fresh attempt succeeds.

## Accessibility And Responsive Behavior

- All controls must be reachable by keyboard and have visible focus states.
- Form inputs, notices, icons, and status badges need accessible names or text equivalents.
- Color cannot be the only signal for provider health, delivery status, or destructive action.
- Tables should degrade to readable narrow layouts without losing the primary status and action.
- Copy must remain readable at normal WordPress admin zoom and browser text-size settings.

## Copy And Tone

OneSMTP copy should be clear, operational, and specific.

- Prefer short labels: `Test connection`, `Retry now`, `Switch provider`, `View logs`.
- Use sentence-case UI text unless WordPress conventions require otherwise.
- Say what the system knows. Use `Not configured` instead of vague states like `Inactive`.
- Avoid fear-based language, exaggerated reliability claims, and competitor comparisons.
- Keep public README, readme.txt, website, and docs copy aligned with implemented 0.1.0 behavior.

## Website, Docs, And Release Assets

Launch assets should show the real product state and avoid screenshots or copy that imply unavailable features.

- Screenshots should show actual admin screens, setup flow, provider configuration, logs, and recovery states.
- Docs should separate admin tasks from developer extension points.
- Website sections should focus on use cases, reliability behavior, setup steps, and operational visibility.
- Public issue and docs text should stay competitor-neutral.
- Coordinate screenshot, README, readme.txt, and launch-page work with the launch-readiness backlog.

## Current And Future Boundaries

For 0.1.0, design work should support the free MVP foundation: provider setup, failover behavior, retries, logs, resend, and docs. Future paid, hosted, analytics, or advanced automation concepts must remain clearly marked as future work until product scope is approved.

## Non-Goals

- No redesign of WordPress admin conventions.
- No pricing, licensing, or free-vs-pro positioning decisions.
- No public API, database schema, or privacy policy changes.
- No claims about WordPress.org availability until a public plugin slug exists.
- No decorative marketing system for wp-admin screens.

## Review Checklist

- The screen or asset matches implemented 0.1.0 behavior.
- Primary status, next action, and recovery path are visible.
- Provider credentials and sensitive email data are protected.
- Keyboard, focus, text, and color accessibility were checked.
- README, readme.txt, docs, and website copy do not drift from the product state.
