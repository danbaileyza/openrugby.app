#!/usr/bin/env python3
"""Scrape Champions Cup pool assignments from rugby365's logs page per season.

Usage:
    python3.11 scripts/scrape_cc_pools.py --year 2026   # rugby365 season id
"""

import argparse
import json
import re
from pathlib import Path

from playwright.sync_api import sync_playwright


def scrape(year):
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()
        url = f"https://rugby365.com/tournaments/european-cup/logs/?season={year}"
        page.goto(url, wait_until="networkidle", timeout=30000)
        page.wait_for_timeout(5000)

        tables = page.query_selector_all("table")
        if not tables:
            return {}

        rows = tables[0].query_selector_all("tr")
        current = None
        pools = {}
        for r in rows:
            cells = [c.inner_text().strip() for c in r.query_selector_all("td,th")]
            if not cells:
                continue
            m = re.match(r"Pool\s+(\d+)", cells[0])
            if m and len(cells) == 1:
                current = int(m.group(1))
                pools.setdefault(current, [])
                continue
            if current and len(cells) >= 3 and cells[1].isdigit():
                pools[current].append({"position": int(cells[1]), "team": cells[2]})
        browser.close()
    return pools


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--year", type=int, required=True)
    args = parser.parse_args()

    pools = scrape(args.year)
    total = sum(len(v) for v in pools.values())
    print(f"Year {args.year}: {len(pools)} pools, {total} teams")

    out = Path(__file__).parent.parent / "storage" / "app" / f"cc_pools_{args.year}.json"
    with open(out, "w") as f:
        json.dump(pools, f, indent=2)
    print(f"Output: {out}")


if __name__ == "__main__":
    main()
