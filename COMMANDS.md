# RugbyStats Command Runbook

## Scheduled (daily)

Configured in `routes/console.php`:

- `04:00 UTC` → `php artisan rugby:sync-daily`
- `05:00 UTC` → `php artisan rugby:generate-rag`

These are the only commands that should run automatically every day by default.

## Daily operational checks (recommended)

Run manually after imports or on a daily QA pass:

- `php artisan rugby:audit-match-completeness --only-missing --limit=50`

Optional source-focused checks:

- `php artisan rugby:audit-match-completeness --source=espn --only-missing --limit=50`
- `php artisan rugby:audit-match-completeness --source=allrugby --only-missing --limit=50`

## Ingestion and enrichment commands

### API-Sports / daily sync

- `rugby:sync-daily`
  - Primary scheduled sync command.
  - Use `--full` to sync all priority competitions in one run.
  - Use `--competition=` and `--season=` for targeted refreshes.

- `rugby:api-debug`
  - Debug helper for API-Sports endpoints.

### ESPN

- `rugby:import-espn`
  - Imports ESPN teams/players.

- `rugby:import-espn-matches`
  - Imports ESPN matches + available detail payloads.
  - Common targeted run: `php artisan rugby:import-espn-matches --league=champions_cup`

### all.rugby

- `rugby:import-allrugby`
  - Imports all.rugby player and team linkage data.

- `rugby:import-allrugby-careers`
  - Imports all.rugby player career/game sheet data.

- `rugby:import-allrugby-matches`
  - Imports all.rugby match metadata and scores.

- `rugby:import-allrugby-match-details`
  - Imports all.rugby lineups, events, substitutions, and team stats.
  - Use `--force` only for backfill/correction runs.

### URC and other sources

- `rugby:import-urc`
  - Imports URC data (players, teams, and optional matches).

- `rugby:import-urc-match-details`
  - Imports URC detailed match data.

- `rugby:import-rugbypy`
  - Imports rugbypy JSON exports.

- `rugby:import-kaggle`
  - Imports historical Kaggle CSV data.

## Data quality and maintenance commands

- `rugby:audit-match-completeness`
  - Audits presence of teams, side lineups, events, stats, and officials.

- `rugby:deduplicate-matches`
  - Finds and merges/removes duplicate matches.

- `rugby:merge-competitions`
  - Merges duplicate competitions and their linked season data.

- `rugby:competitions`
  - Lists competitions and seasons currently in DB.

- `rugby:set-match-officials`
  - Manual official assignment when upstream sources have no officials.

- `rugby:fix-provence`
  - One-off cleanup command for historical Provence team-link bug.

## Auditing

- `rugby:audit-season`
  - Scores each season on data completeness (teams, scores, lineups, events, officials).
  - Stores score + audit JSON on the season record; auto-verifies seasons at ≥95% by default.
  - Use `--competition=urc --season=2025-26` for a specific season, `--all` for every season with matches.
  - Use `--verify-above=98` to tighten the verification threshold.

## Refresh / Results

- `rugby:refresh`
  - Full pipeline: ESPN scrape → import → dedup → recompute standings & player stats → audit.
  - Default targets the main active competitions (URC, Six Nations, Premiership, Top 14, Champions Cup, etc.).
  - Use `--competition=urc --competition=six_nations` to limit scope.
  - Use `--details` to also refresh match details (lineups, events, stats).
  - Scheduled to run Sun 23:00 UTC and Mon 07:00 UTC for weekend result sweeps.

## Standings

- `rugby:compute-standings`
  - Computes league standings from match results (scores, tries, bonus points).
  - Use `--competition=urc --season=2024-25` for a specific season.
  - Use `--all-seasons` to compute across all seasons.
  - Use `--audit` for data integrity report (missing scores, winner mismatches, try count discrepancies).
  - Use `--dry-run` to preview without writing to DB.
  - Bonus point rules are configurable per competition in `config/rugby.php`.

## RAG/AI pipeline

- `rugby:generate-rag`
  - Generates natural language RAG docs from structured data.
  - Scheduled daily at `05:00 UTC`.

## Suggested run cadence

- Daily (scheduled):
  - `rugby:sync-daily`
  - `rugby:generate-rag`

- Daily (manual QA):
  - `rugby:audit-match-completeness --only-missing --limit=50`

- As-needed (after scraper/import runs):
  - `rugby:import-espn-matches`
  - `rugby:import-allrugby-match-details`
  - `rugby:set-match-officials`

- One-off maintenance:
  - `rugby:deduplicate-matches`
  - `rugby:merge-competitions`
  - `rugby:fix-provence`
