# Licensing and update foundation (0.4.0 candidate)

Aculect Mail 0.4.0 contains repository-local contracts for a future Pro
licensing and update runtime. The foundation makes the trust boundary testable;
it does not connect to a licensing vendor or Aculect-hosted service.

## Included contracts

- `LicenseClient` describes status, activation, deactivation, and refresh
  operations without providing a transport.
- `EntitlementProvider` supplies one fail-closed entitlement decision.
- `UpdateProvider` supplies a bounded update status without an update URL,
  package, signature, or download operation.
- `LicenseStatus`, `UpdateStatus`, and their backed enums expose only bounded
  state/reason values. `MaskedIdentifier` discards the raw identifier and keeps
  only a masked four-character suffix.
- `LicenseEntitlementProvider` recognizes only an explicit `active` license
  state. `FeatureGateAdapter` still requires an individual rollout flag and
  denies entitlement exceptions.
- Unavailable local implementations and injectable test fakes prove empty,
  active, error, and default-deny behavior without network access or secrets.

The Advanced screen reports the foundation honestly. It offers no license-key
field, activation button, update action, purchase link, or external request.

## Deferred owner decisions

A later backlog item must choose the distribution format, licensing authority,
site-count and staging rules, activation data, offline/grace policy, update
host, authenticated package delivery, signing algorithm, and signing-key
custody. Until that work is separately approved and implemented, this
foundation must not be described as production license activation, paid
distribution, or Pro update delivery.
