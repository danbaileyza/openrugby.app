#!/usr/bin/env python3
"""
Scrape Top 14 match officials from top14.lnr.fr using Playwright.

Pattern:
  - /calendrier-resultats lists all match days with links like:
      /feuille-de-match/{season}/j{round}/{matchId}-{home}-{away}
  - Match page has: "ARBITRE CENTRAL\n<Name>" pattern

Usage:
    python3.11 scripts/top14_scraper.py --season 2025-2026 --round 1
    python3.11 scripts/top14_scraper.py --season 2025-2026 --all-rounds

Output: storage/app/top14_match_officials.json
"""

import argparse
import json
import re
import sys
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

sys.stdout.reconfigure(line_buffering=True)


def collect_match_urls(page, season, round_num):
    """Navigate to a specific round and collect match URLs."""
    url = f"https://top14.lnr.fr/calendrier-resultats?saison={season}&journee=j{round_num}"
    print(f"  Loading season={season} round={round_num}...", flush=True)
    page.goto(url, wait_until="load", timeout=30000)
    time.sleep(3)

    # Collect all feuille-de-match links
    links = page.locator("a").all()
    urls = set()
    for link in links:
        href = link.get_attribute("href") or ""
        if "/feuille-de-match" in href and f"/j{round_num}/" in href and season in href:
            full = href if href.startswith("http") else f"https://top14.lnr.fr{href}"
            urls.add(full)

    print(f"    Found {len(urls)} match URLs", flush=True)
    return list(urls)


def parse_match_page(page, url):
    page.goto(url, wait_until="load", timeout=30000)
    time.sleep(2)
    text = page.inner_text("body")

    # Extract referee — text is:
    # "OFFICIELS DE MATCH\n\n<Name>\n\nArbitre Central\n\n..."
    # Grab the name between two blank lines, just before "Arbitre Central"
    referee = None
    m = re.search(r"\n\s*\n\s*([^\n]+?)\s*\n\s*\n\s*Arbitre Central", text)
    if m:
        name = m.group(1).strip()
        if len(name.split()) >= 2 and len(name) < 50 and name.upper() != "OFFICIELS DE MATCH":
            referee = name

    # Date from URL + text
    date_match = re.search(r"(\d{1,2})/(\d{1,2})/(\d{4})", text)
    date_iso = None
    if date_match:
        d, m_, y = date_match.groups()
        date_iso = f"{y}-{int(m_):02d}-{int(d):02d}"

    # Teams from URL path
    url_match = re.search(r"/j(\d+)/\d+-(.+?)(?:-|$)", url)
    round_num = int(url_match.group(1)) if url_match else None

    # Extract home/away teams from URL - format is "home-away" but home name can contain hyphens
    # Better to get from page text — teams are in capitals at top
    teams = None
    # Pattern: "HOME" + " - " + "AWAY" near top
    teams_match = re.search(r"([A-ZÀ-Ÿ\-\s]+?)\s*-\s*([A-ZÀ-Ÿ\-\s]+?)\n", text[:500])
    if teams_match:
        teams = {
            "home": teams_match.group(1).strip().title(),
            "away": teams_match.group(2).strip().title(),
        }

    # Full score — often "HH : AA" or "HH - AA"
    score_match = re.search(r"(\d{1,3})\s*[-–:]\s*(\d{1,3})", text[:300])
    full_score = None
    if score_match:
        full_score = {"home": int(score_match.group(1)), "away": int(score_match.group(2))}

    return {
        "url": url,
        "referee": referee,
        "round": round_num,
        "date": date_iso,
        "teams": teams,
        "full_score": full_score,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--season", default="2025-2026", help="e.g. 2025-2026")
    parser.add_argument("--round", type=int)
    parser.add_argument("--all-rounds", action="store_true")
    parser.add_argument("--max-rounds", type=int, default=26)
    args = parser.parse_args()

    rounds = [args.round] if args.round else (
        list(range(1, args.max_rounds + 1)) if args.all_rounds else [1]
    )

    output_path = Path(__file__).parent.parent / "storage" / "app" / "top14_match_officials.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)

    existing = {}
    if output_path.exists():
        try:
            for entry in json.loads(output_path.read_text()):
                existing[entry.get("url")] = entry
        except Exception:
            pass

    all_matches = dict(existing)

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        ctx = browser.new_context(
            user_agent="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36",
            viewport={"width": 1280, "height": 900},
        )
        page = ctx.new_page()

        all_urls = set()
        for round_num in rounds:
            try:
                urls = collect_match_urls(page, args.season, round_num)
                all_urls.update(urls)
            except Exception as e:
                print(f"  Round {round_num}: {e}", flush=True)

        print(f"\n  Total unique URLs: {len(all_urls)}\n", flush=True)

        for i, url in enumerate(sorted(all_urls), 1):
            if url in existing and existing[url].get("referee"):
                all_matches[url] = existing[url]
                continue

            try:
                data = parse_match_page(page, url)
                data["season"] = args.season
                all_matches[url] = data
                ref = data.get("referee") or "—"
                r = data.get("round") or "?"
                teams = data.get("teams") or {}
                home = teams.get("home", "?")
                away = teams.get("away", "?")
                print(f"    [{i}/{len(all_urls)}] R{r}: {home} vs {away} | Ref: {ref}", flush=True)
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
