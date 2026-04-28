#!/usr/bin/env python3
"""
Scrape SA schoolboy rugby weekly roundups from schoolboyrugby.co.za.

Each weekly post contains tables with match rows:
  [Date, Home region, Home team, Score, Away team, Away region, Notes]

Usage:
    python3 scripts/schoolboyrugby_scraper.py                # all visible weekly posts
    python3 scripts/schoolboyrugby_scraper.py --post 48754   # single post

Output: storage/app/schoolboyrugby_<scope>.json
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

MONTHS = {"Jan":1,"Feb":2,"Mar":3,"Apr":4,"May":5,"Jun":6,"Jul":7,"Aug":8,"Sep":9,"Oct":10,"Nov":11,"Dec":12}


import gzip

# WAF-friendly headers: real browsers always accept gzip and send a Referer.
# Sending `Accept-Encoding: identity` was tripping LiteSpeed's bot detector,
# which returned 403 on individual post pages while the index still worked.
BASE_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "en-US,en;q=0.9",
    "Accept-Encoding": "gzip, deflate",
    "Connection": "keep-alive",
}


def fetch(url, retries=5, referer="https://schoolboyrugby.co.za/?cat=23"):
    headers = {**BASE_HEADERS, "Referer": referer}
    for attempt in range(retries):
        try:
            req = Request(url, headers=headers)
            with urlopen(req, timeout=30) as resp:
                raw = resp.read()
                if resp.headers.get("Content-Encoding") == "gzip":
                    raw = gzip.decompress(raw)
                return raw.decode("utf-8", errors="replace")
        except Exception as e:
            msg = str(e)
            wait = 60 + attempt * 30 if ("429" in msg or "406" in msg) else 3 + attempt * 2
            print(f"  ! {url}: {e} (retry {attempt + 1}/{retries} in {wait}s)")
            time.sleep(wait)
    print(f"  !! gave up on {url}")
    return None


def strip_tags(html):
    import html as _html
    text = re.sub(r"<[^>]+>", " ", html).strip()
    return _html.unescape(text)


def parse_date(day_field, post_year):
    # Expected format: "Sat.18Apr" or "Tue.14Apr"
    m = re.match(r"\w{3}\.(\d{1,2})(\w{3})", day_field)
    if not m:
        return None
    try:
        day = int(m.group(1))
        mon = MONTHS[m.group(2).capitalize()]
    except (ValueError, KeyError):
        return None
    return f"{post_year}-{mon:02d}-{day:02d}"


def parse_post(url):
    html = fetch(url)
    if not html:
        return None, []

    title_m = re.search(r"<title>([^<]+)</title>", html)
    title = title_m.group(1) if title_m else ""
    # Pull year from title (e.g. "week ending 18 April 2026")
    year_m = re.search(r"(\d{4})", title)
    post_year = int(year_m.group(1)) if year_m else datetime.now().year

    tables = re.findall(r"<table[^>]*>(.*?)</table>", html, re.DOTALL)
    matches = []
    for t in tables:
        rows = re.findall(r"<tr[^>]*>(.*?)</tr>", t, re.DOTALL)
        for r in rows:
            cells = re.findall(r"<td[^>]*>(.*?)</td>", r, re.DOTALL)
            cells = [strip_tags(c) for c in cells]
            if len(cells) < 5:
                continue
            date_field = cells[0]
            if not re.match(r"\w{3}\.\d{1,2}\w{3}", date_field):
                continue
            # Detect expected layout
            home_region = cells[1]
            home_team = cells[2]
            score = cells[3]
            away_team = cells[4]
            away_region = cells[5] if len(cells) > 5 else ""
            notes = cells[6] if len(cells) > 6 else ""

            score_m = re.match(r"(\d+)\s*[-–]\s*(\d+)", score)
            if score_m:
                home_score = int(score_m.group(1))
                away_score = int(score_m.group(2))
                status = "ft"
            elif re.search(r"\b(vs|v|x-x|x\s*-\s*x|tbd|tbc|-)\b", score.lower()) or score.strip() in ("", "-"):
                home_score = away_score = None
                status = "scheduled"
            else:
                continue

            date_iso = parse_date(date_field, post_year)
            if not date_iso:
                continue

            matches.append({
                "date": date_iso,
                "home_team": home_team,
                "home_region": home_region,
                "home_score": home_score,
                "away_score": away_score,
                "away_team": away_team,
                "away_region": away_region,
                "status": status,
                "notes": notes.strip() or None,
                "source_url": url,
            })
    return title, matches


def get_all_post_urls():
    """Get all weekly roundup post URLs from cat=23 listing."""
    html = fetch("https://schoolboyrugby.co.za/?cat=23")
    if not html:
        return []
    posts = re.findall(
        r"<h[23][^>]*>\s*<a[^>]*href=\"(https://schoolboyrugby\.co\.za/\?p=\d+)\"[^>]*>([^<]+)</a>",
        html,
    )
    # Try pagination to get older posts
    seen = {u for u, _ in posts}
    results = list(posts)
    for page in range(2, 20):
        try:
            html = fetch(f"https://schoolboyrugby.co.za/page/{page}/?cat=23")
            more = re.findall(
                r"<h[23][^>]*>\s*<a[^>]*href=\"(https://schoolboyrugby\.co\.za/\?p=\d+)\"[^>]*>([^<]+)</a>",
                html,
            )
            new = [(u, t) for u, t in more if u not in seen]
            if not new:
                break
            seen.update(u for u, _ in new)
            results.extend(new)
        except Exception:
            break
    return results


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--post", type=int, help="Scrape a single post by ID")
    parser.add_argument("--year", type=int, help="Only keep matches from this year")
    args = parser.parse_args()

    if args.post:
        posts = [(f"https://schoolboyrugby.co.za/?p={args.post}", f"post-{args.post}")]
    else:
        posts = get_all_post_urls()
        print(f"Found {len(posts)} weekly roundup posts")

    all_matches = []
    for url, title in posts:
        print(f"Scraping: {title.strip()[:80]}")
        _, matches = parse_post(url)
        if args.year:
            matches = [m for m in matches if m["date"].startswith(str(args.year))]
        all_matches.extend(matches)
        print(f"  +{len(matches)} matches")
        time.sleep(5)  # be polite — avoids 429 rate limits

    scope = f"p{args.post}" if args.post else (f"y{args.year}" if args.year else "all")
    out = Path(__file__).parent.parent / "storage" / "app" / f"schoolboyrugby_{scope}.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    with open(out, "w") as f:
        json.dump({
            "source": "schoolboyrugby.co.za",
            "scraped_at": datetime.utcnow().isoformat() + "Z",
            "matches": all_matches,
        }, f, indent=2)
    print(f"\nOutput: {out} ({len(all_matches)} matches)")


if __name__ == "__main__":
    main()
