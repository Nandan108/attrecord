# attrecord — Project Standards

A dependency-free, attribute-driven active-record library. **Tri-dialect: MySQL/MariaDB,
PostgreSQL, SQLite.** Everything below exists because a dialect difference is a *correctness*
difference, not a style one.

## Definition of Done — a change is NOT done until all of this is green

1. **Tests pass on ALL THREE backends.** `composer test` runs the full suite against MySQL/MariaDB,
   PostgreSQL, *and* SQLite. **A skipped backend is NOT a passing backend.** If a backend is down
   locally, bring it up — do not skip it and do not rely on CI to be the first place it runs:
   ```bash
   docker compose up -d          # starts the mariadb (:3306) + postgres (:5432) services
   composer test                 # MySQL + PG + SQLite; expect 0 skipped for infra reasons
   ```
   PG connection env (defaults): `PGSQL_HOST=127.0.0.1 PGSQL_PORT=5432 PGSQL_DB=attrecord_test
   PGSQL_USER=postgres PGSQL_PASS=postgres`. MySQL analogues under `MYSQL_*`.
   > **Why this rule exists (2026-07-24):** v0.9.0 shipped with two PostgreSQL-only bugs because PG
   > was down locally and the suite silently skipped its 10 PG tests — the SQL looked fine on MySQL.
   > CI (which runs PG) caught them after tag+push. A red release is far more expensive than
   > `docker compose up -d`. **Never tag/push/GH-release until the full matrix is green locally.**
2. **Static analysis** — `composer psalm` must produce **zero** errors (psalm level 1).
3. **Code style** — `composer cs-fix` run and changes re-staged before commit.
4. **Coverage** — new code paths covered; the deliberate exception is a defensive error-rethrow
   `catch` (consistent with the existing ones — document if you add another).
5. **Docs move with the code.** A public-surface change updates **all three** doc homes:
   `README.md` (the human narrative guide), `docs/llm-reference.md` (the exhaustive AI-facing
   reference), and `CHANGELOG.md`. The README is the one that silently drifts — check it explicitly.

## Cross-dialect gotchas (the running list — add to it every time one bites)

Generated SQL must be valid on **all three** engines. Known traps:

- **PostgreSQL `ON CONFLICT DO UPDATE SET`: a bare column is ambiguous** between the target table and
  the `EXCLUDED` pseudo-row (SQLSTATE 42702). The "existing value" reference must be **table-qualified**
  (`table.col`). `Record::stored()` / `UpsertColumn->stored` do this; hand-written upsert expressions
  must too. MySQL/SQLite accept the qualified form equally.
- **PostgreSQL/SQLite reject an explicit `NULL` into a SERIAL/AUTOINCREMENT PK** — the sequence default
  fires only when the column is **omitted**. MySQL/MariaDB auto-fill an explicit `NULL`. So a
  single-statement multi-row write can't mix PK-null and PK-carrying rows on PG (one uniform column
  list can't omit the PK for only some rows). `insertAll()` requires homogeneous batches;
  `upsertAll(strategy: Lockless)` requires every record to carry its PK.
- **PostgreSQL types a bare `NULL` / bare string literal as `text`/`unknown`** inside `VALUES` and
  `CASE` branches, then rejects it against a non-text column (SQLSTATE 42804). Emit **typed** nulls
  (`CAST(NULL AS <type>)`) and typed literals where the surrounding context can't infer the type
  (multi-row derived tables, `CASE … END`). `PgsqlDialect::toLiteral()` casts nulls for exactly this
  reason. *(This is the root of the still-open `created_at` derived-table failure — see below.)*
- **SQLite**: no per-row `FOR UPDATE` (writers serialize at the DB level → `forUpdateClause()` is
  empty); `RETURNING` needs 3.35+; `INSERT OR IGNORE` swallows NOT-NULL/CHECK violations, not just key
  conflicts (prefer `ON CONFLICT DO NOTHING` when you mean *only* key conflicts).
- **MySQL `VALUES(col)` for the incoming row is deprecated (8.0.20+)** but is the only form MariaDB
  supports — keep it as the portable MySQL-family choice. PG/SQLite use `EXCLUDED.col`.

## Snapshot canonicalization (the v0.7.0 → v0.9.1 PG-red root cause, fixed in v0.9.2)

PostgreSQL CI was red from **v0.7.0** (`0846c86`, the read-back feature) through v0.9.1. Root cause:
`hydrateFromRow()` / `patchColumnsFromRow()` snapshotted plain columns as the **raw DB string**
(`(string) $raw`) while `refreshSnapshot()` and `dirtyFields()` use the **canonical**
`ColumnSerializer::toSnapshotString($value)`. For a `DateTime`, PG's raw `timestamp` string differs
from that canonical form (MySQL's happens to match), so a read-back left the record **falsely dirty**
— and that falsely-dirty timestamp then got pulled into a keyed upsert's UPDATE set, where the 3-step
derived-table `u."created_at"` (typed `text`) hit a `timestamp` column (SQLSTATE 42804). Both
symptoms, one cause. Fix: **snapshot the canonical form everywhere** (all three writers now call
`toSnapshotString`). Invariant to preserve: *the snapshot a row is loaded/read-back with must be
byte-identical to what `dirtyFields()` computes for the same value* — never snapshot a raw DB string.

Latent-but-untriggered: the 3-step derived-table still emits bare literals that PG types as `text`;
it only bit via the falsely-dirty timestamp. If a timestamp/`Json`/binary column is *legitimately*
updated through the Locked keyed-upsert path on PG, add typed literals to the derived table.

## Commit & release

- Detailed, conventional commits. Breaking changes get a `!` and a **Breaking** note in the CHANGELOG.
- `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>` trailer.
- **Releases are CI-gated — do NOT hand-create `vX.Y.Z` tags.** Packagist publishes on tag existence,
  so the tag must be a *product* of green CI, not a precondition. Flow: promote the CHANGELOG section →
  push the release commit to `main` → run the **Release** workflow (Actions → Release → Run workflow →
  `version: X.Y.Z`; see `.github/workflows/release.yml`). It re-runs the full matrix and only then
  creates the annotated tag + GitHub release, so a red build never reaches Packagist. A manual
  `git tag && git push` bypasses this gate — don't (and ideally a `v*` tag ruleset rejects it; set one
  via repo Settings → Rules if not yet configured). Still run `docker compose up -d && composer test`
  locally first so the workflow doesn't fail on something you could have caught. Pre-1.0: a minor may
  break — mark it `!` + **Breaking** in the CHANGELOG.
