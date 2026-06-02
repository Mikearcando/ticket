# Installatiegids

Deze gids beschrijft een praktische installatie van het Ticket Systeem MVP op een zelfgehoste LAMP/LEMP-server.

## 1. Serververeisten

Minimaal:

- PHP 8.2+, aanbevolen PHP 8.3
- MySQL 8.0+ of MariaDB 10.6+
- Composer 2.x
- Nginx of Apache 2.4
- 512 MB RAM, aanbevolen 1 GB of meer

PHP-extensies:

```text
pdo_mysql
mbstring
fileinfo
openssl
curl
```

Controleer PHP:

```bash
php -v
php -m
composer --version
```

## 2. Project plaatsen

Plaats de applicatie buiten de publieke webroot, bijvoorbeeld:

```bash
cd /var/www
git clone https://github.com/organisatie/ticket-systeem.git ticket-systeem
cd ticket-systeem
composer install --no-dev --optimize-autoloader
```

Als er nog geen repository is, plaats de projectbestanden handmatig in dezelfde mapstructuur.

## 2.1 Snelle installatie via wizard

De CLI-wizard vraagt database, SMTP, eerste admin en retentie-instellingen uit:

```bash
composer install-wizard
```

Zonder Composer:

```bash
php install.php
```

De wizard schrijft `.env`, voert migraties en seeddata uit en plaatst `.installed`.

Optioneel kan de webinstaller gebruikt worden zolang `.installed` nog niet bestaat:

```text
https://helpdesk.example.nl/install
```

Verwijder `.installed` alleen handmatig wanneer u bewust opnieuw wilt installeren.

## 3. Database aanmaken

Voorbeeld voor MySQL/MariaDB:

```sql
CREATE DATABASE ticket_systeem CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ticket_user'@'localhost' IDENTIFIED BY 'vervang-door-sterk-wachtwoord';
GRANT ALL PRIVILEGES ON ticket_systeem.* TO 'ticket_user'@'localhost';
FLUSH PRIVILEGES;
```

Gebruik in productie een uniek wachtwoord en geef de databasegebruiker geen globale rechten.

## 4. Omgeving configureren

Kopieer het voorbeeldbestand:

```bash
cp .env.example .env
```

Als `.env.example` nog niet bestaat, maak `.env` met minimaal:

```dotenv
APP_ENV=production
APP_URL=https://helpdesk.example.nl
APP_NAME="Supportdesk"

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ticket_systeem
DB_USERNAME=ticket_user
DB_PASSWORD=vervang-door-sterk-wachtwoord

MAIL_FROM_NAME="Supportdesk"
MAIL_FROM=support@example.nl
SMTP_HOST=smtp.example.nl
SMTP_PORT=587
SMTP_USERNAME=smtp-user
SMTP_PASSWORD=smtp-wachtwoord
SMTP_ENCRYPTION=tls

DEFAULT_ADMIN_NAME="Beheerder"
DEFAULT_ADMIN_EMAIL=admin@example.nl
DEFAULT_ADMIN_PASSWORD="tijdelijk-sterk-wachtwoord"
```

Controleer:

- `APP_URL` moet exact overeenkomen met de publieke URL.
- `APP_ENV=production` voor productie.
- `MAIL_FROM` moet passen bij het domein waarvoor SPF/DKIM is ingesteld.
- Bewaar `.env` nooit in git.

## 5. SMTP configureren

Gebruik bij voorkeur een betrouwbare SMTP-provider of een goed geconfigureerde eigen mailserver.

Veelgebruikte poorten:

| Poort | Encryptie | Gebruik |
|---:|---|---|
| 587 | `tls` | Aanbevolen voor authenticated SMTP |
| 465 | `ssl` | Alternatief bij sommige providers |
| 25 | geen of `tls` | Alleen gebruiken als provider dit vereist |

DNS-aanbevelingen:

- SPF-record voor de verzendende SMTP-server.
- DKIM ingeschakeld bij de mailprovider.
- DMARC-record minimaal in monitoringmodus.

Test na installatie of de events `ticket_created`, `reply_from_agent` en `status_changed` e-mail verzenden.

Voer daarnaast een directe SMTP-test uit:

```bash
php smtp_test.php support@example.nl
```

Als `SMTP_HOST` leeg is of de provider weigert, wordt de poging in `mail_log` vastgelegd met foutmelding.

## 6. Migreren

Draai de database-migraties:

```bash
composer migrate
```

Verwacht resultaat:

- tabellen zoals `tickets`, `ticket_replies`, `attachments`, `users`, `categories`, `sla_policies`, `audit_log` en `email_templates` bestaan;
- migraties zijn idempotent of worden geregistreerd zodat ze niet dubbel draaien.

Alternatief kan direct `php migrate.php` worden gebruikt. Het aanwezige migratiescript voert SQL-bestanden in `migrations/` uit in volgorde van bestandsnaam.

## 7. Seeden

Draai de seed-stap:

```bash
composer seed
```

De seed hoort minimaal aan te maken:

- eerste admin-account;
- standaardcategorie `Overig`;
- standaard SLA-regels voor `laag`, `normaal`, `hoog` en `kritiek`;
- standaard e-mailsjablonen.

Alternatief kan direct `php seed.php` worden gebruikt. Wijzig het tijdelijke admin-wachtwoord direct na de eerste login.

## 7.1 SLA-cron activeren

Laat de SLA-check periodiek draaien zodat waarschuwingen rond 80% verstreken tijd en overschrijdingen worden verstuurd:

```bash
php /var/www/ticket-systeem/sla_check.php
```

Voorbeeld cronregel, elke 15 minuten:

```cron
*/15 * * * * cd /var/www/ticket-systeem && php sla_check.php >> storage/sla-check.log 2>&1
```

De check schrijft auditlogregels `sla_warning` en `sla_breach` en gebruikt de templates met dezelfde eventnamen.

## 8. Bestandsrechten

De webserver moet kunnen schrijven naar `storage/`.

Voorbeeld op Linux:

```bash
chown -R www-data:www-data storage
chmod -R 750 storage
```

Bijlagen horen in `storage/attachments/` te staan en niet rechtstreeks publiek bereikbaar te zijn.

## 9. Webserver

Richt de webroot op `public/`, niet op de projectroot.

Nginx voorbeeld:

```nginx
server {
    listen 80;
    server_name helpdesk.example.nl;
    root /var/www/ticket-systeem/public;
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

Zet daarna HTTPS aan, bijvoorbeeld met Let's Encrypt.

Kant-en-klare voorbeelden staan in:

- `deploy/nginx.conf`
- `deploy/apache-vhost.conf`
- `deploy/cron.example`

Apache gebruikt dezelfde hoofdregel: documentroot moet naar `public/` wijzen. Zorg dat URL rewriting naar `public/index.php` werkt.

## 9.1 Docker-demo

Voor een lokale demo met app + MySQL:

```bash
docker compose up -d --build
docker compose exec app php migrate.php
docker compose exec app php seed.php
```

Open daarna `http://127.0.0.1:8081`.

## 10. Eerste admin-login

1. Open `https://helpdesk.example.nl/login`.
2. Log in met `DEFAULT_ADMIN_EMAIL` en `DEFAULT_ADMIN_PASSWORD`, of met de gegevens die `composer seed` toont.
3. Wijzig het wachtwoord.
4. Controleer `/admin/users`, `/admin/categories`, `/admin/sla` en `/admin/templates`.
5. Maak minimaal een agent-account aan voor dagelijks gebruik.

## 11. Functionele test

Voer na installatie deze rooktest uit:

1. Open `/` zonder login.
2. Maak een testticket aan met een intern e-mailadres.
3. Controleer dat het ticketnummer het formaat `TKT-YYYY-NNNNNN` heeft.
4. Controleer dat de klantbevestiging per e-mail aankomt.
5. Open `/login` en log in als agent of admin.
6. Wijs het ticket toe aan een agent.
7. Voeg een publieke reactie toe.
8. Wijzig de status naar `in_behandeling`, daarna naar `opgelost`.
9. Controleer dat statuswijzigingen in de auditlog staan.
10. Controleer dat bijlagen uploaden en downloaden werkt.

## 12. Back-up en onderhoud

Maak minimaal back-ups van:

- de database;
- `.env`;
- `storage/attachments/`;
- eventuele aangepaste e-mailsjablonen als die niet in de database staan.

Aanbevolen onderhoud:

- wekelijks dependency-updates controleren;
- maandelijks hersteltest van back-up uitvoeren;
- periodiek schijfruimte van `storage/attachments/` controleren;
- maildeliverability controleren bij SMTP-wijzigingen.
- dagelijks `php retention_cleanup.php` draaien voor gesloten tickets ouder dan `DATA_RETENTION_DAYS` dagen; minimum is 365.

## 13. Problemen oplossen

Geen databaseverbinding:

- controleer `DB_HOST`, `DB_PORT`, database, gebruikersnaam en wachtwoord;
- test met de MySQL-client vanaf dezelfde server;
- controleer of de databasegebruiker rechten heeft op de juiste database.

Geen e-mail:

- controleer SMTP-host, poort, encryptie en login;
- controleer firewallregels voor uitgaande SMTP;
- controleer SPF/DKIM;
- test met een intern adres en daarna met een extern adres.

Uploads werken niet:

- controleer schrijfrechten op `storage/attachments/`;
- controleer PHP-limieten `upload_max_filesize` en `post_max_size`;
- controleer dat de bestandstypen toegestaan zijn.

Login werkt niet:

- draai `composer seed` opnieuw alleen als de seed idempotent is;
- controleer of het admin-account actief is;
- gebruik de wachtwoordreset zodra die beschikbaar is.
