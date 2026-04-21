#!/usr/bin/env python3
"""
Scrape rugby player and team data from ESPN's public API.

ESPN's site API provides rich player data including nationality, position,
height, weight, team association — all the fields rugbypy is missing.

Usage:
    python3 scripts/espn_scraper.py                    # Scrape all known leagues
    python3 scripts/espn_scraper.py --league urc       # Just one league
    python3 scripts/espn_scraper.py --list-leagues      # Show available leagues
    python3 scripts/espn_scraper.py --teams-only        # Just teams, skip rosters

Output files are written to storage/app/espn/
Then import with: php artisan rugby:import-espn
"""

import argparse
import json
import sys
import time
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError

BASE_URL = "https://site.api.espn.com/apis/site/v2/sports/rugby"

# Known ESPN rugby league IDs
# You can discover more at: {BASE_URL}/scoreboard (lists active leagues)
LEAGUES = {
    # Union
    "urc": {"id": "270557", "name": "United Rugby Championship"},
    "premiership": {"id": "267979", "name": "Premiership Rugby"},
    "top14": {"id": "270559", "name": "Top 14"},
    "super_rugby": {"id": "242041", "name": "Super Rugby Pacific"},
    "six_nations": {"id": "180659", "name": "Six Nations"},
    "rugby_championship": {"id": "244293", "name": "Rugby Championship"},
    "currie_cup": {"id": "270563", "name": "Currie Cup"},
    "european_champions": {"id": "271937", "name": "European Champions Cup"},
    "challenge_cup": {"id": "272073", "name": "European Challenge Cup"},
    "world_cup": {"id": "164205", "name": "Rugby World Cup"},
    "autumn_internationals": {"id": "289234", "name": "Autumn Internationals"},
    # League (NRL etc)
    "nrl": {"id": "111", "name": "NRL"},
    "super_league": {"id": "112", "name": "Super League"},
}


def get_output_dir():
    """Get the output directory (storage/app/espn/)."""
    script_dir = Path(__file__).parent.parent
    output_dir = script_dir / "storage" / "app" / "espn"
    output_dir.mkdir(parents=True, exist_ok=True)
    return output_dir


def fetch_json(url, retries=3, delay=1.0):
    """Fetch JSON from a URL with retries."""
    headers = {
        "User-Agent": "Mozilla/5.0 (compatible; RugbyStats/1.0)",
        "Accept": "application/json",
    }
    for attempt in range(retries):
        try:
            req = Request(url, headers=headers)
            with urlopen(req, timeout=15) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except HTTPError as e:
            if e.code == 404:
                return None
            if e.code == 429 or e.code >= 500:
                time.sleep(delay * (attempt + 1))
                continue
            print(f"  HTTP {e.code} for {url}")
            return None
        except (URLError, TimeoutError) as e:
            if attempt < retries - 1:
                time.sleep(delay * (attempt + 1))
                continue
            print(f"  Timeout/error for {url}: {e}")
            return None
    return None


def fetch_league_teams(league_key, league_info):
    """Fetch all teams for a league."""
    league_id = league_info["id"]
    url = f"{BASE_URL}/teams?league={league_id}"
    print(f"  Fetching teams for {league_info['name']} (league {league_id})...")

    data = fetch_json(url)
    if not data:
        # Try alternate URL pattern
        url = f"{BASE_URL}/{league_id}/teams"
        data = fetch_json(url)

    if not data:
        print(f"    No data returned")
        return []

    teams = []
    # ESPN response format varies — handle both structures
    sports = data.get("sports", [])
    if sports:
        for sport in sports:
            for league in sport.get("leagues", []):
                for team_entry in league.get("teams", []):
                    team = team_entry.get("team", team_entry)
                    teams.append(parse_team(team, league_key, league_info))
    else:
        # Direct teams array
        for team_entry in data.get("teams", []):
            team = team_entry.get("team", team_entry)
            teams.append(parse_team(team, league_key, league_info))

    print(f"    Found {len(teams)} teams")
    return teams


def parse_team(team_data, league_key, league_info):
    """Parse a team object from ESPN API."""
    location = team_data.get("location", "")
    name = team_data.get("name", team_data.get("displayName", ""))
    display_name = team_data.get("displayName", f"{location} {name}".strip())

    logo = None
    logos = team_data.get("logos", [])
    if logos:
        logo = logos[0].get("href")

    return {
        "espn_id": str(team_data.get("id", "")),
        "name": display_name,
        "short_name": team_data.get("abbreviation", ""),
        "location": location,
        "color": team_data.get("color"),
        "alt_color": team_data.get("alternateColor"),
        "logo_url": logo,
        "league_key": league_key,
        "league_name": league_info["name"],
        "league_id": league_info["id"],
    }


def fetch_team_roster(espn_team_id, team_name, league_id):
    """Fetch the roster for a team. Returns list of player dicts."""
    # Try league-specific roster first, then generic
    urls = [
        f"{BASE_URL}/{league_id}/teams/{espn_team_id}/roster",
        f"{BASE_URL}/teams/{espn_team_id}/roster",
        f"{BASE_URL}/{league_id}/teams/{espn_team_id}?enable=roster",
    ]

    data = None
    for url in urls:
        data = fetch_json(url)
        if data and (data.get("athletes") or data.get("roster")):
            break

    if not data:
        return []

    players = []
    # Handle different response formats
    athlete_groups = data.get("athletes", data.get("roster", []))
    if isinstance(athlete_groups, list):
        for group in athlete_groups:
            if isinstance(group, dict) and "items" in group:
                # Grouped by position
                position_name = group.get("position", group.get("name", ""))
                for athlete in group.get("items", []):
                    players.append(parse_player(athlete, espn_team_id, team_name, position_name))
            elif isinstance(group, dict):
                # Direct athlete object
                players.append(parse_player(group, espn_team_id, team_name, ""))
    elif isinstance(athlete_groups, dict):
        # Single roster group
        for athlete in athlete_groups.get("items", athlete_groups.get("entries", [])):
            players.append(parse_player(athlete, espn_team_id, team_name, ""))

    return players


def parse_player(athlete_data, team_id, team_name, position_group=""):
    """Parse a player/athlete object from ESPN API."""
    # ESPN sometimes nests the actual athlete data
    if "athlete" in athlete_data:
        athlete_data = athlete_data["athlete"]

    full_name = athlete_data.get("displayName", athlete_data.get("fullName", ""))
    first_name = athlete_data.get("firstName", "")
    last_name = athlete_data.get("lastName", "")

    if not first_name and full_name:
        parts = full_name.split(" ", 1)
        first_name = parts[0]
        last_name = parts[1] if len(parts) > 1 else ""

    # Position
    position = athlete_data.get("position", {})
    if isinstance(position, dict):
        position_name = position.get("name", position.get("displayName", ""))
        position_abbr = position.get("abbreviation", "")
    else:
        position_name = str(position)
        position_abbr = ""

    # Nationality / birth info
    nationality = ""
    dob = ""
    birth_place = ""
    if "citizenship" in athlete_data:
        nationality = athlete_data["citizenship"]
    if "flag" in athlete_data:
        flag = athlete_data["flag"]
        if isinstance(flag, dict):
            nationality = nationality or flag.get("alt", flag.get("text", ""))

    if "dateOfBirth" in athlete_data:
        dob = athlete_data["dateOfBirth"]  # Usually ISO format
    if "birthPlace" in athlete_data:
        bp = athlete_data["birthPlace"]
        if isinstance(bp, dict):
            city = bp.get("city", "")
            country = bp.get("country", "")
            nationality = nationality or country
            birth_place = f"{city}, {country}".strip(", ")
        else:
            birth_place = str(bp)

    # Physical
    height_cm = None
    weight_kg = None
    if "height" in athlete_data:
        h = athlete_data["height"]
        if isinstance(h, (int, float)):
            # ESPN often provides height in inches
            height_cm = round(h * 2.54)
        elif isinstance(h, dict):
            height_cm = h.get("value")
    if "weight" in athlete_data:
        w = athlete_data["weight"]
        if isinstance(w, (int, float)):
            # ESPN often provides weight in lbs
            weight_kg = round(w * 0.453592)
        elif isinstance(w, dict):
            weight_kg = w.get("value")

    # Photo
    photo_url = ""
    headshot = athlete_data.get("headshot", {})
    if isinstance(headshot, dict):
        photo_url = headshot.get("href", headshot.get("url", ""))
    elif isinstance(headshot, str):
        photo_url = headshot

    # Jersey number
    jersey = athlete_data.get("jersey", "")

    return {
        "espn_id": str(athlete_data.get("id", "")),
        "first_name": first_name,
        "last_name": last_name,
        "full_name": full_name,
        "nationality": nationality,
        "dob": dob,
        "birth_place": birth_place,
        "position": position_name,
        "position_abbr": position_abbr,
        "position_group": position_group or classify_position(position_name, position_abbr),
        "height_cm": height_cm,
        "weight_kg": weight_kg,
        "photo_url": photo_url,
        "jersey": jersey,
        "team_espn_id": team_id,
        "team_name": team_name,
    }


def classify_position(position_name, position_abbr=""):
    """Classify a rugby position into forward/back."""
    pos = (position_name + " " + position_abbr).lower()

    forwards = [
        "prop", "hooker", "lock", "flanker", "number eight", "no.8", "no8",
        "loosehead", "tighthead", "second row", "back row", "blindside",
        "openside", "forward",
    ]
    backs = [
        "scrumhalf", "scrum-half", "flyhalf", "fly-half", "centre", "center",
        "wing", "winger", "fullback", "full-back", "halfback", "half-back",
        "inside centre", "outside centre", "back", "first five", "second five",
        "standoff", "stand-off",
    ]

    for f in forwards:
        if f in pos:
            return "Forward"
    for b in backs:
        if b in pos:
            return "Back"
    return ""


def main():
    parser = argparse.ArgumentParser(description="Scrape ESPN rugby data")
    parser.add_argument("--league", type=str, help="Scrape a specific league key (e.g., urc, super_rugby)")
    parser.add_argument("--list-leagues", action="store_true", help="List available league keys")
    parser.add_argument("--teams-only", action="store_true", help="Only scrape teams, skip rosters")
    parser.add_argument("--delay", type=float, default=0.5, help="Delay between API calls (seconds)")
    args = parser.parse_args()

    if args.list_leagues:
        print("Available leagues:")
        for key, info in sorted(LEAGUES.items()):
            print(f"  {key:25s} {info['name']} (ESPN ID: {info['id']})")
        return

    output_dir = get_output_dir()
    print(f"Output directory: {output_dir}")

    # Determine which leagues to scrape
    if args.league:
        if args.league not in LEAGUES:
            print(f"Unknown league: {args.league}")
            print(f"Available: {', '.join(sorted(LEAGUES.keys()))}")
            sys.exit(1)
        leagues_to_scrape = {args.league: LEAGUES[args.league]}
    else:
        # Default: scrape the major union leagues (skip NRL/Super League for now)
        union_keys = [
            "urc", "premiership", "top14", "super_rugby", "six_nations",
            "rugby_championship", "currie_cup", "european_champions",
            "world_cup",
        ]
        leagues_to_scrape = {k: LEAGUES[k] for k in union_keys if k in LEAGUES}

    # --- Scrape teams ---
    all_teams = []
    for league_key, league_info in leagues_to_scrape.items():
        teams = fetch_league_teams(league_key, league_info)
        all_teams.extend(teams)
        time.sleep(args.delay)

    # Deduplicate teams by espn_id (teams can appear in multiple leagues)
    seen_ids = {}
    unique_teams = []
    for team in all_teams:
        eid = team["espn_id"]
        if eid not in seen_ids:
            seen_ids[eid] = team
            unique_teams.append(team)
        else:
            # Merge league info
            existing = seen_ids[eid]
            if league_key not in existing.get("leagues", ""):
                existing.setdefault("additional_leagues", []).append(team["league_key"])

    # Save teams
    teams_path = output_dir / "teams.json"
    with open(teams_path, "w") as f:
        json.dump(unique_teams, f, indent=2, default=str)
    print(f"\nExported {len(unique_teams)} unique teams to {teams_path}")

    if args.teams_only:
        print("\n--teams-only specified, skipping rosters.")
        print_summary(output_dir)
        return

    # --- Scrape rosters ---
    all_players = []
    errors = 0

    print(f"\nFetching rosters for {len(unique_teams)} teams...")
    print("Tip: Ctrl+C to stop — partial results are saved.\n")

    try:
        for i, team in enumerate(unique_teams):
            team_id = team["espn_id"]
            team_name = team["name"]
            league_id = team["league_id"]

            players = fetch_team_roster(team_id, team_name, league_id)
            if players:
                all_players.extend(players)
                print(f"  [{i+1}/{len(unique_teams)}] {team_name}: {len(players)} players")
            else:
                errors += 1
                print(f"  [{i+1}/{len(unique_teams)}] {team_name}: no roster data")

            # Checkpoint every 25 teams
            if (i + 1) % 25 == 0 and all_players:
                players_path = output_dir / "players.json"
                with open(players_path, "w") as f:
                    json.dump(all_players, f, indent=2, default=str)
                print(f"  ** Checkpoint: {len(all_players)} players saved **")

            time.sleep(args.delay)

    except KeyboardInterrupt:
        print("\n  Interrupted! Saving partial results...")

    # Deduplicate players by espn_id
    seen_player_ids = set()
    unique_players = []
    for p in all_players:
        pid = p.get("espn_id", "")
        if pid and pid not in seen_player_ids:
            seen_player_ids.add(pid)
            unique_players.append(p)
        elif not pid:
            unique_players.append(p)

    # Save players
    players_path = output_dir / "players.json"
    with open(players_path, "w") as f:
        json.dump(unique_players, f, indent=2, default=str)
    print(f"\nExported {len(unique_players)} unique players to {players_path}")
    print(f"({errors} teams had no roster data)")

    print_summary(output_dir)


def print_summary(output_dir):
    """Print summary of exported files."""
    print("\n" + "=" * 50)
    print("Export complete! Files in:", output_dir)
    for f in sorted(output_dir.glob("*.json")):
        size_kb = f.stat().st_size / 1024
        print(f"  {f.name}: {size_kb:.1f} KB")
    print("\nNext step: php artisan rugby:import-espn")


if __name__ == "__main__":
    main()
