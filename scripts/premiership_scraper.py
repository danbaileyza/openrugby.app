#!/usr/bin/env python3
"""
Scrape Premiership Rugby fixtures, results and referees from premiershiprugby.com.

Pages:
  /fixtures-results?competition=gallagher-prem&round=N — list of match URLs per round
  /match-centre/{matchId}/lineups — referee data embedded in JSON

Usage:
    python3.11 scripts/premiership_scraper.py --all-rounds
    python3.11 scripts/premiership_scraper.py --round 12

Output: storage/app/premiership_match_officials.json
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
    for sel in ['#onetrust-accept-btn-handler', 'button:has-text("Accept")', 'button:has-text("Agree")']:
        try:
            page.locator(sel).first.click(timeout=2000)
            time.sleep(1)
            return
        except Exception:
            pass


def collect_match_ids(page, round_num):
    url = f"https://www.premiershiprugby.com/fixtures-results?competition=gallagher-prem&round={round_num}"
    page.goto(url, wait_until="load", timeout=30000)
    time.sleep(3)
    html = page.content()
    ids = set(re.findall(r"/match-centre/(\d+)", html))
    return sorted(ids)


def parse_match(page, match_id):
    url = f"https://www.premiershiprugby.com/match-centre/{match_id}/lineups"
    page.goto(url, wait_until="load", timeout=30000)
    time.sleep(2)
    html = page.content()

    # Extract match details from the page text
    text = page.inner_text("body")

    # Teams + score: text format includes "TEAM\n<score>\nFT\n<score>\nHT: X - Y\n...\nTEAM"
    teams = None
    full_score = None
    ht_score = None

    # Look for "FT\n<score>" pattern with surrounding teams
    score_match = re.search(
        r"([A-Z][A-Z\s]+?)\s*\n\s*(\d+)\s*\n\s*FT\s*\n\s*(\d+)\s*\n\s*HT:\s*(\d+)\s*-\s*(\d+)\s*\n[^\n]*\n([A-Z][A-Z\s]+?)\s*\n",
        text,
    )
    if score_match:
        teams = {"home": score_match.group(1).strip().title(), "away": score_match.group(6).strip().title()}
        full_score = {"home": int(score_match.group(2)), "away": int(score_match.group(3))}
        ht_score = {"home": int(score_match.group(4)), "away": int(score_match.group(5))}

    # Extract referee from the JSON-encoded HTML
    referee = None
    ref_match = re.search(r'"([A-Z][a-zA-ZÀ-ÿ\s\-\']+?)","referee"', html)
    if ref_match:
        referee = ref_match.group(1).strip()

    # Extract round from page (round selector)
    round_num = None
    rd_match = re.search(r"ROUND\s+(\d+)", text)
    if rd_match:
        round_num = int(rd_match.group(1))

    # Extract date
    date_iso = None
    # Format like "Sun, 29 Mar 2026"
    date_match = re.search(r"(\d{1,2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\w*\s+(\d{4})", text)
    if date_match:
        d = int(date_match.group(1))
        m_name = date_match.group(2).lower()
        y = int(date_match.group(3))
        months = {"jan": 1, "feb": 2, "mar": 3, "apr": 4, "may": 5, "jun": 6,
                  "jul": 7, "aug": 8, "sep": 9, "oct": 10, "nov": 11, "dec": 12}
        date_iso = f"{y}-{months[m_name]:02d}-{d:02d}"

    return {
        "match_id": match_id,
        "url": url,
        "referee": referee,
        "teams": teams,
        "full_score": full_score,
        "ht_score": ht_score,
        "round": round_num,
        "date": date_iso,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--round", type=int)
    parser.add_argument("--all-rounds", action="store_true")
    parser.add_argument("--max-rounds", type=int, default=18)
    args = parser.parse_args()

    rounds = [args.round] if args.round else (
        list(range(1, args.max_rounds + 1)) if args.all_rounds else [12]
    )

    output_path = Path(__file__).parent.parent / "storage" / "app" / "premiership_match_officials.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)

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
            user_agent="Mozilla/5.0 Chrome/120.0.0.0 Safari/537.36",
            viewport={"width": 1280, "height": 900},
        )
        page = ctx.new_page()

        # Accept cookies once
        page.goto("https://www.premiershiprugby.com/fixtures-results?competition=gallagher-prem", wait_until="load", timeout=30000)
        time.sleep(3)
        accept_cookies(page)

        all_ids = set()
        for r in rounds:
            try:
                ids = collect_match_ids(page, r)
                print(f"  Round {r}: {len(ids)} match IDs", flush=True)
                all_ids.update(ids)
            except Exception as e:
                print(f"  Round {r}: ERROR {e}", flush=True)

        print(f"\n  Total unique match IDs: {len(all_ids)}\n", flush=True)

        for i, mid in enumerate(sorted(all_ids), 1):
            if mid in existing and existing[mid].get("referee"):
                all_matches[mid] = existing[mid]
                continue

            try:
                data = parse_match(page, mid)
                all_matches[mid] = data
                ref = data.get("referee") or "—"
                teams = data.get("teams") or {}
                home = teams.get("home", "?")
                away = teams.get("away", "?")
                print(f"    [{i}/{len(all_ids)}] R{data.get('round')}: {home} vs {away} | Ref: {ref}", flush=True)
            except Exception as e:
                print(f"    ERROR on match {mid}: {e}", flush=True)

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
