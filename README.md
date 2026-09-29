# Otu-Zan

React frontend with a Laravel 12 API and a MySQL/MariaDB database managed through phpMyAdmin.

Repo layout: `frontend/` holds the React app (its own `package.json`, `src/`, `public/`, `.env`), `backend/` holds the Laravel API (including `database/` — migrations, seeders, schema — and `.env`), and `scripts/` at the root holds the shared dev/generator scripts. Run `npm` commands from `frontend/` and `php artisan` commands from `backend/`.

## Run this checkout on Windows

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. From `frontend`, run `npm.cmd start`. It starts and checks Laravel automatically before it launches React, so the sign-in page cannot open without its local API.

- Frontend: http://localhost:3000
- Laravel API: http://localhost:5000/api/health
- phpMyAdmin: http://localhost/phpmyadmin/index.php?route=/database/structure&db=otu-zan-db
- Database: `otu-zan-db` on `127.0.0.1:3306`
- Database credentials and application key: ignored `backend/.env`
- Frontend API URL: ignored `frontend/.env`
- Background launcher log: ignored `.local/laravel.log`
- Laravel application log: `backend/storage/logs/laravel.log`

The launcher uses XAMPP PHP at `C:/xampp/php/php.exe`, or PHP on PATH. Override with `PHP_BINARY` if needed. It checks database connectivity and will not replace another service already using port 5000. Use `npm.cmd run backend:start` (from `frontend`) to run Laravel in the foreground instead. `npm start` stops with a clear XAMPP instruction if the database cannot be reached rather than starting a frontend that cannot sign in.

## Setup on another computer

Requires Node.js, PHP 8.2+, Composer, and MySQL/MariaDB (XAMPP includes PHP, MariaDB and phpMyAdmin).

1. Run `npm install` in `frontend`.
2. Start XAMPP Apache and MySQL.
3. Run `composer install` inside `backend` - required on every fresh clone, `backend/vendor` is not tracked by git.
4. Copy `backend/.env.example` to `backend/.env` and set database credentials, frontend origin and staff registration codes.
5. Inside `backend`, run `php artisan key:generate`, `php artisan migrate`, then `php artisan db:seed` to load the initial catalog.
6. Copy `frontend/.env.example` to `frontend/.env`, then start the backend and frontend as above.

For real Forgot Password emails, configure the `MAIL_*` values in `backend/.env` for your SMTP provider. The default `MAIL_MAILER=log` keeps local development safe by writing reset links to `backend/storage/logs/laravel.log`; never commit `backend/.env` or SMTP credentials.

Laravel migrations adopt existing Users records and add the address and API-token storage. Existing bcrypt passwords and account IDs are preserved. Staff accounts use backend roles `driver` and `admin`; local default access codes are `DRIVER2024` and `ADMIN2024`.

## Deploying to a host

Frontend and backend deploy separately: static hosting serves the React build, while the Laravel API needs a host that runs a persistent PHP process with a MySQL service. This repo ships configs for Vercel (frontend) and Railway (backend); any equivalents work the same way.

Frontend on **Vercel**: import the repo with **root directory `frontend`** — `frontend/vercel.json` pins the CRA framework preset, `npm run build` as the build command and `build/` as the output directory, plus the SPA rewrite that routes every path to `index.html` (without it, a refresh on a client-routed URL 404s). Set `REACT_APP_API_URL` in the project's environment variables — it is baked into the bundle at build time, so rebuild after any change.

Backend on **Railway**: create the service from `backend` as the root — `backend/railway.json` pins the Nixpacks build (`composer install --no-dev --optimize-autoloader --no-interaction`) and a start command that runs `php artisan migrate --force` then `php artisan serve --host=0.0.0.0 --port=$PORT`, with `/api/health` as the health check. Two caveats: `php artisan serve` is fine for this capstone's traffic but is not a production-hardened server (swap to php-fpm/roadrunner if that ever matters), and `backend/public/uploads` is ephemeral on Railway — uploaded brand/product images and bill proofs are lost on redeploy unless you attach a persistent volume.

**Safety:** Railway environments can be transient. Before real user data exists, verify your database backup strategy (Railway's automated snapshots or external scheduled dumps) to protect against data loss.

Backend environment, set on the host - never committed:

- `APP_ENV=production` and `APP_DEBUG=false`: debug mode returns full stack traces with real server paths to anyone who trips an error.
- `APP_URL`: the backend's own public URL (`https://...`).
- `FRONTEND_URL`: the deployed frontend origin, exactly (scheme + host, no trailing slash) — for the Vercel deployment above, your `https://....vercel.app` origin. CORS allows this origin and rejects every other; comma-separate if two origins are ever needed.
- `DB_*` and `MAIL_*`: the host's MySQL and SMTP credentials.
- Run `php artisan key:generate` once per environment and `php artisan migrate --force` on each release.

First admin account: public signup always creates customers, so bootstrap the first admin on the server with:

    php artisan otuzan:make-admin "admin@example.com" "Admin Name"

It prompts for a password (`--password=` to pass one explicitly, `--user-type=student` if needed). That admin can then create rider and customer accounts through the admin UI.

Frontend build: set `REACT_APP_API_URL` to the backend's public URL in the host's build environment variables (Vercel project settings). It is baked into the bundle at `npm run build` time, so rebuilding is required after any change.

After pulling backend changes that include seeders, run `php artisan db:seed` inside `backend` to add new catalog entries (e.g. `JollibeeProductsSeeder`) to your local database - pulling code does not update anyone else's database.

## Backend and verification

Laravel implements registration, login, current account, logout, the admin-only rider directory, and health endpoints. API responses retain the React frontend's existing format. Bearer authentication uses Laravel Sanctum with tokens valid for two hours. Sign in again after migrating from the old Node backend; old JWT sessions are not Laravel tokens.

The customer Home and product menus read the active service, brand, and product catalog from MySQL through `/api/catalog`. Admin Catalog changes are persisted through the protected `/api/admin/catalog` routes and appear to customers on refresh or when they return to Home. Brand and product uploads are served from Laravel's public `uploads` directory. The initial catalog seed only runs when the Brands table is empty, so subsequent normal `db:seed` runs do not restore deleted catalog entries.

All of these run from `frontend/`:

- `npm run backend:db-check`: verify Laravel database access
- `npm run backend:migrate`: apply Laravel migrations
- `npm run generate:manuelas-catalog`: refresh Manuela's Product Management import from the customer menu; run `php artisan db:seed --class=ManuelasProductsSeeder` inside `backend` to add any new entries
- `npm run backend:test`: Laravel tests using a separate in-memory SQLite database
- `npm test -- --watchAll=false`: React tests
- `npm run build`: frontend production build

Backend tests can also be run directly as `php artisan test` from `backend/`.

## Migration notes

This checkout was moved from the separate MySQL instance on port 3307 to XAMPP on port 3306. All seven tables were copied and row counts verified. Other XAMPP databases were left in place. The old database files and migration backups remain in ignored `.local` storage; they are no longer the active database. The former Express implementation was deleted in commit `01aecd5` (Laravel is the only backend), and the repo has since been split into `frontend/` + `backend/` folders — the migration from the old Node backend is complete.

Orders and assignment still use the existing browser-storage implementation. Status updates propagate within the app and across tabs in the same browser profile. Moving that workflow to server-side storage and cross-device delivery is separate from this authentication/database migration.
