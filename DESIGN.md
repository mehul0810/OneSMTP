# Aculect Mail Design Contract

This document defines the product design contract for Aculect Mail. It is a contributor guide for the Aculect ecosystem admin UI, setup, provider settings, routing, delivery, analytics, docs, and launch assets. It is not a full brand system or a promise of unshipped features.

## Aculect Ecosystem Brand

Aculect Mail is an Aculect ecosystem plugin. Its admin UI should feel like a focused member of the same product family while retaining native WordPress admin behavior.

Use these shared tokens as the starting contract:

| Token | Value | Use |
| --- | --- | --- |
| Primary | `#1d4ed8` | Links, active tabs, primary emphasis, focus rings |
| Ink | `#1d2327` | Primary text and headings |
| Muted | `#646970` | Descriptions and secondary text |
| Surface | `#ffffff` | Panels and form surfaces |
| Surface alternate | `#f6f7f7` | Secondary panels and quiet backgrounds |
| Border | `#dcdcde` | Dividers, panel borders, and table boundaries |
| Success | `#008a20` | Positive status when paired with text or an icon |

Use WordPress semantic status colors for warning and error states. Do not introduce a new accent palette without documenting the decision here. Color must never be the only status signal.

The current admin information architecture is:

`Overview` · `Providers` · `Routing` · `Activity` · `Analytics` · `Settings` · `Advanced`

Delivery testing is part of the guided setup and provider verification flow. The
legacy `onesmtp-delivery` destination remains an alias to the Overview setup
surface for backward compatibility.

## WordPress Design System

The [WordPress Design System](https://www.figma.com/community/file/1436359662053949167/wordpress-design-system) is the visual and interaction reference for Aculect Mail. Use WordPress core admin patterns and the available `@wordpress/*` packages before inventing custom equivalents.

- Prefer `@wordpress/components` for interactive controls, `@wordpress/element` for React rendering, `@wordpress/i18n` for translated strings, `@wordpress/icons` for icons, and `@wordpress/dataviews` for sortable, filterable admin listings when a JavaScript surface is introduced.
- Use Heroicons outline paths for Aculect Mail-specific product icons, rendered accessibly through the shared PHP helper.
- Use `@wordpress/data` only when shared client state is needed; keep simple forms and server-rendered screens simple.
- Do not add a second component library or icon package without an architecture decision recorded in `docs/developer/design-system.md`.
- Keep the current PHP-rendered admin compatible with WordPress core. Adopt components incrementally at clear interaction boundaries instead of forcing a wholesale rewrite.
- Match WordPress spacing, typography, focus, notice, table, form, and responsive behavior before applying Aculect brand tokens.
- A Figma-driven visual change requires the exact frame/node context and a screenshot before implementation, followed by rendered QA at desktop and narrow admin widths.

## Product Posture

Aculect Mail should feel like a reliable WordPress operations tool: calm, direct, and built for repeat use. Prioritize clear status, fast scanning, predictable controls, and recovery paths over decorative layouts.

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

Every failure state should answer what happened, what Aculect Mail will do next, and what the admin can do now.

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

Aculect Mail copy should be clear, operational, and specific.

- Prefer short labels: `Test connection`, `Retry now`, `Switch provider`, `View logs`.
- Use sentence-case UI text unless WordPress conventions require otherwise.
- Say what the system knows. Use `Not configured` instead of vague states like `Inactive`.
- Avoid fear-based language, exaggerated reliability claims, and competitor comparisons.
- Keep public README, readme.txt, website, and docs copy aligned with the currently implemented release behavior.

## Website, Docs, And Release Assets

Launch assets should show the real product state and avoid screenshots or copy that imply unavailable features.

- Screenshots should show actual admin screens, setup flow, provider configuration, logs, and recovery states.
- Docs should separate admin tasks from developer extension points.
- Website sections should focus on use cases, reliability behavior, setup steps, and operational visibility.
- Public issue and docs text should stay competitor-neutral.
- Coordinate screenshot, README, readme.txt, and launch-page work with the launch-readiness backlog.

## Current And Future Boundaries

Design work should support the current release milestone: provider setup, failover behavior, retries, delivery logs, resend, analytics, and docs. Cost intelligence, hosted functionality, pricing, and advanced automation must remain clearly marked as unavailable until implemented and approved.

## Non-Goals

- No redesign of WordPress admin conventions.
- No pricing, licensing, or free-vs-pro positioning decisions.
- No public API, database schema, or privacy policy changes.
- No claims about WordPress.org availability until a public plugin slug exists.
- No decorative marketing system for wp-admin screens.

## Review Checklist

- The screen or asset matches implemented behavior for the target release.
- Primary status, next action, and recovery path are visible.
- Provider credentials and sensitive email data are protected.
- Keyboard, focus, text, and color accessibility were checked.
- README, readme.txt, docs, and website copy do not drift from the product state.
