#!/usr/bin/env python3
"""
Scrape match, player, and squad data from the URC GraphQL API.

The URC stats site (stats.unitedrugby.com) uses a GraphQL API at
www.unitedrugby.com/graphql with GET requests.

Usage:
    python3 scripts/urc_scraper.py --squads                # All team squads with player profiles
    python3 scripts/urc_scraper.py --squads --club STO     # Just Stormers squad
    python3 scripts/urc_scraper.py --season 202501         # All matches for 2025-26 season
    python3 scripts/urc_scraper.py --match 287855          # Single match detail
    python3 scripts/urc_scraper.py --player-stats 202501   # Player season stats
    python3 scripts/urc_scraper.py --standings 202501      # League standings

Output: storage/app/urc/
Then import with: php artisan rugby:import-urc
"""

import argparse
import json
import sys
import time
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError
from urllib.parse import urlencode, quote

GRAPHQL_URL = "https://www.unitedrugby.com/graphql"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36",
    "Accept": "application/json",
    "Referer": "https://stats.unitedrugby.com/",
    "Origin": "https://stats.unitedrugby.com",
}

# URC club short codes and IDs (from Apollo cache)
URC_CLUBS = {
    "SHA": {"id": 1527, "name": "Hollywoodbets Sharks"},
    "EDI": {"id": 1641, "name": "Edinburgh"},
    "BEN": {"id": 2019, "name": "Benetton"},
    "ULS": {"id": 2129, "name": "Ulster"},
    "GLA": {"id": 3098, "name": "Glasgow Warriors"},
    "SCA": {"id": 3514, "name": "Scarlets"},
    "DRA": {"id": 3533, "name": "Dragons RFC"},
    "STO": {"id": 3994, "name": "DHL Stormers"},
    "MUN": {"id": 4377, "name": "Munster"},
    "CAR": {"id": 4471, "name": "Cardiff Rugby"},
    "ZEB": {"id": 4474, "name": "Zebre Parma"},
    "OSP": {"id": 5057, "name": "Ospreys"},
    "LIO": {"id": 5092, "name": "Fidelity SecureDrive Lions"},
    "LEI": {"id": 5356, "name": "Leinster"},
    "CON": {"id": 5483, "name": "Connacht"},
    "BUL": {"id": 5586, "name": "Vodacom Bulls"},
}

# Season IDs: 202501 = 2025-26, 202401 = 2024-25, etc.
KNOWN_SEASONS = {
    "2025-26": 202501,
    "2024-25": 202401,
    "2023-24": 202301,
    "2022-23": 202201,
    "2021-22": 202101,
}

# ─── GraphQL Queries (extracted from JS bundle) ─────────────────────

QUERY_SQUADS_FULL = """
query GetPlayerThemeSettingsById($currentClub: [String]) {
    playerThemeSettings(currentClub: $currentClub) {
        squads {
            currentClub
            squad {
                knownName
                birthcountry
                dateOfBirth
                nationalTeam
                headshots
                playerFirstName
                playerAge
                playerId
                playerLastName
                playerPosition
                playerWeight
                playerHeight
                playerHeightCm
                urcDebut
            }
        }
    }
}
"""

QUERY_SQUADS_ALL = """
query GetPlayerThemeSettings {
    playerThemeSettings {
        squads {
            currentClub
            squad {
                knownName
                heroNumber
                headshots
                playerFirstName
                playerId
                playerLastName
                playerPosition
            }
        }
    }
}
"""

QUERY_ROUNDS = """
query GetRoundsData($seasonId: [Int]) {
    matchstats(season_id: $seasonId) {
        match_id
        match_datetime
        match_status
        match_period
        stats_data {
            tbc
            dateTime
            id
            matchWinner
            round
            season { id }
            homeTeam {
                team { id, name }
                score { currentScore, finalScore }
            }
            awayTeam {
                team { id, name }
                score { currentScore, finalScore }
            }
            venue { timezone, name }
            events { display }
        }
    }
}
"""

QUERY_MATCH_EVENTS = """
query GetMatchEventsByTeamsQuery($match_id: [Int]!) {
    matchstats(match_id: $match_id) {
        season_id
        home_team_id
        away_team_id
        match_status
        stats_data {
            dateTime
            homeTeam {
                team { id, name }
                score { currentScore }
            }
            awayTeam {
                team { id, name }
                score { currentScore }
            }
            venue {
                name
                country { name }
            }
            events {
                id
                display
                time
                timestamp
                period { name }
                player { name }
                team { id, name }
                playerOff { name }
                playerOn { name }
                type { name }
                properties { name, value }
            }
        }
    }
}
"""

QUERY_MATCH_LINEUPS = """
query GetPostLineupData($match_id: [Int]) {
    matchstats(match_id: $match_id) {
        home_team_id
        away_team_id
        stats_data {
            awayTeam {
                team { id, name }
                players {
                    id
                    firstName
                    lastName
                    position { name, shirtNumber }
                }
            }
            homeTeam {
                team { id, name }
                players {
                    id
                    firstName
                    lastName
                    position { name, shirtNumber }
                }
            }
            officials { id, name, role }
        }
    }
}
"""

QUERY_PLAYER_SEASON_STATS = """
query GetPlayerSeasonStats($seasonId: [Int]) {
    playerseasonstats(season_id: $seasonId) {
        id
        team_id
        player_stats {
            firstName
            lastName
            playerStats {
                scoring { points, tryScored }
                attack { carries, offload, metresMade, defenderBeaten, cleanBreak }
                defence { tackle, percentTackleMade, turnoverWon }
            }
        }
    }
}
"""

QUERY_STANDINGS = """
query GetStandingData($seasonId: [Int]) {
    standingsdata(season_id: $seasonId) {
        standings {
            teamId
            teamName
            played
            won
            drawn
            lost
            pointsFor
            pointsAgainst
            pointsDifference
            bonusPoints
            totalPoints
        }
    }
}
"""

QUERY_PLAYER_MATRIX = """
query GetPlayerMatrixStats($seasonId: [Int]) {
    playermatrixstats(season_id: $seasonId) {
        id
        matrix_category
        matrix_name
        players_data {
            player_id
            player_name
            position
            score
            team_id
        }
    }
}
"""


def get_output_dir():
    script_dir = Path(__file__).parent.parent
    output_dir = script_dir / "storage" / "app" / "urc"
    output_dir.mkdir(parents=True, exist_ok=True)
    return output_dir


def graphql_get(query, variables=None, retries=3):
    """Execute a GraphQL query via GET request."""
    params = {
        "query": query.strip(),
    }
    if variables:
        params["variables"] = json.dumps(variables)

    url = f"{GRAPHQL_URL}?{urlencode(params, quote_via=quote)}"

    for attempt in range(retries):
        try:
            req = Request(url, headers=HEADERS)
            with urlopen(req, timeout=30) as resp:
                body = json.loads(resp.read().decode("utf-8"))
                if "errors" in body:
                    print(f"  GraphQL errors: {body['errors']}")
                return body.get("data")
        except HTTPError as e:
            if e.code == 429:
                wait = 2 * (attempt + 1)
                print(f"  Rate limited, waiting {wait}s...")
                time.sleep(wait)
                continue
            if e.code >= 500:
                time.sleep(1)
                continue
            print(f"  HTTP {e.code}")
            return None
        except (URLError, TimeoutError) as e:
            if attempt < retries - 1:
                time.sleep(1)
                continue
            print(f"  Error: {e}")
            return None
    return None


def scrape_squads(club_codes=None):
    """Scrape full squad data with player profiles."""
    output_dir = get_output_dir()

    if club_codes:
        print(f"Fetching squads for: {', '.join(club_codes)}")
        data = graphql_get(QUERY_SQUADS_FULL, {"currentClub": club_codes})
    else:
        # Try full query first (all clubs at once)
        print("Fetching all URC squads...")
        data = graphql_get(QUERY_SQUADS_FULL, {"currentClub": list(URC_CLUBS.keys())})

        if not data or not data.get("playerThemeSettings"):
            # Fall back to fetching one by one
            print("  Bulk fetch failed, trying individual clubs...")
            all_squads = []
            for code, info in URC_CLUBS.items():
                print(f"  Fetching {info['name']} ({code})...")
                club_data = graphql_get(QUERY_SQUADS_FULL, {"currentClub": [code]})
                if club_data and club_data.get("playerThemeSettings"):
                    squads = club_data["playerThemeSettings"].get("squads", [])
                    all_squads.extend(squads)
                time.sleep(0.5)

            data = {"playerThemeSettings": {"squads": all_squads}}

    if not data or not data.get("playerThemeSettings"):
        print("  No squad data returned.")
        return

    squads = data["playerThemeSettings"].get("squads", [])

    # Flatten into player records
    all_players = []
    for squad in squads:
        club_code = squad.get("currentClub", "")
        club_info = URC_CLUBS.get(club_code, {})

        for player in squad.get("squad", []):
            all_players.append({
                "urc_id": player.get("playerId"),
                "first_name": player.get("playerFirstName", ""),
                "last_name": player.get("playerLastName", ""),
                "known_name": player.get("knownName", ""),
                "nationality": player.get("birthcountry", ""),
                "national_team": player.get("nationalTeam", ""),
                "date_of_birth": player.get("dateOfBirth", ""),
                "age": player.get("playerAge"),
                "position": player.get("playerPosition", ""),
                "height": player.get("playerHeight", ""),
                "height_cm": player.get("playerHeightCm"),
                "weight": player.get("playerWeight", ""),
                "photo_url": player.get("headshots", ""),
                "urc_debut": player.get("urcDebut", ""),
                "club_code": club_code,
                "club_name": club_info.get("name", club_code),
                "club_urc_id": club_info.get("id"),
            })

    # Save
    squads_path = output_dir / "squads.json"
    with open(squads_path, "w") as f:
        json.dump(all_players, f, indent=2, default=str)

    # Count by club
    club_counts = {}
    for p in all_players:
        c = p["club_name"]
        club_counts[c] = club_counts.get(c, 0) + 1

    print(f"\nExported {len(all_players)} players to {squads_path}")
    for club, count in sorted(club_counts.items()):
        print(f"  {club}: {count} players")

    return all_players


def scrape_season(season_id):
    """Scrape all matches for a season."""
    output_dir = get_output_dir()
    print(f"Fetching season {season_id} matches...")

    data = graphql_get(QUERY_ROUNDS, {"seasonId": [season_id]})
    if not data or not data.get("matchstats"):
        print("  No match data returned.")
        return

    matches = data["matchstats"]
    path = output_dir / f"season_{season_id}_matches.json"
    with open(path, "w") as f:
        json.dump(matches, f, indent=2, default=str)

    completed = sum(1 for m in matches if m.get("match_status") == "result")
    print(f"Exported {len(matches)} matches ({completed} completed) to {path}")
    return matches


def scrape_match(match_id):
    """Scrape detailed match data including events and lineups."""
    output_dir = get_output_dir()
    print(f"Fetching match {match_id} details...")

    match_data = {}

    # Events
    events = graphql_get(QUERY_MATCH_EVENTS, {"match_id": [match_id]})
    if events and events.get("matchstats"):
        match_data["events"] = events["matchstats"]
        print(f"  Events: OK")
    time.sleep(0.3)

    # Lineups
    lineups = graphql_get(QUERY_MATCH_LINEUPS, {"match_id": [match_id]})
    if lineups and lineups.get("matchstats"):
        match_data["lineups"] = lineups["matchstats"]
        print(f"  Lineups: OK")

    if match_data:
        path = output_dir / f"match_{match_id}.json"
        with open(path, "w") as f:
            json.dump(match_data, f, indent=2, default=str)
        print(f"Saved to {path}")

    return match_data


def scrape_player_stats(season_id):
    """Scrape player season statistics."""
    output_dir = get_output_dir()
    print(f"Fetching player stats for season {season_id}...")

    data = graphql_get(QUERY_PLAYER_SEASON_STATS, {"seasonId": [season_id]})
    if not data or not data.get("playerseasonstats"):
        print("  No player stats returned.")
        return

    stats = data["playerseasonstats"]
    path = output_dir / f"player_stats_{season_id}.json"
    with open(path, "w") as f:
        json.dump(stats, f, indent=2, default=str)

    print(f"Exported {len(stats)} player stat records to {path}")
    return stats


def scrape_standings(season_id):
    """Scrape league standings."""
    output_dir = get_output_dir()
    print(f"Fetching standings for season {season_id}...")

    data = graphql_get(QUERY_STANDINGS, {"seasonId": [season_id]})
    if not data or not data.get("standingsdata"):
        print("  No standings data returned.")
        return

    standings = data["standingsdata"]
    path = output_dir / f"standings_{season_id}.json"
    with open(path, "w") as f:
        json.dump(standings, f, indent=2, default=str)

    print(f"Exported standings to {path}")
    return standings


def scrape_all_match_details(season_id, delay=0.5):
    """Scrape lineups and events for all completed matches in a season."""
    output_dir = get_output_dir()

    # First get the season matches
    season_path = output_dir / f"season_{season_id}_matches.json"
    if season_path.exists():
        matches = json.loads(season_path.read_text())
    else:
        matches = scrape_season(season_id)

    if not matches:
        return

    completed = [m for m in matches if m.get("match_status") == "result"]
    print(f"\nFetching details for {len(completed)} completed matches...")

    all_details = []
    for i, match in enumerate(completed):
        match_id = match.get("match_id") or match.get("stats_data", {}).get("id")
        if not match_id:
            continue

        detail = scrape_match(match_id)
        if detail:
            detail["match_id"] = match_id
            all_details.append(detail)
            print(f"  [{i+1}/{len(completed)}] Match {match_id}: OK")
        else:
            print(f"  [{i+1}/{len(completed)}] Match {match_id}: no data")

        # Checkpoint
        if (i + 1) % 20 == 0:
            path = output_dir / f"match_details_{season_id}.json"
            with open(path, "w") as f:
                json.dump(all_details, f, indent=2, default=str)

        time.sleep(delay)

    path = output_dir / f"match_details_{season_id}.json"
    with open(path, "w") as f:
        json.dump(all_details, f, indent=2, default=str)
    print(f"\nExported {len(all_details)} match details to {path}")


def main():
    parser = argparse.ArgumentParser(description="Scrape URC stats via GraphQL")
    parser.add_argument("--squads", action="store_true",
                        help="Scrape team squads with full player profiles")
    parser.add_argument("--club", type=str, nargs="*",
                        help="Club codes to scrape (e.g., STO SHA BUL). Default: all")
    parser.add_argument("--season", type=str,
                        help="Scrape all matches for a season (e.g., 202501 or 2025-26)")
    parser.add_argument("--match", type=int,
                        help="Scrape a single match by ID")
    parser.add_argument("--match-details", type=str,
                        help="Scrape lineups+events for all matches in a season")
    parser.add_argument("--player-stats", type=str,
                        help="Scrape player season stats")
    parser.add_argument("--standings", type=str,
                        help="Scrape league standings")
    parser.add_argument("--all", type=str, metavar="SEASON",
                        help="Scrape everything for a season")
    parser.add_argument("--list-clubs", action="store_true",
                        help="List URC club codes")
    parser.add_argument("--delay", type=float, default=0.5,
                        help="Delay between requests (default: 0.5s)")
    args = parser.parse_args()

    if args.list_clubs:
        print("URC Clubs:")
        for code, info in sorted(URC_CLUBS.items()):
            print(f"  {code:5s} {info['name']:35s} (ID: {info['id']})")
        return

    output_dir = get_output_dir()
    print(f"Output directory: {output_dir}")
    print(f"GraphQL endpoint: {GRAPHQL_URL}\n")

    def resolve_season(val):
        if val in KNOWN_SEASONS:
            return KNOWN_SEASONS[val]
        return int(val)

    if args.all:
        season_id = resolve_season(args.all)
        scrape_squads()
        scrape_season(season_id)
        scrape_player_stats(season_id)
        scrape_standings(season_id)
        scrape_all_match_details(season_id, args.delay)
        return

    if args.squads or args.club:
        club_codes = [c.upper() for c in args.club] if args.club else None
        scrape_squads(club_codes)

    if args.season:
        season_id = resolve_season(args.season)
        scrape_season(season_id)

    if args.match:
        scrape_match(args.match)

    if args.match_details:
        season_id = resolve_season(args.match_details)
        scrape_all_match_details(season_id, args.delay)

    if args.player_stats:
        season_id = resolve_season(args.player_stats)
        scrape_player_stats(season_id)

    if args.standings:
        season_id = resolve_season(args.standings)
        scrape_standings(season_id)

    if not any([args.squads, args.club, args.season, args.match,
                args.match_details, args.player_stats, args.standings, args.all]):
        parser.print_help()
        print("\nQuick start:")
        print("  python3 scripts/urc_scraper.py --squads          # Player profiles with nationality")
        print("  python3 scripts/urc_scraper.py --all 2025-26     # Everything for current season")


if __name__ == "__main__":
    main()
