#!/usr/bin/env python3
"""
Scrape Western Province Club Rugby (fixtures.wpclubrugby.co.za) via form interaction.

Iterates competitions + date ranges to collect all match results.

Usage:
    python3.11 scripts/wpclubrugby_scraper.py --year 2024
    python3.11 scripts/wpclubrugby_scraper.py --year 2026

Output: storage/app/wpclubrugby_<year>.json
"""

import argparse
import json
import re
import sys
from datetime import datetime
from pathlib import Path

from playwright.sync_api import sync_playwright

sys.stdout.reconfigure(line_buffering=True)

# Competitions in the dropdown (empty = all)
COMPETITIONS = [
    "Super League A", "Super League B", "Super League C",
    "Southern League", "Northern League", "Paarl Region",
    "Womens League Div 1", "Womens League Div 2",
]


def scrape_range(page, list_path, from_date, to_date, competition):
    """Submit the search form and collect all rendered rows."""
    page.goto(f"https://fixtures.wpclubrugby.co.za/{list_path}", wait_until="networkidle", timeout=30000)
    page.wait_for_timeout(2000)

    # Populate date fields via DOM
    page.evaluate(f"""
        document.querySelector('input#x_Date').value = '{from_date}';
        document.querySelector('input#y_Date').value = '{to_date}';
    """)
    if competition:
        page.select_option("select#x_Competition", label=competition)
    else:
        page.evaluate("document.querySelector('select#x_Competition').value = ''")

    page.wait_for_timeout(500)
    page.click("#btn-submit")
    page.wait_for_timeout(4000)

    matches = []
    rows = page.query_selector_all("table tr")
    for r in rows:
        cells = [c.inner_text().strip() for c in r.query_selector_all("td,th")]
        # Match rows: at least Day, Date, League, Team, ..., Home, H Pts, ..., Away, A pts
        if len(cells) < 10:
            continue
        if not re.match(r"\d{1,2}/\d{1,2}/\d{4}", cells[2]) if len(cells) > 2 else True:
            continue

        try:
            # Parse DD/MM/YYYY
            day, month, year = map(int, cells[2].split("/"))
            date_iso = f"{year:04d}-{month:02d}-{day:02d}"

            league = cells[3]
            team_cat = cells[4]  # "1st Team", "2nd Team" etc.
            home_name = cells[6]
            home_pts = cells[7] if cells[7] != "" else None
            away_name = cells[9]
            away_pts = cells[10] if cells[10] != "" else None

            if home_pts is None or away_pts is None:
                continue
            home_score = int(home_pts) if home_pts.isdigit() else None
            away_score = int(away_pts) if away_pts.isdigit() else None
            if home_score is None or away_score is None:
                continue
        except (ValueError, IndexError):
            continue

        matches.append({
            "date": date_iso,
            "league": league,
            "team_category": team_cat,
            "home_team": home_name,
            "home_score": home_score,
            "away_team": away_name,
            "away_score": away_score,
        })
    return matches


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--year", type=int, required=True)
    parser.add_argument("--list", default="Results2024List", help="Which list endpoint to use (Results2024List | ClubFixtures2024List)")
    args = parser.parse_args()

    # Split year into 3 ranges to avoid server truncation
    ranges = [
        (f"01/01/{args.year}", f"31/05/{args.year}"),
        (f"01/06/{args.year}", f"31/08/{args.year}"),
        (f"01/09/{args.year}", f"31/12/{args.year}"),
    ]

    all_matches = []
    seen = set()
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()

        for from_date, to_date in ranges:
            for comp in COMPETITIONS:
                print(f"Scraping {comp} | {from_date} → {to_date}")
                try:
                    batch = scrape_range(page, args.list, from_date, to_date, comp)
                except Exception as e:
                    print(f"  ! error: {e}")
                    continue
                new = 0
                for m in batch:
                    key = (m["date"], m["league"], m["team_category"], m["home_team"], m["away_team"])
                    if key in seen:
                        continue
                    seen.add(key)
                    all_matches.append(m)
                    new += 1
                print(f"  +{new} (total {len(all_matches)})")

        browser.close()

    out = Path(__file__).parent.parent / "storage" / "app" / f"wpclubrugby_{args.year}.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    with open(out, "w") as f:
        json.dump({
            "year": args.year,
            "source": "fixtures.wpclubrugby.co.za",
            "scraped_at": datetime.utcnow().isoformat() + "Z",
            "matches": all_matches,
        }, f, indent=2)
    print(f"\nOutput: {out} ({len(all_matches)} matches)")


if __name__ == "__main__":
    main()
