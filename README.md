# Karelia Conversions

**Server-side conversion tracking for Statamic.** When a visitor sends a form, signs up or pays for a Cargo order, Karelia Conversions sends the conversion from your server to the **Meta Conversions API**, **Google Analytics 4** (Measurement Protocol) and the **TikTok Events API**. Ad blockers, Safari's tracking prevention and lost cookies no longer hide those conversions from the ad platforms.

- **Forms without code.** Pick the forms that count as leads in the Control Panel. Email, phone and name fields are found automatically.
- **Sign-ups and Cargo orders.** Registrations send a sign-up. Paid Cargo orders send a purchase with value, currency, order number and products.
- **Click IDs and UTM tags.** `fbclid`, `gclid`, `ttclid`, `msclkid` and `utm_*` from the landing page are kept (after consent) and sent with the conversion, so Meta and TikTok can match it to the ad click.
- **Consent first.** Nothing is sent and nothing is stored until the visitor accepts marketing cookies. Works with any cookie banner.
- **Personal data is hashed** (SHA-256) on your server before it is sent, normalised the way each platform matches it. It is never logged.
- **A log in the Control Panel** shows every conversion, where it came from, its value and each platform's answer, with a dashboard widget for the last 30 days.
- **Test buttons** send a test event to Meta (Test Events), TikTok (test code) and GA4's validation endpoint.
- **No slowdown for visitors.** Conversions are sent after the response, or on your queue.

Requires Statamic 6.20 or later (Core or Pro) and PHP 8.3 or later.

## Installation

```bash
composer require karelialabs/statamic-conversions
```

## Setup

### 1. Secrets go in `.env`

Control Panel settings are saved in `resources/addons` and usually end up in git, so access tokens never go there:

```dotenv
KARELIA_CONVERSIONS_META_ACCESS_TOKEN=...
KARELIA_CONVERSIONS_GA4_API_SECRET=...
KARELIA_CONVERSIONS_TIKTOK_ACCESS_TOKEN=...
```

Where to get them:

- **Meta:** Events Manager > your dataset (Pixel) > Settings > Conversions API > Generate access token.
- **GA4:** Admin > Data streams > your web stream > Measurement Protocol API secrets > Create.
- **TikTok:** TikTok Ads Manager > Events > Web events > your pixel > Settings > Events API > Generate access token.

The IDs (Meta Pixel ID, GA4 Measurement ID, TikTok pixel code) can go in `.env` too (`KARELIA_CONVERSIONS_META_PIXEL_ID`, `KARELIA_CONVERSIONS_GA4_MEASUREMENT_ID`, `KARELIA_CONVERSIONS_TIKTOK_PIXEL_CODE`) or in the settings.

### 2. Settings

Open **Tools > Addons > Karelia Conversions**:

- **Platforms:** switch each platform on or off and add the IDs. Add a test event code while you test.
- **Events:** add the forms that count as conversions, and what to send for each: Lead, Contact, Sign-up or a custom event, with an optional value and currency. Sign-ups and Cargo orders are on by default.
- **Consent:** "Only after consent" (default) or "Always".

### 3. Add the script to your layout

```antlers
<head>
    {{ conversions:script }}
    ...
</head>
```

It keeps UTM tags and click IDs from the landing page in a first-party cookie, but only after consent. It works on statically cached pages too.

### 4. Connect your cookie banner

When the visitor accepts marketing cookies:

```js
KareliaConversions.consent(true);
```

and when they withdraw consent:

```js
KareliaConversions.consent(false);
```

If your banner already writes a cookie, you can use that instead: set **Consent cookie** to its name and **Cookie contains** to the text that means "marketing accepted" (for example `marketing`). For sites that need no consent, choose **Always**.

### 5. Test

- Open **Tools > Conversions** and press **Send test event** on each platform. Meta's and TikTok's test events show up under Test Events in their event managers; GA4 is checked against Google's validation endpoint.
- Or from the command line: `php please conversions:test` (or `conversions:test meta`).
- Then send one of your forms and watch it appear in the log.

Remove the test event codes when you are done.

## Google Ads

Karelia Conversions sends to GA4. To count the conversions in Google Ads, link GA4 and Google Ads, mark the events (`generate_lead`, `sign_up`, `purchase`) as key events in GA4 (Admin > Events), and import them in Google Ads (Goals > Summary > Create conversion action > Google Analytics). See Google's help article "Create conversions from Google Analytics events in Google Ads". Imports can take up to 24 hours.

## Events

| Statamic | Meta | GA4 | TikTok |
|---|---|---|---|
| Form, sent as Lead | `Lead` | `generate_lead` | `Lead` |
| Form, sent as Contact | `Contact` | `generate_lead` | `Contact` |
| Registration | `CompleteRegistration` | `sign_up` | `CompleteRegistration` |
| Paid Cargo order | `Purchase` | `purchase` | `Purchase` |
| Custom event "Book a demo" | `BookADemo` | `book_a_demo` | `BookADemo` |

Every conversion gets one event ID that is the same on all platforms.

## Sending conversions from your own code

```php
use KareliaLabs\Conversions\Conversion;
use KareliaLabs\Conversions\Conversions;

app(Conversions::class)->track(
    Conversion::make('lead')
        ->fromRequest(request())
        ->user(['email' => $email, 'phone' => $phone, 'first_name' => $first, 'last_name' => $last])
        ->value(120, 'EUR')
        ->source('booking', 'demo', $booking->id, 'Demo booking')
);
```

`fromRequest()` fills in the page, IP address, browser, click IDs and consent. Events are `lead`, `contact`, `sign_up`, `purchase` and `custom` (with a name: `Conversion::make('custom', 'Book a demo')`).

To decide consent in code instead of with a cookie:

```php
\KareliaLabs\Conversions\Consent::resolveUsing(fn ($request) => $request->user()?->get('marketing_consent') === true);
```

## Cargo orders

Payment is often confirmed by a webhook from the payment provider, so the request that marks an order paid is not the shopper's. Karelia Conversions therefore keeps the shopper's details (IP address, browser, click IDs, consent) when the order is created during checkout, and uses them when the order is paid. Amounts are converted from Cargo's minor units, and the currency comes from the site.

## What is sent, and what is kept

- **Sent:** the event, its time and ID, the page address, the visitor's IP address and browser, the click IDs and first-party cookies the platforms set themselves (`_fbp`, `_fbc`, `_ga`, `_ttp`), value, currency and products, and email, phone, name, city, postcode and country **hashed with SHA-256**.
- **Not sent:** anything for visitors who have not given consent (in "Only after consent" mode).
- **Kept on your server:** a log line per conversion in `storage/statamic/karelia-conversions`, with the event, source, value, UTM source and campaign, click ID type and each platform's answer. No names, emails, phone numbers or IP addresses. Lines older than 30 days are deleted (configurable).
- **Never sent to Karelia Labs.** The addon has no telemetry and no licence server.

You remain the data controller for what you send to Meta, Google and TikTok. Mention it in your privacy policy.

## Configuration

Publish the config file to change the defaults:

```bash
php artisan vendor:publish --tag=statamic-conversions-config
```

| Setting | Default | What it does |
|---|---|---|
| `KARELIA_CONVERSIONS_ENABLED` | `true` | Switch all sending off, e.g. on staging |
| `KARELIA_CONVERSIONS_QUEUE` | none | Queue connection. Without one, conversions are sent right after the response |
| `KARELIA_CONVERSIONS_CONSENT` | `cookie` | `cookie` or `always` |
| `KARELIA_CONVERSIONS_CURRENCY` | `USD` | Default currency |
| `KARELIA_CONVERSIONS_GA4_ENDPOINT` | `https://www.google-analytics.com` | Use `https://region1.google-analytics.com` to keep collection in the EU |
| `KARELIA_CONVERSIONS_META_API_VERSION` | `v26.0` | Graph API version |
| `log_days` | `30` | How long the log is kept |

## Permissions

- **View conversions:** see the log and the totals.
- **Send test events:** use the test buttons.

## Good to know

- Karelia Conversions does not add the Meta Pixel, the GA4 tag or the TikTok pixel to your pages. If a form also fires a `Lead` in the browser Pixel, Meta counts both; keep form leads on the server only.
- GA4 ties the conversion to the visitor's session through the `_ga` cookie. Without the GA4 tag on your site, server-side events still arrive, but as separate users.
- The Measurement Protocol never reports errors on the live endpoint; use the test button (validation endpoint) to check your setup.

## Support

support@karelialabs.atlassian.net · Documentation: https://ziroxonline111.github.io/karelia-labs/products/karelia-conversions/docs/

## Licence

Commercial. A licence from the Statamic Marketplace covers one production site; development and staging copies are free. See [LICENSE.md](LICENSE.md).
