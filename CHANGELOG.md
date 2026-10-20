# Changelog

## 1.0.1 (2026-10-20)

- Consent from a banner's own cookie is checked exactly, never as "contains": a key and value such as `marketing:true`, or the exact cookie value. A refusal such as `marketing:false` or `advertisement:no` can no longer count as consent.
- Ready-made consent rules for Cookiebot, CookieYes and Complianz.
- Cargo orders: the shopper's IP address, browser and click IDs are only kept between checkout and payment when they have given consent.
- The documentation now says exactly what is hashed: Google Analytics 4 receives city, postcode and country in plain text, as its API requires.

## 1.0.0 (2026-10-20)

First release.

- Server-side conversions to the Meta Conversions API, Google Analytics 4 (Measurement Protocol) and the TikTok Events API.
- Forms chosen in the Control Panel send Lead, Contact, Sign-up or a custom event, with optional value and currency.
- Sign-ups from `user:register_form`, and paid Cargo orders as purchases with products.
- UTM tags and ad click IDs kept after consent with `{{ conversions:script }}`, also on statically cached pages.
- Consent with `KareliaConversions.consent(true)`, your banner's own cookie, or "always".
- Personal data hashed with SHA-256 on the server; nothing personal in the log.
- Control Panel log, totals per day, source and event, dashboard widget, test buttons and `conversions:test`.
