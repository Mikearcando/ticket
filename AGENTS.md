# Repository Guidelines

## Project Structure & Module Organization

This is a dependency-light PHP ticket system. Core application logic lives in `src/`, with `src/App.php` handling routing and request flow and `src/Installer.php` handling CLI/web installation. The webroot is `public/`; keep only public assets and the front controller there. Configuration belongs in `config/`, SQL migrations in `migrations/`, operational scripts in the repository root or `scripts/`, deployment examples in `deploy/`, and contributor/admin documentation in `docs/`. Runtime data belongs in `storage/` and must not be committed.

## Build, Test, and Development Commands

Use Docker for the most consistent local environment:

```powershell
docker compose up -d --build
docker compose exec app php migrate.php
docker compose exec app php seed.php
.\scripts\smoke-test.ps1 -BaseUrl http://127.0.0.1:8081
```

Useful maintenance commands:

```powershell
docker compose exec app php sla_check.php
docker compose exec app php retention_cleanup.php
.\scripts\performance-test.ps1 -BaseUrl http://127.0.0.1:8081
.\scripts\load-test.ps1 -BaseUrl http://127.0.0.1:8081
```

For non-Docker development, run `composer install`, configure `.env`, then use `php -S 127.0.0.1:8080 -t public`.

## Coding Style & Naming Conventions

Write PHP for PHP 8.2+. Use four-space indentation, strict comparisons where practical, descriptive method names, and small helper methods for repeated request or rendering logic. Keep route handling readable and avoid introducing framework-specific patterns unless the project adopts that framework. SQL migration files use numeric prefixes, for example `005_add_ticket_tags.sql`.

## Testing Guidelines

There is no PHPUnit suite yet. Validate changes with the smoke test after migrations and seed data are applied. For changes affecting ticket lists, filters, SLA, or concurrency, also run the performance and load scripts. Add focused scripts or docs updates when introducing new operational behavior.

## Commit & Pull Request Guidelines

Current history uses concise imperative commits, for example `Build ticket system MVP`. Keep future messages short and action-oriented: `Add SLA escalation report`, `Fix customer reply validation`. Pull requests should include a clear summary, commands run, migration impact, screenshots for UI changes, and linked issues when available.

## Security & Configuration Tips

Never commit `.env`, `.installed`, `vendor/`, attachments, logs, or generated `storage/` snapshots. Keep `APP_ENV=production` for production, use strong SMTP/database credentials, and ensure `storage/attachments/` is not directly browseable. Test SMTP with `smtp_test.php` before go-live.
