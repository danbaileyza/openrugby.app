#!/usr/bin/env python3
"""
Scrape Super Rugby Pacific match details (teams, score, date, referee, venue)
from super.rugby using Playwright.

The fixtures page has "Match Page" links to match centre URLs like:
    /superrugby/match-centre/?competition=205&season=2026&match=949208

The match centre has a "Referee{Name}" pattern we can extract.

Requires:
    python3.11 -m pip install playwright
    python3.11 -m playwright install chromium

Usage:
    python3.11 scripts/super_rugby_scraper.py --season 2026 --round 9
    python3.11 scripts/super_rugby_scraper.py --season 2026 --all-rounds
    python3.11 scripts/super_rugby_scraper.py --all-seasons

Output: storage/app/super_rugby_match_officials.json
"""

import argparse
import json
import re
import sys
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

# Force unbuffered output so progress shows through pipes
sys.stdout.reconfigure(line_buffering=True)


BASE = "https://super.rugby/superrugby/fixtures/"


def collect_match_urls(page, season, round_num):
    url = f"{BASE}?round={round_num}"
    if season and season != 2026:  # current season, no param needed
        url = f"{BASE}?season={season}&round={round_num}"

    print(f"  Loading round {round_num}...", flush=True)
    # Use 'load' instead of 'networkidle' — super.rugby has background analytics that prevent idle
    page.goto(url, wait_until="load", timeout=30000)
    time.sleep(2)

    match_links = page.locator("a:has-text('Match Page')").all()
    urls = []
    for link in match_links:
        href = link.get_attribute("href")
        if not href:
            continue
        if href.startswith("/"):
            href = "https://super.rugby" + href

        # Filter to current season only (archive pages can leak in)
        if season and f"season={season}" not in href and f"season={season-1}" not in href:
            continue
        urls.append(href)

    # Deduplicate
    urls = list(dict.fromkeys(urls))
    print(f"    Found {len(urls)} match URLs")
    return urls


def parse_match_page(page, url):
    page.goto(url, wait_until="load", timeout=30000)
    time.sleep(2)
    text = page.inner_text("body")

    # Extract actual round from the page (format: "Round 9" or "ROUND 9")
    round_match = re.search(r"ROUND\s+(\d+)", text, re.IGNORECASE)
    actual_round = int(round_match.group(1)) if round_match else None

    # Extract date — format like "Friday 11 April 2026" or "Sat, 12 April"
    date_match = re.search(r"(Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?:day|\w*)?,?\s+(\d{1,2})\s+(\w+)\s+(\d{4})", text, re.IGNORECASE)
    date_iso = None
    if date_match:
        day = int(date_match.group(2))
        month_name = date_match.group(3).lower()
        year = int(date_match.group(4))
        months = {'january':1,'february':2,'march':3,'april':4,'may':5,'june':6,'july':7,'august':8,'september':9,'october':10,'november':11,'december':12}
        month = months.get(month_name)
        if month:
            date_iso = f"{year}-{month:02d}-{day:02d}"

    # Extract referee — pattern observed: "VenueXYZRefereeName\n" or "Referee\nName"
    referee = None
    ref_match = re.search(r"Referee([A-Z][a-zA-ZÀ-ÿ\s\-'.]+?)(?:\n|STATS|TIMELINE|Assistant|TMO|$)", text)
    if ref_match:
        name = ref_match.group(1).strip()
        # Filter out junk
        if 3 <= len(name) <= 50 and " " in name:
            referee = name

    # Extract venue
    venue = None
    venue_match = re.search(r"Venue([A-Z][^,\n]+?),\s*([^R\n]+?)(?=Referee|\n)", text)
    if venue_match:
        venue = {
            "name": venue_match.group(1).strip(),
            "city": venue_match.group(2).strip(),
        }

    # Extract teams and score — look near top of text
    teams_score = None
    # Pattern: "HomeTeam\n\tScore - Score\t\n\nAwayTeam"  ← not stable
    # Try extracting from URL match parameter + the visible "HT 8-10" pattern
    # The visible format tends to be: TEAM_NAME <SCORE>\n<SCORE> TEAM_NAME (vertical)

    # More reliable: parse the match ID from URL and teams from page title
    title = page.title()
    ms = re.search(r"match=(\d+)", url)
    match_id = ms.group(1) if ms else None

    # Attempt HT score extraction
    ht_match = re.search(r"HT\s+(\d+)-(\d+)", text)
    ht_score = {"home": int(ht_match.group(1)), "away": int(ht_match.group(2))} if ht_match else None

    # Full-time score comes before HT in the layout: "X-Y\n\n\nHT X-Y"
    score_match = re.search(r"(\d+)\s*[-–]\s*(\d+)\s*\n.*?HT", text, re.DOTALL)
    full_score = {"home": int(score_match.group(1)), "away": int(score_match.group(2))} if score_match else None

    # Teams extraction — the match centre shows:
    #   \tHome Team\t\n<score>\n\t-\t\n<score>\n\tAway Team\t\n\nHT X-Y
    # Use the last 20 lines before HT to avoid picking up other round listings
    teams = None
    ht_pos = text.find("HT ")
    if ht_pos > 0:
        pre_ht = text[:ht_pos]
        lines = [l.strip() for l in pre_ht.split("\n")]
        # Walk backwards finding pattern: TEAM / SCORE / - / SCORE / TEAM
        for i in range(len(lines) - 1, 4, -1):
            try:
                if (lines[i].strip() == ""  # HT has blank line before
                    or lines[i] == ""):
                    continue
                # Try to match: lines[i-4]=Team1, [i-3]=score, [i-2]=-, [i-1]=score, [i]=Team2
                if (lines[i - 2] in ("-", "–")
                    and lines[i - 3].isdigit()
                    and lines[i - 1].isdigit()
                    and lines[i - 4]
                    and lines[i]):
                    teams = {
                        "home": lines[i - 4].strip(),
                        "away": lines[i].strip(),
                    }
                    if not full_score:
                        full_score = {
                            "home": int(lines[i - 3]),
                            "away": int(lines[i - 1]),
                        }
                    break
            except (IndexError, ValueError):
                continue

    return {
        "url": url,
        "match_id": match_id,
        "referee": referee,
        "venue": venue,
        "ht_score": ht_score,
        "full_score": full_score,
        "teams": teams,
        "title": title,
        "actual_round": actual_round,
        "date": date_iso,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--season", type=int, default=2026)
    parser.add_argument("--round", type=int)
    parser.add_argument("--all-rounds", action="store_true")
    parser.add_argument("--max-rounds", type=int, default=18)
    args = parser.parse_args()

    rounds = [args.round] if args.round else (
        list(range(1, args.max_rounds + 1)) if args.all_rounds else [10]
    )

    output_path = Path(__file__).parent.parent / "storage" / "app" / "super_rugby_match_officials.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)

    # Load existing data for incremental updates
    existing = {}
    if output_path.exists():
        try:
            for entry in json.loads(output_path.read_text()):
                if entry.get("match_id"):
                    existing[entry["match_id"]] = entry
        except Exception:
            pass

    all_matches = dict(existing)

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        ctx = browser.new_context(
            user_agent="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
            viewport={"width": 1280, "height": 900},
        )
        page = ctx.new_page()

        # Collect ALL match URLs across rounds first, then visit each match page once
        all_urls = set()
        for round_num in rounds:
            try:
                urls = collect_match_urls(page, args.season, round_num)
                all_urls.update(urls)
            except Exception as e:
                print(f"  Round {round_num}: ERROR {e}", flush=True)

        print(f"\n  Total unique match URLs to visit: {len(all_urls)}\n", flush=True)

        for i, url in enumerate(sorted(all_urls), 1):
            # Skip if we already have this match's referee from a previous run
            ms = re.search(r"match=(\d+)", url)
            match_id = ms.group(1) if ms else None
            if match_id and match_id in existing and existing[match_id].get("referee"):
                all_matches[match_id] = existing[match_id]
                continue

            try:
                data = parse_match_page(page, url)
                data["season"] = args.season
                if data.get("match_id"):
                    all_matches[data["match_id"]] = data
                ref = data.get("referee") or "—"
                r = data.get("actual_round") or "?"
                print(f"    [{i}/{len(all_urls)}] R{r}: Ref: {ref} | {data.get('date') or '?'} | {url.split('match=')[-1]}", flush=True)
            except Exception as e:
                print(f"    ERROR on {url}: {e}", flush=True)

            # Save incrementally every 10 matches
            if i % 10 == 0:
                with open(output_path, "w") as f:
                    json.dump(list(all_matches.values()), f, indent=2)

        # Final save
        with open(output_path, "w") as f:
            json.dump(list(all_matches.values()), f, indent=2)

        browser.close()

    print(f"\n{'='*50}")
    print(f"Total matches scraped: {len(all_matches)}")
    refs_found = sum(1 for m in all_matches.values() if m.get("referee"))
    print(f"With referee: {refs_found}")
    print(f"Output: {output_path}")


if __name__ == "__main__":
    main()
