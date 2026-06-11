# Login OTP via Email — OMP / OJS Plugin

A Generic Plugin for [Open Monograph Press (OMP)](https://pkp.sfu.ca/omp/) and [Open Journal Systems (OJS)](https://pkp.sfu.ca/ojs/) 3.5.x that adds two-factor authentication to the login process by sending a one-time code (OTP) via email.

No additional app required. Compliant with Italian ACN Guidelines for Public Administrations and GDPR.

Developed by **[Archimede Informatica](https://www.archimede.net/)**.

> 🇮🇹 [Versione italiana](#versione-italiana) più in basso.

---

## Features

- **Email OTP**: after a successful username/password login, a 6-digit one-time code is sent to the user's registered email address
- **Role-based configuration**: administrators can choose which roles must use 2FA (site admin, manager, editor, reviewer, author, etc.) — or disable it entirely
- **Role hierarchy**: the user's highest-privilege role determines the 2FA policy — if that role is exempt, all lower roles are exempt too
- **Brute-force protection**: account locked for 15 minutes after 5 failed attempts
- **Flood protection**: at most one OTP sent per user per 60 seconds
- **Secure by design**: only a SHA-256 hash of the OTP is stored (in session), never the plain code; OTP generated via CSPRNG (`random_int`)
- **No extra dependencies**: uses the existing email infrastructure and database of OMP/OJS

---

## Compatibility

| Plugin version | OMP   | OJS   |
|----------------|-------|-------|
| 1.0.1          | 3.5.x | 3.5.x |

---

## Requirements

- OMP 3.5.x or OJS 3.5.x
- PHP 8.1+
- A working email configuration (`config.inc.php`, `[email]` section)

---

## Installation

### Manual installation via plugin/generic folder

1. Download the latest release `.tar.gz` from the [Releases](../../releases) page
2. Extract it into `plugins/generic/loginOtp/` inside your OMP or OJS installation
3. In OMP/OJS, go to **Settings → Website → Plugins → Generic Plugins**
4. Find **Login OTP via Email** and click **Enable**

### Manual installation via upload in plugin page

1. Download the latest release `.tar.gz` from the [Releases](../../releases) page
2. In OMP/OJS, go to **Settings → Website → Plugins → Upload a New Plugin**
3. Browse to the plugin `.tar.gz` file and install the plugin
4. Find **Login OTP via Email** and click **Enable**

---

## Configuration

After enabling the plugin, click **Settings** to choose which roles must complete the 2FA step.

| Setting | Behaviour |
|---------|-----------|
| One or more roles selected | Only users whose highest-privilege role is selected are required to verify via email |
| No roles selected | 2FA is disabled for all users |
| Never configured (default) | 2FA is required for all users |

### Role hierarchy

The plugin evaluates the user's **highest-privilege role** to determine whether 2FA is required. The hierarchy, from most to least privileged, is:

Site Administrator → Manager → Section/Series Editor → Assistant → Reviewer → Author → Reader → Subscription Manager

**Example**: a user who is both Site Administrator and Author — if Site Administrator is unchecked (exempt), that user will **not** be asked for OTP, even though Author is checked. The highest role wins.

### Email configuration

Make sure OMP/OJS is configured to send emails. See the `[email]` section in `config.inc.php`. The plugin uses whatever transport is already configured (SMTP, sendmail, etc.).

---

## Security notes

- OTP codes are generated using `random_int()` (CSPRNG — Cryptographically Secure Pseudo-Random Number Generator)
- OTP codes expire after **10 minutes**
- Only the SHA-256 hash of the OTP is stored in the session — the plain code is never persisted
- Failed attempts are tracked in the database and persist across sessions
- The lockout resets automatically after 15 minutes
- The redirect URL after login is sanitised to prevent open-redirect attacks

> **Limitation**: the security of email OTP depends on the security of the user's email account. For systems requiring higher assurance, consider a TOTP-based solution.

---

## Running tests (for contributors)

```bash
php lib/pkp/lib/vendor/bin/phpunit \
    --configuration lib/pkp/tests/phpunit.xml \
    plugins/generic/loginOtp/tests/
```

---

## License

This plugin is released under the [GNU General Public License v3.0](LICENSE).

Copyright (c) 2026 [Archimede Informatica](https://www.archimede.net/)

---

## Credits

Developed by **Archimede Informatica**.

Contributions and bug reports are welcome via [GitHub Issues](../../issues).

---
---

# Versione italiana

## Login OTP via Email — Plugin per OMP / OJS

Plugin generico per [Open Monograph Press (OMP)](https://pkp.sfu.ca/omp/) e [Open Journal Systems (OJS)](https://pkp.sfu.ca/ojs/) 3.5.x che aggiunge l'autenticazione a due fattori al processo di login tramite codice OTP inviato via email.

Nessuna app aggiuntiva richiesta. Conforme alle Linee Guida ACN per le Pubbliche Amministrazioni e al GDPR.

Sviluppato da **[Archimede Informatica](https://www.archimede.net/)**.

---

## Funzionalità

- **OTP via email**: dopo un login con username/password corretto, un codice monouso a 6 cifre viene inviato all'indirizzo email registrato dell'utente
- **Configurazione per ruolo**: gli amministratori possono scegliere quali ruoli devono usare la 2FA (amministratore del sito, gestore, editor, revisore, autore, ecc.) — oppure disabilitarla completamente
- **Gerarchia dei ruoli**: il ruolo più elevato dell'utente determina la policy 2FA — se quel ruolo è esente, anche tutti i ruoli inferiori lo sono
- **Protezione brute-force**: account bloccato per 15 minuti dopo 5 tentativi falliti
- **Protezione flood**: al massimo un OTP inviato per utente ogni 60 secondi
- **Sicuro by design**: solo l'hash SHA-256 dell'OTP è memorizzato (in sessione), mai il codice in chiaro; OTP generato tramite CSPRNG (`random_int`)
- **Nessuna dipendenza esterna**: usa l'infrastruttura email e il database già configurati in OMP/OJS

---

## Compatibilità

| Versione plugin | OMP   | OJS   |
|-----------------|-------|-------|
| 1.0.1           | 3.5.x | 3.5.x |

---

## Requisiti

- OMP 3.5.x o OJS 3.5.x
- PHP 8.1+
- Configurazione email funzionante (`config.inc.php`, sezione `[email]`)

---

## Installazione

### Installazione manuale

1. Scarica il file `.tar.gz` dell'ultima release dalla pagina [Releases](../../releases)
2. Estrai il contenuto in `plugins/generic/loginOtp/` nella tua installazione OMP o OJS
3. In OMP/OJS, vai a **Impostazioni → Sito web → Plugin → Plugin generici**
4. Trova **Login OTP via Email** e clicca **Abilita**

### Installazione manuale con **Upload** dalla pagina Plugins
1. Scarica il file `.tar.gz` dell'ultima release dalla pagina [Releases](../../releases)
2. Estrai il contenuto in `plugins/generic/loginOtp/` nella tua installazione OMP o OJS
3. Seleziona il file `.tar.gz` scaricato e procedere con l'installazione
4. Trova **Login OTP via Email** e clicca **Abilita**

---

## Configurazione

Dopo aver abilitato il plugin, clicca **Impostazioni** per scegliere quali ruoli devono completare il passaggio 2FA.

| Impostazione | Comportamento |
|--------------|---------------|
| Uno o più ruoli selezionati | Solo gli utenti il cui ruolo più elevato è selezionato devono verificare via email |
| Nessun ruolo selezionato | La 2FA è disabilitata per tutti gli utenti |
| Mai configurato (default) | La 2FA è obbligatoria per tutti gli utenti |

### Gerarchia dei ruoli

Il plugin valuta il **ruolo più elevato** dell'utente per determinare se la 2FA è necessaria. La gerarchia, dal più al meno privilegiato, è:

Amministratore del sito → Gestore → Editor di sezione/serie → Assistente → Revisore → Autore → Lettore → Gestore abbonamenti

**Esempio**: un utente che è sia Amministratore del sito che Autore — se Amministratore del sito è deselezionato (esente), quell'utente **non** riceverà la richiesta OTP, anche se Autore è selezionato. Il ruolo più elevato prevale.

### Configurazione email

Assicurati che OMP/OJS sia configurato per l'invio email. Vedi la sezione `[email]` in `config.inc.php`. Il plugin usa il trasporto già configurato (SMTP, sendmail, ecc.).

---

## Note di sicurezza

- I codici OTP sono generati tramite `random_int()` (CSPRNG — Generatore di numeri pseudocasuali crittograficamente sicuro)
- I codici OTP scadono dopo **10 minuti**
- Solo l'hash SHA-256 dell'OTP è memorizzato nella sessione — il codice in chiaro non è mai persistito
- I tentativi falliti sono tracciati nel database e persistono tra le sessioni
- Il blocco si resetta automaticamente dopo 15 minuti
- L'URL di redirect dopo il login è sanificato per prevenire attacchi open-redirect

> **Limitazione**: la sicurezza dell'OTP via email dipende dalla sicurezza dell'account email dell'utente. Per sistemi che richiedono un livello di garanzia superiore, considerare una soluzione basata su TOTP.

---

## Esecuzione dei test (per contributori)

```bash
php lib/pkp/lib/vendor/bin/phpunit \
    --configuration lib/pkp/tests/phpunit.xml \
    plugins/generic/loginOtp/tests/
```

---

## Licenza

Questo plugin è rilasciato sotto [GNU General Public License v3.0](LICENSE).

Copyright (c) 2026 [Archimede Informatica](https://www.archimede.net/)

---

## Crediti

Sviluppato da **Archimede Informatica**.

Contributi e segnalazioni di bug sono benvenuti tramite [GitHub Issues](../../issues).
