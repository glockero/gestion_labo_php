# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Laboratorio Técnico — repair-order management system for a technical lab (intake → repair → delivery, with technician/state tracking and history). Port of an older Python/Flask+SQLite app.

**Stack:** PHP 8+ (no framework), MySQL/MariaDB (`laboratorio_db`), Bootstrap 5 (CDN), Tom Select, vanilla JS/jQuery. No build step, no package manager, no test suite.

## Setup / Run

1. Import [database/schema.sql](database/schema.sql) into MySQL/MariaDB.
2. Edit [app/config.php](app/config.php): DB credentials and `APP_URL` (must match the deployment path — e.g. `/laboratorio_php/public` for Laragon dev, `/public` for cPanel).
3. Serve via Laragon/XAMPP/Apache. Web root is **`public/`**, not the project root.
4. Visit `public/install.php` once to create the first admin user.
5. Default dev DB: host `127.0.0.1`, user `root`, empty password, db `laboratorio_db`.

There are no build, lint, or test commands — changes are validated by loading pages in the browser.

## Architecture

Simplified MVC; all entry points are PHP scripts that `require_once` from `app/`.

- **`app/`** — core includes. `config.php` (constants + session start), `db.php` (PDO singleton), `auth.php`, `csrf.php`, `helpers.php` (`e()`, `redirect()`), `historial.php` (`registrarHistorial()`), `estado.php` (state machine), `flash.php`, models (`ReparacionModel`, `CatalogoModel`, `UsuarioModel`), `ImportService`, `XlsxBuilder`.
- **`public/`** — web-facing pages: `index.php`, `login.php`, `dashboard.php`, `reparacion_{nueva,editar,detalle}.php`, plus `assets/` (CSS/JS) and `includes/` (shared header/sidebar/footer partials).
- **`admin/`** — admin-only pages, gated by `requireRole('admin')`: backups, CSV import, user config, history, deletion.
- **`api/`** — JSON endpoints called via AJAX for partial updates (change estado, priority, technician, add comment, NPU uniqueness check, devolver).
- **`exports/exportar_excel.php`** — Excel report generation via `XlsxBuilder`.
- **`database/schema.sql`** — single-source schema (no migrations).

### Cross-cutting conventions

- **Web root indirection:** every internal URL must be built with `redirect('/path')` or prefixed with `APP_URL` — never hard-code `/laboratorio_php/...`.
- **Auth:** session-based with **single-session-per-user** enforcement. `login()` stamps `session_id` into `usuarios`; `requireLogin()` rejects if the row's `session_id` no longer matches. Login is rate-limited (5 attempts / 5 min → 15 min block) via an auto-created `login_attempts` table — see [app/auth.php](app/auth.php).
- **Roles:** `admin` (full) vs `tecnico` (limited to own repairs). Gate with `requireRole('admin')`. Some UI affordances (e.g. editing fecha de ingreso) require admin.
- **CSRF:** every state-changing form/endpoint pairs `generateCsrfToken()` (render) with `requireCsrf()` (handler). API endpoints in `api/` also enforce this.
- **All repair access goes through `ReparacionModel`** — do not write ad-hoc SQL against the `reparaciones` table in pages or endpoints. Significant state changes must be logged via `registrarHistorial()`.
- **State machine** lives in [app/estado.php](app/estado.php). States are normalized by label (uppercased/trimmed) and bucketed into categories (`revision`, `working`, `waiting`, `repaired`, `unrepaired`, `delivered`). `canTransitionEstado()` has separate admin/tecnico transition matrices; `estadoTransitionRequiresComment()` decides when a comment is mandatory. Add new states by extending the predicate functions, not by sprinkling string comparisons.
- **Output escaping:** always `e($value)` when echoing user-supplied data. PDO prepared statements for all queries.
- **Locale:** timezone hard-coded to `America/Argentina/Buenos_Aires`. DB tables/columns and session keys are in Spanish; code is mixed Spanish/English.

### UI/UX conventions (high-density SaaS)

The codebase deliberately follows a compact admin aesthetic — keep new UI consistent with it:

- Standardized control height **38–40px** via `.btn-compact`, `.form-control-compact`.
- Page titles 26–28px; body/table/form text 13–14px; secondary labels 12px in muted gray (`#64748b`).
- Admin tables use `table-layout: fixed` with sticky headers and an internal scroll container (`.table-scroll`, `max-height: calc(100vh - 330px)`). Stack two values per cell (`.table-cell-stack`, `.identificacion-cell`) instead of widening columns; truncate long text with ellipsis.
- Estados render as pill/chip badges (`.status-pill`, `.nav-tab-custom`) with a fixed color mapping: Urgentes=red, Pend. Reparación=blue, En Reparación=violet, Reparados=green, Pendientes=mustard, Sin Reparación=slate, Todas=orange.
- Modals: `border-radius: 12px`, `shadow-sm`, `max-width: 340–400px`.
- Sensitive fields (e.g. fecha de ingreso) default to `readonly` and unlock via an admin-only toggle.

## Data import/export

Repairs and equipment can be bulk-imported from CSV via `admin/importar_csv.php` (logic in `app/ImportService.php`). Sample CSVs at repo root: `equipos.csv`, `familias.csv`, `reparaciones.csv`. Excel export at `exports/exportar_excel.php` uses the in-house `XlsxBuilder` (no PhpSpreadsheet dependency).

Equipment identity uses both **UID** and **NPU**; NPU uniqueness is validated live via `api/check_npu.php`.
