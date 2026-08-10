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
value; labels are normalized, redacted for secret- and address-like values,
truncated to 80 characters, and escaped at render time. Empty subjects are
shown as `No subject`.

The public repository report contract exposes only a stable group key, safe
label, and count; raw subject values never leave the repository boundary.

Each report slice uses an explicit UTC time range, an indexed `created_at`
predicate, deterministic ordering, and a bounded result limit. Subject groups
use a two-stage bound: up to `min(500, limit * 10)` exact subject candidates are
read, normalized/grouped in the repository, and then the final top-N is applied
by combined count, stable key, and safe label. This keeps queries bounded while
allowing common case/whitespace variants to combine; variants beyond the
candidate cap can be omitted from the slice. The displayed counts describe the
returned report window and are not delivery or provider SLA guarantees.
