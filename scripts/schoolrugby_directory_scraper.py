#!/usr/bin/env python3
"""Scrape schoolrugby.co.za's school directory by iterating tournament pages.

Each tournament page lists participating schools as <a> links to school.aspx?s=ID.
We iterate tournament IDs 1..N until we've seen many consecutive 404s, building
a {id: name} map so the importer can backfill external_ids on schools we already
have by name but never linked.

Usage:
    python3 scripts/schoolrugby_directory_scraper.py
    python3 scripts/schoolrugby_directory_scraper.py --max 500

Output: storage/app/schoolrugby_directory.json
"""

import argparse
import json
import re
import sys
import time
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.error import HTTPError

sys.stdout.reconfigure(line_buffering=True)

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
}


def fetch(url, retries=2):
    for attempt in range(retries):
        try:
            req = Request(url, headers=HEADERS)
            with urlopen(req, timeout=15) as resp:
                return resp.read().decode("utf-8", errors="replace")
        except HTTPError as e:
            if e.code == 404:
                return None
            time.sleep(2)
        except Exception:
            time.sleep(2)
    return None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--max", type=int, default=300, help="Max tournament id to try")
    parser.add_argument("--stop-after-404", type=int, default=40, help="Stop after this many consecutive 404s")
    args = parser.parse_args()

    collected = {}
    consecutive_404 = 0

    for tid in range(1, args.max + 1):
        html = fetch(f"http://schoolrugby.co.za/tournament.aspx?t={tid}")
        if html is None:
            consecutive_404 += 1
            if consecutive_404 >= args.stop_after_404:
                print(f"Stopping after {consecutive_404} consecutive 404s.")
                break
            continue

        consecutive_404 = 0
        links = re.findall(r"school\.aspx\?s=(\d+)[^>]*>([^<]+)<", html)
        new_count = 0
        for sid, name in links:
            if sid not in collected:
                collected[sid] = name.strip()
                new_count += 1

        if tid % 25 == 0 or new_count > 0:
            print(f"  t={tid}: +{new_count} new (total {len(collected)})")

        time.sleep(0.15)

    out = Path(__file__).parent.parent / "storage" / "app" / "schoolrugby_directory.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    with open(out, "w") as f:
        json.dump({"discovered_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                   "schools": collected}, f, indent=2, sort_keys=True)
    print(f"\nOutput: {out} ({len(collected)} schools)")


if __name__ == "__main__":
    main()
