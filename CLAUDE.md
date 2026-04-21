# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

RugbyStats is a Laravel 12 application that aggregates rugby union data from multiple sources (API-Sports, ESPN, all.rugby, URC, rugbypy, Kaggle) into a unified database, then generates RAG documents for an AI-powered chatbot. The frontend uses Livewire 4 + Tailwind CSS 4.

## Common Commands

All commands run from the `RugbyStats/` directory.

```bash
# Development server
php artisan serve          # Backend at localhost:8000
npm run dev                # Vite dev server (CSS/JS hot reload)

# Database
php artisan migrate        # Run migrations (single file: database/migrations/2026_04_10_000001_create_rugby_hub_tables.php)
php artisan db:seed        # Seeds competitions via CompetitionSeeder

# Tests
php artisan test                          # Run all tests
php artisan test --testsuite=Unit         # Unit tests only
php artisan test --testsuite=Feature      # Feature tests only
php artisan test --filter=SomeTestClass   # Single test class
./vendor/bin/phpunit                      # Direct PHPUnit (uses in-memory SQLite)

# Linting
./vendor/bin/pint          # Laravel Pint (code style fixer)

# Build
npm run build              # Production Vite build
```

## Architecture

### Data Ingestion Pipeline

Data flows: **External Sources -> Python Scrapers/APIs -> Artisan Commands -> Services -> Eloquent Models -> Database**

- `scripts/` — Python scrapers (ESPN, all.rugby, URC, rugbypy) that produce JSON files consumed by Artisan import commands
- `app/Console/Commands/` — Artisan commands for each data source (`Import*Command.php`) plus maintenance commands (dedup, merge, audit)
- `app/Services/Rugby/Import/` — Importer services. `BaseImporter.php` is the shared base; source-specific importers live in `Sources/`
- `app/Console/Commands/Concerns/ResolvesMatches.php` — Shared trait for match resolution logic across commands

### RAG Pipeline

- `rugby:generate-rag` command runs daily at 05:00 UTC (after 04:00 sync)
- `app/Services/Rugby/Rag/DocumentGenerator.php` — Converts structured data into natural-language documents
- `app/Models/RagDocument.php` — Stores generated documents
- Document types: match_summary, player_profile, team_season_review, competition_overview, referee_profile

### Web Layer

- **Livewire components** (`app/Livewire/`) handle all frontend pages — no traditional Blade controllers for the UI
- **API controllers** (`app/Http/Controllers/`) provide a REST API under `/api` for competitions, teams, players, matches, standings, and chat
- **Chat endpoint** (`POST /api/chat`) powers the RAG chatbot via `ChatController`

### Key Models

The main entity is `RugbyMatch` (not `Match` — reserved word). Related models: `MatchTeam`, `MatchEvent`, `MatchLineup`, `MatchStat`, `MatchOfficial`, `PlayerMatchStat`. Players link to teams via `PlayerContract`. Competitions have `Season`s, seasons have `Standing`s and `TeamSeason`s. `DataImport` tracks ingestion runs.

### Configuration

- `config/rugby.php` — Source API keys, rate limits, sync schedule, RAG settings, and canonical stat key lists
- `.env` — API keys for API-Sports and OpenAI; database connection (defaults to SQLite)
- `app/helpers.php` — Auto-loaded via composer (currently provides `ordinal()` helper)

### Scheduled Commands

Defined in `routes/console.php`:
- `04:00 UTC` — `rugby:sync-daily` (API-Sports sync, respects free tier 100 req/day limit)
- `05:00 UTC` — `rugby:generate-rag`

See `COMMANDS.md` for the full command runbook including manual QA and one-off maintenance commands.
