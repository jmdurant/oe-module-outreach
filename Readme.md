# oe-module-outreach

Patient outreach platform for OpenEMR — multi-channel reminder, confirmation, and notification dispatch with central tracking, rate-limit, opt-out, and audit.

## What it does

Concerns from other modules plug in via an `OutreachConcernRegistryEvent` fired at boot. Each concern is a small class implementing `OutreachConcern`:

- **Appointment confirmation** (oe-module-online-booking) — Y/N reminder before scheduled appointments
- **Post-visit superbill** (oe-module-prepayment) — visit-documented notification after encounter sign
- **Copay reminder** (oe-module-prepayment) — pre-visit balance prompt
- **Unpaid statement** (oe-module-portal-messaging-v2) — post-visit billing cadence
- **Pre-visit questionnaires** (oe-module-portal-messaging-v2) — intake form reminders
- **Post-visit follow-up forms** (oe-module-portal-messaging-v2) — follow-up assessments

Each concern's home module owns its domain knowledge (the candidate query). The outreach module owns dispatch, tracking, rate limiting, opt-outs, and audit.

## Channels

- **SMS** via OpenEMR's `AppDispatch` (whatever provider the practice has wired — Doximity, Google Voice, RingCentral, etc.)
- **Email** via `MyMailer`
- **Firebase push** via webhook (same pattern as `oe-module-prepayment` `EncounterWebhookController`)

Channel preference is per-patient, per-concern, then practice-default — first dispatcher that can reach the patient wins.

## Tables

| Table | Purpose |
|---|---|
| `module_outreach_messages` | Audit + state for every dispatched message |
| `module_outreach_concerns_config` | Per-concern practice overrides (cadence, channels, rate limits) |
| `module_outreach_patient_prefs` | Per-patient opt-out, channel preferences, quiet hours |
| `module_outreach_rate_limits` | Cached daily counters for fast rate-limit checks |

## Architecture

```
                 ┌──────────────────────────────┐
                 │  PatientOutreachService      │
                 │  ─ registry walk             │
                 │  ─ sweep (one or all)        │
                 │  ─ dispatch (multi-channel)  │
                 │  ─ rate limit + opt-out      │
                 │  ─ lookup by phone           │
                 │  ─ handle reply              │
                 │  ─ expire pending            │
                 └─────────┬────────────────────┘
                           │
        ┌──────────────────┼──────────────────────┐
        │                  │                      │
   Concerns          Channel adapters       Tracking tables
   (registered       (SMS/Email/Push)       (4 tables)
    via event)
```

## Status

v0.1.0 — foundation in place. REST + MCP surface and concern migrations land in subsequent commits.

## License

GPL-3.0
