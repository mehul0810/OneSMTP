# Failover and Rotation

## Primary + Secondary

Aculect Mail sends through the selected provider first. If that provider fails, it records the failed attempt and immediately tries each other healthy active provider once. This keeps failover within the same message lineage and avoids duplicate message records.

## Smart Rotation

When more than two providers are configured, Aculect Mail rotates through the available healthy providers based on the configured order and routing strategy. Providers with an open circuit are skipped until they become eligible again.

When Pro provider sending budgets are enabled, quota-exhausted providers are
also skipped after normal active/circuit eligibility is calculated. Priority,
routing rules, and failover ordering remain deterministic among the providers
that still have capacity. If all eligible providers are at a configured
minute, hour, or day limit, Aculect returns a typed deferral with the earliest
window reset and schedules the message for that time. The deferral records no
attempt row and does not mark the message failed, preventing tight retry loops
or false terminal failures.

This deferral path is only used when the payload is safely reproducible from
the stored retry data. Attachment-bearing messages fail closed with the safe
`attachment_quota_deferral_not_persisted` terminal reason instead of enqueueing
a retry that would omit their files.

## Failure Switch Rule

Each provider request has its own attempt row, while all attempts remain part of one message lineage. A `provider_failover` event records every change from the failed source to the next provider.

Analytics separates failovers **to** a provider from switches **away** from a provider. “Switched away” is the provider reliability signal: it counts how often that provider was the source of a failover during the selected window.

If all healthy providers fail, the message is returned to the Action Scheduler retry queue with exponential backoff. A terminal failure is recorded when no safe alternate or retry path remains. Delivery is best-effort and depends on provider acceptance, recipient-domain policy, DNS, and downstream mailbox availability; no plugin can guarantee 100% inbox delivery.

The normal retry policy can keep a provider for one additional attempt when immediate failover is not requested. Retry processing and background delivery opt into immediate failover so queued messages can move to another provider after the first failure.

## Provider reliability score

When Pro analytics is enabled, the Analytics screen calculates a provider reliability score from aggregate Aculect Mail attempt history for the selected window. The score starts with the recorded success percentage, then applies bounded penalties for retry rate, switch-away rate, and average provider response latency. The result is clamped between 0 and 100.

The dashboard labels fewer than 20 recorded attempts as a **Limited sample** and 20 or more as an **Established sample**. This label describes evidence volume, not statistical certainty. Scores contain no recipient address, message body, subject, credential, or provider response content.

Reliability scores are operational comparisons inside the selected site and time window. They are not inbox-placement measurements, contractual provider SLAs, or delivery guarantees.

## Conditional routing (Pro)

When the Smart routing capability is enabled, administrators can add bounded
conditional rules on the Routing screen. Supported fields are sender,
recipient, subject, content, and source attribution (source type, slug, name,
or origin). Matching uses case-insensitive equality or bounded text operators;
regular expressions and arbitrary payload/header fields are not accepted.

Rules are evaluated by ascending priority. A lower number wins, and rules with
the same priority keep their configured order. Disabled rules, malformed rules,
inactive providers, and providers with an open circuit are skipped before the
normal healthy-provider route is used.

Content and source values are read only while a message is being dispatched.
The routing context is bounded in memory and is never copied into routing
events, audit records, or new persistent fields. Rule definitions contain only
the administrator's configured field/operator/value; message bodies,
recipients, and headers are not added to the logs.

## Simulate a routing decision (Pro)

Use the **Simulate routing decision** panel to test either the current saved
rules or one unsaved candidate rule against synthetic sender, recipient,
subject, content, and source fields. The result identifies a matched rule and
provider, or explains a safe no-match/no-eligible-provider result. Inactive
providers and providers skipped by an open circuit breaker are shown as
eligibility effects.

Simulation evaluates only the bounded in-memory context. It does not call a
provider, send or queue mail, create message/attempt/event/audit records, or
change saved routing rules. Sample values are discarded when the request ends.
