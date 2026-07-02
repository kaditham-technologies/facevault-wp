<!-- Thanks for contributing! Please keep PRs to one topic. -->

## What & why

<!-- What does this change and why? Link any related issue. -->

## Checklist

- [ ] One topic per PR.
- [ ] Public contract unchanged (REST routes, `facevault_*` hooks/filters, `_facevault_*` meta keys, shortcode), or the break is called out above and justified.
- [ ] No runtime dependencies added to the shipped plugin; no build step introduced.
- [ ] No raw verification scores stored, logged, or displayed.
- [ ] Local checks pass: `composer lint` and `composer test`.
- [ ] `CHANGELOG.md` updated under `## [Unreleased]` (if user-facing).
