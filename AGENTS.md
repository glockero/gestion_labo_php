# Laboratorio PHP

## Stack
- PHP (plain, no framework)
- MySQL (`laboratorio_db`)
- Bootstrap 5 (CDN), Tom Select

## Web Root
- `public/` is the web root (not project root)
- Entry point: `public/index.php`
- Install: `public/install.php` creates the first admin user

## Setup
1. Run `database/schema.sql` to create the database and tables
2. Configure `app/config.php` (DB credentials, `APP_URL`)
3. Visit `public/install.php` to create the first admin user
4. Default DB: host `127.0.0.1`, user `root`, password empty

## Key Conventions
- `APP_URL` in `config.php` must match the deployment path (e.g. `/laboratorio_php/public` for dev, `/public` for cPanel)
- All forms use CSRF (`generateCsrfToken()` / `requireCsrf()`)
- Timezone: `America/Argentina/Buenos_Aires`
- Auth: session-based, single-session-per-user enforcement in `app/auth.php`
- Roles: `admin` (full access), `tecnico` (limited)
- Redirect helper: `redirect('/path')` appends `APP_URL`

## Directory Layout
- `app/` — models, auth, helpers, config (core PHP includes)
- `public/` — web entry points (`index.php`, `login.php`, `install.php`, `dashboard.php`)
- `admin/` — admin-only pages (requires `admin` role)
- `api/` — JSON API endpoints (state changes, comments, priority)
- `exports/` — Excel export
- `database/` — SQL schema only

## API Files (direct POST/GET)
- `api/check_npu.php` — NPU uniqueness check
- `api/reparacion_estado.php` — change repair state
- `api/reparacion_comentario.php` — add comment
- `api/reparacion_prioridad.php` — change priority
- `api/reparacion_tecnico.php` — assign technician

## Models
- `ReparacionModel` — repairs CRUD + filtering
- `CatalogoModel` — catalog lookup (salas, equipos, familias, estados, tecnicos)
- `UsuarioModel` — user management
