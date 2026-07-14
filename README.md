# FaceVault Identity Verification for WordPress & WooCommerce

Official WordPress plugin for [FaceVault](https://facevault.id) — AI-powered
identity verification (ID document + selfie + liveness). Let customers verify
who they are without their documents ever touching your server.

- Verify button anywhere: shortcode, Gutenberg block, or the WooCommerce
  **My Account → Identity Verification** tab.
- Verification runs in FaceVault's hosted widget (camera, document capture,
  liveness) — no camera code, no PII storage on your site.
- Signed webhooks flip the customer's verification status automatically;
  a polling fallback covers hosts that can't receive webhooks.
- Built for WooCommerce age-restricted and high-value stores; checkout
  gating ships in an upcoming release.

## How it works

```
Shopper clicks "Verify my identity"
        │
Your WordPress server mints a single-use widget token
(POST /widget_sessions — your API key never reaches the browser)
        │
FaceVault's hosted widget opens in a modal (embed.js)
and the shopper completes ID + selfie + liveness
        │
FaceVault POSTs a signed webhook to /wp-json/facevault/v1/webhook
        │
The plugin verifies the HMAC signature and stores the outcome
on the WordPress user (verified / pending review / not verified)
```

The browser `complete` event updates the UI optimistically; the **webhook is
the source of truth**. Documents and selfies are processed and stored by
FaceVault, never by your WordPress site.

## Requirements

- WordPress 6.3+, PHP 7.4+.
- A [FaceVault account](https://devdash.facevault.id) (free tier: 50
  verifications/month) with a Hosted Verification site.
- HTTPS on your site to receive webhooks (the polling fallback works
  without it).
- WooCommerce is optional — the core verify button and webhook work on any
  WordPress site.

## Install

Until the plugin is listed in the wordpress.org directory:

1. Download the latest `facevault-identity-verification-x.y.z.zip` from
   [Releases](https://github.com/kaditham-technologies/facevault-wp/releases).
2. WordPress admin → Plugins → Add New → Upload Plugin.
3. Activate, then open **Settings → FaceVault** and follow the setup
   checklist (paste your Site ID, API key, and webhook signing secret; copy
   the webhook URL into your FaceVault dashboard).

## Usage

Add the verify button to any page:

```
[facevault_verify]
```

or insert the **FaceVault Verify Button** block in the editor. Optional
shortcode attributes: `label="Verify my age"` and `redirect="/thanks/"`
(same-origin only).

With WooCommerce active, customers also get a **My Account → Identity
Verification** tab showing their status.

### WooCommerce checkout gating

Mark what needs a verified customer:

- **Per product** — "Requires identity verification" checkbox in the
  product's General panel.
- **Per category** — the same flag on a product category (child categories
  inherit it).
- **Everything** — "Gate all purchases" in Settings → FaceVault.

Unverified customers can't place a gated order: the classic checkout shows a
notice with the verify button and rejects submission server-side; the
Checkout Block (and any headless Store API client) gets a hard block at the
Store API, with shoppers routed to the My Account verification tab. Guests
are asked to log in or create an account first — verification always binds
to a WordPress account.

Every gated order records the customer's verification status, session
reference, and verification date as order meta (HPOS-compatible), shown in
an **Identity verification** panel on the admin order screen. Customers whose
verification is pending human review can either be blocked, or allowed
through with the order held automatically until the approval webhook arrives
(default; see Settings → FaceVault).

Admins get an **Identity** column on the Users list and a manual
verify/unverify override on each user's profile screen; overrides are
recorded in the user's verification history with the acting admin's ID.

Boundaries worth knowing: verification binds identity to the WP account, not
to the person clicking "place order"; the order-pay page for pre-created
orders and admin-created orders bypass checkout and are not gated; the gate
itself never calls the FaceVault API during order placement, so an API
outage can never block a verified customer.

### Hooks for developers

- `facevault_status_changed( $user_id, $new_status, $old_status, $source )` —
  fires on every applied status change (`verified`, `review`, `rejected`, …).
- `facevault_external_user_id` — filter the ID sent to FaceVault. Defaults to
  an opaque per-user random reference stored in user meta — never the raw WP
  user id, whose sequential values would make verification status enumerable
  through FaceVault's public status poll.

## External service disclosure

This plugin sends data to FaceVault (facevault.id) to perform identity
verification: the widget loads from `app.facevault.id`, and your server calls
`api.facevault.id` to create verification sessions and check status. Identity
documents and selfies are captured inside FaceVault's hosted widget and are
processed by FaceVault — they are never uploaded to your WordPress site. See
FaceVault's [terms](https://facevault.id/terms) and
[privacy policy](https://facevault.id/privacy).

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md). Short version:

```bash
composer install
composer lint   # PHPCS, WordPress-Extra ruleset
composer test   # PHPUnit unit tests
npx wp-env start  # local WordPress + WooCommerce for manual testing
```

## License

[GPLv2 or later](LICENSE). The plugin talks to the FaceVault hosted service,
which is a commercial product with a free tier.
