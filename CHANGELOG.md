# Changelog

Alle noemenswaardige wijzigingen aan dit project worden hier bijgehouden volgens semantische versies.

## [0.2.0] - 2026-06-02

### Toegevoegd

- SLA-escalatiecheck via `php sla_check.php`.
- Eenmalige `sla_warning`- en `sla_breach`-notificaties per ticket, inclusief auditlog.
- Adminscherm om bestaande gebruikers te bewerken, te deactiveren en rollen te wijzigen.
- Categorieen bewerken/deactiveren met bescherming voor minimaal een actieve categorie.
- Datumfilter en SLA-deadline sortering in ticketoverzicht.
- Migratietracking via `schema_migrations`.
- CLI-installatiewizard en webinstaller met `.installed` flag.
- Retentie-cleanup voor gesloten tickets ouder dan de configureerbare bewaartermijn.
- Docker app-service en deployvoorbeelden voor Nginx, Apache en cron.
- Performance- en a11y-verificatiescripts plus verificatierapport.
- Security headers en sessie-cookie-hardening.
- Admin auditlog-viewer.
- SMTP-testscript.
- Racevrije ticketnummering via `ticket_sequences`.
- Eerste-reactie-SLA monitoring en KPI.
- Uitgebreidere smoke-test voor klantportaalreactie, password reset en mail_log.
- Login-throttling en 10k/50-concurrency loadtestscript.
- Login-throttling verificatie en loadtestresultaat vastgelegd.

## [0.1.0] - 2026-06-02

### Toegevoegd

- Eerste Nederlandstalige MVP-documentatie.
- Installatie-instructies voor PHP/Slim, database, SMTP, migraties en seeding.
- Admin-handleiding voor eerste login, basisgebruik, gebruikers, categorieen, SLA en e-mailsjablonen.
- Deployment-checklist voor zelfgehoste productie-installaties.

### Opmerking

- De documentatie is afgestemd op de aanwezige `.env.example`, Composer-scripts, migratie- en seedbestanden.
