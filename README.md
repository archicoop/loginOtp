# Two-Factor Authentication via Email (2FA) — OMP / OJS Plugin

A Generic Plugin for [Open Monograph Press (OMP)](https://pkp.sfu.ca/omp/) and [Open Journal Systems (OJS)](https://pkp.sfu.ca/ojs/) 3.5.x that adds two-factor authentication to the login process by sending a one-time code (OTP) via email.

No additional app required. Compliant with Italian ACN Guidelines for Public Administrations and GDPR.

Developed by **[Archimede Informatica](https://www.archimede.net/)**.

---

## Features

- **Email OTP**: after a successful username/password login, a 6-digit one-time code is sent to the user's registered email address
- **Role-based configuration**: administrators can choose which roles must use 2FA (site admin, manager, editor, reviewer, author, etc.) — or disable it entirely
- **Brute-force protection**: account locked for 15 minutes after 5 failed attempts
- **Flood protection**: at most one OTP sent per user per 60 seconds
- **Secure by design**: only a SHA-256 hash of the OTP is stored (in session), never the plain code
- **No extra dependencies**: uses the existing email infrastructure and database of OMP/OJS

---

## Compatibility

| Plugin version | OMP | OJS |
|---|---|---|
| 1.0.0 | 3.5.x | 3.5.x |

---

## Requirements

- OMP 3.5.x or OJS 3.5.x
- PHP 8.1+
- A working email configuration (`config.inc.php`, `[email]` section)

---

## Installation

### Via Plugin Gallery (recommended)

1. In OMP/OJS, go to **Settings → Website → Plugins → Plugin Gallery**
2. Find **Two-Factor Authentication via Email** and click **Install**

### Manual installation

1. Download the latest release `.tar.gz` from the [Releases](../../releases) page
2. Extract it into `plugins/generic/twoFactorAuth/` inside your OMP or OJS installation
3. In OMP/OJS, go to **Settings → Website → Plugins → Generic Plugins**
4. Find **Two-Factor Authentication via Email** and click **Enable**

---

## Configuration

After enabling the plugin, click **Settings** to choose which roles must complete the 2FA step.

| Setting | Behaviour |
|---|---|
| One or more roles selected | Only users with those roles are required to verify via email |
| No roles selected | 2FA is disabled for all users |
| Never configured (default) | 2FA is required for all users |

**Email configuration** — make sure OMP/OJS is configured to send emails. See the `[email]` section in `config.inc.php`. The plugin uses whatever transport is already configured (SMTP, sendmail, etc.).

---

## Security notes

- OTP codes expire after **10 minutes**
- Failed attempts are tracked in the database and persist across sessions
- The lockout resets automatically after 15 minutes
- The redirect URL after login is sanitised to prevent open-redirect attacks

> **Limitation**: the security of email OTP depends on the security of the user's email account. For systems requiring higher assurance, consider a TOTP-based solution.

---

## License

This plugin is released under the [GNU General Public License v3.0](LICENSE).

Copyright (c) 2026 [Archimede Informatica](https://www.archimede.net/)

---

## Credits

Developed by **Archimede Informatica**.

Contributions and bug reports are welcome via [GitHub Issues](../../issues).
