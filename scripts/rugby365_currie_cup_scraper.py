#!/usr/bin/env python3
"""
Scrape Currie Cup match lineups from rugby365.com.

Uses the AJAX API to get fixture URLs per season, then fetches each
match's /teams/ page for lineup data (static HTML).

Usage:
    python3 scripts/rugby365_currie_cup_scraper.py --season 2025
    python3 scripts/rugby365_currie_cup_scraper.py --season 2024

Output: storage/app/rugby365_lineups_currie_cup_{season}.json
"""

import argparse
import json
import re
import sys
import time
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.parse import urlencode

sys.stdout.reconfigure(line_buffering=True)

FIXTURES_URL = "https://rugby365.com/tournaments/currie-cup/fixtures-results/"


def fetch(url):
    req = Request(url, headers={"User-Agent": "Mozilla/5.0"})
    with urlopen(req, timeout=20) as resp:
        return resp.read().decode("utf-8", errors="replace")


def get_season_fixtures(season_label):
    data = urlencode({"action": "get-season", "season": season_label}).encode()
    req = Request(FIXTURES_URL, data=data, headers={
        "User-Agent": "Mozilla/5.0",
        "Content-Type": "application/x-www-form-urlencoded",
        "X-Requested-With": "XMLHttpRequest",
    })
    with urlopen(req, timeout=20) as resp:
        result = json.loads(resp.read().decode("utf-8", errors="replace"))
    items = result.get("items", [])
    all_html = "".join(items)

    fixtures = []
    seen = set()
    current_date = None
    months = {"jan":"01","feb":"02","mar":"03","apr":"04","may":"05","jun":"06",
              "jul":"07","aug":"08","sep":"09","oct":"10","nov":"11","dec":"12"}
    for item in items:
        date_m = re.search(r'class="date">\s*\w+\s+(\w+)\s+(\d{1,2}),\s+(\d{4})', item)
        if date_m:
            mon = months.get(date_m.group(1).lower()[:3], "01")
            current_date = f"{date_m.group(3)}-{mon}-{int(date_m.group(2)):02d}"
        for m in re.finditer(r'href="(https://rugby365\.com/live/[^"]+\?g=(\d+))"', item):
            url, gid = m.group(1), m.group(2)
            if gid not in seen:
                seen.add(gid)
                fixtures.append((url, gid, current_date))
    return fixtures


def parse_player_block(html, start):
    chunk = html[start:start + 800]
    num_m = re.search(r'<div class="num">(\d+)</div>', chunk)
    name_m = re.search(r'<div class="name">\s*([^<]+?)(?:\s*<|\s*</div>)', chunk)
    if not (num_m and name_m):
        return None, None
    name = name_m.group(1).strip()
    if not name or len(name) < 2:
        return None, None
    sub_m = re.search(r'<div class="sub">\s*(.*?)\s*</div>\s*</div>', chunk, re.DOTALL)
    off_min = on_min = None
    if sub_m:
        sub_html = sub_m.group(1)
        om = re.search(r'class="off">\s*(\d+)', sub_html)
        nm = re.search(r'class="on">\s*(\d+)', sub_html)
        if om: off_min = int(om.group(1))
        if nm: on_min = int(nm.group(1))
    return {
        "number": int(num_m.group(1)),
        "name": name,
        "off_minute": off_min,
        "on_minute": on_min,
    }, start + 800


def parse_match_page(match_url, match_id, date_hint=None):
    slug_m = re.match(r"(https://rugby365\.com/live/[^/?]+)/?\?g=(\d+)", match_url)
    if not slug_m:
        raise RuntimeError(f"Bad URL: {match_url}")
    teams_url = f"{slug_m.group(1)}/teams/?g={match_id}"
    html = fetch(teams_url)

    if 'class="player' not in html:
        raise RuntimeError(f"No lineup in {teams_url}")

    team_names = re.findall(r'team-name[^>]*>([^<]{3,60})<', html)
    if len(team_names) < 2:
        raise RuntimeError(f"Expected 2 teams, got {team_names}")
    home_name, away_name = team_names[0].strip(), team_names[1].strip()

    date_iso = date_hint
    if not date_iso:
        m = re.search(r"(\d{1,2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\w*\s+(\d{4})", html)
        if m:
            months = {"jan":1,"feb":2,"mar":3,"apr":4,"may":5,"jun":6,"jul":7,"aug":8,"sep":9,"oct":10,"nov":11,"dec":12}
            date_iso = f"{int(m.group(3))}-{months[m.group(2).lower()]:02d}-{int(m.group(1)):02d}"

    starts = [m.start() for m in re.finditer(r'class="player(?:\s+odd)?\s*"\s*>', html)]
    players = []
    for s in starts:
        p, _ = parse_player_block(html, s)
        if p:
            players.append(p)

    home, away = [], []
    seen_home_numbers = set()
    for p in players:
        num = p["number"]
        if num <= 15:
            if num not in seen_home_numbers:
                home.append(p)
                seen_home_numbers.add(num)
            else:
                away.append(p)
        else:
            if sum(1 for x in home if x["number"] == num) == 0:
                home.append(p)
            else:
                away.append(p)

    return {
        "match_id": int(match_id),
        "url": teams_url,
        "date": date_iso,
        "home": home_name,
        "away": away_name,
        "home_lineup": sorted(home, key=lambda x: x["number"]),
        "away_lineup": sorted(away, key=lambda x: x["number"]),
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--season", required=True)
    args = parser.parse_args()

    print(f"Fetching fixtures for Currie Cup {args.season}...")
    fixtures = get_season_fixtures(args.season)
    print(f"  Found {len(fixtures)} matches")

    results = []
    for i, (url, gid, date_hint) in enumerate(fixtures, 1):
        try:
            data = parse_match_page(url, gid, date_hint=date_hint)
            data["competition"] = "currie_cup"
            data["season"] = args.season
            results.append(data)
            print(f"  [{i}/{len(fixtures)}] g={gid} {data['home']} vs {data['away']} "
                  f"({len(data['home_lineup'])}+{len(data['away_lineup'])}) {data['date']}",
                  flush=True)
        except Exception as e:
            print(f"  [{i}/{len(fixtures)}] g={gid} ERROR: {e}", flush=True)
        time.sleep(1)

    out_path = Path(__file__).parent.parent / "storage" / "app" / f"rugby365_lineups_currie_cup_{args.season}.json"
    out_path.parent.mkdir(parents=True, exist_ok=True)
    with open(out_path, "w") as f:
        json.dump({
            "competition": "currie_cup",
            "season": args.season,
            "matches": results,
        }, f, indent=2)
    print(f"\nOutput: {out_path}")


if __name__ == "__main__":
    main()
