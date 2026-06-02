# MVP-status

Deze implementatie is een werkend PHP 8.3 MVP-skelet met de belangrijkste helpdeskflows:

- publiek ticketformulier zonder login;
- ticketnummering `TKT-YYYY-NNNNNN`;
- klantportaal via unieke token;
- agent/admin-login met bcrypt en PHP-sessie;
- ticketlijst, filters, detailpagina, statuswijziging en toewijzing;
- publieke reacties, interne notities en bijlagen tot 10 MB;
- adminbeheer voor gebruikers, categorieen, SLA en e-mailsjablonen;
- ticketfilters op status, prioriteit, categorie, agent, datum en zoekterm;
- dashboard en basisrapportages;
- auditlog voor ticketacties;
- SMTP-verzending via PHPMailer en logging in `mail_log`;
- periodieke SLA-waarschuwingen en breaches via `php sla_check.php`.
- CLI-installatiewizard via `php install.php` / `composer install-wizard`;
- webinstaller via `/install` met `.installed` flag;
- configureerbare retentie-cleanup via `php retention_cleanup.php`;
- deployvoorbeelden voor Nginx, Apache en cron.
- geverifieerde performance bij 1.000 tickets en a11y/contrastscan voor publieke MVP-schermen.
- loadtestscript voor 10.000 tickets en 50 gelijktijdige requests.
- security headers, hardened session cookies en admin auditlog-viewer;
- SMTP-testscript via `php smtp_test.php ontvanger@example.nl`.
- racevrije ticketnummering via `ticket_sequences`;
- eerste-reactie-SLA monitoring en rapportage.
- login-throttling tegen brute-force pogingen.
- geverifieerd met `load-test-ok` op 10.000 tickets / 50 gelijktijdige geauthenticeerde requests.

Bewuste MVP-beperkingen:

- Uitgaande e-mail wordt verzonden via PHPMailer zodra `composer install` is uitgevoerd en SMTP is ingevuld. Zonder PHPMailer of SMTP blijft elk event zichtbaar in `mail_log`.
- De PRD noemt Slim 4 en Twig. Omdat Composer lokaal niet beschikbaar was, is het MVP gebouwd als dependency-light PHP front controller. Dit kan later naar Slim/Twig worden gemigreerd zonder het databaseschema te vervangen.
- De cronjob voor `php sla_check.php` moet op de server nog worden ingesteld.
- De cronjob voor `php retention_cleanup.php` moet op de server nog worden ingesteld.
- Bulk-acties, IMAP-intake, kennisbank, CSAT en API blijven bewust buiten P0/MVP.
