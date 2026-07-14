=== FaceVault Identity Verification ===
Contributors: facevault
Tags: identity verification, kyc, age verification, woocommerce, biometrics
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Identity verification (ID document + selfie + liveness) for WordPress and WooCommerce. Documents never touch your server.

== Description ==

Official FaceVault plugin. Customers verify their identity in FaceVault's
hosted widget; the signed webhook flips their verification status on your
site. Add the verify button with the `[facevault_verify]` shortcode, the
FaceVault Verify Button block, or the WooCommerce My Account tab.

This plugin connects to the FaceVault service (facevault.id): the widget is
loaded from app.facevault.id and your server calls api.facevault.id to create
verification sessions and check status. Identity documents and selfies are
captured inside FaceVault's hosted widget and processed by FaceVault — they
are never uploaded to your WordPress site. Terms: https://facevault.id/terms
Privacy: https://facevault.id/privacy

(Full directory listing copy, FAQ, and screenshots land before the
wordpress.org submission.)

== Changelog ==

= 0.1.1 =
* Security hardening: the default external user reference sent to FaceVault
  is now an opaque per-user random value instead of the raw WP user id, so
  verification status can no longer be enumerated from sequential ids. Sites
  using the facevault_external_user_id filter are unaffected.

= 0.1.0 =
* Initial release: settings page, verify button (shortcode + block),
  HMAC-verified webhook receiver, WooCommerce My Account tab, status-poll
  fallback.
