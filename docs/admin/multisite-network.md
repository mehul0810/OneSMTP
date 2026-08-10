# Multisite network controls

This page describes the unreleased `release/0.4.0` candidate. It is not a
public availability, licensing, or upgrade announcement.

Multisite network controls are a Pro capability. They are available only from
the WordPress network admin when the `multisite_management` entitlement and
rollout flag are both enabled. Free/core installs, single-site installs, and
normal site-admin screens keep their existing behavior.

## Network settings

Network defaults are intentionally limited to non-secret operational controls:

- Delivery rate limits (per minute, hour, and day)
- Background sending enabled/disabled
- Attachment metadata logging enabled/disabled

Each control has explicit inheritance. A site can inherit the network value or
turn inheritance off and retain an allowlisted site-level override. The plugin
does not copy provider credentials, API keys, OAuth tokens, alert destinations,
sender recipients, message bodies, headers, or raw payload JSON between sites.

The effective value is resolved at read time. This means changing a network
default does not overwrite a site option, and turning inheritance off does not
require moving or rewriting provider configuration.

## Network logs

Network logs are rendered only in the network admin and require the
`manage_network_options` capability in addition to the Pro gate. The view
switches into each site context before querying that site's plugin tables, then
returns only safe summaries with the site ID and name. It does not provide a
REST route or unauthenticated endpoint.

The query is bounded to 100 sites, 100 rows per site, and 50 rows per page.
Filters are limited to status, site ID, and bounded text search. Output omits
message bodies, full recipients, raw headers, secrets, and payload JSON; safe
recipient metadata contains only a count and domains.

The screen includes explicit empty, success, failure, and long-content-safe
states using WordPress admin tabs, notices, forms, and tables.
