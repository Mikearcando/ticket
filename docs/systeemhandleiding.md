# Systeemhandleiding Ticket Systeem

Laatste update: 2026-06-10

Deze handleiding beschrijft de installatie, eerste inrichting, het dagelijks gebruik en het beheer van het Ticket Systeem. Het systeem is een zelfgehost PHP-ticket- en helpdesksysteem met MySQL/MariaDB, SMTP, selfservice, ticketbeheer, SLA-bewaking, kennisbank, rapportages, webhooks, IMAP-intake en AD/LDAPS-login.

## Inhoud

1. [Snel overzicht](#snel-overzicht)
2. [Rollen en rechten](#rollen-en-rechten)
3. [Installatie met Docker](#installatie-met-docker)
4. [Handmatige installatie](#handmatige-installatie)
5. [Installatie via wizard](#installatie-via-wizard)
6. [Eerste inrichting na installatie](#eerste-inrichting-na-installatie)
7. [Gebruik door klanten](#gebruik-door-klanten)
8. [Gebruik door agents](#gebruik-door-agents)
9. [Gebruik door managers](#gebruik-door-managers)
10. [Gebruik door admins](#gebruik-door-admins)
11. [Integraties](#integraties)
12. [Onderhoud en cronjobs](#onderhoud-en-cronjobs)
13. [Back-up en beveiliging](#back-up-en-beveiliging)
14. [Testen en controleren](#testen-en-controleren)
15. [Problemen oplossen](#problemen-oplossen)
16. [Belangrijke routes](#belangrijke-routes)

## Snel overzicht

Het Ticket Systeem bestaat uit:

- Een publiek portaal waar klanten tickets kunnen aanmaken.
- Een klantportaal via een persoonlijke link per ticket.
- Een loginomgeving voor medewerkers.
- Een dashboard met werkvoorraad en SLA-informatie.
- Ticketlijsten met filters, bulkacties, toewijzing en statusbeheer.
- Publieke reacties, interne notities, tijdregistratie en bijlagen.
- Beheer van gebruikers, categorieen, SLA-regels en e-mailsjablonen.
- Kennisbankartikelen voor selfservice.
- Rapportages met CSV- en PDF-export.
- Auditlog voor ticket- en systeemacties.
- Configuratie voor SMTP, IMAP, AD/LDAPS, thema en retentie.

De applicatie gebruikt deze hoofdonderdelen:

| Onderdeel | Locatie |
|---|---|
| Applicatielogica | `src/App.php` |
| Installatiewizard | `src/Installer.php` |
| Webroot | `public/` |
| Configuratie | `.env` en `config/settings.php` |
| Database-migraties | `migrations/` |
| Runtimebestanden en bijlagen | `storage/` |
| Scripts | repository-root en `scripts/` |

## Rollen en rechten

| Rol | Doel | Rechten |
|---|---|---|
| `viewer` | Alleen meekijken | Kan inloggen, dashboard en tickets bekijken, maar tickets niet wijzigen. |
| `agent` | Dagelijkse ticketbehandeling | Kan tickets behandelen, toewijzen, status wijzigen, reageren, interne notities plaatsen, tijd boeken en bulkacties uitvoeren. |
| `manager` | Operationele sturing | Heeft agentrechten plus rapportages, auditlog en configuratiestatus. |
| `admin` | Systeembeheer | Heeft managerrechten plus gebruikers, categorieen, SLA, templates, kennisbank, webhooks en configuratiebeheer. |

Belangrijk:

- Houd minimaal een actief lokaal adminaccount als noodaccount.
- Geef alleen echte systeembeheerders de rol `admin`.
- Deactiveer vertrokken medewerkers in plaats van accounts te hergebruiken.
- AD-gebruikers hebben `auth_source=ad`; lokale wachtwoordwijzigingen gelden alleen voor lokale accounts.

## Installatie met Docker

Docker is de snelste manier om lokaal of op een eenvoudige server te starten.

### Vereisten

- Docker Desktop of Docker Engine met Docker Compose.
- Vrije poort `8081` voor de applicatie.
- Vrije poort `3307` voor de MySQL-container, tenzij je `docker-compose.yml` aanpast.

### Stappen

1. Open PowerShell in de projectmap.

```powershell
cd c:\Users\mikea\Documents\projecten\ticket
```

2. Bouw en start de containers.

```powershell
docker compose up -d --build
```

3. Voer de migraties uit.

```powershell
docker compose exec app php migrate.php
```

4. Maak de standaarddata aan.

```powershell
docker compose exec app php seed.php
```

5. Open de applicatie.

```text
http://127.0.0.1:8081
```

6. Log in met de lokale Docker-admin.

```text
E-mail: admin@example.nl
Wachtwoord: de waarde van `DEFAULT_ADMIN_PASSWORD` in `docker-compose.yml` of je lokale `.env`
```

Deze standaardlogin is alleen bedoeld voor lokale controle. Wijzig dit wachtwoord direct via `/profile` en gebruik voor productie altijd een eigen sterk tijdelijk wachtwoord.

### Docker beheren

Containers bekijken:

```powershell
docker compose ps
```

Logs bekijken:

```powershell
docker compose logs -f app
```

Containers stoppen:

```powershell
docker compose down
```

Containers en databasevolume verwijderen:

```powershell
docker compose down -v
```

Let op: `docker compose down -v` verwijdert ook de database-inhoud.

## Handmatige installatie

Gebruik deze route voor productie of een bestaande server met Nginx/Apache.

### Serververeisten

Minimaal:

- PHP 8.2 of hoger.
- MySQL 8.0 of hoger, of MariaDB 10.6 of hoger.
- Composer 2.x.
- Nginx of Apache 2.4.
- Schrijfrechten op `storage/`.

Verplichte PHP-extensies:

```text
pdo_mysql
mbstring
fileinfo
openssl
curl
```

Optionele PHP-extensies:

```text
imap
ldap
```

`imap` is nodig voor inkomende e-mail naar tickets. `ldap` is nodig voor AD/LDAPS-login.

### Project plaatsen

Plaats het project buiten de publieke webroot, bijvoorbeeld:

```bash
cd /var/www
git clone https://github.com/Mikearcando/ticket.git ticket
cd ticket
composer install --no-dev --optimize-autoloader
```

Voor lokale ontwikkeling kan ook:

```bash
composer install
```

### Database aanmaken

Voorbeeld voor MySQL/MariaDB:

```sql
CREATE DATABASE ticket_systeem CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ticket_user'@'localhost' IDENTIFIED BY 'vervang-door-sterk-wachtwoord';
GRANT ALL PRIVILEGES ON ticket_systeem.* TO 'ticket_user'@'localhost';
FLUSH PRIVILEGES;
```

Gebruik in productie een sterk, uniek wachtwoord en geef de databasegebruiker alleen rechten op deze database.

### `.env` configureren

Kopieer het voorbeeldbestand:

```bash
cp .env.example .env
```

Vul minimaal deze waarden in:

```dotenv
APP_NAME="Ticket Systeem"
APP_URL=https://helpdesk.example.nl
APP_ENV=production

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
DEFAULT_ADMIN_PASSWORD=vervang-door-sterk-tijdelijk-wachtwoord
DATA_RETENTION_DAYS=365
```

Belangrijk:

- `APP_URL` moet de echte publieke URL zijn.
- Gebruik `APP_ENV=production` op productie.
- Bewaar `.env` nooit in git.
- Gebruik een tijdelijk adminwachtwoord van minimaal 10 tekens en wijzig het na de eerste login.
- `DATA_RETENTION_DAYS` kan niet lager zijn dan `365`.

### Migraties en seeddata

Voer de migraties uit:

```bash
php migrate.php
```

Maak standaarddata aan:

```bash
php seed.php
```

De seed maakt onder andere:

- Een adminaccount op basis van `DEFAULT_ADMIN_*`.
- Standaardcategorieen: Hardware, Software, Netwerk en Overig.
- SLA-regels voor `laag`, `normaal`, `hoog` en `kritiek`.
- Standaard e-mailsjablonen.

### Webserver instellen

Richt de documentroot altijd op:

```text
public/
```

Niet op de projectroot.

Voorbeeld Nginx:

```nginx
server {
    listen 80;
    server_name helpdesk.example.nl;
    root /var/www/ticket/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(env|git) {
        deny all;
    }
}
```

Voorbeeldbestanden staan in:

- `deploy/nginx.conf`
- `deploy/apache-vhost.conf`
- `deploy/cron.example`

Zet in productie altijd HTTPS aan.

### Lokale ontwikkelserver

Voor niet-productie:

```bash
php -S 127.0.0.1:8080 -t public
```

Open daarna:

```text
http://127.0.0.1:8080
```

## Installatie via wizard

De applicatie heeft ook een installatiewizard.

### CLI-wizard

```bash
php install.php
```

Of via Composer:

```bash
composer install-wizard
```

De wizard vraagt om:

- Applicatienaam en publieke URL.
- Omgeving.
- Databasegegevens.
- SMTP-gegevens.
- Eerste adminaccount.
- Dataretentie.

Daarna schrijft de wizard `.env`, voert migraties uit, seedt de basisdata en maakt `.installed` aan.

### Webinstaller

Zolang `.installed` nog niet bestaat, is de webinstaller bereikbaar via:

```text
/install
```

Na installatie toont `/install` dat installatie is gesloten. Verwijder `.installed` alleen als je bewust opnieuw wilt installeren.

## Eerste inrichting na installatie

Doorloop na de eerste login deze stappen:

1. Ga naar `/login`.
2. Log in met het adminaccount uit Docker, `.env` of de wizard.
3. Ga naar `/profile` en wijzig het tijdelijke wachtwoord.
4. Controleer `/admin/config`.
5. Configureer SMTP en test e-mail met `php smtp_test.php ontvanger@example.nl`.
6. Controleer de categorieen via `/admin/categories`.
7. Controleer SLA-regels via `/admin/sla`.
8. Maak medewerkeraccounts aan via `/admin/users`.
9. Controleer e-mailsjablonen via `/admin/templates`.
10. Stel cronjobs in voor SLA, retentie en eventueel IMAP.
11. Maak een testticket aan via `/`.
12. Laat een agent het testticket behandelen en sluiten.

## Gebruik door klanten

Klanten hebben geen account nodig om een ticket aan te maken.

### Ticket aanmaken

1. Open de startpagina `/`.
2. Vul naam en e-mailadres in.
3. Vul onderwerp en omschrijving in.
4. Kies categorie en prioriteit.
5. Voeg eventueel een bijlage toe.
6. Verstuur het formulier.

Na het aanmaken krijgt het ticket een nummer zoals:

```text
TKT-2026-000001
```

De klant ontvangt een e-mail met een persoonlijke link naar het klantportaal.

### Ticket volgen

Via de persoonlijke ticketlink kan de klant:

- De status bekijken.
- De omschrijving en publieke reacties lezen.
- Zelf een publieke reactie plaatsen.
- Publieke bijlagen downloaden.

Interne notities zijn nooit zichtbaar voor klanten.

### Bijlagen

Toegestane bijlagen:

- PNG
- JPG/JPEG
- PDF
- ZIP
- LOG

Maximale grootte:

```text
10 MB per bijlage
```

### Kennisbank gebruiken

Klanten kunnen de kennisbank openen via:

```text
/knowledge-base
```

Gepubliceerde artikelen zijn publiek zichtbaar. Concepten zijn alleen in beheer zichtbaar.

### Tevredenheid invullen

Wanneer een ticket wordt gesloten, ontvangt de klant een CSAT-link. Via deze link kan de klant:

- Een score van 1 tot en met 5 geven.
- Optioneel een toelichting achterlaten.

Een CSAT-link is gekoppeld aan een specifiek ticket.

## Gebruik door agents

Agents behandelen de dagelijkse tickets.

### Inloggen

1. Open `/login`.
2. Log in met een lokaal account of, als geconfigureerd, met AD/LDAPS.
3. Ga naar `/dashboard` of `/tickets`.

Na 5 mislukte inlogpogingen per e-mail/IP binnen 15 minuten wordt inloggen tijdelijk afgeremd.

### Dashboard

Het dashboard toont onder andere:

- Open tickets.
- Nieuwe tickets.
- Tickets die wachten op klant.
- Mijn open tickets.
- SLA-informatie.
- Wachtrijen met recente tickets.

Managers zien extra inzichten zoals gemiddelde eerste reactietijd, SLA-naleving en werkverdeling.

### Ticketlijst gebruiken

Open:

```text
/tickets
```

Beschikbare filters:

- Zoekterm of ticketnummer.
- Status.
- Prioriteit.
- Categorie.
- Toegewezen agent.
- Datum vanaf.
- Datum tot.

De lijst sorteert tickets op prioriteit, SLA-deadline en aanmaakdatum.

### Ticket behandelen

Open een ticket vanuit `/tickets`.

Een agent kan:

- Status wijzigen.
- Ticket toewijzen aan een agent, manager of admin.
- Publieke reactie plaatsen.
- Interne notitie plaatsen.
- Bijlage toevoegen.
- Tijd boeken.
- Audit/tijdlijn bekijken.

### Statussen

| Status | Betekenis |
|---|---|
| `nieuw` | Ticket is aangemaakt en nog niet opgepakt. |
| `open` | Ticket is aangenomen of opnieuw geopend. |
| `in_behandeling` | Er wordt actief aan gewerkt. |
| `wachtend_op_klant` | Het team wacht op informatie van de klant. |
| `opgelost` | Oplossing is geleverd, maar nog niet definitief gesloten. |
| `gesloten` | Ticket is afgerond. |

Toegestane statusovergangen:

| Van | Naar |
|---|---|
| `nieuw` | `open`, `in_behandeling`, `gesloten` |
| `open` | `in_behandeling`, `wachtend_op_klant`, `opgelost`, `gesloten` |
| `in_behandeling` | `wachtend_op_klant`, `opgelost`, `gesloten` |
| `wachtend_op_klant` | `open`, `in_behandeling`, `opgelost`, `gesloten` |
| `opgelost` | `open`, `gesloten` |
| `gesloten` | `open` |

Bij sluiten stuurt het systeem een sluitingsmail met CSAT-link naar de klant.

### Publieke reactie of interne notitie

Gebruik een publieke reactie voor communicatie met de klant. De klant krijgt dan een notificatie.

Gebruik een interne notitie voor teaminformatie. Interne notities:

- Zijn niet zichtbaar voor de klant.
- Tellen niet als eerste publieke agentreactie.
- Versturen geen klantmail.

### Tijd registreren

Op de ticketdetailpagina kan een agent tijd boeken.

Regels:

- Minimum: 1 minuut.
- Maximum: 1440 minuten per boeking.
- Notitie is optioneel.

Tijdregistraties worden gebruikt in rapportages.

### Bulkacties

Op `/tickets` kunnen agents meerdere tickets selecteren en in een keer:

- De status wijzigen.
- Een agent toewijzen.

Bulkacties worden vastgelegd in de auditlog.

## Gebruik door managers

Managers sturen de operatie, maar beheren geen systeeminstellingen die adminrechten vereisen.

### Rapportages

Open:

```text
/admin/reports
```

Beschikbare informatie:

- Aantal open tickets.
- Gemiddelde eerste reactietijd.
- Gemiddelde afhandeltijd.
- SLA-naleving.
- Totaal gelogde uren.
- Gemiddelde CSAT-score.
- Tickets per agent.
- Tijd per agent en per ticket binnen een periode.

Exports:

- CSV via de knop `CSV`.
- PDF via de knop `PDF`.

CSV-export beschermt klantvelden tegen spreadsheet-formules.

### Auditlog

Open:

```text
/admin/audit
```

Gebruik de auditlog voor:

- Statuswijzigingen.
- Toewijzingen.
- Reacties.
- Tijdregistraties.
- SLA-waarschuwingen en SLA-breaches.
- AD-login en AD-connectietests.

Filters:

- Ticketnummer.
- Actietype.

### Configuratie bekijken

Managers kunnen `/admin/config` bekijken. Zij zien systeemstatus zonder geheime waarden, zoals:

- Applicatienaam.
- Publieke URL.
- Databasehost.
- SMTP-status.
- IMAP-status.
- AD/LDAPS-status.
- Retentie-instelling.
- Bijlagenmap en schrijfrechten.
- PHP-versie.
- Uploadlimiet.

Alleen admins kunnen deze instellingen wijzigen.

## Gebruik door admins

Admins beheren het systeem.

### Gebruikers beheren

Open:

```text
/admin/users
```

Admins kunnen:

- Gebruikers aanmaken.
- Naam, e-mail en rol wijzigen.
- Accounts activeren of deactiveren.
- Lokale wachtwoorden wijzigen.
- Notificatie bij toewijzing aan- of uitzetten.

Regels:

- Wachtwoorden moeten minimaal 10 tekens hebben.
- Het systeem voorkomt dat de laatste actieve admin verdwijnt.
- Het systeem voorkomt dat het laatste lokale break-glass adminaccount verdwijnt.

### Categorieen beheren

Open:

```text
/admin/categories
```

Categorieen worden gebruikt bij ticketaanmaak en filtering.

Aanbevolen werkwijze:

- Houd de lijst kort en duidelijk.
- Gebruik namen zoals Hardware, Software, Netwerk, Facturatie en Overig.
- Deactiveer oude categorieen in plaats van ze te verwijderen.
- Laat minimaal een actieve categorie bestaan.

### SLA beheren

Open:

```text
/admin/sla
```

Standaard SLA-regels:

| Prioriteit | Eerste reactie | Oplossing |
|---|---:|---:|
| `laag` | 16 uur | 120 uur |
| `normaal` | 8 uur | 48 uur |
| `hoog` | 4 uur | 8 uur |
| `kritiek` | 1 uur | 4 uur |

Het systeem bewaakt:

- Eerste publieke agentreactie.
- Oplossingsdeadline.

De periodieke SLA-check stuurt waarschuwingen bij dreigende overschrijding en legt breaches vast.

### E-mailsjablonen beheren

Open:

```text
/admin/templates
```

Belangrijke events:

- `ticket_created`
- `ticket_assigned`
- `reply_from_agent`
- `reply_from_customer`
- `status_changed`
- `sla_warning`
- `sla_breach`
- `ticket_closed`

Ondersteunde variabelen:

- `{{ ticket.number }}`
- `{{ ticket.subject }}`
- `{{ ticket.status }}`
- `{{ ticket.link }}`
- `{{ customer.name }}`
- `{{ agent.name }}`
- `{{ site.name }}`
- `{{ site.url }}`
- `{{ csat.link }}`

Test na aanpassing altijd een echte notificatie naar een intern adres.

### Kennisbank beheren

Open:

```text
/admin/knowledge-base
```

Admins kunnen:

- Artikelen toevoegen.
- Artikelen bewerken.
- Een categorie koppelen.
- Artikelen publiceren of als concept bewaren.

Gepubliceerde artikelen verschijnen op `/knowledge-base`.

### Webhooks beheren

Open:

```text
/admin/webhooks
```

Admins kunnen endpoints toevoegen voor bijvoorbeeld Teams, Slack of eigen systemen.

Velden:

- Naam.
- HTTPS-URL.
- Events, bijvoorbeeld `*` of `ticket_created,status_changed`.

Gebruik in productie alleen HTTPS. Webhook-URLs kunnen bearer secrets bevatten; deel ze niet in tickets of screenshots.

### Configuratie beheren

Open:

```text
/admin/config
```

Admins kunnen via de UI wijzigen:

- Applicatienaam en publieke URL.
- Omgeving.
- Dataretentie.
- Database-instellingen.
- SMTP-instellingen.
- IMAP-instellingen.
- AD/LDAPS-instellingen.
- Thema-instellingen.

Wachtwoordvelden blijven leeg om bestaande secrets te behouden. Vul alleen een nieuw wachtwoord in als het gewijzigd moet worden. Gebruik de wisoptie alleen als de secret echt verwijderd moet worden.

Na wijzigingen kan een herstart van de container of webserver nodig zijn.

## Integraties

### SMTP

SMTP is nodig voor uitgaande e-mail.

Belangrijke instellingen:

```dotenv
MAIL_FROM=support@example.nl
MAIL_FROM_NAME="Supportdesk"
SMTP_HOST=smtp.example.nl
SMTP_PORT=587
SMTP_USERNAME=smtp-user
SMTP_PASSWORD=smtp-wachtwoord
SMTP_ENCRYPTION=tls
```

Test SMTP met:

```bash
php smtp_test.php ontvanger@example.nl
```

Als SMTP niet volledig is ingesteld, blijft mailgedrag zichtbaar in `mail_log`.

### IMAP-intake

IMAP-intake zet inkomende e-mail om naar tickets.

Vereist:

- PHP-extensie `imap`.
- IMAP-mailboxgegevens.
- Een standaardcategorie.

Voorbeeld `.env`:

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

Aanbevolen cronjob:

```cron
*/5 * * * * cd /var/www/ticket && php imap_intake.php >> storage/imap-intake.log 2>&1
```

Gedrag:

- Nieuwe e-mails worden nieuwe tickets.
- E-mails met een bestaand ticketnummer `TKT-YYYY-NNNNNN` in onderwerp of body worden als publieke klantreactie toegevoegd.
- Verwerkte berichten worden gelogd in `inbound_mail_log`.

### AD/LDAPS

AD/LDAPS-login vereist de PHP-extensie `ldap`.

Voorbeeld `.env`:

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

Belangrijke regels:

- Gebruik `ldaps` met poort `636`, of `starttls` met poort `389`.
- In productie is `AD_TLS_REQUIRE_CERT=demand` aanbevolen.
- `AD_NETWORK_TIMEOUT` moet tussen 1 en 60 seconden liggen.
- Test de verbinding via `/admin/config` met de AD-connectietest.
- Lokale admin-login blijft beschikbaar als fallback voor lokale accounts.

AD-gebruikers kunnen via `/ad/password` hun AD-wachtwoord wijzigen als de AD-omgeving dit toestaat.

### Webhooks

Webhooks versturen ticket-events als HTTP POST naar actieve endpoints.

Payload bevat onder andere:

- Eventtype.
- Ticketnummer.
- Status.
- Prioriteit.
- Onderwerp.
- Ticket-URL.

Webhookresultaten worden gelogd in `webhook_logs`.

## Onderhoud en cronjobs

### Belangrijke scripts

| Script | Doel |
|---|---|
| `php migrate.php` | Database-migraties uitvoeren. |
| `php seed.php` | Standaarddata aanmaken of bijwerken. |
| `php install.php` | CLI-installatiewizard starten. |
| `php sla_check.php` | SLA-waarschuwingen en breaches controleren. |
| `php retention_cleanup.php` | Gesloten tickets buiten retentie verwijderen. |
| `php imap_intake.php` | Inkomende e-mail verwerken. |
| `php smtp_test.php <email>` | SMTP testen. |
| `php scripts/prepare-production.php --yes` | Lokale runtime/testdata verwijderen en basisdata resetten voor productievoorbereiding. |

Via Composer:

```bash
composer migrate
composer seed
composer sla-check
composer retention-cleanup
composer imap-intake
composer smtp-test
composer prepare-production
```

Gebruik `prepare-production` alleen wanneer je bewust alle tickets, reacties, logs, resetlinks, CSAT, testgebruikers, testcategorieen en demo-kennisbankartikelen uit de huidige database wilt verwijderen. Het script behoudt het geconfigureerde adminaccount, zet de vier standaardcategorieen terug en leegt `storage/attachments/`.

### Aanbevolen cronjobs

```cron
*/15 * * * * cd /var/www/ticket && php sla_check.php >> storage/sla-check.log 2>&1
*/5  * * * * cd /var/www/ticket && php imap_intake.php >> storage/imap-intake.log 2>&1
0    3 * * * cd /var/www/ticket && php retention_cleanup.php >> storage/retention-cleanup.log 2>&1
```

Gebruik de IMAP-cronjob alleen als IMAP-intake is ingericht.

### Retentie

`retention_cleanup.php` verwijdert gesloten tickets waarvan `closed_at` ouder is dan `DATA_RETENTION_DAYS`.

Regel:

```text
DATA_RETENTION_DAYS >= 365
```

Controleer voor productie of dit past bij het bewaarbeleid van de organisatie.

## Back-up en beveiliging

### Back-up

Maak minimaal back-ups van:

- Database.
- `.env`.
- `storage/attachments/`.
- Eventuele serverconfiguratie.

Aanbevolen:

- Dagelijkse databaseback-up.
- Dagelijkse of wekelijkse back-up van `storage/attachments/`.
- Maandelijkse hersteltest.
- Back-ups versleutelen en buiten de server bewaren.

### Beveiligingschecklist

Controleer voor productie:

- `APP_ENV=production`.
- HTTPS staat aan.
- Webroot wijst naar `public/`.
- `.env`, `.git`, `storage/` en projectroot zijn niet publiek browsebaar.
- Eerste adminwachtwoord is gewijzigd.
- Er is minimaal een lokaal break-glass adminaccount.
- SMTP gebruikt sterke credentials.
- SPF/DKIM/DMARC zijn ingericht voor de verzendende mail.
- Databasegebruiker heeft alleen rechten op de applicatiedatabase.
- `storage/attachments/` is schrijfbaar voor de applicatie, maar niet direct publiek.
- Cronjobs voor SLA en retentie draaien.
- IMAP en AD gebruiken TLS waar mogelijk.
- Webhook-URLs worden behandeld als secrets.

## Testen en controleren

### Smoke test

Na installatie:

```powershell
.\scripts\smoke-test.ps1 -BaseUrl http://127.0.0.1:8081
```

Verwachte uitkomst:

```text
smoke-test-ok
```

### Performance test

Voor wijzigingen aan ticketlijsten, filters, SLA of rapportages:

```powershell
.\scripts\performance-test.ps1 -BaseUrl http://127.0.0.1:8081
```

### Load test

Voor grotere wijzigingen of schaalbaarheidscontrole:

```powershell
.\scripts\load-test.ps1 -BaseUrl http://127.0.0.1:8081
```

### AD-hardening test

Voor AD/LDAPS-configuratie of beveiligingswijzigingen:

```powershell
.\scripts\ad-hardening-smoke.ps1 -BaseUrl http://127.0.0.1:8081
```

## Problemen oplossen

### Applicatie opent niet

Controleer:

- Draait de webserver of Docker-container?
- Wijst de webroot naar `public/`?
- Is `APP_URL` correct?
- Zijn PHP en vereiste extensies geinstalleerd?
- Geeft `docker compose logs -f app` fouten?

### Databasefout

Controleer:

- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
- Of de database bestaat.
- Of de gebruiker rechten heeft.
- Of migraties zijn uitgevoerd met `php migrate.php`.

### Inloggen lukt niet

Controleer:

- Is het account actief?
- Klopt het wachtwoord?
- Is het account lokaal of AD?
- Is er login-throttling na mislukte pogingen?
- Bestaat er minimaal een lokale admin?

Voor lokale accounts kan wachtwoordreset via `/password/forgot` worden gebruikt als e-mail werkt.

### Geen e-mail

Controleer:

- SMTP-host, poort, gebruikersnaam, wachtwoord en encryptie.
- Firewallregels voor uitgaande SMTP.
- SPF/DKIM/DMARC.
- `php smtp_test.php ontvanger@example.nl`.
- `mail_log` voor foutmeldingen.

### Bijlagen uploaden werkt niet

Controleer:

- Is `storage/attachments/` schrijfbaar?
- Is het bestand kleiner dan 10 MB?
- Is het bestandstype toegestaan?
- Zijn PHP-instellingen `upload_max_filesize` en `post_max_size` hoog genoeg?

### IMAP verwerkt geen mail

Controleer:

- Is de PHP-extensie `imap` geinstalleerd?
- Klopt `IMAP_MAILBOX`?
- Kloppen gebruikersnaam en wachtwoord?
- Bestaat `IMAP_DEFAULT_CATEGORY_ID` en is de categorie actief?
- Draait de cronjob?
- Staat het ticketnummer correct in onderwerp of body bij replies?

### AD/LDAPS werkt niet

Controleer:

- Is de PHP-extensie `ldap` geinstalleerd?
- Kloppen host, poort, Base DN en Bind DN?
- Gebruik je `ldaps:636` of `starttls:389`?
- Vertrouwt de server het AD-certificaat?
- Zijn group mappings ingevuld?
- Wat toont de AD-connectietest op `/admin/config`?
- Wat staat in `/admin/audit` bij AD-events?

## Belangrijke routes

### Publiek

| Route | Doel |
|---|---|
| `/` | Publiek ticketformulier. |
| `/knowledge-base` | Publieke kennisbank. |
| `/knowledge-base/{slug}` | Publiek kennisbankartikel. |
| `/ticket/{token}` | Klantportaal voor een ticket. |
| `/csat/{token}` | Tevredenheidsformulier. |
| `/login` | Login voor medewerkers. |
| `/ad/password` | AD-wachtwoord wijzigen. |
| `/password/forgot` | Wachtwoordreset aanvragen. |

### Medewerkers

| Route | Doel |
|---|---|
| `/dashboard` | Dashboard. |
| `/tickets` | Ticketlijst met filters en bulkacties. |
| `/tickets/new` | Nieuw ticket aanmaken vanuit loginomgeving. |
| `/tickets/{id}` | Ticketdetail. |
| `/profile` | Profiel en lokaal wachtwoord wijzigen. |

### Manager en admin

| Route | Minimumrol | Doel |
|---|---|---|
| `/admin/reports` | manager | Rapportages en exports. |
| `/admin/audit` | manager | Ticket- en systeemauditlog. |
| `/admin/config` | manager | Configuratiestatus bekijken. |

### Alleen admin

| Route | Doel |
|---|---|
| `/admin/users` | Gebruikers en rollen beheren. |
| `/admin/categories` | Categorieen beheren. |
| `/admin/sla` | SLA-regels beheren. |
| `/admin/templates` | E-mailsjablonen beheren. |
| `/admin/knowledge-base` | Kennisbankartikelen beheren. |
| `/admin/webhooks` | Webhooks beheren. |
| `/admin/config` | Configuratie wijzigen. |
| `/admin/ad/test` | AD-connectietest uitvoeren. |

## Dagelijkse werkwijze in het kort

Voor klanten:

1. Maak ticket aan via `/`.
2. Volg ticket via de link in de e-mail.
3. Reageer via het klantportaal.
4. Vul CSAT in na sluiting.

Voor agents:

1. Start op `/dashboard`.
2. Pak nieuwe tickets op via `/tickets?status=nieuw`.
3. Wijs tickets toe.
4. Reageer publiek of plaats interne notities.
5. Boek tijd waar nodig.
6. Zet status naar `opgelost` of `gesloten`.

Voor managers:

1. Controleer dashboard en rapportages.
2. Bewaak SLA en werkverdeling.
3. Gebruik auditlog bij discussies of incidenten.

Voor admins:

1. Beheer gebruikers en rollen.
2. Houd categorieen, SLA en templates actueel.
3. Controleer SMTP, IMAP, AD en webhooks.
4. Bewaak cronjobs, back-ups en beveiliging.
