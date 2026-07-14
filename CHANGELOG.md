# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- WooCommerce checkout gating: flag individual products, product categories
  (children inherit), or every purchase (settings toggle). Unverified
  customers are blocked server-side on the classic checkout, the Checkout
  Block, and the Store API (headless included); guests on a gated cart are
  asked to log in or create an account.
- Order stamping: the customer's verification status, session reference, and
  verification date are written to the order (HPOS-compatible) and shown in
  an Identity verification panel on the admin order screen.
- Review handling: orders from customers whose verification is pending human
  review are placed on hold with an order note and released automatically
  when the approval webhook arrives (configurable to block checkout instead).
- Users-list Identity column and a manual verify/unverify override on the
  user profile screen, recorded in the verification history with the acting
  admin's ID.

### Notes

- The gate never calls the FaceVault API during order placement — decisions
  are local meta reads, so an API outage cannot block a verified customer.
- Out of scope for the gate: the order-pay page for pre-created orders and
  admin-created orders (they bypass checkout), and guest verification.

## [0.1.1] - 2026-07-14

### Changed

- The default `external_user_id` sent to FaceVault is now an opaque per-user
  random reference stored in user meta instead of the raw WP user id, whose
  sequential values made verification-status enumeration possible through
  FaceVault's public status poll. Sites overriding the
  `facevault_external_user_id` filter are unaffected, and webhooks for
  sessions minted by 0.1.0 (which echo the raw id) still apply.
- Documented that the webhook receiver's malformed-JSON guard only fires for
  non-JSON content types — WordPress rejects malformed `application/json`
  bodies before dispatch.

## [0.1.0] - 2026-07-02

### Added

- Initial plugin: settings page with setup checklist and connection test,
  verify button (shortcode + Gutenberg block), HMAC-verified webhook
  receiver, WooCommerce My Account "Identity Verification" tab, and a
  status-poll fallback for hosts that cannot receive webhooks.
