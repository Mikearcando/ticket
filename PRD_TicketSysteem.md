# PRD – Ticket Systeem (Helpdesk / Support)
**Versie:** 1.0  
**Datum:** 2 juni 2026  
**Auteur:** Projectteam  
**Status:** Concept  

---

## 1. Probleemstelling

Kleine en middelgrote bedrijven (MKB) in Nederland missen een eenvoudig, zelfgehost ticket- en helpdeskplatform dat ze op hun eigen server kunnen draaien zonder abonnementskosten of vendor lock-in. Bestaande SaaS-oplossingen (Freshdesk, Zendesk) zijn te duur of te complex voor de doelgroep. Intern worden klachten en verzoeken nu afgehandeld via losse e-mails, WhatsApp of Excel — onhoudbaar zodra het volume groeit.

**Doelgroep:** ICT-dienstenleners, webshops, facilitaire diensten en servicedesks (2–25 agents).  
**Risico van niets doen:** Klantverzoeken raken verloren, SLA's worden niet gehaald, kennisopbouw ontbreekt.

---

## 2. Doelen

| # | Doel | Type | Meetbaar |
|---|------|------|----------|
| G1 | Klanten kunnen zelfstandig een ticket aanmaken zonder account aanmaak te vereisen | Gebruikersdoel | Formulier beschikbaar zonder login |
| G2 | Agents verwerken een ticket van open → gesloten in < 5 muisklikken | Efficiëntie | Meting via gemiddeld aantal acties per ticket |
| G3 | Elke statuswijziging genereert automatisch een e-mailnotificatie | Systeemkwaliteit | 100% notificatiedekking bij statuschange |
| G4 | Het systeem is installeerbaar op een standaard LAMP/LEMP-server in < 30 minuten | Deployability | Installatietijd gemeten bij eerste deploy |
| G5 | Rapportages tonen openstaande tickets, gemiddelde afhandeltijd en agent-prestaties | Business intelligence | Dashboard aanwezig bij MVP-release |

---

## 3. Non-Goals (buiten scope v1)

| # | Buiten scope | Reden |
|---|--------------|-------|
| NG1 | Live chat / chatbot integratie | Te complex voor MVP; aparte module |
| NG2 | Multi-tenant SaaS (één installatie voor meerdere klanten) | Doelstelling is zelfgehost per klant |
| NG3 | Mobiele native app (iOS/Android) | Responsive webinterface volstaat |
| NG4 | AI-suggesties voor ticketoplossingen | Post-MVP feature |
| NG5 | Betaaldkoppeling / facturatiemodule | Aparte scope; verwijzing naar webshop-template |
| NG6 | LDAP / Active Directory SSO | Fase 2 na marktvalidatie |

---

## 4. Gebruikersrollen & Persona's

### 4.1 Rollen

| Rol | Omschrijving | Rechten |
|-----|-------------|---------|
| **Klant (Guest/Customer)** | Eindgebruiker die een verzoek indient | Ticket aanmaken, eigen tickets inzien, reageren |
| **Agent** | Medewerker die tickets verwerkt | Tickets beheren, toewijzen, sluiten, reageren |
| **Admin** | Systeembeheerder | Alles + gebruikersbeheer, categorieën, SLA-instellingen, rapportages |

### 4.2 User Stories

**Als klant:**
- Wil ik een ticket kunnen aanmaken via een eenvoudig formulier (naam, e-mail, onderwerp, omschrijving, bijlage) zodat ik snel hulp kan aanvragen.
- Wil ik per e-mail een bevestiging ontvangen met een uniek ticketnummer zodat ik mijn verzoek kan volgen.
- Wil ik via een link in de e-mail mijn ticket kunnen bekijken en reageren zonder verplichte accountregistratie.
- Wil ik een notificatie ontvangen wanneer de status van mijn ticket wijzigt zodat ik weet waar ik aan toe ben.

**Als agent:**
- Wil ik een overzicht zien van alle aan mij toegewezen tickets (gesorteerd op prioriteit en deadline) zodat ik weet wat het urgentst is.
- Wil ik een ticket snel kunnen herindelen naar een andere agent of categorie zodat het bij de juiste persoon terechtkomt.
- Wil ik interne notities kunnen toevoegen (niet zichtbaar voor klant) zodat ik met collega's kan overleggen binnen de ticket-context.
- Wil ik de status in één klik kunnen wijzigen (open / in behandeling / wachtend / gesloten) zodat de administratie minimale tijd kost.
- Wil ik bijlagen kunnen uploaden en inzien zodat screenshots of logbestanden snel gedeeld kunnen worden.

**Als admin:**
- Wil ik agents en klantaccounts kunnen aanmaken, bewerken en deactiveren zodat ik volledige controle heb over toegang.
- Wil ik categorieën en prioriteiten kunnen configureren zodat het systeem aansluit op onze werkprocessen.
- Wil ik SLA-tijden per prioriteit instellen (bijv. hoog = 4 uur responstijd) zodat escalaties automatisch getriggerd worden.
- Wil ik een dashboard zien met KPI's (open tickets, gemiddelde doorlooptijd, tickets per agent) zodat ik de servicekwaliteit kan monitoren.
- Wil ik e-mailsjablonen kunnen aanpassen zodat notificaties onze huisstijl volgen.

---

## 5. Functionele Eisen

### 5.1 Must-Have (P0)

#### 5.1.1 Ticketbeheer (core)

| ID | Requirement | Acceptatiecriteria |
|----|------------|-------------------|
| F01 | Ticketaanmaak via publiek webformulier | Formulier beschikbaar zonder login; verplichte velden: naam, e-mail, onderwerp, beschrijving; optioneel: prioriteit, bijlage (max 10 MB) |
| F02 | Automatische ticketnummer generatie | Formaat: `TKT-YYYY-NNNNNN` (bijv. TKT-2026-000042); uniek, opeenvolgend |
| F03 | Statusworkflow | Statussen: `nieuw → open → in_behandeling → wachtend_op_klant → opgelost → gesloten`; alleen geldige transities toegestaan |
| F04 | Prioriteiten | Vier niveaus: `laag / normaal / hoog / kritiek`; default = normaal |
| F05 | Categorieën | Admin configureert categorieën (bijv. Hardware, Software, Netwerk, Overig); min. 1 verplicht bij aanmaak |
| F06 | Toewijzing aan agent | Admin/agent kan ticket toewijzen aan een specifieke agent; notificatie aan toegewezen agent |
| F07 | Reacties (threaded) | Publieke reacties zichtbaar voor klant én agent; interne notities alleen zichtbaar voor agents/admins |
| F08 | Bijlagebeheer | Upload van PNG/JPG/PDF/ZIP/LOG tot 10 MB; opgeslagen in `/storage/attachments/` |
| F09 | E-mailnotificaties | Triggers: ticket aangemaakt, status gewijzigd, nieuwe reactie, SLA-waarschuwing; verzonden via SMTP (configureerbaar) |
| F10 | Klantportaal | Klant opent ticket via unieke link in e-mail; kan reageren en status inzien zonder account |

#### 5.1.2 Gebruikersbeheer

| ID | Requirement | Acceptatiecriteria |
|----|------------|-------------------|
| U01 | Inloggen voor agents en admins | E-mail + wachtwoord; bcrypt hashing; sessie via PHP session of JWT |
| U02 | Roltoewijzing | Admin kent rol toe: `agent` of `admin`; klanten hebben geen account (guest-flow) |
| U03 | Wachtwoordherstel | Reset via e-maillink (token geldig 60 minuten) |
| U04 | Profielbeheer | Agent kan naam, wachtwoord en notificatievoorkeuren aanpassen |

#### 5.1.3 Dashboard & Overzichten

| ID | Requirement | Acceptatiecriteria |
|----|------------|-------------------|
| D01 | Agent dashboard | Kolommen: mijn open tickets, onbehandeld, wachtend op klant; sorteer op prioriteit en aanmaakdatum |
| D02 | Admin dashboard | KPI-kaarten: totaal open, gemiddelde reactietijd (uren), tickets per agent, SLA-naleving (%) |
| D03 | Ticketoverzicht (lijstweergave) | Filterbaar op: status, prioriteit, categorie, agent, datum; zoekfunctie op ticketnummer + trefwoord |
| D04 | Ticketdetailpagina | Volledig ticket met tijdlijn van reacties, statuswijzigingen, bijlagen en interne notities |

#### 5.1.4 SLA-beheer

| ID | Requirement | Acceptatiecriteria |
|----|------------|-------------------|
| S01 | SLA-configuratie per prioriteit | Admin stelt in: eerste reactietijd (uren) en oplostijd (uren) per prioriteitsniveau |
| S02 | SLA-indicator op ticket | Kleurcodering: groen (op tijd), oranje (< 25% over), rood (SLA overschreden) |
| S03 | SLA-escalatienotificatie | Bij dreigend overschrijden (bijv. 80% verstreken) e-mail naar agent + admin |

---

### 5.2 Nice-to-Have (P1)

| ID | Requirement |
|----|------------|
| P101 | Bulk-acties in ticketoverzicht (meerdere tickets tegelijk status wijzigen / toewijzen) |
| P102 | E-mailintake: inkomende e-mails naar een mailbox worden automatisch omgezet naar ticket (IMAP polling) |
| P103 | Kennisbank / FAQ-module gekoppeld aan categorieën |
| P104 | Tijdregistratie per ticket (uren gelogd door agent) |
| P105 | Tevredenheidsscore (CSAT) na sluiting via e-maillink |
| P106 | Exportfunctie (CSV/PDF) van ticketoverzichten |
| P107 | Webhook-ondersteuning bij statuswijziging (voor integratie met Slack, Teams etc.) |
| P108 | Darkmode / themewisseling via CSS-variabelen |

---

### 5.3 Fase 2 / Toekomst (P2)

| ID | Idee |
|----|------|
| P201 | Multi-language support (NL/EN/DE) via i18n |
| P202 | REST API voor externe integraties |
| P203 | SSO / LDAP-koppeling |
| P204 | AI-gestuurde ticketclassificatie en suggesties |
| P205 | White-label theming per klantinstallatie |
| P206 | SLA-rapportage export naar PDF |

---

## 6. Niet-functionele Eisen

| Categorie | Eis |
|-----------|-----|
| **Performance** | Lijstpagina laadt < 1,5 seconden bij 1.000 tickets in database |
| **Beveiliging** | OWASP Top 10 nageleefd; XSS-protectie via output escaping (Twig); SQL-injection preventie via prepared statements; CSRF-tokens op alle formulieren |
| **Schaalbaarheid** | Systeem functioneert stabiel tot 10.000 tickets en 50 gelijktijdige gebruikers op standaard VPS (2 vCPU / 4 GB RAM) |
| **Beschikbaarheid** | Stateless PHP-applicatie; geschikt voor horizontale schaling achter nginx load balancer |
| **Installatie** | Installatie via CLI-wizard of webinstaller in < 30 minuten op LAMP/LEMP |
| **Dataretentie** | Gesloten tickets bewaard conform AVG (minimaal 1 jaar, configureerbaar) |
| **Toegankelijkheid** | WCAG 2.1 AA voor publiek klantformulier |
| **Logging** | Alle statuswijzigingen gelogd in `audit_log`-tabel met timestamp en actor |

---

## 7. Technische Architectuur

### 7.1 Tech Stack

| Laag | Keuze | Motivatie |
|------|-------|-----------|
| Backend | PHP 8.3 + Slim 4 | Bestaande expertise; lichtgewicht; geen framework overkill |
| Templating | Twig 3 | Veilige output escaping; herbruikbare layouts |
| Database | MySQL 8 / MariaDB 10.6 | Bewezen stabiel; breed gehost |
| Frontend | Vanilla JS + CSS (geen framework) | Geen build-pipeline; laag onderhoud |
| E-mail | PHPMailer + SMTP | Betrouwbaar; configureerbaar |
| Authenticatie | PHP Sessions + bcrypt | Eenvoudig; geen JWT-overhead voor v1 |
| File storage | Lokaal bestandssysteem (`/storage/`) | Geen S3-afhankelijkheid; migreerbaar |
| Webserver | Nginx (aanbevolen) of Apache + .htaccess | Standaard hosting |

### 7.2 Databaseschema (overzicht)

```sql
-- Tickets (core-entiteit)
tickets
  id              INT AUTO_INCREMENT PK
  ticket_number   VARCHAR(20) UNIQUE          -- TKT-2026-000042
  subject         VARCHAR(255)
  description     TEXT
  status          ENUM('nieuw','open','in_behandeling','wachtend_op_klant','opgelost','gesloten')
  priority        ENUM('laag','normaal','hoog','kritiek')
  category_id     INT FK → categories.id
  assigned_to     INT FK → users.id NULL
  customer_name   VARCHAR(100)
  customer_email  VARCHAR(150)
  customer_token  VARCHAR(64) UNIQUE          -- voor gastlink zonder login
  sla_deadline    DATETIME NULL
  created_at      DATETIME
  updated_at      DATETIME
  closed_at       DATETIME NULL

-- Reacties / berichten
ticket_replies
  id              INT AUTO_INCREMENT PK
  ticket_id       INT FK → tickets.id
  user_id         INT FK → users.id NULL      -- NULL = klantreactie via gastlink
  author_name     VARCHAR(100)
  body            TEXT
  is_internal     TINYINT(1) DEFAULT 0        -- 1 = interne notitie
  created_at      DATETIME

-- Bijlagen
attachments
  id              INT AUTO_INCREMENT PK
  ticket_id       INT FK → tickets.id
  reply_id        INT FK → ticket_replies.id NULL
  filename        VARCHAR(255)
  filepath        VARCHAR(500)
  filesize        INT
  mime_type       VARCHAR(100)
  uploaded_at     DATETIME

-- Gebruikers (agents + admins)
users
  id              INT AUTO_INCREMENT PK
  name            VARCHAR(100)
  email           VARCHAR(150) UNIQUE
  password_hash   VARCHAR(255)
  role            ENUM('agent','admin')
  is_active       TINYINT(1) DEFAULT 1
  created_at      DATETIME
  last_login      DATETIME NULL

-- Categorieën
categories
  id              INT AUTO_INCREMENT PK
  name            VARCHAR(100)
  description     VARCHAR(255) NULL
  is_active       TINYINT(1) DEFAULT 1

-- SLA-configuratie
sla_policies
  id              INT AUTO_INCREMENT PK
  priority        ENUM('laag','normaal','hoog','kritiek')
  first_response_hours  INT
  resolution_hours      INT

-- Auditlog (niet muteren, alleen schrijven)
audit_log
  id              INT AUTO_INCREMENT PK
  ticket_id       INT FK → tickets.id
  actor_id        INT FK → users.id NULL
  actor_name      VARCHAR(100)
  action          VARCHAR(100)                -- bijv. 'status_changed', 'assigned', 'reply_added'
  details         JSON NULL
  created_at      DATETIME

-- E-mailsjablonen
email_templates
  id              INT AUTO_INCREMENT PK
  event_type      VARCHAR(100)               -- 'ticket_created', 'status_changed', etc.
  subject         VARCHAR(255)
  body_html       TEXT
  updated_at      DATETIME
```

### 7.3 Mapstructuur

```
/ticket-systeem/
├── public/                     # Webroot (nginx root)
│   ├── index.php               # Front controller
│   └── assets/
│       ├── css/
│       │   └── app.css
│       └── js/
│           └── app.js
├── src/
│   ├── Controllers/
│   │   ├── TicketController.php
│   │   ├── ReplyController.php
│   │   ├── AuthController.php
│   │   ├── AdminController.php
│   │   └── DashboardController.php
│   ├── Models/
│   │   ├── Ticket.php
│   │   ├── Reply.php
│   │   ├── User.php
│   │   └── ...
│   ├── Services/
│   │   ├── MailService.php
│   │   ├── SlaService.php
│   │   └── AuditService.php
│   └── Middleware/
│       ├── AuthMiddleware.php
│       └── RoleMiddleware.php
├── templates/                  # Twig templates
│   ├── layout/
│   │   ├── base.html.twig
│   │   ├── admin.html.twig
│   │   └── portal.html.twig
│   ├── ticket/
│   │   ├── create.html.twig
│   │   ├── detail.html.twig
│   │   └── list.html.twig
│   ├── dashboard/
│   │   ├── agent.html.twig
│   │   └── admin.html.twig
│   └── email/
│       └── *.html.twig
├── storage/
│   └── attachments/            # Buiten webroot (via symlink of X-Accel-Redirect)
├── config/
│   ├── settings.php
│   └── routes.php
├── migrations/
│   └── *.sql
├── .env.example
├── composer.json
└── README.md
```

---

## 8. UI/UX Richtlijnen

### 8.1 Pagina's & Routes

| Route | Doel | Toegang |
|-------|------|---------|
| `GET /` | Publiek ticketformulier | Iedereen |
| `POST /ticket` | Ticket aanmaken | Iedereen |
| `GET /ticket/{token}` | Klantportaal (ticket inzien/reageren) | Via unieke token in e-mail |
| `GET /login` | Inlogpagina agents/admins | Iedereen |
| `GET /dashboard` | Agent-dashboard | Agent, Admin |
| `GET /tickets` | Ticketoverzicht (lijstweergave) | Agent, Admin |
| `GET /tickets/{id}` | Ticketdetailpagina | Agent, Admin |
| `POST /tickets/{id}/reply` | Reactie toevoegen | Agent, Admin |
| `PATCH /tickets/{id}/status` | Status wijzigen | Agent, Admin |
| `PATCH /tickets/{id}/assign` | Toewijzen | Agent, Admin |
| `GET /admin/users` | Gebruikersbeheer | Admin |
| `GET /admin/categories` | Categoriebeheer | Admin |
| `GET /admin/sla` | SLA-instellingen | Admin |
| `GET /admin/templates` | E-mailsjablonen | Admin |
| `GET /admin/reports` | Rapportages | Admin |

### 8.2 UI Componenten (prioriteit)

- **Ticketkaart** (lijstweergave): ticketnummer, onderwerp, status-badge (kleurgecodeerd), prioriteit-icoon, toegewezen agent, tijdsindicator (bijv. "3 uur geleden")
- **Statusbadge**: kleurcodering — nieuw (blauw), open (groen), in behandeling (oranje), wachtend (grijs), opgelost (teal), gesloten (zwart)
- **SLA-balk**: progressiebalk met kleurcodering (groen/oranje/rood) op ticketdetailpagina
- **Tijdlijn**: chronologische weergave van reacties en statuswijzigingen, duidelijk onderscheid publiek vs. intern
- **Snelle acties**: dropdowns voor status en agent direct in de lijstweergave (AJAX)
- **Filter/zoekbalk**: bovenaan ticketoverzicht, persistent in sessionStorage

---

## 9. E-mailnotificaties

### 9.1 Triggertabel

| Event | Ontvanger(s) | Template |
|-------|-------------|----------|
| Ticket aangemaakt | Klant (bevestiging + link), alle admins | `ticket_created` |
| Ticket toegewezen aan agent | Toegewezen agent | `ticket_assigned` |
| Nieuwe publieke reactie (door agent) | Klant | `reply_from_agent` |
| Nieuwe reactie (door klant) | Toegewezen agent + admins | `reply_from_customer` |
| Status gewijzigd | Klant (indien relevant), toegewezen agent | `status_changed` |
| SLA-waarschuwing (80% verstreken) | Toegewezen agent + admins | `sla_warning` |
| SLA overschreden | Toegewezen agent + admins | `sla_breach` |
| Ticket gesloten | Klant (incl. CSAT-link indien P105 actief) | `ticket_closed` |

### 9.2 Sjabloonvariabelen

Alle templates ondersteunen: `{{ ticket.number }}`, `{{ ticket.subject }}`, `{{ ticket.status }}`, `{{ ticket.link }}`, `{{ customer.name }}`, `{{ agent.name }}`, `{{ site.name }}`, `{{ site.url }}`

---

## 10. Succesmetrieken

### 10.1 Leading Indicators (eerste 30 dagen na go-live)

| Metric | Doel | Meting |
|--------|------|--------|
| Adoptie ticketformulier | ≥ 80% klantcontacten via systeem (vs. e-mail/telefoon) | Tickets aangemaakt per week |
| Gemiddelde eerste reactietijd | < 4 uur (kantoortijden) | `audit_log` timestamp analyse |
| SLA-nalevingspercentage | ≥ 90% tickets binnen SLA-limiet | Admin dashboard KPI |
| Foutpercentage formulier | < 5% validatiefouten bij aanmaken | Server-side logging |

### 10.2 Lagging Indicators (30–90 dagen)

| Metric | Doel |
|--------|------|
| Gemiddelde doorlooptijd per prioriteit | Hoog < 8 uur, Normaal < 48 uur |
| Heropening van tickets | < 10% van gesloten tickets heropend |
| Agent-workloadverdeling | Maximale afwijking 20% tussen agents |
| CSAT-score (indien P105 actief) | ≥ 4,0 / 5,0 gemiddeld |

---

## 11. MVP Roadmap (12 weken)

| Sprint | Weken | Deliverables |
|--------|-------|-------------|
| **Sprint 1** | 1–2 | Project setup, database migraties, Slim 4 routing, authenticatie (login/logout/reset), rollen middleware |
| **Sprint 2** | 3–4 | Publiek ticketformulier, ticketaanmaak (backend), automatisch ticketnummer, bevestigingsmail, klantportaal via token |
| **Sprint 3** | 5–6 | Agent dashboard, ticketlijst (filters + zoeken), ticketdetailpagina, statuswijziging, toewijzing |
| **Sprint 4** | 7–8 | Reactiesysteem (publiek + intern), bijlage-upload, e-mailnotificaties alle triggers |
| **Sprint 5** | 9–10 | Admin paneel (gebruikers, categorieën, SLA, e-mailsjablonen), audit log viewer |
| **Sprint 6** | 11–12 | Admin rapportages dashboard, SLA-escalatielogica, performance-optimalisatie, installatie-wizard, UAT |

**MVP Go-live:** week 12 (installeerbaar bij eerste klant)

---

## 12. Open Vragen

| # | Vraag | Eigenaar | Blokkerend? |
|---|-------|----------|-------------|
| OQ1 | Wordt e-mailintake (IMAP) meegenomen in MVP of pas in P1? | Mike | Nee – standaard buiten scope |
| OQ2 | Welke SMTP-provider wordt aanbevolen aan klanten? (Mailgun / Brevo / eigen server?) | Mike | Nee – configureerbaar door installateur |
| OQ3 | Moeten bijlagen buiten webroot opgeslagen worden met X-Accel-Redirect (nginx), of is een PHP-pass-through acceptabel voor MVP? | Mike | Ja – bepaalt mapstructuur |
| OQ4 | Wordt het klantportaal volledig stateless (token only) of is optionele accountregistratie gewenst voor v1? | Mike | Ja – bepaalt DB-schema voor customers |
| OQ5 | Licentiemodel: open source (MIT) of commercieel (per installatie)? | Mike | Nee – maar beïnvloedt README en installer |
| OQ6 | Is multi-taal (NL/EN toggle) een harde klanteis voor eerste klant? | Mike | Nee – Fase 2 tenzij specifieke klant vraagt |

---

## 13. Afhankelijkheden & Risico's

| Risico | Kans | Impact | Mitigatie |
|--------|------|--------|-----------|
| SMTP-deliverability problemen bij klant | Middel | Hoog | Documenteer SPF/DKIM-configuratie; test met PHPMailer debug mode |
| Bijlage-opslag groeit onbeperkt | Middel | Middel | Maximale bestandsgrootte enforcement; toekomstige opschoonroutine |
| Clientformulier spam/abuse | Middel | Middel | Honeypot-veld + optionele CAPTCHA (P1) |
| Tokenlek klantportaal (URL gedeeld) | Laag | Hoog | Token is 64-karakter random hex; optioneel OTP-verificatie (P2) |
| PHP-sessies bij load balancing | Laag | Middel | Documenteer sessie-opslag in database of memcached als alternatief |

---

## 14. Installatie & Deployment Vereisten

**Minimale serverspecificaties:**
- PHP 8.2+ met extensies: pdo_mysql, mbstring, fileinfo, openssl, curl
- MySQL 8.0+ of MariaDB 10.6+
- Nginx (aanbevolen) of Apache 2.4
- Composer 2.x
- 512 MB RAM minimum, 1 GB aanbevolen

**Installatieproces (CLI-wizard):**
```bash
git clone https://github.com/[repo]/ticket-systeem
cd ticket-systeem
composer install --no-dev
cp .env.example .env
# Vul .env in (DB credentials, SMTP, APP_URL)
php migrate.php         # Draait SQL-migraties
php seed.php            # Maakt standaardcategorieën en admin-account aan
```

**Optionele webinstaller:** `/install/` route (disabled na eerste run via `.installed` flag)

---

## 15. Documentatieverplichtingen

Bij oplevering MVP aanwezig:
- `README.md` – installatie, vereisten, eerste stappen
- `CHANGELOG.md` – semantisch versioned
- `docs/api.md` – (Fase 2) REST API-documentatie
- Admin handleiding (PDF of docs-pagina) – gebruikersbeheer, SLA-instelling, e-mailsjablonen
- Installatiegids voor ICT-partners

---

*Dit document is een levend document en wordt bijgewerkt bij elke sprintplanningssessie.*
