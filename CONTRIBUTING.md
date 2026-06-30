# Contributing to attrecord

Thanks for your interest in improving attrecord! This is a small, dependency-free library,
so the contribution loop is simple.

## Requirements

- PHP 8.1+ (CI runs 8.1–8.4)
- [Composer](https://getcomposer.org/)
- Docker (for the integration test databases) — optional; unit tests need no database

## Getting started

```bash
git clone https://github.com/Nandan108/attrecord.git
cd attrecord
composer install
```

## Running the checks

The same three checks run in CI; please make sure all pass before opening a PR.

```bash
# Unit tests (no database needed)
composer test -- --testsuite unit

# Integration tests against MySQL/MariaDB + PostgreSQL
docker compose up -d
composer test                       # unit + integration, both backends

# Static analysis (must be zero errors)
composer psalm

# Code style — PHP CS Fixer
composer cs-check                   # report violations (CI uses this)
composer cs-fix                     # apply fixes locally
```

Connection settings for the integration suites default to the `docker-compose.yml` services;
override via the `DB_*` / `PGSQL_*` environment variables documented in the README if needed.
PostgreSQL tests skip (rather than fail) when no PostgreSQL server is reachable.

## Coding standards

- **Style:** PHP CS Fixer with the `@Symfony` ruleset plus project overrides
  (`.php-cs-fixer.php`). Run `composer cs-fix` before committing.
- **Static analysis:** Psalm at level 1 — keep it clean. Prefer precise type annotations over
  `@psalm-suppress`; reserve suppressions for cases the type system genuinely can't express.
- **Tests:** new behaviour needs coverage. Behaviour that must hold on both engines belongs in a
  shared `…Cases` trait under `tests/Integration/Cases/` (it then runs on MySQL and PostgreSQL);
  backend-specific SQL-shape assertions live in the per-backend concrete class.
- **Commits:** conventional-commit style (`feat:`, `fix:`, `docs:`, `test:`, …) with a clear body.

## Scope

attrecord is intentionally small and framework-agnostic. Schema diffing, `ALTER TABLE`
generation, and migration tracking are **out of scope** (see
[docs/ddl-generation.md](docs/ddl-generation.md)); DDL emission is fresh-install only. Deferred
ideas are tracked in [docs/backlog.md](docs/backlog.md) — open an issue to discuss before
building anything large.

## Reporting bugs

Open an issue with a minimal reproduction: the Record definition, the call you made, and what
you expected vs. observed (include the dialect — MySQL/MariaDB or PostgreSQL).
