#!/usr/bin/env python3
"""
Scrape match lineups (starting XV + replacements) from rugby365.com.

Each match has a /teams/?g=<id> page with both squads as static HTML,
including jersey number, full name, and sub on/off minutes.

Usage:
    python3 scripts/rugby365_lineup_scraper.py --fixtures-url https://rugby365.com/tournaments/british-irish-lions/fixtures-results/ --competition lions_tour --season 2025
    python3 scripts/rugby365_lineup_scraper.py --match-id 11612 --competition lions_tour --season 2025

Output: storage/app/rugby365_lineups_{competition}_{season}.json
"""

import argparse
import json
import re
import sys
import time
from pathlib import Path
from urllib.request import urlopen, Request


sys.stdout.reconfigure(line_buffering=True)


def fetch(url):
    req = Request(url, headers={"User-Agent": "Mozilla/5.0"})
    with urlopen(req, timeout=20) as resp:
        return resp.read().decode("utf-8", errors="replace")


def parse_date(text):
    months = {"jan": 1, "feb": 2, "mar": 3, "apr": 4, "may": 5, "jun": 6,
              "jul": 7, "aug": 8, "sep": 9, "oct": 10, "nov": 11, "dec": 12}
    m = re.search(r"(\d{1,2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\w*\s+(\d{4})", text)
    if not m:
        return None
    return f"{int(m.group(3))}-{months[m.group(2).lower()]:02d}-{int(m.group(1)):02d}"


def parse_player_block(html, start):
    """Parse one player block starting at `start`. Returns (player_dict, end_offset) or (None, None)."""
    # Look ahead for num + name (name may contain nested divs for try/penalty icons)
    chunk = html[start:start + 800]
    num_m = re.search(r'<div class="num">(\d+)</div>', chunk)
    name_m = re.search(r'<div class="name">\s*([^<]+?)(?:\s*<|\s*</div>)', chunk)
    if not (num_m and name_m):
        return None, None

    # Sub block: off minute = came off; on minute = came on
    sub_m = re.search(r'<div class="sub">\s*(.*?)\s*</div>\s*</div>', chunk, re.DOTALL)
    off_min = on_min = None
    if sub_m:
        sub_html = sub_m.group(1)
        om = re.search(r'class="off">\s*(\d+)', sub_html)
        nm = re.search(r'class="on">\s*(\d+)', sub_html)
        if om: off_min = int(om.group(1))
        if nm: on_min = int(nm.group(1))

    name = name_m.group(1).strip()
    # Ignore icon-only matches that may still have leaked through
    if not name or len(name) < 2:
        return None, None

    return {
        "number": int(num_m.group(1)),
        "name": name,
        "off_minute": off_min,
        "on_minute": on_min,
    }, start + 800


def parse_match_page(match_url, date_hint=None):
    # match_url is the canonical /live/<slug>/?g=<id> URL — insert /teams/ before the query.
    m = re.match(r"(https://rugby365\.com/live/[^/?]+)/?\?g=(\d+)", match_url)
    if not m:
        raise RuntimeError(f"Unrecognised match URL: {match_url}")
    slug_base, match_id = m.group(1), m.group(2)
    url = f"{slug_base}/teams/?g={match_id}"
    html = fetch(url)
    if "class=\"player" not in html:
        raise RuntimeError(f"No lineup blocks in {url}")

    # Teams
    team_names = re.findall(r'team-name[^>]*>([^<]{3,60})<', html)
    if len(team_names) < 2:
        raise RuntimeError(f"Expected 2 team names, got {team_names}")
    home_name, away_name = team_names[0].strip(), team_names[1].strip()

    # Prefer the fixtures-page epoch date (reliable); fall back to HTML text.
    date_iso = date_hint or parse_date(html)

    # Find all player block starts
    starts = [m.start() for m in re.finditer(r'class="player(?:\s+odd)?\s*"\s*>', html)]
    if len(starts) != 46:
        print(f"    ⚠ g={match_id}: expected 46 player blocks, got {len(starts)}", flush=True)

    players = []
    for s in starts:
        p, _ = parse_player_block(html, s)
        if p:
            players.append(p)

    # Rugby365 orders: home 1-15, away 1-15, home 16-23, away 16-23
    # Verify by number sequence
    home = []
    away = []
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
            # Replacements 16-23: first occurrence goes home, second away
            if sum(1 for x in home if x["number"] == num) == 0:
                home.append(p)
            else:
                away.append(p)

    return {
        "match_id": int(match_id),
        "url": url,
        "date": date_iso,
        "home": home_name,
        "away": away_name,
        "home_lineup": sorted(home, key=lambda x: x["number"]),
        "away_lineup": sorted(away, key=lambda x: x["number"]),
    }


def parse_fixtures_page(fixtures_url):
    """Return list of (url, date_iso) tuples from the fixtures page.

    Walks brace-matched JSON object boundaries around each ?g=<id> occurrence so
    url and epoch come from the same fixture record.
    """
    import datetime as dt
    html = fetch(fixtures_url)
    fixtures = []
    seen = set()
    for m in re.finditer(r'\?g=\d+', html):
        # Walk back to enclosing '{'
        i, depth = m.start(), 0
        while i > 0:
            c = html[i]
            if c == '}': depth += 1
            elif c == '{':
                if depth == 0: break
                depth -= 1
            i -= 1
        # Walk forward to matching '}'
        j, depth = m.end(), 0
        while j < len(html):
            c = html[j]
            if c == '{': depth += 1
            elif c == '}':
                if depth == 0: break
                depth -= 1
            j += 1
        obj = html[i:j + 1]
        um = re.search(r'"url":"([^"]+\?g=\d+)"', obj)
        if not um:
            continue
        url = um.group(1).replace("\\/", "/")
        if url in seen:
            continue
        seen.add(url)
        em = re.search(r'"epoch":(\d+)', obj)
        date_iso = None
        if em:
            date_iso = dt.datetime.utcfromtimestamp(int(em.group(1))).strftime("%Y-%m-%d")
        fixtures.append((url, date_iso))
    return fixtures


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--fixtures-url")
    parser.add_argument("--match-url", help="Canonical rugby365 match URL, e.g. https://rugby365.com/live/australia-vs-british-irish-lions/?g=11612")
    parser.add_argument("--competition", required=True)
    parser.add_argument("--season", required=True)
    args = parser.parse_args()

    if args.match_url:
        fixtures = [(args.match_url, None)]
    elif args.fixtures_url:
        print(f"Fetching fixtures from {args.fixtures_url}")
        fixtures = parse_fixtures_page(args.fixtures_url)
        print(f"  Found {len(fixtures)} match URLs")
    else:
        parser.error("Provide --fixtures-url or --match-url")

    results = []
    for i, (u, date_hint) in enumerate(fixtures, 1):
        try:
            data = parse_match_page(u, date_hint=date_hint)
            data["competition"] = args.competition
            data["season"] = args.season
            results.append(data)
            print(f"  [{i}/{len(fixtures)}] g={data['match_id']} {data['home']} vs {data['away']} "
                  f"({len(data['home_lineup'])}+{len(data['away_lineup'])}) {data['date']}",
                  flush=True)
        except Exception as e:
            print(f"  [{i}/{len(fixtures)}] {u} ERROR: {e}", flush=True)
        time.sleep(1)

    out_path = Path(__file__).parent.parent / "storage" / "app" / f"rugby365_lineups_{args.competition}_{args.season}.json"
    out_path.parent.mkdir(parents=True, exist_ok=True)
    with open(out_path, "w") as f:
        json.dump({
            "competition": args.competition,
            "season": args.season,
            "matches": results,
        }, f, indent=2)
    print(f"\nOutput: {out_path}")


if __name__ == "__main__":
    main()
