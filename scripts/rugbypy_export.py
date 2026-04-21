#!/usr/bin/env python3
"""
Export rugbypy data to JSON files for Laravel import.

Usage:
    pip3 install rugbypy
    python3 scripts/rugbypy_export.py                           # Export all matches + players
    python3 scripts/rugbypy_export.py --matches-only            # Just matches (fast)
    python3 scripts/rugbypy_export.py --players-only            # Just players
    python3 scripts/rugbypy_export.py --with-details            # Also fetch match details (slow)
    python3 scripts/rugbypy_export.py --with-stats              # Also fetch player stats (very slow)

Output files are written to storage/app/rugbypy/
"""

import argparse
import json
import sys
import warnings
from datetime import datetime
from pathlib import Path

warnings.filterwarnings("ignore", category=DeprecationWarning)

# Try importing the new API first, fall back to old
try:
    from rugbypy.match import fetch_match_details
    from rugbypy.player import fetch_all_players, fetch_player_stats
except ImportError:
    print("rugbypy not installed. Run: pip3 install rugbypy")
    sys.exit(1)

# Try importing team functions
try:
    from rugbypy.team import fetch_all_teams
    HAS_FETCH_ALL_TEAMS = True
except ImportError:
    HAS_FETCH_ALL_TEAMS = False

# Import match functions — prefer newer API
try:
    from rugbypy.match import fetch_all_matches
    HAS_FETCH_ALL = True
except ImportError:
    HAS_FETCH_ALL = False

try:
    from rugbypy.match import fetch_matches_by_date
    HAS_FETCH_BY_DATE = True
except ImportError:
    HAS_FETCH_BY_DATE = False

try:
    from rugbypy.match import fetch_matches
    HAS_FETCH_LEGACY = True
except ImportError:
    HAS_FETCH_LEGACY = False


def get_output_dir():
    """Get the output directory (storage/app/rugbypy/)."""
    script_dir = Path(__file__).parent.parent
    output_dir = script_dir / "storage" / "app" / "rugbypy"
    output_dir.mkdir(parents=True, exist_ok=True)
    return output_dir


def safe_convert(df):
    """Convert a DataFrame to a list of dicts, handling NaN and special types."""
    if df is None:
        return []
    try:
        if df.empty:
            return []
    except Exception:
        return []

    df = df.where(df.notnull(), None)
    records = df.to_dict(orient="records")
    for record in records:
        for key, val in record.items():
            if hasattr(val, 'isoformat'):
                record[key] = val.isoformat()
            elif hasattr(val, 'item'):
                record[key] = val.item()
    return records


def export_players(output_dir):
    """Export all players to JSON."""
    print("Fetching all players...")
    try:
        df = fetch_all_players()
    except Exception as e:
        print(f"  Error fetching players: {e}")
        return []

    players = safe_convert(df)
    if not players:
        print("  No players found.")
        return []

    output_path = output_dir / "players.json"
    with open(output_path, "w") as f:
        json.dump(players, f, indent=2, default=str)

    print(f"  Exported {len(players)} players to {output_path}")
    if players:
        print(f"  Columns: {', '.join(players[0].keys())}")
    return players


def export_all_matches(output_dir):
    """Export ALL matches using fetch_all_matches() — one API call, gets everything."""
    print("Fetching all matches (fetch_all_matches)...")
    try:
        df = fetch_all_matches()
    except Exception as e:
        print(f"  Error: {e}")
        return []

    matches = safe_convert(df)
    if not matches:
        print("  No matches returned.")
        return []

    output_path = output_dir / "matches.json"
    with open(output_path, "w") as f:
        json.dump(matches, f, indent=2, default=str)

    print(f"  Exported {len(matches)} matches to {output_path}")
    if matches:
        print(f"  Columns: {', '.join(matches[0].keys())}")
    return matches


def export_matches_by_date(output_dir, start_date, end_date):
    """Export matches day by day using fetch_matches_by_date() — YYYYMMDD format."""
    from datetime import timedelta

    all_matches = []
    errors = 0
    current = start_date
    total_days = (end_date - start_date).days + 1

    print(f"Fetching matches from {start_date.strftime('%Y-%m-%d')} to {end_date.strftime('%Y-%m-%d')} ({total_days} days)...")

    day_num = 0
    while current <= end_date:
        date_str = current.strftime("%Y%m%d")  # YYYYMMDD format required
        day_num += 1

        try:
            if HAS_FETCH_BY_DATE:
                df = fetch_matches_by_date(date=date_str)
            elif HAS_FETCH_LEGACY:
                df = fetch_matches(date=date_str)
            else:
                print("  No match fetch function available!")
                return []

            if df is not None:
                matches = safe_convert(df)
                if matches:
                    all_matches.extend(matches)
        except Exception as e:
            errors += 1
            if errors <= 3:
                print(f"  Warning on {date_str}: {e}")

        if day_num % 30 == 0:
            print(f"  Scanned {day_num}/{total_days} days, found {len(all_matches)} matches...")

        current += timedelta(days=1)

    if all_matches:
        output_path = output_dir / "matches.json"
        with open(output_path, "w") as f:
            json.dump(all_matches, f, indent=2, default=str)
        print(f"  Exported {len(all_matches)} matches to {output_path}")
        if all_matches:
            print(f"  Columns: {', '.join(all_matches[0].keys())}")
    else:
        print(f"  No matches found ({errors} errors)")

    return all_matches


def export_match_details(output_dir, match_ids):
    """Export detailed match data for specific match IDs."""
    all_details = []
    errors = 0
    output_path = output_dir / "match_details.json"

    print(f"Fetching details for {len(match_ids)} matches...")

    for i, match_id in enumerate(match_ids):
        try:
            df = fetch_match_details(str(match_id))
            if df is not None:
                details = safe_convert(df)
                if details:
                    all_details.extend(details)
        except Exception as e:
            errors += 1
            if errors <= 5:
                print(f"  Error on match {match_id}: {e}")

        if (i + 1) % 100 == 0:
            print(f"  Processed {i + 1}/{len(match_ids)} matches ({len(all_details)} details)...")

        # Checkpoint every 500
        if (i + 1) % 500 == 0 and all_details:
            with open(output_path, "w") as f:
                json.dump(all_details, f, indent=2, default=str)

    if all_details:
        with open(output_path, "w") as f:
            json.dump(all_details, f, indent=2, default=str)
        print(f"  Exported {len(all_details)} match detail records to {output_path}")
        if all_details:
            print(f"  Columns: {', '.join(all_details[0].keys())}")
    else:
        print(f"  No match details found ({errors} errors)")

    return all_details


def export_player_stats(output_dir, player_ids, batch_size=500):
    """Export player statistics in batches with incremental saves."""
    all_stats = []
    errors = 0
    output_path = output_dir / "player_stats.json"

    print(f"  Processing {len(player_ids)} players in batches of {batch_size}...")

    for i, player_id in enumerate(player_ids):
        try:
            df = fetch_player_stats(str(player_id))
            if df is not None:
                stats = safe_convert(df)
                if stats:
                    for s in stats:
                        s['player_id'] = player_id
                    all_stats.extend(stats)
        except Exception:
            errors += 1

        if (i + 1) % 100 == 0:
            print(f"  Processed {i + 1}/{len(player_ids)} players ({len(all_stats)} stats, {errors} errors)...")

        if (i + 1) % batch_size == 0 and all_stats:
            with open(output_path, "w") as f:
                json.dump(all_stats, f, indent=2, default=str)
            print(f"  Saved checkpoint: {len(all_stats)} stats")

    if all_stats:
        with open(output_path, "w") as f:
            json.dump(all_stats, f, indent=2, default=str)
        print(f"  Exported {len(all_stats)} player stat records to {output_path}")
    else:
        print(f"  No player stats found ({errors} errors)")

    return all_stats


def export_teams(output_dir):
    """Export all teams to JSON using fetch_all_teams()."""
    if not HAS_FETCH_ALL_TEAMS:
        print("fetch_all_teams not available in this rugbypy version.")
        return []

    print("Fetching all teams...")
    try:
        df = fetch_all_teams()
    except Exception as e:
        print(f"  Error fetching teams: {e}")
        return []

    teams = safe_convert(df)
    if not teams:
        print("  No teams found.")
        return []

    output_path = output_dir / "teams.json"
    with open(output_path, "w") as f:
        json.dump(teams, f, indent=2, default=str)

    print(f"  Exported {len(teams)} teams to {output_path}")
    if teams:
        print(f"  Columns: {', '.join(teams[0].keys())}")
    return teams


def main():
    parser = argparse.ArgumentParser(description="Export rugbypy data to JSON")
    parser.add_argument("--dates", nargs=2, metavar=("START", "END"),
                        help="Date range YYYY-MM-DD YYYY-MM-DD (uses day-by-day fetch)")
    parser.add_argument("--players-only", action="store_true",
                        help="Only export player data")
    parser.add_argument("--matches-only", action="store_true",
                        help="Only export match data")
    parser.add_argument("--teams-only", action="store_true",
                        help="Only export team data")
    parser.add_argument("--with-details", action="store_true",
                        help="Also fetch detailed match data (slower)")
    parser.add_argument("--with-stats", action="store_true",
                        help="Also fetch player stats (very slow — 8000+ API calls)")
    parser.add_argument("--stats-batch", type=int, default=500,
                        help="Save player stats every N players (default: 500)")
    args = parser.parse_args()

    output_dir = get_output_dir()
    print(f"Output directory: {output_dir}")
    print(f"Available APIs: fetch_all_matches={HAS_FETCH_ALL}, fetch_matches_by_date={HAS_FETCH_BY_DATE}, fetch_matches={HAS_FETCH_LEGACY}, fetch_all_teams={HAS_FETCH_ALL_TEAMS}\n")

    # --- Teams ---
    if args.teams_only:
        export_teams(output_dir)
        return

    if not args.matches_only and not args.players_only:
        export_teams(output_dir)

    # --- Players ---
    players = []
    if not args.matches_only:
        players = export_players(output_dir)

    # --- Matches ---
    matches = []
    if not args.players_only:
        if args.dates:
            # Date range mode: day-by-day
            start = datetime.strptime(args.dates[0], "%Y-%m-%d")
            end = datetime.strptime(args.dates[1], "%Y-%m-%d")
            matches = export_matches_by_date(output_dir, start, end)
        elif HAS_FETCH_ALL:
            # Best option: get everything in one call
            matches = export_all_matches(output_dir)
        else:
            print("No fetch_all_matches available and no --dates specified.")
            print("Use: --dates 2024-01-01 2026-04-11")

        # Match details (optional, slower)
        if matches and args.with_details:
            match_ids = list(set(m.get("match_id") for m in matches if m.get("match_id")))
            print(f"\nFetching details for {len(match_ids)} matches...")
            print("Tip: Ctrl+C to stop — partial results are saved.")
            try:
                export_match_details(output_dir, match_ids)
            except KeyboardInterrupt:
                print("\n  Interrupted! Partial details saved.")

    # --- Player stats (optional, very slow) ---
    if players and args.with_stats:
        player_ids = list(set(p.get("player_id") for p in players if p.get("player_id")))
        print(f"\nFetching stats for {len(player_ids)} players...")
        print("Tip: Ctrl+C to stop — partial results are saved incrementally.")
        try:
            export_player_stats(output_dir, player_ids, batch_size=args.stats_batch)
        except KeyboardInterrupt:
            print("\n  Interrupted! Partial stats saved at last checkpoint.")

    # Summary
    print("\n" + "=" * 50)
    print("Export complete! Files in:", output_dir)
    for f in sorted(output_dir.glob("*.json")):
        size_mb = f.stat().st_size / (1024 * 1024)
        print(f"  {f.name}: {size_mb:.1f} MB")
    print("\nNext step: php artisan rugby:import-rugbypy")


if __name__ == "__main__":
    main()
