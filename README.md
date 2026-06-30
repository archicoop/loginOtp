# Login OTP via Email — OMP / OJS Plugin

A Generic Plugin for [Open Monograph Press (OMP)](https://pkp.sfu.ca/omp/) and [Open Journal Systems (OJS)](https://pkp.sfu.ca/ojs/) 3.5.x that adds two-factor authentication to the login process by sending a one-time code (OTP) via email.

No additional app required. Compliant with Italian ACN Guidelines for Public Administrations and GDPR.

Developed by **[Archimede Informatica](https://www.archimede.net/)**.

> 🇮🇹 [Versione italiana](#versione-italiana) più in basso.

---

## Features

- **Email OTP**: after a successful username/password login, a 6-digit one-time code is sent to the user's registered email address
- **Per-journal configuration**: each journal independently decides which roles must complete the 2FA step on that journal
- **Site-wide protection**: site-level login (the global login form) always requires OTP, regardless of per-journal settings
- **Always-active design**: the plugin runs as a system component — it cannot be disabled, only configured. To opt a journal out, leave its required-roles list empty (see Configuration for the caveat)
- **Brute-force protection**: account locked for 15 minutes after 5 failed attempts
- **Flood protection**: at most one OTP sent per user per 60 seconds
- **Secure by design**: only a SHA-256 hash of the OTP is stored (in session), never the plain code; OTP generated via CSPRNG (`random_int`)
- **No extra dependencies**: uses the existing email infrastructure and database of OMP/OJS

---

## Compatibility

| Plugin version | OMP   | OJS   |
|----------------|-------|-------|
| 2.0.0          | 3.5.x | 3.5.x |

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
4. The plugin appears in the list as enabled (it cannot be disabled — see Configuration)

### Manual installation via upload in plugin page

1. Download the latest release `.tar.gz` from the [Releases](../../releases) page
2. In OMP/OJS, go to **Settings → Website → Plugins → Upload a New Plugin**
3. Browse to the plugin `.tar.gz` file and install the plugin

After installation, the plugin is active across the whole site. Configure each journal individually via its **Settings** action.

---

## Configuration

### Where to configure

The plugin exposes a **Settings** action on two panels:

- **Site Admin → Hosted Journals → (any journal) → Plugins**: configure that journal's required roles
- **Journal Manager → Website → Plugins**: same form, scoped to the manager's journal

Both Site Admins and Journal Managers can change a journal's configuration. There is no site-wide configuration — every journal is configured independently.

The enable/disable checkbox is **read-only and always on**: the plugin is an always-active system component. To opt a journal out of OTP for normal users, save the form with **no roles selected** (see the limitation below).

### What you configure

The settings form is a checklist of roles. For each journal, you select which roles must complete the OTP step when logging in **on that journal**.

| Setting | Effect on that journal's login |
|---------|--------------------------------|
| One or more roles selected | A user with at least one of the selected roles in that journal must verify via OTP |
| No roles selected | Most users skip OTP on that journal — but several site-wide rules still force OTP for elevated or external users (see Rules) |
| Never configured | OTP required for all users (fail-safe default; in normal use this state is unreachable because the plugin always writes an initial configuration) |

### Rules

OTP is required on login if **any** of the following rules apply, evaluated in order. The first match wins.

1. **Site-level login** — login from the global form (no journal context) → OTP.
2. **Site Administrator** — user holds the Site Admin role → OTP.
3. **Journal Manager anywhere** — user holds the Journal Manager role in any journal of the site (not just the one being logged into) → OTP.
4. **No roles in the current journal** — user has no roles at all in the journal being logged into → OTP. The login is treated as "external" to that journal.
5. **Settings unconfigured for the current journal** — defensive fallback, in practice unreachable.
6. **Role match** — at least one of the user's roles in the current journal is in the journal's required-roles list → OTP. Otherwise, no OTP.

Rules 2 and 3 are site-wide: once a user holds Site Admin or Journal Manager in any journal, OTP follows them everywhere for the whole site. Rules 4–6 are evaluated against the specific journal being logged into.

### Examples

Assume two journals, `Journal A` and `Journal B`.

| User | Roles | Logs in on | Outcome |
|---|---|---|---|
| `alice` | Site Admin | any journal, or site-level | OTP (Rule 2) |
| `bob` | Journal Manager on A | A | OTP (Rule 3) |
| `bob` | Journal Manager on A | B (where bob has no roles) | OTP (Rule 3 wins; Rule 4 would also have caught it) |
| `carol` | Author on A | A, with required-roles `[Author]` | OTP (Rule 6) |
| `carol` | Author on A | A, with required-roles `[Reviewer]` | no OTP (Rule 6, no match) |
| `carol` | Author on A | B (no roles) | OTP (Rule 4) |
| `dave` | no roles anywhere | A | OTP (Rule 4) |
| `dave` | no roles anywhere | site-level | OTP (Rule 1) |

### Known limitation — empty required-roles list

If a Journal Manager saves an empty required-roles list on a journal, users **with roles on that journal that are not Site Admin and not Journal Manager elsewhere** will log in without OTP on that journal. This is by design — it is the mechanism for opting a journal out of OTP for ordinary roles. It is the site administrator's and Journal Manager's responsibility to configure each journal appropriately.

Note that Rules 1–4 still cover the most sensitive cases regardless of the per-journal list: a Site Admin, a Journal Manager from any journal, an external user, and the site-level login form all keep OTP. The "opt-out" only relaxes OTP for users whose entire footprint on the site is roles in that one journal that the manager has chosen not to protect.

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

## Running tests

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
- **Configurazione per rivista**: ogni rivista decide indipendentemente quali ruoli devono completare il passaggio 2FA su quella rivista
- **Protezione lato sito**: il login dal modulo globale di sito richiede sempre OTP, indipendentemente dalle configurazioni delle singole riviste
- **Sempre attivo**: il plugin funziona come componente di sistema — non può essere disabilitato, solo configurato. Per escludere una rivista dall'OTP, lasciare vuota la lista dei ruoli richiesti (vedi Configurazione per la nota su questo)
- **Protezione brute-force**: account bloccato per 15 minuti dopo 5 tentativi falliti
- **Protezione flood**: al massimo un OTP inviato per utente ogni 60 secondi
- **Sicuro by design**: solo l'hash SHA-256 dell'OTP è memorizzato (in sessione), mai il codice in chiaro; OTP generato tramite CSPRNG (`random_int`)
- **Nessuna dipendenza esterna**: usa l'infrastruttura email e il database già configurati in OMP/OJS

---

## Compatibilità

| Versione plugin | OMP   | OJS   |
|-----------------|-------|-------|
| 2.0.0           | 3.5.x | 3.5.x |

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
4. Il plugin appare nell'elenco come attivo (non può essere disabilitato — vedi Configurazione)

### Installazione manuale con Upload dalla pagina Plugin

1. Scarica il file `.tar.gz` dell'ultima release dalla pagina [Releases](../../releases)
2. In OMP/OJS, vai a **Impostazioni → Sito web → Plugin → Carica un nuovo plugin**
3. Seleziona il file `.tar.gz` scaricato e procedi con l'installazione

Dopo l'installazione, il plugin è attivo su tutto il sito. Configura ogni rivista individualmente tramite la sua azione **Impostazioni**.

---

## Configurazione

### Dove si configura

Il plugin espone un'azione **Impostazioni** su due pannelli:

- **Amministratore sito → Riviste ospitate → (una rivista) → Plugin**: configura i ruoli richiesti per quella rivista
- **Gestore rivista → Sito web → Plugin**: stesso form, limitato alla rivista del gestore

Sia gli Amministratori del sito che i Gestori delle riviste possono modificare la configurazione di una rivista. Non esiste una configurazione a livello di sito — ogni rivista è configurata indipendentemente.

La checkbox di abilitazione è **in sola lettura e sempre attiva**: il plugin è un componente di sistema sempre attivo. Per escludere una rivista dall'OTP per gli utenti normali, salva il form **senza selezionare alcun ruolo** (vedi la nota sulla limitazione più sotto).

### Cosa si configura

Il form delle impostazioni è una checklist di ruoli. Per ogni rivista, selezioni quali ruoli devono completare il passaggio OTP quando fanno login **su quella rivista**.

| Impostazione | Effetto sul login di quella rivista |
|--------------|-------------------------------------|
| Uno o più ruoli selezionati | Un utente con almeno uno dei ruoli selezionati su quella rivista deve verificare via OTP |
| Nessun ruolo selezionato | La maggior parte degli utenti salta l'OTP su quella rivista — ma diverse regole a livello di sito forzano comunque l'OTP per utenti privilegiati o esterni (vedi Regole) |
| Mai configurato | OTP obbligatorio per tutti (default di sicurezza; in uso normale questo stato è irraggiungibile perché il plugin scrive sempre una configurazione iniziale) |

### Regole

L'OTP è richiesto al login se **una qualsiasi** delle seguenti regole si applica, valutate in ordine. La prima che corrisponde vince.

1. **Login a livello sito** — login dal modulo globale (nessuna rivista di contesto) → OTP.
2. **Amministratore del sito** — l'utente ha il ruolo Site Admin → OTP.
3. **Gestore di una qualsiasi rivista** — l'utente ha il ruolo Gestore in una qualsiasi rivista del sito (non solo quella su cui sta facendo login) → OTP.
4. **Nessun ruolo nella rivista corrente** — l'utente non ha alcun ruolo nella rivista su cui sta facendo login → OTP. Il login è trattato come "esterno" a quella rivista.
5. **Impostazioni non configurate per la rivista corrente** — fallback difensivo, in pratica irraggiungibile.
6. **Match sui ruoli** — almeno uno dei ruoli dell'utente nella rivista corrente è nella lista dei ruoli richiesti per quella rivista → OTP. Altrimenti, niente OTP.

Le regole 2 e 3 sono a livello di sito: una volta che un utente ha Site Admin o Gestore in una qualsiasi rivista, l'OTP lo segue ovunque per tutto il sito. Le regole 4–6 sono valutate sulla rivista specifica su cui si sta facendo login.

### Esempi

Assumi due riviste, `Rivista A` e `Rivista B`.

| Utente | Ruoli | Login su | Esito |
|---|---|---|---|
| `alice` | Site Admin | qualsiasi rivista, o livello sito | OTP (Regola 2) |
| `bob` | Gestore su A | A | OTP (Regola 3) |
| `bob` | Gestore su A | B (dove bob non ha ruoli) | OTP (Regola 3 vince; anche la Regola 4 lo avrebbe coperto) |
| `carol` | Autore su A | A, con ruoli richiesti `[Autore]` | OTP (Regola 6) |
| `carol` | Autore su A | A, con ruoli richiesti `[Revisore]` | niente OTP (Regola 6, no match) |
| `carol` | Autore su A | B (nessun ruolo) | OTP (Regola 4) |
| `dave` | nessun ruolo da nessuna parte | A | OTP (Regola 4) |
| `dave` | nessun ruolo da nessuna parte | livello sito | OTP (Regola 1) |

### Limitazione nota — lista ruoli richiesti vuota

Se un Gestore della rivista salva una lista di ruoli richiesti vuota su una rivista, gli utenti **con ruoli su quella rivista che non sono né Site Admin né Gestori altrove** faranno login senza OTP su quella rivista. È intenzionale — è il meccanismo per escludere una rivista dall'OTP per i ruoli ordinari. È responsabilità dell'amministratore del sito e del Gestore della rivista configurare ogni rivista in modo appropriato.

Le regole 1–4 coprono comunque i casi più sensibili indipendentemente dalla lista per-rivista: Site Admin, Gestore di qualsiasi rivista, utente esterno e login dal modulo di sito mantengono sempre l'OTP. L'"opt-out" richiede l'OTP solo per gli utenti che hanno assegnato sulla rivista almeno uno dei ruoli che il gestore ha deciso di proteggere.

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

## Esecuzione dei test

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
