# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Work in progress for the next major release (2.0.0).

### Changed
- OTP enforcement logic: switching from "highest-privilege role wins" to OR logic
  (OTP is required if the user has *any* of the selected roles).
- Settings storage: returning to per-journal `plugin_settings` (instead of the
  site-wide `site_settings` used in 1.0.1).

### Added
- Warning in the settings form when the Reviewer role is selected and the journal
  has `reviewerAccessKeysEnabled` set to true (informing administrators about the
  interaction with one-click reviewer access).

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

[Unreleased]: https://github.com/archicoop/loginOtp/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/archicoop/loginOtp/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/archicoop/loginOtp/releases/tag/v1.0.0
