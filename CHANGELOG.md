# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Site-level login interception: a new `Authentication::authenticate` hook
  intercepts login attempts on the global (site-level) login form. Site-level
  login always requires OTP, regardless of per-journal configuration.
- Site-wide rules for elevated roles: a Site Administrator now always requires
  OTP, on any journal and at site level. A Journal Manager in any journal of
  the site now always requires OTP on any journal, symmetrically to Site Admin.
  This replaces the previous "Journal Manager of the current journal" rule,
  which produced inconsistent behaviour for managers logging into journals they
  did not manage.
- Cautious default for external users: a user with no roles in the journal they
  are logging into now always receives OTP, regardless of the per-journal
  configuration. Logging into a journal where the user has no roles is treated
  as equivalent to a site-level login.
- Documented opt-out mechanism: to opt a journal out of OTP for ordinary roles,
  a Journal Manager (or Site Administrator) can save the journal's required-roles
  list with no roles selected. The README documents this as the intended
  mechanism, together with its scope (Site Admin, Journal Manager, and external
  users still receive OTP via the rules above).

### Changed
- OTP enforcement logic: switching from "highest-privilege role wins" to OR logic
  (OTP is required if the user has *any* of the selected roles).
- Settings storage: returning to per-journal `plugin_settings` (instead of the
  site-wide `site_settings` used in 1.0.1).
- Plugin architecture: the plugin now registers as a site plugin
  (`isSitePlugin()` returns `true`) but cannot be disabled (`getCanDisable()`
  returns `false`). It runs as an always-active system component;
  administrators and Journal Managers configure required roles per journal,
  but cannot turn the plugin off.
- Configuration scope: settings are now per-journal again. Each journal has
  its own required-roles list, configurable by both the Site Administrator
  (via Hosted Journals) and the journal's Manager (via the journal's Plugins
  panel). There is no longer a single site-wide configuration.
- README structure: the "Role hierarchy" section (introduced in 1.0.1 to
  describe the highest-wins cascade) has been removed; the Configuration
  section now documents the new OR-based rule set with explicit examples and
  a "Known limitation" note for the opt-out behaviour.

### Removed
- Role hierarchy logic ("highest-privilege role wins") and the visual cascade
  in the settings form that disabled lower-privilege checkboxes. Both were
  tied to the 1.0.1 cascade model and are no longer meaningful under OR logic.
- Per-journal enable/disable toggle for the plugin. The plugin is now always
  active; the only configuration is the required-roles list per journal.

## [1.0.1] - 2026-06-08

First publicly documented release. Supersedes 1.0.0, which was patched in place
between 2026-05-29 and 2026-06-08 (the tarball attached to the 1.0.0 release
was iteratively replaced with fixes before a proper version bump).

### Fixed
- Site-level login (no journal context) caused a null pointer that broke the site.
  The plugin is now registered as a site plugin (`isSitePlugin()` returns `true`),
  with role configuration moved from `plugin_settings` to `site_settings` to
  bypass the foreign-key constraint on null context.
- OTP code rendering in the email body: the code is now shown as a standalone
  styled block, making it easier to copy and paste from email clients.
- OTP failure caused by throttle/session mismatch, thanks to [@withanage](https://github.com/withanage).

### Changed
- Role-based OTP determination: introduced a role hierarchy where the user's
  highest-privilege role determines the 2FA policy. If that role is exempt,
  all lower-privilege roles are exempt too. Hierarchy (most to least privileged):
  Site Administrator → Manager → Section/Series Editor → Assistant → Reviewer →
  Author → Reader → Subscription Manager.
- Settings UI: added a visual role hierarchy cascade — selecting a higher-privilege
  role auto-disables (greys out) lower-privilege checkboxes. Visual only; does
  not affect saved state in the database.

### Added
- Brazilian Portuguese (`pt_BR`) translation, thanks to [@lurymorais](https://github.com/lurymorais).
- Italian section in the README, alongside the English version.
- Documentation of the security model in the README (OTP generation via
  `random_int()`/CSPRNG, SHA-256 hashing of the OTP, 10-minute expiration,
  database-persisted failed attempts, sanitisation of post-login redirect URLs).

## [1.0.0] - 2026-05-29

Initial release.

### Added
- Email-based OTP after username/password login, with a 6-digit code sent to
  the user's registered email address.
- Role-based configuration: administrators select which roles must complete
  the OTP step. If no roles are selected, OTP is disabled; if the plugin is
  not configured, OTP is required for all users (fail-safe default).
- Brute-force protection: account locked for 15 minutes after 5 failed OTP
  attempts; failed attempts persist across sessions.
- Flood protection: at most one OTP sent per user per 60 seconds.
- OTP codes generated via `random_int()` (CSPRNG), stored only as a SHA-256
  hash in the session, and expiring after 10 minutes.
- Sanitisation of the post-login redirect URL to prevent open-redirect attacks.
- English (`en`) and Italian (`it`) localizations.

[Unreleased]: https://github.com/archicoop/loginOtp/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/archicoop/loginOtp/compare/v1.0.1...v2.0.0
[1.0.1]: https://github.com/archicoop/loginOtp/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/archicoop/loginOtp/releases/tag/v1.0.0
