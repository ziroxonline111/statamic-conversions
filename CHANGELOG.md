# Changelog

## 1.0.0 (2026-10-20)

First release.

- Server-side conversions to the Meta Conversions API, Google Analytics 4 (Measurement Protocol) and the TikTok Events API.
- Forms chosen in the Control Panel send Lead, Contact, Sign-up or a custom event, with optional value and currency.
- Sign-ups from `user:register_form`, and paid Cargo orders as purchases with products.
- UTM tags and ad click IDs kept after consent with `{{ conversions:script }}`, also on statically cached pages.
- Consent with `KareliaConversions.consent(true)`, your banner's own cookie, or "always".
- Personal data hashed with SHA-256 on the server; nothing personal in the log.
- Control Panel log, totals per day, source and event, dashboard widget, test buttons and `conversions:test`.
