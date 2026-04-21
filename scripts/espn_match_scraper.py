#!/usr/bin/env python3
"""
Scrape match data and per-match player stats from ESPN's hidden API.

ESPN's site.api.espn.com has detailed match summaries with:
- Full match details (teams, scores, venue, date, round)
- Per-match player statistics (tackles, carries, metres, tries, etc.)
- Lineups with jersey numbers and positions
- Match events (tries, cards, substitutions)

This scraper targets the /scoreboard and /summary endpoints.

Leagues supported (ESPN IDs):
    URC: 270557          Premiership: 267979      Top 14: 270559
    Super Rugby: 242041  Six Nations: 180659      Rugby Championship: 244293
    Champions Cup: 271937  Challenge Cup: 272073
    World Cup: 164205    Autumn Internationals: 289234

Usage:
    # Scrape all URC matches for 2024-25 season
    python3 scripts/espn_match_scraper.py --league urc --season 2024

    # Scrape multiple seasons (back to 2000)
    python3 scripts/espn_match_scraper.py --league urc --from-year 2000 --to-year 2025

    # Scrape match details (player stats) for already-fetched matches
    python3 scripts/espn_match_scraper.py --details

    # Scrape specific leagues
    python3 scripts/espn_match_scraper.py --league six_nations premiership --season 2023

    # List available leagues
    python3 scripts/espn_match_scraper.py --list-leagues

Output: storage/app/espn_matches/
Then import with: php artisan rugby:import-espn-matches
"""

import argparse
import http.client
import json
import os
import sys
import time
from datetime import datetime
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError

BASE_URL = "https://site.api.espn.com/apis/site/v2/sports/rugby"

# ESPN league IDs
LEAGUES = {
    "urc":                  {"id": "270557",  "name": "United Rugby Championship"},
    "premiership":          {"id": "267979",  "name": "Premiership Rugby"},
    "top14":                {"id": "270559",  "name": "Top 14"},
    "super_rugby":          {"id": "242041",  "name": "Super Rugby Pacific"},
    "six_nations":          {"id": "180659",  "name": "Six Nations"},
    "rugby_championship":   {"id": "244293",  "name": "Rugby Championship"},
    "champions_cup":        {"id": "271937",  "name": "Champions Cup"},
    "challenge_cup":        {"id": "272073",  "name": "Challenge Cup"},
    "world_cup":            {"id": "164205",  "name": "Rugby World Cup"},
    "autumn_internationals":{"id": "289234",  "name": "Autumn Internationals"},
    "currie_cup":           {"id": "291469",  "name": "Currie Cup"},
    "mlr":                  {"id": "272075",  "name": "Major League Rugby"},
}

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36",
    "Accept": "application/json",
}


def get_output_dir():
    script_dir = Path(__file__).parent.parent
    output_dir = script_dir / "storage" / "app" / "espn_matches"
    output_dir.mkdir(parents=True, exist_ok=True)
    return output_dir


def fetch_json(url, retries=3, delay=1.0):
    """Fetch JSON from ESPN API with retries."""
    for attempt in range(retries):
        try:
            req = Request(url, headers=HEADERS)
            with urlopen(req, timeout=30) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except HTTPError as e:
            if e.code == 404:
                return None
            if e.code == 429:
                wait = 5 * (attempt + 1)
                print(f"    Rate limited, waiting {wait}s...")
                time.sleep(wait)
                continue
            if e.code >= 500:
                time.sleep(delay * (attempt + 1))
                continue
            print(f"    HTTP {e.code} for {url}")
            return None
        except (URLError, TimeoutError, json.JSONDecodeError, http.client.IncompleteRead, ConnectionError) as e:
            if attempt < retries - 1:
                time.sleep(delay * (attempt + 1))
                continue
            print(f"    Error: {e}")
            return None
    return None


def fetch_scoreboard(league_id, date_range):
    """
    Fetch scoreboard (list of matches) for a date range.

    ESPN scoreboard endpoint:
        /apis/site/v2/sports/rugby/{leagueId}/scoreboard?dates={YYYYMMDD}-{YYYYMMDD}&limit=200

    Returns list of match events with basic info (teams, scores, date, status).
    """
    url = f"{BASE_URL}/{league_id}/scoreboard?dates={date_range}&limit=200"
    print(f"  Fetching scoreboard: {date_range}...")
    data = fetch_json(url)
    if not data:
        return []

    events = data.get("events", [])
    print(f"    Found {len(events)} matches")
    return events


def fetch_match_summary(match_id, league_id):
    """
    Fetch detailed match summary with player stats.

    ESPN summary endpoint:
        /apis/site/v2/sports/rugby/{leagueId}/summary?event={matchId}

    Returns:
        - boxscore: team stats (tackles, carries, metres, etc.)
        - rosters: full lineups with player details
        - plays: match events (tries, cards, etc.)
        - scoringPlays: try/penalty/drop goal details
        - keyEvents: major match moments
        - article: match report
    """
    url = f"{BASE_URL}/{league_id}/summary?event={match_id}"
    data = fetch_json(url)
    return data


def parse_match_event(event):
    """Parse a scoreboard event into a clean match dict."""
    competition = event.get("competitions", [{}])[0]

    match = {
        "espn_id": event.get("id"),
        "date": event.get("date"),
        "name": event.get("name", ""),
        "short_name": event.get("shortName", ""),
        "status": "ft",
        "round": None,
        "stage": None,
        "venue": None,
        "attendance": None,
        "home_team": None,
        "away_team": None,
    }

    # Status
    status_data = competition.get("status", {})
    type_data = status_data.get("type", {})
    match["status"] = type_data.get("name", "STATUS_FINAL")

    # Venue
    venue = competition.get("venue", {})
    if venue:
        match["venue"] = {
            "name": venue.get("fullName", venue.get("shortName", "")),
            "city": venue.get("address", {}).get("city", ""),
            "country": venue.get("address", {}).get("country", ""),
        }

    # Attendance
    match["attendance"] = competition.get("attendance")

    # Round / stage (from notes or season type)
    notes = competition.get("notes", [])
    if notes:
        match["round"] = notes[0].get("headline", "")

    season = competition.get("season", {})
    if season:
        match["stage"] = season.get("slug", "")

    # Teams
    competitors = competition.get("competitors", [])
    for comp in competitors:
        team_data = {
            "espn_id": comp.get("id"),
            "name": comp.get("team", {}).get("displayName", ""),
            "short_name": comp.get("team", {}).get("abbreviation", ""),
            "logo": comp.get("team", {}).get("logo", ""),
            "score": None,
            "winner": comp.get("winner", False),
        }

        # Score
        try:
            team_data["score"] = int(comp.get("score", 0))
        except (ValueError, TypeError):
            pass

        # Linescores (half-time scores)
        linescores = comp.get("linescores", [])
        if linescores:
            try:
                team_data["ht_score"] = int(linescores[0].get("value", 0))
            except (ValueError, TypeError, IndexError):
                pass

        if comp.get("homeAway") == "home":
            match["home_team"] = team_data
        else:
            match["away_team"] = team_data

    return match


def parse_match_summary(summary_data, match_id):
    """Parse a match summary into player stats, lineups, and events."""
    result = {
        "match_id": match_id,
        "player_stats": [],
        "lineups": [],
        "events": [],
        "team_stats": [],
        "officials": [],
    }

    if not summary_data:
        return result

    # ── Player statistics from boxscore ──
    boxscore = summary_data.get("boxscore", {})
    players_data = boxscore.get("players", [])

    for team_data in players_data:
        team_info = team_data.get("team", {})
        team_id = team_info.get("id")
        team_name = team_info.get("displayName", "")

        statistics = team_data.get("statistics", [])
        for stat_group in statistics:
            stat_name = stat_group.get("name", "")  # e.g., "Scoring", "Attack", "Defence"
            stat_labels = stat_group.get("labels", [])
            athletes = stat_group.get("athletes", [])

            for athlete in athletes:
                player_info = athlete.get("athlete", {})
                player_id = player_info.get("id")
                player_name = player_info.get("displayName", "")
                player_position = player_info.get("position", {}).get("abbreviation", "")
                jersey = athlete.get("jersey", "")

                stats = athlete.get("stats", [])
                if stats and stat_labels:
                    for label, value in zip(stat_labels, stats):
                        if value and value != "-" and value != "0":
                            result["player_stats"].append({
                                "player_espn_id": player_id,
                                "player_name": player_name,
                                "player_position": player_position,
                                "jersey_number": jersey,
                                "team_espn_id": team_id,
                                "team_name": team_name,
                                "stat_group": stat_name,
                                "stat_key": label,
                                "stat_value": value,
                            })

    # ── Team statistics ──
    team_stats = boxscore.get("teams", [])
    for team_data in team_stats:
        team_info = team_data.get("team", {})
        team_id = team_info.get("id")
        team_name = team_info.get("displayName", "")

        statistics = team_data.get("statistics", [])
        for stat in statistics:
            result["team_stats"].append({
                "team_espn_id": team_id,
                "team_name": team_name,
                "stat_key": stat.get("name", ""),
                "stat_label": stat.get("label", ""),
                "stat_value": stat.get("displayValue", ""),
            })

    # ── Rosters / Lineups ──
    rosters = summary_data.get("rosters", [])
    for team_roster in rosters:
        team_info = team_roster.get("team", {})
        team_id = team_info.get("id")
        team_name = team_info.get("displayName", "")

        roster = team_roster.get("roster", [])
        for player in roster:
            player_info = player.get("athlete", {}) if isinstance(player, dict) else {}
            if not player_info and isinstance(player, dict):
                player_info = player

            result["lineups"].append({
                "player_espn_id": player_info.get("id"),
                "player_name": player_info.get("displayName", ""),
                "jersey_number": player.get("jersey", player_info.get("jersey", "")),
                "position": player_info.get("position", {}).get("abbreviation", ""),
                "starter": player.get("starter", False),
                "team_espn_id": team_id,
                "team_name": team_name,
            })

    # ── Events ──
    # ESPN has TWO sources:
    #   1. header.competitions.details — richest: tries, cons, pens, cards, subs, with player info
    #   2. scoringPlays — subset: scoring plays only, sometimes with different clock values
    # Some matches populate both (causing duplicates if we consume both), so prefer details
    # and only fall back to scoringPlays+keyEvents if details is missing.
    header = summary_data.get("header", {})
    header_comps = header.get("competitions", [])
    details_list = header_comps[0].get("details", []) if header_comps else []

    if details_list:
        for detail in details_list:
            event_type = detail.get("type", {}).get("text", "")
            team_info = detail.get("team", {})
            participants = detail.get("participants", [])
            player_info = participants[0].get("athlete", {}) if participants else {}

            result["events"].append({
                "type": event_type,
                "clock": detail.get("clock", {}).get("displayValue", ""),
                "period": detail.get("period", {}).get("number", 0),
                "team_espn_id": team_info.get("id"),
                "team_name": team_info.get("displayName", ""),
                "player_espn_id": player_info.get("id"),
                "player_name": player_info.get("displayName", ""),
                "score_home": detail.get("homeScore"),
                "score_away": detail.get("awayScore"),
            })
    else:
        # Fallback: scoringPlays + keyEvents (older matches / clubs without details)
        for play in summary_data.get("scoringPlays", []):
            result["events"].append({
                "type": play.get("type", {}).get("text", ""),
                "text": play.get("text", ""),
                "clock": play.get("clock", {}).get("displayValue", ""),
                "period": play.get("period", {}).get("number", 0),
                "team_espn_id": play.get("team", {}).get("id"),
                "team_name": play.get("team", {}).get("displayName", ""),
                "score_home": play.get("homeScore"),
                "score_away": play.get("awayScore"),
            })
        for event in summary_data.get("keyEvents", []):
            play = event.get("play", {})
            if play:
                result["events"].append({
                    "type": play.get("type", {}).get("text", "Key Event"),
                    "text": play.get("text", ""),
                    "clock": play.get("clock", {}).get("displayValue", ""),
                    "period": play.get("period", {}).get("number", 0),
                })

    # ── Match officials ──
    officials = boxscore.get("officials", [])
    if not officials:
        officials = summary_data.get("gameInfo", {}).get("officials", [])

    for official in officials:
        person = official.get("athlete", {}) if isinstance(official, dict) else {}
        name = (
            official.get("fullName")
            or official.get("displayName")
            or person.get("displayName")
            or ""
        )
        role_info = official.get("type") if isinstance(official, dict) else None
        if isinstance(role_info, dict):
            role = role_info.get("text") or role_info.get("name") or ""
        else:
            role = role_info or official.get("role") or ""

        # Extract nationality from athlete flag or citizenship
        nationality = (
            person.get("citizenship", "")
            or person.get("flag", {}).get("alt", "")
            if isinstance(person.get("flag"), dict)
            else person.get("flag", "")
        ) if person else ""

        # Extract headshot/photo URL
        headshot = person.get("headshot", {})
        photo_url = ""
        if isinstance(headshot, dict):
            photo_url = headshot.get("href", "") or headshot.get("url", "")
        elif isinstance(headshot, str):
            photo_url = headshot

        if name:
            result["officials"].append({
                "official_espn_id": official.get("id") or person.get("id"),
                "name": name,
                "role": role,
                "nationality": nationality,
                "photo_url": photo_url,
            })

    return result


def scrape_season_matches(league_key, league_id, year, output_dir, delay=0.5):
    """Scrape all matches for a given season year."""
    # ESPN seasons typically span Aug-Jun for club competitions
    # and Jun-Nov for international windows
    # We use broad date ranges and let ESPN filter

    # For a "2024" season, covers Aug 2024 - Jul 2025
    start = f"{year}0801"
    end = f"{year + 1}0731"
    date_range = f"{start}-{end}"

    events = fetch_scoreboard(league_id, date_range)

    if not events:
        # Try calendar year for internationals
        date_range = f"{year}0101-{year}1231"
        events = fetch_scoreboard(league_id, date_range)

    if not events:
        print(f"    No matches found for {league_key} {year}")
        return []

    matches = []
    for event in events:
        match = parse_match_event(event)
        match["league"] = league_key
        match["league_id"] = league_id
        match["season_year"] = year
        matches.append(match)

    # Save match list
    season_file = output_dir / f"matches_{league_key}_{year}.json"
    with open(season_file, "w") as f:
        json.dump(matches, f, indent=2, default=str)

    print(f"    Saved {len(matches)} matches to {season_file.name}")
    time.sleep(delay)
    return matches


def scrape_match_details(matches, league_id, output_dir, delay=0.8, refresh=False):
    """Scrape detailed stats for a list of matches."""
    details_dir = output_dir / "details"
    details_dir.mkdir(exist_ok=True)

    total = len(matches)
    success = 0
    skipped = 0

    for i, match in enumerate(matches):
        match_id = match.get("espn_id")
        if not match_id:
            continue

        # Skip if already fetched unless refresh was requested
        detail_file = details_dir / f"match_{match_id}.json"
        if detail_file.exists() and not refresh:
            skipped += 1
            continue

        # Only fetch completed matches
        status = match.get("status", "")
        if status not in ("STATUS_FINAL", "STATUS_FULL_TIME", "ft"):
            continue

        print(f"  [{i+1}/{total}] Fetching details for {match.get('short_name', match_id)}...")
        summary = fetch_match_summary(match_id, league_id)

        if summary:
            parsed = parse_match_summary(summary, match_id)

            # Save full summary + parsed data
            output = {
                "match": match,
                "parsed": parsed,
                "raw_summary_keys": list(summary.keys()),
            }

            with open(detail_file, "w") as f:
                json.dump(output, f, indent=2, default=str)

            stat_count = len(parsed["player_stats"])
            lineup_count = len(parsed["lineups"])
            event_count = len(parsed["events"])
            print(f"    Stats: {stat_count} player stats, {lineup_count} lineup entries, {event_count} events")
            success += 1
        else:
            print(f"    No summary data")

        time.sleep(delay)

        # Progress checkpoint
        if (i + 1) % 25 == 0:
            print(f"  ** Progress: {i+1}/{total} ({success} detailed, {skipped} cached) **")

    print(f"\n  Completed: {success} new details fetched, {skipped} already cached")
    return success


def main():
    parser = argparse.ArgumentParser(description="Scrape ESPN rugby match data and player stats")
    parser.add_argument("--league", nargs="+", metavar="LEAGUE",
                        help="League(s) to scrape: urc, premiership, top14, etc.")
    parser.add_argument("--season", type=int,
                        help="Single season year to scrape (e.g., 2024 for 2024-25)")
    parser.add_argument("--from-year", type=int, default=None,
                        help="Start year for multi-season scrape (default: same as --season)")
    parser.add_argument("--to-year", type=int, default=None,
                        help="End year for multi-season scrape (default: current year)")
    parser.add_argument("--details", action="store_true",
                        help="Also fetch match details (player stats, lineups, events)")
    parser.add_argument("--details-only", action="store_true",
                        help="Only fetch details for already-scraped matches (skip scoreboard)")
    parser.add_argument("--refresh-details", action="store_true",
                        help="Re-fetch and overwrite cached detail files")
    parser.add_argument("--list-leagues", action="store_true",
                        help="List available leagues")
    parser.add_argument("--delay", type=float, default=0.5,
                        help="Delay between requests (default: 0.5s)")
    args = parser.parse_args()

    if args.list_leagues:
        print("Available leagues:")
        for key, info in sorted(LEAGUES.items()):
            print(f"  {key:25s} ID: {info['id']:8s}  {info['name']}")
        return

    output_dir = get_output_dir()
    print(f"Output directory: {output_dir}")

    if not args.league:
        parser.print_help()
        print("\nQuick start:")
        print("  python3 scripts/espn_match_scraper.py --league urc --season 2024 --details")
        print("  python3 scripts/espn_match_scraper.py --league urc --from-year 2015 --to-year 2025 --details")
        print("  python3 scripts/espn_match_scraper.py --league six_nations premiership --season 2023 --details")
        return

    # Determine year range
    current_year = datetime.now().year
    if args.season:
        from_year = args.season
        to_year = args.season
    elif args.from_year:
        from_year = args.from_year
        to_year = args.to_year or current_year
    else:
        from_year = current_year - 1
        to_year = current_year

    # Validate leagues
    leagues = []
    for key in args.league:
        key = key.lower().replace("-", "_")
        if key == "all":
            leagues = list(LEAGUES.items())
            break
        elif key in LEAGUES:
            leagues.append((key, LEAGUES[key]))
        else:
            print(f"Unknown league: {key}")
            print(f"Available: {', '.join(sorted(LEAGUES.keys()))}")
            return

    total_matches = 0
    all_matches_by_league = {}

    # ── Phase 1: Fetch scoreboards ──
    if not args.details_only:
        print(f"\n{'='*60}")
        print(f"Phase 1: Fetching scoreboards")
        print(f"Leagues: {', '.join(k for k, _ in leagues)}")
        print(f"Years: {from_year} to {to_year}")
        print(f"{'='*60}\n")

        for league_key, league_info in leagues:
            league_id = league_info["id"]
            print(f"\n--- {league_info['name']} ({league_key}) ---")

            league_matches = []
            for year in range(from_year, to_year + 1):
                matches = scrape_season_matches(league_key, league_id, year, output_dir, args.delay)
                league_matches.extend(matches)
                time.sleep(args.delay)

            all_matches_by_league[league_key] = {
                "league_id": league_id,
                "matches": league_matches,
            }
            total_matches += len(league_matches)
            print(f"  Total: {len(league_matches)} matches for {league_key}")

    # ── Phase 2: Fetch match details (if requested) ──
    if args.details or args.details_only:
        print(f"\n{'='*60}")
        print(f"Phase 2: Fetching match details (player stats)")
        print(f"{'='*60}\n")

        # If details-only, load existing match files
        if args.details_only:
            for league_key, league_info in leagues:
                league_id = league_info["id"]
                matches = []

                # Load all match files for this league
                for f in sorted(output_dir.glob(f"matches_{league_key}_*.json")):
                    with open(f) as fh:
                        matches.extend(json.load(fh))

                if matches:
                    all_matches_by_league[league_key] = {
                        "league_id": league_id,
                        "matches": matches,
                    }
                    total_matches += len(matches)

        for league_key, data in all_matches_by_league.items():
            league_id = data["league_id"]
            matches = data["matches"]

            if not matches:
                continue

            print(f"\n--- {league_key}: {len(matches)} matches ---")
            scrape_match_details(matches, league_id, output_dir, args.delay, args.refresh_details)

    # ── Summary ──
    print(f"\n{'='*60}")
    print(f"SUMMARY")
    print(f"{'='*60}")
    print(f"Total matches scraped: {total_matches}")
    for league_key, data in all_matches_by_league.items():
        print(f"  {league_key:25s} {len(data['matches'])} matches")

    details_dir = output_dir / "details"
    if details_dir.exists():
        detail_count = len(list(details_dir.glob("match_*.json")))
        print(f"\nMatch details cached: {detail_count}")

    print(f"\nOutput: {output_dir}")
    print(f"Next step: php artisan rugby:import-espn-matches")


if __name__ == "__main__":
    main()
