# AGENTS.md — log-anomaly-detector

Source of truth for AI coding tools. Scoped `AGENTS.md` files under `app/`, `migrations/`, `tests/` carry local conventions; security rules live in [SECURITY.md](SECURITY.md).

## Project

Unsupervised anomaly detection for HTTP logs: **PHP 8.4 · Flight PHP (HTTP only) · PHP-ML (DBSCAN, encapsulated) · SQLite (SimplePdo)**. Primary surface is the JSON API under `/api/v1`; the only HTML page is the dashboard (`/`, Twig).

## Layout

| Path | Role |
|------|------|
| `app/Domain/Anomaly/` | Entities, value objects, enums, **ports** (`AnomalyDetector`, `CategoricalEncoder`, `Normalizer`, repositories, loader) |
| `app/Application/Anomaly/` | Use case `AnalyzeLogs` (pipeline orchestration) |
| `app/Infrastructure/MachineLearning/` | PHP-ML wrapper, encoder, normalizer — **only place allowed to `use Phpml\`** |
| `app/Infrastructure/Log/` | CSV dataset loader |
| `app/Infrastructure/Persistence/` | SQLite repositories (SimplePdo + prepared statements); `SqliteAnalysisResultRepository` persists run+entries **atomically in one transaction** |
| `app/Controller/`, `app/config/routes.php` | Thin HTTP actions; no ML/SQL/domain logic |
| `app/views/` | Twig: `layout.twig` + `dashboard.twig` |
| `migrations/` | Plain SQL applied by `php runway migrate` |
| `tests/Unit/`, `tests/Integration/` | Unit = pure/isolated; Integration = real SQLite + migrations + full pipeline |

## Hard rules

1. **No `Phpml\` imports outside `app/Infrastructure/MachineLearning/`.**
2. **No ML, SQL, or feature engineering in controllers/routes.** Flow: Request → validation → domain DTO → use case/repository → response.
3. **No `Flight::` facade in app layer** — inject `flight\Engine`. Bootstrap/services may use `Flight::app()`.
4. **No `$_ENV` outside bootstrap** (`Env::load`, `Config::mergeEnv`) and CLI config loading. Inject `App\Utils\Config`.
5. **Anomaly = DBSCAN noise point.** Never invent `confidence`/`probability`/`anomaly_score`. `POST /api/v1/detect` stays `501` (no incremental DBSCAN inference).
6. **Analysis persistence is atomic** — write runs+entries through `AnalysisResultRepository::save()`, never two separate repository calls.
7. **Style**: `declare(strict_types=1)`, no `else`/`elseif` (`match` instead), ≤15 methods per class, ≤3 returns per function, magic numbers in consts, native PHP functions first.
8. **API never accepts filesystem paths**; inline logs only, with payload/log-count limits.

## Flight / DI specifics

- Controllers are resolved by Dice (`[Class::class, 'method']` in `routes.php`). Shared services are constructed in `app/config/services.php` and substituted by interface/class — reassign `$container = $container->addRule(...)` every time (Dice is immutable per call); substitute `Engine::class => $app`.
- `services.php` runs in two contexts: web (Engine + Config present) and Runway CLI (raw array config, **no Engine**) — it returns early in CLI.
- `json()`, `request()`, `render()` are Engine magic (`@method` annotated); `render` maps to Twig.

## Config

- `app/config/config.php` = literal defaults (Runway-safe; `runway config:set` rewrites it — never put `$_ENV` there). Secrets/deploy overrides in `.env`, merged by `Config::mergeEnv` (env wins when non-empty). Env allowlist = `Config::ENV_MAP`.
- New anomaly settings go under the `anomaly` key; optional env override requires `.env.example` + `ENV_MAP` entry.

## Migrations / database

- SQLite default (`DB_DRIVER=sqlite`). Files: `migrations/YYYYMMDDHHMMSS_description.sql`. Apply: `php runway migrate`. `_migrations` tracks applied names.
- SQLite connections enable `PRAGMA foreign_keys = ON` in `DatabaseFactory` (connection-level only).
- `log_entries.analysis_run_id` has `FOREIGN KEY … ON DELETE CASCADE`.

## Testing & static analysis (required after changes)

```bash
composer test      # PHPUnit (suite: Log Anomaly Detector)
composer analyse   # PHPStan level 8 — do not lower
composer check     # both
```

- Unit tests: no DB, no filesystem where avoidable; mock Engine with `addMethods()` for magic methods (`json`, `request`, `render`).
- Integration tests (`tests/Integration/`): use `Tests\Integration\Support\TestDatabase` (temp SQLite + real migrations), clean up temp files in `tearDown`.
- ML tests must be deterministic (fixed datasets, seeded generator in `scripts/generate_dataset.php`).

## What not to do

- Do not re-add ActiveRecord, Session, or a second ORM/view engine.
- Do not lower PHPStan level or skip `composer check` after PHP changes.
- Do not document phantom CLI commands; Runway commands live in `app/commands/`.
- Do not edit an already-applied migration for deployed databases — add a new file.
- Do not put secrets in git (see SECURITY.md).
