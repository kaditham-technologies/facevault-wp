# Security Policy

## Reporting a vulnerability

If you believe you have found a security issue in this plugin or the FaceVault
services it depends on (the widget-session endpoint, the hosted verification
page, webhook signing), please **do not open a public GitHub issue**.

Instead, email **security@facevault.id** with:

- A description of the issue and its impact.
- Reproduction steps or a proof-of-concept.
- Affected version (the plugin version from the Plugins screen or release tag).
- Whether you have already disclosed the issue elsewhere.

We will acknowledge receipt within **3 business days** and aim to ship a fix
within **30 days** for high-severity issues. We will credit you in the release
notes unless you ask to remain anonymous.

## Scope

In scope:

- The plugin's REST endpoints — webhook signature verification
  (`/wp-json/facevault/v1/webhook`), token minting, and status refresh.
- The user verification-status state machine (privilege or status escalation,
  e.g. becoming "verified" without completing a verification).
- Handling of the API key and webhook secret inside the plugin.
- The `POST /api/v1/widget_sessions` endpoint that mints the single-use
  `widget_token`, and the hosted verification widget (`app.facevault.id`).

Out of scope:

- Site-operator misconfiguration (e.g. publishing the API key, running the
  site over plain HTTP, or granting untrusted users admin capabilities —
  WordPress admins can always read stored options).
- Vulnerabilities in WordPress core, WooCommerce, or other plugins.
- DoS / volumetric attacks — the FaceVault endpoints are rate-limited at the
  edge.
- Theoretical issues without a demonstrated impact path.

## Supply chain

- The shipped plugin has **zero runtime dependencies** — no Composer packages,
  no bundled JS libraries, no build step. The only external code is FaceVault's
  own `embed.js`, loaded from the FaceVault origin configured in settings.
- All GitHub Actions used in CI and the release workflow are SHA-pinned;
  comments record the human-readable version next to each SHA so bumps stay
  reviewable. `dependabot.yml` watches the pins for updates.
- Release assets include an unsigned `SHA256SUMS.txt`. We are evaluating
  sigstore signing for a future release.
