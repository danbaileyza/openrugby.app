#!/usr/bin/env python3
"""
Scrape schoolrugby.co.za results via the GetPosts JSON API.

The API endpoint /GetCategories.asmx/GetPosts returns HTML tiles for matches
and paginates via strItems (list of already-fetched IDs).

Usage:
    python3 scripts/schoolrugby_scraper.py --tournament 213        # Interschools
    python3 scripts/schoolrugby_scraper.py --tournament 11         # *South Africa feed
    python3 scripts/schoolrugby_scraper.py --tournament 14         # Wildeklawer
    python3 scripts/schoolrugby_scraper.py --year 2026             # all 2026

Output: storage/app/schoolrugby_<scope>_<year>.json
"""

import argparse
import json
import re
import sys
import time
from datetime import datetime
from pathlib import Path
from urllib.request import urlopen, Request

sys.stdout.reconfigure(line_buffering=True)

MONTHS = {"JAN": 1, "FEB": 2, "MAR": 3, "APR": 4, "MAY": 5, "JUN": 6,
          "JUL": 7, "AUG": 8, "SEP": 9, "OCT": 10, "NOV": 11, "DEC": 12}

API_URL = "http://schoolrugby.co.za/GetCategories.asmx/GetPosts"


def fetch_posts(tournament, year, existing_ids, school=0, retries=3):
    body = json.dumps({
        "strItems": existing_ids,
        "category": 0,
        "school": str(school) if school else 0,
        "school2": 0,
        "tournament": str(tournament) if tournament else "0",
        "year": str(year) if year else "0",
        "user": "",
        "sort": "",
    }).encode()
    last_err = None
    for attempt in range(retries):
        try:
            req = Request(API_URL, data=body, headers={
                "User-Agent": "Mozilla/5.0",
                "Content-Type": "application/json; charset=UTF-8",
                "Accept": "application/json",
            })
            with urlopen(req, timeout=30) as resp:
                data = json.loads(resp.read().decode("utf-8", errors="replace"))
            return data.get("d", "")
        except Exception as e:
            last_err = e
            time.sleep(2 + attempt * 2)
    print(f"  ⚠ fetch failed after {retries} retries: {last_err}")
    return ""


def parse_tiles(html):
    """Extract match tiles from the returned HTML."""
    if not html:
        return []
    tiles = re.split(r"(?=<div[^>]*class='row ResultTile)", html)
    matches = []
    for tile in tiles:
        if "ResultTile" not in tile:
            continue
        year = re.search(r"class='rotate year'>(\d{4})<", tile)
        month = re.search(r"class='month'>([A-Z]{3})<", tile)
        day = re.search(r"class='day'>(\d{1,2})<", tile)
        tid = re.search(r"<span id='(\d+)'></span>", tile)
        tournament_name = re.search(r"class='black' href='/tournament\.aspx\?t=(\d+)[^']*'[^>]*>([^<]+)</a>", tile)
        match_line = re.search(
            r"<a href='/school\.aspx\?s=(\d+)'>([^<]+)</a>\s+<strong>(\d+)</strong>\s*-\s*(\d+)\s+<a href='/school\.aspx\?s=(\d+)'>([^<]+)</a>",
            tile,
        )
        if not (year and month and day and match_line):
            continue
        matches.append({
            "id": tid.group(1) if tid else None,
            "date": f"{year.group(1)}-{MONTHS[month.group(1)]:02d}-{int(day.group(1)):02d}",
            "tournament_id": int(tournament_name.group(1)) if tournament_name else None,
            "tournament_name": tournament_name.group(2).strip() if tournament_name else None,
            "home_school_id": int(match_line.group(1)),
            "home_team": match_line.group(2).strip(),
            "home_score": int(match_line.group(3)),
            "away_score": int(match_line.group(4)),
            "away_school_id": int(match_line.group(5)),
            "away_team": match_line.group(6).strip(),
        })
    return matches


def scrape(tournament=0, year=0, school=0, max_pages=200):
    all_matches = []
    seen_ids = []
    for page in range(max_pages):
        html = fetch_posts(tournament, year, seen_ids, school=school)
        batch = parse_tiles(html)
        if not batch:
            break
        new_in_batch = [m for m in batch if m["id"] not in seen_ids]
        if not new_in_batch:
            break
        all_matches.extend(new_in_batch)
        seen_ids.extend([m["id"] for m in new_in_batch if m["id"]])
        print(f"  Page {page + 1}: +{len(new_in_batch)} (total {len(all_matches)})")
        time.sleep(0.3)
    return all_matches


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--tournament", type=int, default=0, help="Tournament id (0 = all)")
    parser.add_argument("--year", type=int, default=0, help="Year filter (0 = all)")
    parser.add_argument("--school", type=int, default=0, help="School id (0 = all)")
    parser.add_argument("--schools", help="Comma-separated list of school IDs to scrape in one run")
    args = parser.parse_args()

    # Multi-school mode: iterate through a list
    if args.schools:
        ids = [int(s.strip()) for s in args.schools.split(",") if s.strip()]
        all_matches = []
        seen = set()
        for sid in ids:
            print(f"\n=== School {sid} ===")
            matches = scrape(school=sid, year=args.year)
            for m in matches:
                if m["id"] and m["id"] not in seen:
                    seen.add(m["id"])
                    all_matches.append(m)
        scope = f"schools-{len(ids)}"
        yr = str(args.year) if args.year else "all"
        out = Path(__file__).parent.parent / "storage" / "app" / f"schoolrugby_{scope}_{yr}.json"
        out.parent.mkdir(parents=True, exist_ok=True)
        with open(out, "w") as f:
            json.dump({
                "school_ids": ids,
                "year": args.year,
                "scraped_at": datetime.utcnow().isoformat() + "Z",
                "matches": all_matches,
            }, f, indent=2)
        print(f"\nOutput: {out} ({len(all_matches)} unique matches from {len(ids)} schools)")
        return

    scope = f"t{args.tournament}" if args.tournament else (f"s{args.school}" if args.school else "all")
    yr = str(args.year) if args.year else "all"
    print(f"Scraping schoolrugby.co.za (tournament={args.tournament}, school={args.school}, year={yr})...")

    matches = scrape(args.tournament, args.year, args.school)

    out = Path(__file__).parent.parent / "storage" / "app" / f"schoolrugby_{scope}_{yr}.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    with open(out, "w") as f:
        json.dump({
            "tournament_id": args.tournament,
            "year": args.year,
            "scraped_at": datetime.utcnow().isoformat() + "Z",
            "matches": matches,
        }, f, indent=2)
    print(f"\nOutput: {out} ({len(matches)} matches)")


if __name__ == "__main__":
    main()
