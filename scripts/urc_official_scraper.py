#!/usr/bin/env python3
"""
Scrape URC matches with referees from the official URC stats site.

Each season has rounds with match URLs like:
  https://stats.unitedrugby.com/match-centre/202501/united-rugby-championship/<slug>/<matchId>

The match page has tries, scores, referee in the visible text.

Usage:
    python3.11 scripts/urc_official_scraper.py --season 2024-25
    python3.11 scripts/urc_official_scraper.py --all-seasons

Output: storage/app/urc_official_match_officials.json
"""

import argparse
import json
import re
import sys
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

sys.stdout.reconfigure(line_buffering=True)


def accept_cookies(page):
    for sel in ['button:has-text("Accept")', 'button:has-text("Accept All")', '#didomi-notice-agree-button']:
        try:
            page.locator(sel).first.click(timeout=2000)
            time.sleep(1)
            return
        except Exception:
            pass


def collect_match_urls_for_round(page, season, round_num):
    # URC uses URLs like /match-centre/2024-25/round/1
    url = f"https://stats.unitedrugby.com/match-centre/{season}/round/{round_num}"
    try:
        page.goto(url, wait_until="load", timeout=30000)
    except Exception as e:
        print(f"    Round {round_num}: nav error {e}", flush=True)
        return []
    time.sleep(2)
    html = page.content()
    # Match URLs use pattern /match-centre/YYYYNN/united-rugby-championship/.../matchId
    urls = set(re.findall(r"/match-centre/\d{6}/united-rugby-championship/[\w\-]+/\d+", html))
    return ["https://stats.unitedrugby.com" + u for u in urls]


def parse_match(page, url):
    try:
        page.goto(url, wait_until="load", timeout=30000)
    except Exception:
        return None
    time.sleep(2)
    text = page.inner_text("body")

    # Extract teams + score from header pattern:
    # "FULL TIME\n<date> | <time>\n<score>\n<HOME>\nV\n<AWAY>\n<score>\n<venue>"
    teams = None
    full_score = None

    # Match: 24\nOSPREYS\nV\nFIDELITY\nSECUREDRIVE\nLIONS\n24
    m = re.search(
        r"FULL TIME[\s\S]{0,200}?(\d+)\s*\n([A-Z][A-Z\s]+?)\nV\n([A-Z][A-Z\s]+?)\n(\d+)",
        text,
    )
    if m:
        full_score = {"home": int(m.group(1)), "away": int(m.group(4))}
        teams = {
            "home": m.group(2).strip().replace("\n", " ").title(),
            "away": m.group(3).strip().replace("\n", " ").title(),
        }

    # Try counts — pattern: "<n>\nTRIES\n<n>"
    try_match = re.search(r"(\d+)\s*\n\s*TRIES\s*\n\s*(\d+)", text)
    home_tries = int(try_match.group(1)) if try_match else None
    away_tries = int(try_match.group(2)) if try_match else None

    # Date
    date_iso = None
    date_match = re.search(r"(Mon|Tue|Wed|Thu|Fri|Sat|Sun),\s+(\d{1,2})\s+(\w+)\s+(\d{4})", text)
    if date_match:
        d = int(date_match.group(2))
        month_name = date_match.group(3).lower()
        y = int(date_match.group(4))
        months = {"jan": 1, "january": 1, "feb": 2, "february": 2, "mar": 3, "march": 3,
                  "apr": 4, "april": 4, "may": 5, "jun": 6, "june": 6, "jul": 7, "july": 7,
                  "aug": 8, "august": 8, "sep": 9, "september": 9, "oct": 10, "october": 10,
                  "nov": 11, "november": 11, "dec": 12, "december": 12}
        month = months.get(month_name)
        if month:
            date_iso = f"{y}-{month:02d}-{d:02d}"

    # Round
    round_num = None
    rd_match = re.search(r"Round\s+(\d+)", text)
    if rd_match:
        round_num = int(rd_match.group(1))

    # Referee — look for the "Officials" section. URC shows referee in the lineups/stats sub-pages
    # But also in the match centre page header sometimes
    referee = None
    ref_match = re.search(r"(?:Referee|Match Official)[\s:]+([A-Z][a-zA-ZÀ-ÿ\s\-']+?)(?:\n|Touch|TMO|Television|Assistant)", text)
    if ref_match:
        referee = ref_match.group(1).strip()

    # Match ID from URL
    id_match = re.search(r"/(\d+)$", url)
    match_id = id_match.group(1) if id_match else None

    return {
        "match_id": match_id,
        "url": url,
        "teams": teams,
        "full_score": full_score,
        "home_tries": home_tries,
        "away_tries": away_tries,
        "referee": referee,
        "date": date_iso,
        "round": round_num,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--season", default="2024-25", help="e.g. 2024-25")
    parser.add_argument("--all-seasons", action="store_true")
    parser.add_argument("--max-rounds", type=int, default=22)
    args = parser.parse_args()

    seasons = ["2024-25", "2023-24", "2022-23", "2021-22"] if args.all_seasons else [args.season]

    output_path = Path(__file__).parent.parent / "storage" / "app" / "urc_official_match_officials.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)

    existing = {}
    if output_path.exists():
        try:
            for entry in json.loads(output_path.read_text()):
                if entry.get("url"):
                    existing[entry["url"]] = entry
        except Exception:
            pass

    all_matches = dict(existing)

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        ctx = browser.new_context(
            user_agent="Mozilla/5.0 Chrome/120.0.0.0 Safari/537.36",
            viewport={"width": 1280, "height": 900},
        )
        page = ctx.new_page()
        page.goto("https://stats.unitedrugby.com/match-centre", wait_until="load", timeout=30000)
        time.sleep(3)
        accept_cookies(page)

        for season in seasons:
            print(f"\n=== Season {season} ===", flush=True)
            urls_for_season = set()
            for r in range(1, args.max_rounds + 1):
                urls = collect_match_urls_for_round(page, season, r)
                if urls:
                    urls_for_season.update(urls)
                    print(f"  Round {r}: {len(urls)} matches", flush=True)

            print(f"\n  Total URLs for {season}: {len(urls_for_season)}\n", flush=True)

            for i, url in enumerate(sorted(urls_for_season), 1):
                if url in existing and existing[url].get("referee"):
                    all_matches[url] = existing[url]
                    continue

                try:
                    data = parse_match(page, url)
                    if not data:
                        continue
                    data["season"] = season
                    all_matches[url] = data
                    teams = data.get("teams") or {}
                    print(f"    [{i}/{len(urls_for_season)}] R{data.get('round')}: {teams.get('home','?')} vs {teams.get('away','?')} | Ref: {data.get('referee') or '—'}", flush=True)
                except Exception as e:
                    print(f"    ERROR on {url}: {e}", flush=True)

                if i % 10 == 0:
                    with open(output_path, "w") as f:
                        json.dump(list(all_matches.values()), f, indent=2)

            with open(output_path, "w") as f:
                json.dump(list(all_matches.values()), f, indent=2)

        browser.close()

    refs = sum(1 for m in all_matches.values() if m.get("referee"))
    print(f"\n{'='*50}")
    print(f"Total: {len(all_matches)} | With referee: {refs}")
    print(f"Output: {output_path}")


if __name__ == "__main__":
    main()
