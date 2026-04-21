# Deploying Open Rugby

This is the first-deploy runbook for pushing the app to a production server.
The app is a stock Laravel 12 + Livewire 4 install — Forge, Ploi, or any
Nginx + PHP-FPM host works.

## 1. Prerequisites

On the target server:

| Requirement | Version | Notes |
| --- | --- | --- |
| PHP | 8.2 or 8.3 | with `bcmath`, `curl`, `dom`, `intl`, `mbstring`, `mysql`, `pdo`, `tokenizer`, `xml`, `zip` |
| MySQL | 8.0+ | app uses `enum` columns and JSON casts; keep on MySQL |
| Composer | 2.7+ | |
| Node | 20+ | only needed during build; not at runtime |
| Git | any | to clone + pull updates |
| Supervisor | optional | only once you add a queue worker (see §8) |

## 2. Decide: migrate vs DB export

> **TL;DR — export the dev DB. Do not migrate-from-scratch for a real launch.**

The dev database has ~11k matches, ~9k players, ~125k events, ~174k lineups,
~21k RAG documents. That's weeks of ingest across API-Sports (100 req/day on
the free tier), ESPN scrapes, all.rugby, URC, Kaggle historicals. Re-ingesting
from zero isn't practical.

**Option A — Export + restore (recommended for launch).**

```bash
# On dev:
mysqldump -u root -p \
  --single-transaction --quick --default-character-set=utf8mb4 \
  --no-tablespaces openrugby > openrugby.sql
gzip openrugby.sql
scp openrugby.sql.gz deploy@server:/tmp/

# On server, after creating the empty DB + user:
gunzip -c /tmp/openrugby.sql.gz | mysql -u openrugby -p openrugby
```

Then `php artisan migrate --force` on every future deploy. Laravel tracks the
`migrations` table, so already-applied migrations are no-ops and only new
ones run — your data is safe.

**Option B — Migrate + seed (only for a demo/staging env).**

```bash
php artisan migrate --force
php artisan db:seed --force             # seeds competitions
php artisan rugby:sync-daily            # primes with today's fixtures
```

You'll start with ~41 empty competitions and whatever today's API-Sports pull
returns. The chatbot won't work well until documents are generated
(`rugby:generate-rag` picks that up at 05:00 UTC daily, or run manually).

## 3. Clone the repo

```bash
cd /var/www
git clone git@github.com:danbaileyza/openrugby.app.git openrugby
cd openrugby
```

## 4. Configure `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Then edit `.env` and set at minimum:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://openrugby.app`
- `DB_CONNECTION=mysql` + `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`
- `OPENAI_API_KEY=sk-...` (chatbot; leaves `/api/chat` returning 503 if unset)
- `RUGBY_API_SPORTS_KEY=...` (daily sync)
- `RUGBY_API_SPORTS_DAILY_LIMIT=100` (bump to 7500 on the paid plan)

Optional (not required for launch but worth considering):

- `MAIL_*` — password resets fail silently until configured.
- `CACHE_STORE=redis` / `SESSION_DRIVER=redis` if you're going multi-server.

## 5. Install + build

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

Vite writes the manifest + compiled assets to `public/build/`. These are
served directly — no Node runtime needed in production.

## 6. Database

Follow §2. If it's a real launch:

```bash
# ... after restoring the SQL dump ...
php artisan migrate --force          # picks up any migrations added since
```

## 7. Laravel production caches

```bash
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Repeat `config:cache route:cache view:cache event:cache` on every deploy
(after pulling, before web traffic hits the new code). `artisan optimize`
runs all four in one go.

## 8. Web server

Point the document root at `public/` and hand PHP files to PHP-FPM. Minimal
Nginx sketch:

```nginx
server {
    listen 443 ssl http2;
    server_name openrugby.app;

    root /var/www/openrugby/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }

    # Gzip + long-cache built assets
    location /build {
        expires 1y;
        access_log off;
        add_header Cache-Control "public, immutable";
    }
}
```

Forge / Ploi / Vapor give you this out of the box — skip straight to §9.

## 9. Scheduled tasks

The app relies on two scheduled commands (declared in `routes/console.php`):

| Schedule (UTC) | Command | What it does |
| --- | --- | --- |
| `04:00` daily | `rugby:sync-daily` | Pull today's fixtures + results from API-Sports, respecting the 100/day free-tier limit. |
| `05:00` daily | `rugby:generate-rag` | Turn newly-completed matches into RAG documents for the chatbot. |

Install the Laravel scheduler with a single cron entry (runs `schedule:run`
every minute, which then triggers the scheduled commands at their defined
times):

```cron
* * * * * cd /var/www/openrugby && php artisan schedule:run >> /dev/null 2>&1
```

Check registered tasks with `php artisan schedule:list`.

## 10. Queues (not needed yet)

Current: `QUEUE_CONNECTION=sync` — everything runs inline. The chatbot call
to OpenAI blocks the HTTP request, which is fine because it's a user-
initiated request that expects an answer.

You'll want a queue worker once either:

- You add Reverb for live match broadcasting (planned), or
- Imports get moved off the CLI onto background jobs.

When that time comes, add a Supervisor config:

```ini
[program:openrugby-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/openrugby/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=deploy
numprocs=1
stdout_logfile=/var/log/openrugby-queue.log
```

## 11. Smoke test

After deploying, hit these endpoints and confirm the responses:

```bash
curl -sI https://openrugby.app/            | head -1    # expect 200
curl -sI https://openrugby.app/competitions | head -1   # expect 200
curl -s  https://openrugby.app/api/competitions?per_page=1 | head -c 200
curl -s  -X POST https://openrugby.app/api/chat \
     -H 'Content-Type: application/json' \
     -d '{"question":"Who won the URC 2024?"}' | head -c 200
```

Then in the browser:

- Load the dashboard — scorecard renders in dark, ticker numbers populated.
- Click through to a match — lineups + timeline + scorecard all populate.
- Ask the chatbot a question — should return a multi-paragraph answer
  citing data from RAG documents.
- Toggle theme — light mode works (scorecard stays dark by design).
- Sign in as an admin user — Admin nav entry appears; league-capture users
  see the Capture Scores button on matches they're assigned to.

## 12. Deploy script

Minimal `deploy.sh` to run on every subsequent push:

```bash
#!/usr/bin/env bash
set -euo pipefail

cd /var/www/openrugby
php artisan down --render="errors::503"
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --silent
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan up
```

## 13. Things still on the roadmap

These are planned but not blocking a first launch:

- **Reverb WebSocket broadcasting** — live matches currently update via
  `wire:poll` every 3 s, which is good enough to launch. Reverb makes
  updates sub-second.
- **Mobile Live Capture UX** — admins can already capture scores on mobile,
  but the capture UI isn't fully polished for one-handed use.
- **Error reporting** — wire up Sentry or Bugsnag before you get any real
  traffic. Laravel's default `log` channel just writes to
  `storage/logs/laravel.log`.
