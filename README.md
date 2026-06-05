# Ticket Systeem

> Zelfgehost ticket- en helpdesksysteem voor kleine teams — geen framework, geen vendor lock-in, gewoon PHP en MySQL.

Draait op PHP 8.2+, MySQL/MariaDB en SMTP. Bevat een selfserviceportaal, kennisbank, tijdregistratie, CSAT, rapportages, webhooks, IMAP-intake en AD/LDAPS-login. Volledig te deployen via Docker of handmatig op Nginx/Apache.

---

## Inhoudsopgave

1. [Snelle Start](#snelle-start)
2. [Functies](#functies)
3. [Vereisten](#vereisten)
4. [Installatie met Docker](#installatie-met-docker)
5. [Handmatige Installatie](#handmatige-installatie)
6. [Eerste Login](#eerste-login)
7. [Applicatieroutes](#applicatieroutes)
8. [IMAP-Intake](#imap-intake)
9. [AD/LDAPS](#adldaps)
10. [Onderhoud en Cronjobs](#onderhoud-en-cronjobs)
11. [Deployment-Checklist](#deployment-checklist)
12. [Documentatie](#documentatie)

---

## Snelle Start

Voor ervaren gebruikers die Docker hebben draaien:

```bash
git clone https://github.com/Mikearcando/ticket.git && cd ticket
docker compose up -d --build
docker compose exec app php migrate.php
docker compose exec app php seed.php  # optioneel: demodata
# Open http://127.0.0.1:8081 — standaard login: admin@example.nl / ChangeMe123!
```

Rooktest:

```powershell
.\scripts\smoke-test.ps1 -BaseUrl http://127.0.0.1:8081
```

---

## Functies

### Kernfunctionaliteit

- Selfserviceportaal: tickets aanmaken als anonieme of ingelogde gebruiker
- Ticketoverzicht met filters op status, categorie, prioriteit en toegewezen agent
- Bulkacties: status wijzigen en toewijzen in één handeling
- Tijdregistratie per ticket
- SLA-instellingen met automatische notificaties bij dreigende overschrijding
- Auditlog van alle wijzigingen per ticket
- Rollen: viewer, agent, manager, admin

### Kennisbank en communicatie

- Publieke kennisbank/FAQ gekoppeld aan categorieën
- Aanpasbare e-mailsjablonen per gebeurtenis
- CSAT-link automatisch verstuurd na sluiten van een ticket
- Webhooks voor Teams, Slack of eigen endpoints

### Rapportage en beheer

- CSV- en PDF-export via `/admin/reports`
- Dark mode en basisthema via `/admin/config`
- Runtimeconfiguratie van SMTP, IMAP en AD/LDAPS via de beheerinterface

### Integraties

- IMAP-intake: inkomende e-mail automatisch omzetten naar tickets
- AD/LDAPS-login met group-role mapping, connectietest en wachtwoord wijzigen via de applicatie

---

## Vereisten

| Vereiste | Versie |
|---|---|
| PHP | 8.2 of hoger |
| MySQL | 8.0 of hoger |
| MariaDB | 10.6 of hoger (alternatief voor MySQL) |
| Composer | 2.x |
| Webserver | Nginx of Apache 2.4 |

**Verplichte PHP-extensies:** `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `curl`

**Optionele PHP-extensies:**
- `imap` — vereist voor IMAP-intake van inkomende e-mail
- `ldap` — vereist voor AD/LDAPS-login

**Overige vereisten:**
- Schrijfrechten op de map `storage/`
- Een SMTP-account voor uitgaande e-mail

---

## Installatie met Docker

Dit is de snelste manier om de applicatie lokaal of op een server te draaien.

1. Clone de repository.

```bash
git clone https://github.com/Mikearcando/ticket.git
cd ticket
```

2. Start de containers.

```bash
docker compose up -d --build
```

3. Voer de databasemigraties uit.

```bash
docker compose exec app php migrate.php
```

4. Seed de database met demodata (optioneel).

```bash
docker compose exec app php seed.php
```

5. Open de applicatie.

```text
http://127.0.0.1:8081
```

> **Standaard login (Docker):** `admin@example.nl` / `ChangeMe123!` — afkomstig uit `docker-compose.yml`. Wijzig dit wachtwoord direct na de eerste login via `/profile`.

6. Rooktest (optioneel).

```powershell
.\scripts\smoke-test.ps1 -BaseUrl http://127.0.0.1:8081
```

---

## Handmatige Installatie

Gebruik dit als je de applicatie op een bestaande server wilt draaien met Nginx of Apache.

1. Clone de repository.

```bash
git clone https://github.com/Mikearcando/ticket.git
cd ticket
```

2. Installeer PHP-dependencies via Composer.

```bash
composer install --no-dev
```

3. Maak het configuratiebestand aan op basis van het voorbeeld.

```bash
cp .env.example .env
```

4. Vul minimaal de volgende waarden in `.env` in.

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

5. Voer de databasemigraties uit.

```bash
php migrate.php
```

6. Seed de database met demodata (optioneel).

```bash
php seed.php
```

7. Verifieer de SMTP-configuratie.

```bash
php smtp_test.php jouw@emailadres.nl
```

8. Wijs de webserverroot aan op de map `public/`. Zie `docs/installatiegids.md` voor Nginx- en Apache-voorbeeldconfiguraties.

**Lokale ontwikkelserver (niet voor productie):**

```bash
php -S 127.0.0.1:8080 -t public
```

```text
http://127.0.0.1:8080
```

---

## Eerste Login

1. Ga naar `/login`.
2. Log in met de admin-credentials:
   - **Docker:** standaard `admin@example.nl` / `ChangeMe123!` (hardcoded in `docker-compose.yml`)
   - **Handmatig:** de waarden van `DEFAULT_ADMIN_EMAIL` en `DEFAULT_ADMIN_PASSWORD` uit `.env`
3. Wijzig het tijdelijke wachtwoord direct via `/profile`.

---

## Applicatieroutes

### Eindgebruikers

| Route | Omschrijving |
|---|---|
| `/` | Selfserviceportaal en ticketaanmaak |
| `/knowledge-base` | Publieke kennisbank |
| `/login` | Lokale of AD-login |
| `/ad/password` | AD-wachtwoord wijzigen |
| `/dashboard` | Dashboard |
| `/tickets` | Ticketoverzicht met filters en bulkacties |
| `/tickets/new` | Snelle ticketaanmaak voor ingelogde gebruikers |

### Beheer (vereist manager- of adminrol)

| Route | Omschrijving |
|---|---|
| `/admin/users` | Gebruikers en rollen |
| `/admin/categories` | Categorieën |
| `/admin/sla` | SLA-instellingen |
| `/admin/templates` | E-mailsjablonen |
| `/admin/reports` | Rapportages met CSV/PDF-export |
| `/admin/audit` | Auditlog |
| `/admin/config` | Runtimeconfiguratie: SMTP, IMAP, AD/LDAPS en thema |
| `/admin/knowledge-base` | Kennisbankbeheer |
| `/admin/webhooks` | Webhookbeheer |

---

## IMAP-Intake

IMAP-intake zet inkomende e-mail automatisch om naar tickets. Vereist de PHP `imap`-extensie.

Voeg de volgende waarden toe aan `.env` (of configureer via `/admin/config`):

```dotenv
IMAP_MAILBOX="{imap.example.nl:993/imap/ssl}INBOX"
IMAP_USERNAME=support@example.nl
IMAP_PASSWORD=imap-wachtwoord
IMAP_DEFAULT_CATEGORY_ID=1
```

Handmatig uitvoeren:

```bash
php imap_intake.php
```

Aanbevolen cronjob (elke 5 minuten):

```cron
*/5 * * * * cd /var/www/ticket && php imap_intake.php
```

---

## AD/LDAPS

AD/LDAPS-login vereist de PHP `ldap`-extensie. Lokale adminaccounts blijven altijd beschikbaar als fallback.

Voeg de volgende waarden toe aan `.env`:

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
AD_TLS_REQUIRE_CERT=demand
AD_TLS_CACERTFILE=/etc/ssl/certs/ad-ca.pem
AD_TLS_CACERTDIR=
AD_NETWORK_TIMEOUT=5
```

**Belangrijke instellingen:**

- `AD_USE_TLS`: gebruik `ldaps` (poort 636) of `starttls` (poort 389). De configuratie-UI weigert `starttls` op poort 636.
- `AD_TLS_REQUIRE_CERT`: accepteert `demand`, `hard`, `allow`, `try` of `never`. Gebruik in productie `demand` met een vertrouwde CA en een DC-hostnaam die overeenkomt met het certificaat.
- `AD_NETWORK_TIMEOUT`: waarde in seconden, tussen 1 en 60.

Test de verbinding na configuratie via `/admin/config`.

---

## Onderhoud en Cronjobs

### Beschikbare scripts

| Script | Omschrijving |
|---|---|
| `php migrate.php` | Databasemigraties uitvoeren |
| `php seed.php` | Database seeden met demodata |
| `php install.php` | Installatie-wizard starten |
| `php sla_check.php` | SLA-overtredingen controleren |
| `php retention_cleanup.php` | Oude data opruimen conform retentiebeleid |
| `php imap_intake.php` | Inkomende e-mail verwerken |
| `php smtp_test.php <email>` | SMTP-configuratie testen |

### Aanbevolen crontab

```cron
*/15 * * * * cd /var/www/ticket && php sla_check.php
*/5  * * * * cd /var/www/ticket && php imap_intake.php
0    3 * * * cd /var/www/ticket && php retention_cleanup.php
```

---

## Deployment-Checklist

Doorloop deze punten voordat de applicatie in productie gaat.

**Beveiliging**
- [ ] Configuratiebestand is beveiligd: bij handmatige installatie is `.env` ingevuld en buiten de webroot; bij Docker zijn de standaardcredentials in `docker-compose.yml` vervangen door sterke waarden
- [ ] `APP_ENV=production` is ingesteld
- [ ] HTTPS is actief op de domeinnaam
- [ ] Het eerste admin-wachtwoord is gewijzigd via `/profile`
- [ ] De databasegebruiker heeft alleen rechten op de applicatiedatabase
- [ ] Voor AD-wachtwoordmutaties wordt LDAPS of StartTLS gebruikt

**Opslag en bestanden**
- [ ] `storage/` is schrijfbaar door de webservergebruiker
- [ ] `storage/attachments/` is niet rechtstreeks browsebaar (geen directory listing)

**E-mail en integraties**
- [ ] SMTP is getest via `php smtp_test.php`
- [ ] Webhooks zijn geconfigureerd en getest (indien van toepassing)

**Automatisering**
- [ ] Cronjob voor SLA-controle draait elke 15 minuten
- [ ] Cronjob voor retentieopruiming draait dagelijks om 3:00
- [ ] Cronjob voor IMAP-intake draait elke 5 minuten (indien IMAP is ingeschakeld)

---

## Documentatie

| Document | Omschrijving |
|---|---|
| [Installatiegids](docs/installatiegids.md) | Uitgebreide installatie-instructies voor Nginx, Apache en Docker |
| [Admin-handleiding](docs/admin-handleiding.md) | Beheerfuncties, rollen, SLA, sjablonen en rapportages |
| [MVP-status](docs/mvp-status.md) | Overzicht van geïmplementeerde en geplande functies |
| [Verificatie](docs/verification.md) | Teststappen en verificatieprocedures |
| [API-documentatie](docs/api.md) | REST API-referentie voor webhooks en externe integraties |
| [Changelog](CHANGELOG.md) | Versiegeschiedenis en releasenotities |
