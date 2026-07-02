# Contributing

Thanks for taking the time to contribute! This plugin handles identity
verification status — a security-sensitive outcome — so we ask for a bit of
rigour to keep it stable and safe.

## Repo layout

- `facevault-identity-verification/` — the plugin exactly as shipped (this
  directory becomes the installable zip / SVN trunk). No dev tooling inside.
  - `includes/` — core classes (settings, API client, REST controllers,
    user-status state machine, renderer).
  - `woocommerce/` — WooCommerce-only features, loaded only when WooCommerce
    is active.
  - `blocks/verify-button/` — Gutenberg block. Plain `wp.blocks` JavaScript,
    **no JSX, no build step**.
  - `assets/` — front-end and admin JS/CSS. Vanilla, no build step.
- `tests/unit/` — PHPUnit + Brain\Monkey unit tests (no WordPress install
  needed).
- `composer.json` — **dev tooling only**. The shipped plugin has zero runtime
  Composer dependencies, and that is intentional.

## Local development

```bash
composer install
composer lint    # PHPCS (WordPress-Extra ruleset)
composer test    # PHPUnit unit tests
```

For manual testing in a real WordPress ([wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
requires Node 18+ and Docker):

```bash
npx wp-env start   # WordPress + WooCommerce with the plugin mounted
```

Then configure **Settings → FaceVault** with credentials from
[devdash.facevault.id](https://devdash.facevault.id) (free tier). Point
"API base" and "Embed origin" at your own environment if you self-host.

## Pull requests

- One topic per PR.
- **Keep the public contract stable.** The REST routes
  (`/wp-json/facevault/v1/*`), the `facevault_status_changed` action, the
  `facevault_external_user_id` / `facevault_allow_reverify` filters, the
  `_facevault_*` user-meta keys, and the `[facevault_verify]` shortcode are
  integration surfaces — renaming or removing them breaks sites in the wild.
  Adding new hooks or optional attributes is fine.
- **No runtime dependencies and no build step.** The shipped directory must
  work as checked out. Dev tooling goes in root `composer.json`.
- **Never store or log raw verification scores.** The plugin deliberately
  records only the decision band (`verified` / `review` / `rejected`). Tests
  enforce this; do not weaken them.
- Security-sensitive paths (webhook signature verification, the status state
  machine) need unit tests for both the happy path and the abuse path.
- Add a `CHANGELOG.md` entry under `## [Unreleased]`. Bump nothing else —
  cutting a release (moving `[Unreleased]` to a version + tagging) is a
  maintainer step.
- The release workflow publishes the matching `CHANGELOG.md` section verbatim
  as the GitHub release body. Keep a blank line after each `###` heading and
  between bullets, and never wrap the section in a code fence.

## Code style

- PHP: [WordPress coding standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
  via the bundled PHPCS ruleset (`composer lint`). Escape on output, sanitize
  on input, capability-check every admin action.
- JavaScript: vanilla, 4-space indent, single quotes, semicolons. No modern
  syntax that needs transpiling.
- Comments explain *why*, not *what*.

## Reporting a security issue

See [SECURITY.md](SECURITY.md). Please do not open public issues for
security-relevant bugs.

## License

By contributing you agree your contribution will be licensed under the
[GPLv2 or later](LICENSE) — the same as the rest of the project.
