# Ticket Systeem MVP

Zelfgehost ticket- en helpdesksysteem voor kleine teams. Het MVP is bedoeld voor een standaard LAMP/LEMP-server met PHP, MySQL/MariaDB en SMTP voor notificaties.

> Status: MVP-documentatie op basis van de PRD en de aanwezige installatiebestanden. Controleer bij verdere implementatie of nieuwe routes, scripts en configuratie ook in de documentatie worden bijgewerkt.

## Vereisten

- PHP 8.2 of hoger, aanbevolen PHP 8.3
- PHP-extensies: `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `curl`
- MySQL 8.0 of MariaDB 10.6 of hoger
- Composer 2.x
- Nginx of Apache 2.4
- SMTP-account voor uitgaande e-mail
- Schrijfrechten op `storage/`

## Installatie in het kort

```bash
composer install --no-dev
composer install-wizard
```

Op een ontwikkelmachine zonder Composer kunnen de scripts ook direct worden gestart:

```bash
php install.php
php migrate.php
php seed.php
php sla_check.php
php retention_cleanup.php
php -S 127.0.0.1:8080 -t public
```

Optionele webinstaller: open `/install` zolang `.installed` nog niet bestaat.

Docker-demo:

```bash
docker compose up -d --build
docker compose exec app php migrate.php
docker compose exec app php seed.php
```

De Docker-app draait dan op `http://127.0.0.1:8081`.

Rooktest uitvoeren nadat MySQL, migraties, seed en de PHP-server draaien:

```powershell
.\scripts\smoke-test.ps1
```

Vul voor het migreren eerst de database- en SMTP-instellingen in `.env` in. Zie [docs/installatiegids.md](docs/installatiegids.md) voor de volledige installatiegids.

## Belangrijke configuratie

Minimale `.env`-waarden:

```dotenv
APP_NAME="MAAT Ticket Systeem"
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
```

## Eerste admin-login

Na `composer seed` hoort er een eerste admin-account beschikbaar te zijn. Gebruik bij voorkeur een eenmalig wachtwoord dat tijdens het seeden wordt getoond, of stel vooraf deze waarden in `.env` in:

```dotenv
DEFAULT_ADMIN_NAME="Beheerder"
DEFAULT_ADMIN_EMAIL=admin@example.nl
DEFAULT_ADMIN_PASSWORD="tijdelijk-sterk-wachtwoord"
```

Log daarna in via:

```text
/login
```

Wijzig het tijdelijke wachtwoord direct na de eerste login.

## Basisgebruik

- Klanten maken zonder account een ticket aan via `/`.
- Het systeem mailt een bevestiging met ticketnummer en klantlink.
- Agents en admins loggen in via `/login`.
- Agents verwerken tickets via `/dashboard`, `/tickets` en de ticketdetailpagina.
- Admins beheren gebruikers, categorieen, SLA-instellingen en e-mailsjablonen via `/admin/...`.

Zie [docs/admin-handleiding.md](docs/admin-handleiding.md) voor het dagelijkse beheer.

## Mappen

- `public/`: webroot en front controller
- `config/`: applicatieconfiguratie
- `deploy/`: voorbeeldconfiguratie voor Nginx, Apache en cron
- `src/`: PHP-code
- `migrations/`: database-migraties
- `storage/attachments/`: bijlagen, bij voorkeur buiten publieke toegang
- `docs/`: beheer- en installatiehandleidingen

## Deployment-checklist

- `.env` is ingevuld en niet publiek toegankelijk.
- `APP_ENV=production` in productie.
- Databasegebruiker heeft alleen rechten op de applicatiedatabase.
- SMTP is getest met SPF/DKIM voor het verzenddomein.
- `storage/` is schrijfbaar door de webservergebruiker.
- `storage/attachments/` is niet rechtstreeks browsebaar.
- HTTPS is actief.
- Eerste admin-wachtwoord is gewijzigd.
- Cronjob voor `php sla_check.php` draait bijvoorbeeld elke 15 minuten.
- Cronjob voor `php retention_cleanup.php` draait dagelijks.

## Documentatie

- [Installatiegids](docs/installatiegids.md)
- [Admin-handleiding](docs/admin-handleiding.md)
- [MVP-status](docs/mvp-status.md)
- [Verificatie](docs/verification.md)
- [API-documentatie](docs/api.md)
- [Changelog](CHANGELOG.md)
