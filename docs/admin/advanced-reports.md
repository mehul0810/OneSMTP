# Advanced reports

When Pro analytics is enabled, the Analytics screen includes bounded report
slices for the selected 7-day or 30-day window:

- provider attempts with the reliability score foundation used by the normal
  provider comparison;
- message status distribution;
- a daily message trend;
- failure categories from failed attempt logs; and
- top subject groups.

Advanced reports are default-deny. A Pro entitlement and the
`advanced_analytics` rollout flag are both required, and users still need the
normal Aculect Mail log-visibility capability.

Reports read only aggregate fields from the message and attempt tables. They do
not select message bodies, recipients, credentials, provider payloads, or raw
event context. Subject groups use only the existing nullable `messages.subject`
value; labels are normalized, redacted for secret-like values, truncated to 80
characters, and escaped at render time. Empty subjects are shown as `No
subject`.

Each report slice uses an explicit UTC time range, an indexed `created_at`
predicate, deterministic ordering, and a bounded result limit. The displayed
counts describe the returned report window and are not delivery or provider SLA
guarantees.
