# Verificatie MVP

Laatst uitgevoerd op 2026-06-02.

## Functionele smoke test

Command:

```powershell
.\scripts\smoke-test.ps1 -BaseUrl http://127.0.0.1:8081
```

Resultaat:

```text
smoke-test-ok
```

Gedekte flow:

- publiek ticket aanmaken;
- klantportaalreactie;
- admin-login;
- ticketfilter op datum en prioriteit;
- gebruiker aanmaken;
- categorie bewerken;
- statuswijziging;
- wachtwoordreset-aanvraag;
- mail_log verificatie;
- SLA-check script aanroepen;
- auditlog bereikbaar via adminnavigatie.

## Performance

Command:

```powershell
.\scripts\performance-test.ps1
```

Resultaat bij 1.000 tickets:

```text
tickets-page-ms=142.1
performance-test-ok
```

De PRD-limiet voor de lijstpagina is `< 1,5 seconden bij 1.000 tickets`.

## Schaalbaarheid

Command:

```powershell
.\scripts\load-test.ps1
```

Deze test seedt 10.000 tickets en voert 50 gelijktijdige requests uit op het ticketoverzicht.

Resultaat:

```text
load-test-ms=117827.1
load-test-ok
```

Opmerking: de test gebruikt PowerShell `Start-Job`, waardoor processtart-overhead in de totale tijd zit. De waarde bewijst vooral stabiliteit van 50 gelijktijdige geauthenticeerde requests tegen 10.000 tickets.

## Accessibility

Gebruikte skill/tooling:

```bash
python C:\Users\mikea\.codex\skills\a11y-audit\scripts\a11y_scanner.py storage\a11y --format text
python C:\Users\mikea\.codex\skills\a11y-audit\scripts\contrast_checker.py --batch public\assets\css\app.css
```

Resultaat:

```text
Scanned 2 file(s) -- no accessibility issues found.
All checks passed.
Summary: 11/11 pairs pass AA Normal Text
```

Gescande HTML-snapshots:

- publiek ticketformulier;
- webinstaller.

## Deploy

Geverifieerd:

- `docker compose config`;
- `docker compose up -d --build app`;
- app-container healthcheck: `healthy`;
- `http://127.0.0.1:8081/` geeft HTTP 200;
- container-commands `php migrate.php`, `php seed.php`, `php sla_check.php`, `php retention_cleanup.php`.

## Security

Geverifieerd via smoke-test:

- `X-Frame-Options`;
- `X-Content-Type-Options`;
- `Content-Security-Policy`;
- CSRF op applicatieformulieren.
- login-throttling na 5 mislukte pogingen per e-mail/IP in 15 minuten.

Handmatig toegevoegd:

- generieke productie-500 pagina zonder exceptiondetails;
- hardened session cookies;
- CSRF op webinstaller.
