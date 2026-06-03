# Admin-handleiding

Deze handleiding is bedoeld voor beheerders van het Ticket Systeem MVP.

## Eerste login

1. Rond de installatie af volgens [installatiegids.md](installatiegids.md).
2. Maak of seed het eerste admin-account met `composer seed`.
3. Open de applicatie op de ingestelde `APP_URL`.
4. Ga naar `/login`.
5. Log in met het admin-account uit de seed-stap.
6. Wijzig direct het tijdelijke wachtwoord.

Gebruik een uniek wachtwoord en bewaar het niet in gedeelde documentatie of tickets.

## Dashboard

Het admin-dashboard hoort de belangrijkste KPI's te tonen:

- aantal open tickets;
- gemiddelde reactietijd;
- tickets per agent;
- SLA-naleving;
- tickets die bijna of al over SLA zijn.

Gebruik het dashboard dagelijks om werkdruk, onbehandelde tickets en SLA-risico's te controleren.

## Tickets beheren

Tickets hebben de statussen:

- `nieuw`
- `open`
- `in_behandeling`
- `wachtend_op_klant`
- `opgelost`
- `gesloten`

Praktische werkwijze:

1. Open `/tickets`.
2. Filter op status, prioriteit, categorie of agent.
3. Open een ticketdetailpagina.
4. Wijs het ticket toe aan een agent als dat nog niet is gebeurd.
5. Voeg een publieke reactie toe voor klantcommunicatie.
6. Gebruik interne notities alleen voor teaminformatie.
7. Zet de status naar `opgelost` wanneer het inhoudelijk klaar is.
8. Sluit het ticket pas wanneer de klant akkoord is of de afgesproken wachttijd voorbij is.

## Gebruikersbeheer

Admins beheren gebruikers en rollen via `/admin/users`.

Aanbevolen regels:

- Gebruik voor iedere medewerker een eigen account.
- Geef alleen systeembeheerders de rol `admin`.
- Open een bestaande gebruiker via de naam in de tabel om naam, e-mail, rol, status, notificatievoorkeur of wachtwoord te wijzigen.
- Deactiveer accounts van vertrokken medewerkers in plaats van ze te hergebruiken.
- Laat wachtwoorden nooit per e-mail of chat rondgaan; gebruik een resetlink of een tijdelijk wachtwoord dat direct wordt gewijzigd.
- Het systeem voorkomt dat de laatste actieve admin wordt gedeactiveerd of gedegradeerd.

Rollen:

- `viewer`: tickets, tijdlijn en bijlagen bekijken zonder tickets te wijzigen.
- `agent`: tickets behandelen, toewijzen, reageren en sluiten.
- `manager`: alles wat een agent kan, plus rapportages, auditlog en configuratiestatus bekijken.
- `admin`: alles wat een manager kan, plus gebruikers, categorieen, SLA-instellingen en e-mailsjablonen beheren.

## Categorieen beheren

Categorieen staan onder `/admin/categories`.

Voorbeelden:

- Hardware
- Software
- Netwerk
- Facturatie
- Overig

Houd de lijst kort voor het MVP. Te veel categorieen maken het aanmaken en filteren van tickets trager en foutgevoeliger. Deactiveer oude categorieen als ze niet meer gebruikt worden.

Open een bestaande categorie via de naam in de tabel om naam, omschrijving of actief-status te wijzigen. Het systeem voorkomt dat de laatste actieve categorie wordt gedeactiveerd.

## SLA-instellingen

SLA-instellingen staan onder `/admin/sla`.

De PRD gaat uit van vier prioriteiten:

| Prioriteit | Voorbeeld eerste reactie | Voorbeeld oplossing |
|---|---:|---:|
| laag | 16 uur | 120 uur |
| normaal | 8 uur | 48 uur |
| hoog | 4 uur | 8 uur |
| kritiek | 1 uur | 4 uur |

Pas deze waarden aan op echte afspraken met klanten. Controleer na wijziging of bestaande tickets opnieuw berekend moeten worden; dat is afhankelijk van de implementatie.

De periodieke SLA-check draait via:

```bash
php sla_check.php
```

Deze check stuurt een `sla_warning` bij 80% verstreken oplostijd en een `sla_breach` na overschrijding. Beide acties worden ook in de auditlog vastgelegd.

Tickets zonder eerste agentreactie worden ook op `first_response_hours` bewaakt. Bij dreigende of daadwerkelijke overschrijding gebruikt het systeem dezelfde SLA-mailtemplates en legt het `first_response_sla_warning` of `first_response_sla_breach` vast in de auditlog.

## E-mailsjablonen

E-mailsjablonen staan onder `/admin/templates`.

Belangrijke events:

- `ticket_created`
- `ticket_assigned`
- `reply_from_agent`
- `reply_from_customer`
- `status_changed`
- `sla_warning`
- `sla_breach`
- `ticket_closed`

Gebruik duidelijke onderwerpen met het ticketnummer, bijvoorbeeld:

```text
[{{ ticket.number }}] {{ ticket.subject }}
```

Ondersteunde variabelen volgens de PRD:

- `{{ ticket.number }}`
- `{{ ticket.subject }}`
- `{{ ticket.status }}`
- `{{ ticket.link }}`
- `{{ customer.name }}`
- `{{ agent.name }}`
- `{{ site.name }}`
- `{{ site.url }}`

Test na aanpassing altijd een echte notificatie naar een intern adres.

## Bijlagen

Het MVP ondersteunt bijlagen zoals PNG, JPG, PDF, ZIP en LOG tot 10 MB. Bijlagen horen opgeslagen te worden in `storage/attachments/`.

Beheerregels:

- Upload geen wachtwoorden, privegegevens of API-sleutels tenzij strikt noodzakelijk.
- Verwijder gevoelige bijlagen als ze niet meer nodig zijn en het beleid dat toestaat.
- Controleer periodiek de gebruikte schijfruimte.

## Basisrapportage

Gebruik `/admin/reports` voor rapportages zodra deze route beschikbaar is.

Minimale rapportagevragen:

- Hoeveel tickets staan open?
- Welke tickets zijn over SLA?
- Welke agents hebben de meeste open tickets?
- Wat is de gemiddelde afhandeltijd per prioriteit?
- Welke categorieen veroorzaken de meeste tickets?

## Auditlog

Gebruik `/admin/audit` om ticketacties te controleren. De viewer toont onder meer:

- ticketaanmaak;
- statuswijzigingen;
- toewijzingen;
- reacties;
- SLA-waarschuwingen en SLA-overschrijdingen.

Filter op ticketnummer of actietype bij incidentonderzoek of SLA-discussies.

## Configuratie

Gebruik `/admin/config` om runtime-instellingen te controleren zonder geheime waarden te tonen. Managers kunnen deze status bekijken. Admins kunnen applicatie-, database-, SMTP- en retentie-instellingen via de web-UI aanpassen; wachtwoordvelden blijven leeg en behouden de bestaande secret tenzij expliciet een nieuwe waarde wordt ingevuld of de wisoptie wordt aangevinkt. Herstart daarna de applicatiecontainer of webserver als de runtime de waarden al had geladen.

## P1-functies

- Kennisbank: beheer artikelen via `/admin/knowledge-base`; klanten lezen ze via `/knowledge-base`.
- Bulkacties: selecteer tickets in `/tickets` en wijzig status of toewijzing in een keer.
- Tijdregistratie: boek minuten op de ticketdetailpagina.
- CSAT: bij sluiten van een ticket wordt een beoordelingslink meegestuurd.
- Exports: download CSV of PDF via `/admin/reports`.
- Webhooks: beheer Teams/Slack/eigen endpoints via `/admin/webhooks`.
- IMAP-intake: configureer IMAP via `/admin/config` en draai `php imap_intake.php`.
- AD/LDAPS: configureer host, bind-account en group mapping via `/admin/config`; test de verbinding op dezelfde pagina.
- Thema: stel brand/accentkleur in via `/admin/config`; gebruikers kunnen in de navigatie darkmode wisselen.

## Veilig beheer

- Gebruik HTTPS.
- Zet `APP_ENV=production` in productie.
- Houd PHP, webserver en Composer-dependencies actueel.
- Controleer dat `.env` niet publiek bereikbaar is.
- Gebruik SPF en DKIM voor betrouwbare e-mailbezorging.
- Test SMTP met `php smtp_test.php ontvanger@example.nl`.
- Maak periodieke databaseback-ups en test herstel.
