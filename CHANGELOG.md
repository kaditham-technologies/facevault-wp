# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
