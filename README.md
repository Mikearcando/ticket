# Ticket Systeem

Zelfgehost ticket- en helpdesksysteem voor kleine teams. De applicatie draait zonder framework op PHP, MySQL/MariaDB en SMTP en bevat het MVP plus P1-functies zoals kennisbank, bulkacties, CSAT, exports, webhooks, thema-instellingen, IMAP-intake en AD/LDAPS-login.

## Vereisten

- PHP 8.2 of hoger
- PHP-extensies verplicht: `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `curl`
- PHP-extensies optioneel: `imap` voor e-mailintake, `ldap` voor AD/LDAPS
- MySQL 8.0 of MariaDB 10.6 of hoger
- Composer 2.x
- Nginx of Apache 2.4
- Schrijfrechten op `storage/`
- SMTP-account voor uitgaande e-mail

## Snelle Installatie Met Docker

```powershell
docker compose up -d --build
docker compose exec app php migrate.php
docker compose exec app php seed.php
```

Open daarna:

```text
http://127.0.0.1:8081
```

Rooktest:

```powershell
.\scripts\smoke-test.ps1 -BaseUrl http://127.0.0.1:8081
```

## Handmatige Installatie

1. Clone de repository.

```bash
git clone https://github.com/Mikearcando/ticket.git
cd ticket
```

2. Installeer dependencies.

```bash
composer install --no-dev
```

3. Maak `.env`.

```bash
cp .env.example .env
```

4. Vul minimaal deze waarden in `.env`.

```dotenv
APP_NAME="Ticket Systeem"
APP_ENV=production
APP_URL=https://helpdesk.example.nl

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ticket_systeem
DB_USERNAME=ticket_user
DB_PASSWORD=sterk-wachtwoord

MAIL_FROM=support@example.nl
MAIL_FROM_NAME="Supportdesk"
SMTP_HOST=smtp.example.nl
SMTP_PORT=587
SMTP_USERNAME=smtp-user
SMTP_PASSWORD=smtp-wachtwoord
SMTP_ENCRYPTION=tls

DEFAULT_ADMIN_NAME=Admin
DEFAULT_ADMIN_EMAIL=admin@example.nl
DEFAULT_ADMIN_PASSWORD=ChangeMe123!
```

5. Migreer en seed de database.

```bash
php migrate.php
php seed.php
```

6. Start lokaal een ontwikkelserver.

```bash
php -S 127.0.0.1:8080 -t public
```

Open:

```text
http://127.0.0.1:8080
```

## Eerste Login

Log in via:

```text
/login
```

Gebruik `DEFAULT_ADMIN_EMAIL` en `DEFAULT_ADMIN_PASSWORD` uit `.env`. Wijzig het tijdelijke wachtwoord direct via `/profile`.

## Belangrijke Routes

- `/` selfserviceportaal en ticketaanmaak
- `/knowledge-base` publieke kennisbank
- `/login` lokale of AD-login
- `/ad/password` AD-wachtwoord wijzigen
- `/dashboard` dashboard
- `/tickets` ticketoverzicht met filters en bulkacties
- `/tickets/new` snelle ticketaanmaak voor ingelogde gebruikers
- `/admin/users` gebruikers en rollen
- `/admin/categories` categorieen
- `/admin/sla` SLA-instellingen
- `/admin/templates` e-mailsjablonen
- `/admin/reports` rapportages met CSV/PDF-export
- `/admin/audit` auditlog
- `/admin/config` runtimeconfig, SMTP, IMAP, AD/LDAPS en thema
- `/admin/knowledge-base` kennisbankbeheer
- `/admin/webhooks` webhookbeheer

## P1-Functies

- Kennisbank/FAQ gekoppeld aan categorieen.
- Bulkacties op tickets: status wijzigen en toewijzen.
- Tijdregistratie per ticket.
- CSAT-link na sluiten van een ticket.
- CSV/PDF-export via rapportages.
- Webhooks voor Teams, Slack of eigen endpoints.
- Darkmode en basisthema via `/admin/config`.
- IMAP-intake via `php imap_intake.php`.
- AD/LDAPS-login, group-role mapping, connectietest en wachtwoord wijzigen.

## IMAP-Intake

Zet deze waarden in `.env` of via `/admin/config`:

```dotenv
IMAP_MAILBOX="{imap.example.nl:993/imap/ssl}INBOX"
IMAP_USERNAME=support@example.nl
IMAP_PASSWORD=imap-wachtwoord
IMAP_DEFAULT_CATEGORY_ID=1
```

Handmatig draaien:

```bash
php imap_intake.php
```

Cronvoorbeeld:

```cron
*/5 * * * * cd /var/www/ticket && php imap_intake.php
```

## AD/LDAPS

Installeer de PHP LDAP-extensie en configureer:

```dotenv
AD_HOST=dc01.example.local
AD_PORT=636
AD_USE_TLS=ldaps
AD_BASE_DN="DC=example,DC=local"
AD_BIND_DN="CN=svc-ticket,OU=Service Accounts,DC=example,DC=local"
AD_BIND_PASSWORD=sterk-service-wachtwoord
AD_USER_FILTER="(&(objectClass=user)(sAMAccountName={username}))"
AD_GROUP_VIEWER="CN=Ticket Viewers,OU=Groups,DC=example,DC=local"
AD_GROUP_AGENT="CN=Ticket Agents,OU=Groups,DC=example,DC=local"
AD_GROUP_MANAGER="CN=Ticket Managers,OU=Groups,DC=example,DC=local"
AD_GROUP_ADMIN="CN=Ticket Admins,OU=Groups,DC=example,DC=local"
```

Test de verbinding via `/admin/config`. Lokale admin-login blijft beschikbaar als fallback.

## Onderhoud

```bash
php sla_check.php
php retention_cleanup.php
php smtp_test.php ontvanger@example.nl
php imap_intake.php
```

Aanbevolen cron:

```cron
*/15 * * * * cd /var/www/ticket && php sla_check.php
*/5 * * * * cd /var/www/ticket && php imap_intake.php
0 3 * * * cd /var/www/ticket && php retention_cleanup.php
```

## Deployment-Checklist

- `.env` is ingevuld en niet publiek toegankelijk.
- `APP_ENV=production`.
- HTTPS is actief.
- Databasegebruiker heeft alleen rechten op de applicatiedatabase.
- `storage/` is schrijfbaar door de webservergebruiker.
- `storage/attachments/` is niet rechtstreeks browsebaar.
- SMTP is getest.
- Eerste admin-wachtwoord is gewijzigd.
- Cronjobs voor SLA, retentie en optioneel IMAP draaien.
- Voor AD-wachtwoordmutaties wordt LDAPS of StartTLS gebruikt.

## Documentatie

- [Installatiegids](docs/installatiegids.md)
- [Admin-handleiding](docs/admin-handleiding.md)
- [MVP-status](docs/mvp-status.md)
- [Verificatie](docs/verification.md)
- [API-documentatie](docs/api.md)
- [Changelog](CHANGELOG.md)
