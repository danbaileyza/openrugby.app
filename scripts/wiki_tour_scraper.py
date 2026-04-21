#!/usr/bin/env python3
"""
Scrape rugby tour matches from Wikipedia tour pages.

Handles one-off tour competitions like:
 - British & Irish Lions tours (to SA, NZ, Australia)
 - New Zealand tours (the 'Greatest Rivalry' vs SA)
 - Any tour with per-match {{Rugbybox}} entries

Each match entry has date, home (often a provincial team), away (the tour side),
venue, referee, and score.

Usage:
    python3 scripts/wiki_tour_scraper.py --page 2025_British_%26_Irish_Lions_tour_to_Australia --competition lions_tour --season 2025
    python3 scripts/wiki_tour_scraper.py --page 2026_New_Zealand_rugby_union_tour_of_South_Africa --competition greatest_rivalry --season 2026

Output: storage/app/wiki_tour_{competition}_{season}.json
"""

import argparse
import json
import re
import sys
import time
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.parse import quote

sys.stdout.reconfigure(line_buffering=True)


def fetch_wikitext(page_title):
    url = f"https://en.wikipedia.org/w/api.php?action=parse&page={quote(page_title)}&prop=wikitext&format=json"
    req = Request(url, headers={"User-Agent": "Mozilla/5.0"})
    with urlopen(req, timeout=20) as resp:
        return json.loads(resp.read().decode("utf-8")).get("parse", {}).get("wikitext", {}).get("*", "")


def clean(text):
    """Strip common Wikipedia markup."""
    if not text: return ""
    text = text.replace("&ndash;", "–").replace("&mdash;", "—").replace("&amp;", "&")
    text = re.sub(r"<[^>]+>", "", text)  # HTML
    text = re.sub(r"\{\{flag(?:icon|deco|country)?\|[^}]+\}\}", "", text, flags=re.IGNORECASE)  # flags
    text = re.sub(r"<ref[^>]*>.*?</ref>", "", text, flags=re.DOTALL)
    text = re.sub(r"<ref[^/]*/>", "", text)
    # [[Link|Display]] → Display; [[Link]] → Link
    text = re.sub(r"\[\[([^\]|]+?)\|([^\]]+?)\]\]", r"\2", text)
    text = re.sub(r"\[\[([^\]]+?)\]\]", r"\1", text)
    text = re.sub(r"\{\{[Rr]u\|([A-Za-z]+)\}\}", r"\1", text)
    text = re.sub(r"\{\{[Rr]ut\|([^}]+)\}\}", r"\1", text)
    text = re.sub(r"'{2,}", "", text)
    return text.strip()


def parse_date(text):
    text = clean(text)
    # ISO format: "19:00 2010-07-09" or "2010-07-09"
    iso = re.search(r"(\d{4})-(\d{2})-(\d{2})", text)
    if iso:
        return f"{iso.group(1)}-{iso.group(2)}-{iso.group(3)}"
    months = {"january":1,"february":2,"march":3,"april":4,"may":5,"june":6,
              "july":7,"august":8,"september":9,"october":10,"november":11,"december":12}
    m = re.search(r"(\d{1,2})\s+(\w+)\s+(\d{4})", text)
    if m:
        d = int(m.group(1))
        mth = months.get(m.group(2).lower())
        y = int(m.group(3))
        if mth:
            return f"{y}-{mth:02d}-{d:02d}"
    return None


def extract_events(block, field_prefix):
    """Parse try/conversion/penalty/drop events from e.g. 'try1' field.
    Format: "[[Player Name|Surname]] 12' c<br />[[Other|Surname]] 34' c"
    Returns list of dicts.
    """
    events = []
    for ev_type, key in [("try", "try"), ("conversion", "con"), ("penalty", "pen"), ("drop_goal", "drop")]:
        def field(name):
            m = re.search(rf"\|\s*{name}\s*=\s*([^\n]*)", block, re.IGNORECASE)
            return m.group(1).strip() if m else ""

        raw = field(f"{key}{field_prefix}")
        if not raw: continue

        # Player entries separated by <br /> or similar
        entries = re.split(r"<br\s*/?>|\n", raw)
        for entry in entries:
            entry = entry.strip()
            if not entry: continue
            # Extract player name from [[Link|Display]] or [[Link]]
            link = re.search(r"\[\[([^\]|]+?)(?:\|([^\]]+))?\]\]", entry)
            if not link: continue
            player = (link.group(2) or link.group(1)).strip()
            player = re.sub(r"\s*\([^)]+\)\s*$", "", player)

            # Extract minute: " 12'" or "(12')"
            min_match = re.search(r"(\d{1,3})'", entry)
            minute = int(min_match.group(1)) if min_match else None

            events.append({
                "type": ev_type,
                "player": player,
                "minute": minute,
            })
    return events


def extract_rugbybox_blocks(wikitext):
    """Extract {{Rugbybox...}} blocks using balanced brace matching."""
    blocks = []
    for m in re.finditer(r"\{\{[Rr]ugbybox", wikitext):
        start = m.start()
        depth, i = 0, start
        while i < len(wikitext):
            if wikitext[i:i+2] == '{{':
                depth += 1; i += 2
            elif wikitext[i:i+2] == '}}':
                depth -= 1; i += 2
                if depth == 0:
                    blocks.append(wikitext[start:i])
                    break
            else:
                i += 1
    return blocks


def extract_matches(wikitext):
    blocks = extract_rugbybox_blocks(wikitext)
    matches = []
    for block in blocks:
        def field(name):
            m = re.search(rf"\|\s*{name}\s*=\s*([^\n]*)", block, re.IGNORECASE)
            return m.group(1).strip() if m else ""

        date_str = field("date")
        # Home/away may be named home/away or team1/team2
        home_raw = field("home") or field("team1")
        away_raw = field("away") or field("team2")
        score_raw = field("score")
        venue_raw = field("stadium") or field("venue")
        referee_raw = field("referee")
        attendance_raw = field("attendance")

        date_iso = parse_date(date_str)
        home = clean(home_raw)
        away = clean(away_raw)
        venue = clean(venue_raw)

        home_score = away_score = None
        score_text = clean(score_raw)
        if score_text:
            m = re.match(r"(\d+)\s*[-–]\s*(\d+)", score_text)
            if m:
                home_score = int(m.group(1))
                away_score = int(m.group(2))

        referee = None
        if referee_raw and "}}" not in referee_raw.strip()[:3]:
            ref_link = re.search(r"\[\[([^\]|]+?)(?:\|([^\]]+))?\]\]", referee_raw)
            if ref_link:
                referee = (ref_link.group(2) or ref_link.group(1)).strip()
                referee = re.sub(r"\s*\([^)]+\)\s*$", "", referee)
            elif referee_raw.strip():
                # Plain text referee name (no wikilink)
                plain = re.sub(r"<[^>]+>", "", referee_raw).strip()
                plain = re.sub(r"\s*\([^)]+\)\s*$", "", plain)
                if re.match(r"^[A-Z][a-zà-ÿ'\-]+\s+[A-Z][a-zà-ÿ'\-\s]+$", plain):
                    referee = plain

        if not (date_iso and home and away):
            continue

        # Extract events per side
        home_events = extract_events(block, "1")
        away_events = extract_events(block, "2")

        # Attendance
        att = None
        if attendance_raw:
            am = re.search(r"([\d,]+)", attendance_raw)
            if am:
                try:
                    att = int(am.group(1).replace(",", ""))
                except: pass

        matches.append({
            "date": date_iso,
            "home": home,
            "away": away,
            "home_score": home_score,
            "away_score": away_score,
            "venue": venue,
            "referee": referee,
            "attendance": att,
            "home_events": home_events,
            "away_events": away_events,
        })

    return matches


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--page", required=True)
    parser.add_argument("--competition", required=True)
    parser.add_argument("--season", required=True)
    args = parser.parse_args()

    print(f"Fetching {args.page}...")
    wt = fetch_wikitext(args.page)
    print(f"  {len(wt)} chars")

    matches = extract_matches(wt)
    print(f"  Extracted {len(matches)} matches")
    for m in matches[:5]:
        score = f"{m.get('home_score','-')}-{m.get('away_score','-')}"
        print(f"    {m['date']} | {m['home']} {score} {m['away']} | ref: {m.get('referee') or '—'}")

    out = {
        "page": args.page,
        "competition": args.competition,
        "season": args.season,
        "matches": matches,
    }

    output_path = Path(__file__).parent.parent / "storage" / "app" / f"wiki_tour_{args.competition}_{args.season}.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with open(output_path, "w") as f:
        json.dump(out, f, indent=2)
    print(f"\nOutput: {output_path}")


if __name__ == "__main__":
    main()
