#!/usr/bin/env python3
"""
Scrape match officials from Wikipedia tournament pages.

Extracts referee appointments from match infobox wikitext. Each match produces
an entry with date, teams, referee name, and the referee's nationality.

Usage:
    python3 scripts/wiki_referee_scraper.py --tournament six_nations
    python3 scripts/wiki_referee_scraper.py --tournament all

Output: storage/app/wiki_match_officials.json
Then import with: php artisan rugby:import-wiki-officials
"""

import argparse
import json
import re
import time
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.parse import quote

HEADERS = {"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)"}

TOURNAMENTS = {
    "six_nations": {
        "page_format": "{year}_Six_Nations_Championship",
        "years": list(range(2015, 2027)),
        "competition_code": "six_nations",
    },
    "rugby_championship": {
        "page_format": "{year}_Rugby_Championship",
        "years": list(range(2012, 2027)),
        "competition_code": "rugby_championship",
    },
    "world_cup": {
        "page_format": "{year}_Rugby_World_Cup",
        "years": [2011, 2015, 2019, 2023],
        "competition_code": "world_cup",
        "extra_pages": [
            "{year}_Rugby_World_Cup_Pool_A",
            "{year}_Rugby_World_Cup_Pool_B",
            "{year}_Rugby_World_Cup_Pool_C",
            "{year}_Rugby_World_Cup_Pool_D",
            "{year}_Rugby_World_Cup_knockout_stage",
        ],
    },
    "lions_tour": {
        # British & Irish Lions tours — one per cycle
        "page_format": "{year}_British_%26_Irish_Lions_tour_to_{dest}",
        "year_dest_pairs": [
            (2025, "Australia"),
            (2021, "South_Africa"),
            (2017, "New_Zealand"),
            (2013, "Australia"),
            (2009, "South_Africa"),
            (2005, "New_Zealand"),
            (2001, "Australia"),
        ],
        "competition_code": "lions_tour",
    },
    "super_rugby": {
        "page_format": "{year}_Super_Rugby_Pacific_season",
        "years": list(range(2022, 2027)),
        "competition_code": "super_rugby",
    },
    "top14": {
        # Wikipedia uses en-dash in "YYYY\u2013YY" format
        "page_format": "{year}\u2013{next_short}_Top_14_season",
        "years": list(range(2015, 2026)),
        "competition_code": "top14",
    },
    "pro_d2": {
        "page_format": "{year}\u2013{next_short}_Rugby_Pro_D2_season",
        "years": list(range(2015, 2026)),
        "competition_code": "pro_d2",
    },
    "premiership": {
        "page_format": "{year}\u2013{next_short}_Premiership_Rugby",
        "years": list(range(2015, 2026)),
        "competition_code": "premiership",
    },
    "urc": {
        "page_format": "{year}\u2013{next_short}_United_Rugby_Championship_season",
        "years": list(range(2021, 2027)),
        "competition_code": "urc",
    },
    "challenge_cup": {
        "page_format": "{year}\u2013{next_short}_EPCR_Challenge_Cup",
        "years": list(range(2016, 2026)),
        "competition_code": "challenge_cup",
    },
    "champions_cup": {
        "page_format": "{year}\u2013{next_short}_European_Rugby_Champions_Cup",
        "years": list(range(2014, 2026)),
        "competition_code": "champions_cup",
    },
}

TEAM_CODE_MAP = {
    # Six Nations
    "ENG": "England", "FRA": "France", "IRE": "Ireland", "ITA": "Italy",
    "SCO": "Scotland", "WAL": "Wales",
    # Rugby Championship
    "NZL": "New Zealand", "AUS": "Australia", "ARG": "Argentina", "RSA": "South Africa",
    "SAF": "South Africa",
    # World Cup additional
    "JPN": "Japan", "USA": "United States", "CAN": "Canada", "GEO": "Georgia",
    "SAM": "Samoa", "TON": "Tonga", "FIJ": "Fiji", "ROU": "Romania",
    "URU": "Uruguay", "POR": "Portugal", "ESP": "Spain", "CHI": "Chile",
    "HKG": "Hong Kong China", "ZIM": "Zimbabwe", "NAM": "Namibia",
    "RUS": "Russia",
    # Super Rugby
    "CRU": "Crusaders", "CHI_SR": "Chiefs", "BLU": "Blues", "HIG": "Highlanders",
    "HUR": "Hurricanes", "BRU": "Brumbies", "REB": "Rebels", "FOR": "Western Force",
    "REDS": "Reds", "WAR": "Waratahs", "MOA": "Moana Pasifika", "DRU": "Fijian Drua",
}


def fetch_wikitext(page_title):
    url = f"https://en.wikipedia.org/w/api.php?action=parse&page={quote(page_title)}&prop=wikitext&format=json"
    req = Request(url, headers=HEADERS)
    try:
        with urlopen(req, timeout=20) as resp:
            data = json.loads(resp.read().decode("utf-8"))
        return data.get("parse", {}).get("wikitext", {}).get("*")
    except Exception as e:
        print(f"    Error fetching {page_title}: {e}")
        return None


def resolve_team_from_template(template_text):
    """Extract team name from either:
       - {{rug|XXX}} / {{ru|XXX}} / {{Ru-rt|XXX}} templates (international comps)
       - [[Link|Display]] wikilinks (club comps like Top 14, Premiership)
    """
    text = template_text.strip()

    # Strip bonus-point annotations like "(1 BP)" or "'''(1 BP)'''"
    text = re.sub(r"'*\(\s*\d+\s*BP\s*\)'*", "", text).strip()

    # Case-insensitive template name, extract 2-4 letter team code
    m = re.search(r"\{\{[A-Za-z\-]+\|([A-Za-z]{2,4})", text)
    if m:
        code = m.group(1).upper()
        return TEAM_CODE_MAP.get(code, code)

    # Wikilink pattern: [[Link|Display]] — prefer Display
    m = re.search(r"\[\[([^\]|]+?)(?:\|([^\]]+))?\]\]", text)
    if m:
        # Return display text if present, else the link target
        name = (m.group(2) or m.group(1)).strip()
        return name

    # Otherwise return cleaned text
    cleaned = re.sub(r"[\[\]]", "", text).strip()
    return cleaned or None


def extract_matches(wikitext):
    """
    Extract match blocks with date, teams, and referee from wikitext.

    Match blocks use {{rugbybox}} or similar templates with fields like:
        |date = 2 February 2024
        |team1 = {{rug|FRA}}
        |team2 = {{rug|IRE}}
        |referee = [[Karl Dickson]] ([[England]])
    """
    matches = []

    # Find all rugbybox-like templates — they can be nested, so we use a simple approach
    # Match from {{rugbybox up to the closing }}
    blocks = re.findall(r"\{\{\s*rugbybox.*?(?=\n\{\{\s*rugbybox|\n==|\Z)", wikitext, re.DOTALL | re.IGNORECASE)

    for block in blocks:
        # Extract fields
        def field(name):
            m = re.search(rf"\|\s*{name}\s*=\s*(.+?)(?=\n\|\s*\w+\s*=|\n\}}\}}|\Z)", block, re.DOTALL | re.IGNORECASE)
            return m.group(1).strip() if m else None

        date_str = field("date")
        team1_raw = field("team1") or field("home")
        team2_raw = field("team2") or field("away")
        referee_raw = field("referee")

        if not (date_str and team1_raw and team2_raw and referee_raw):
            continue

        team1 = resolve_team_from_template(team1_raw) or team1_raw.strip()
        team2 = resolve_team_from_template(team2_raw) or team2_raw.strip()

        # Parse referee name — [[Name (qualifier)|Display]] wikilink OR plain text
        referee_name = None
        ref_link = re.search(r"\[\[([^\]|]+?)(?:\|([^\]]+))?\]\]", referee_raw)
        if ref_link:
            referee_name = (ref_link.group(2) or ref_link.group(1)).strip()
        else:
            # Plain text — strip markdown, nationality in parens, bonus annotations
            plain = referee_raw.strip()
            plain = re.sub(r"'{2,}", "", plain)  # strip bold
            plain = re.sub(r"\s*\([^)]+\)\s*$", "", plain)  # strip trailing (nationality)
            plain = re.sub(r"<[^>]+>", "", plain)  # strip HTML tags
            plain = plain.strip()
            # Must look like a person's name (at least 2 words, Title Case)
            if re.match(r"^[A-Z][a-zà-ÿ\-']+(?:\s+[A-Z][a-zà-ÿ\-']+)+$", plain):
                referee_name = plain

        if not referee_name:
            continue

        referee_name = re.sub(r"\s*\([^)]+\)\s*$", "", referee_name).strip()

        # Normalize date — "2 February 2024" → "2024-02-02"
        date_iso = parse_date(date_str)

        matches.append({
            "date": date_iso,
            "team1": team1,
            "team2": team2,
            "referee": referee_name,
        })

    return matches


def parse_date(date_str):
    """Parse Wikipedia date formats into YYYY-MM-DD."""
    date_str = re.sub(r"\{\{[^\}]+\}\}", "", date_str).strip()  # strip templates
    date_str = re.sub(r"\[\[|\]\]", "", date_str).strip()
    months = {
        "january": "01", "february": "02", "march": "03", "april": "04",
        "may": "05", "june": "06", "july": "07", "august": "08",
        "september": "09", "october": "10", "november": "11", "december": "12",
    }
    m = re.search(r"(\d{1,2})\s+(\w+)\s+(\d{4})", date_str)
    if m:
        day, month_name, year = m.groups()
        month = months.get(month_name.lower())
        if month:
            return f"{year}-{month}-{int(day):02d}"
    return None


def fetch_referee_nationality(name):
    candidates = [
        name.replace(" ", "_"),
        f"{name.replace(' ', '_')}_(referee)",
        f"{name.replace(' ', '_')}_(rugby_union_referee)",
    ]

    for title in candidates:
        wikitext = fetch_wikitext(title)
        if not wikitext or "may refer to" in wikitext.lower()[:200]:
            continue

        cat_match = re.search(
            r"\[\[Category:(English|Welsh|Irish|Scottish|French|Italian|New Zealand|Australian|"
            r"South African|Argentine|Argentinian|Georgian|Japanese|Canadian|American|"
            r"Fijian|Samoan|Tongan|Romanian|Spanish|Portuguese|Uruguayan|Chilean|Russian|Ukrainian)\s+"
            r"rugby union referees\]\]",
            wikitext,
            re.IGNORECASE,
        )
        if cat_match:
            return normalize_country(cat_match.group(1).strip())

        opening = wikitext[:2000]
        opening_match = re.search(
            r"is\s+an?\s+(English|Welsh|Irish|Scottish|French|Italian|New Zealand|Australian|"
            r"South African|Argentine|Argentinian|Georgian|Japanese|Canadian|American|"
            r"Fijian|Samoan|Tongan|Romanian|Spanish|Portuguese|Uruguayan|Chilean|Russian|Ukrainian)\s+"
            r"rugby",
            opening,
            re.IGNORECASE,
        )
        if opening_match:
            return normalize_country(opening_match.group(1).strip())

        return None

    return None


def normalize_country(value):
    value = value.strip()
    aliases = {
        "English": "England", "Welsh": "Wales", "Scottish": "Scotland",
        "Irish": "Ireland", "French": "France", "Italian": "Italy",
        "Georgian": "Georgia", "New Zealander": "New Zealand",
        "Australian": "Australia", "South African": "South Africa",
        "Argentinian": "Argentina", "Argentine": "Argentina",
        "Japanese": "Japan", "Canadian": "Canada", "American": "United States",
        "Fijian": "Fiji", "Samoan": "Samoa", "Tongan": "Tonga",
        "Romanian": "Romania", "Spanish": "Spain", "Portuguese": "Portugal",
        "Uruguayan": "Uruguay", "Chilean": "Chile", "Russian": "Russia",
        "Ukrainian": "Ukraine",
    }
    return aliases.get(value, value)


def get_output_path():
    return Path(__file__).parent.parent / "storage" / "app" / "wiki_match_officials.json"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--tournament", default="all", choices=list(TOURNAMENTS.keys()) + ["all"])
    parser.add_argument("--year", type=int)
    args = parser.parse_args()

    tournaments = TOURNAMENTS.keys() if args.tournament == "all" else [args.tournament]
    all_matches = []
    referee_nationalities = {}

    for tournament_key in tournaments:
        tournament = TOURNAMENTS[tournament_key]
        years = [args.year] if args.year else tournament["years"]

        print(f"\n=== {tournament_key} ===")

        extra_pages = tournament.get("extra_pages", [])

        for year in years:
            pages_to_fetch = [tournament["page_format"]] + extra_pages
            next_short = f"{(year + 1) % 100:02d}"

            for page_template in pages_to_fetch:
                page_title = page_template.format(year=year, next_short=next_short)
                print(f"  Fetching {page_title}...")
                wikitext = fetch_wikitext(page_title)
                if not wikitext:
                    continue

                matches = extract_matches(wikitext)
                print(f"    Found {len(matches)} matches with referees")

                for m in matches:
                    m["competition_code"] = tournament["competition_code"]
                    m["year"] = year
                    all_matches.append(m)

                time.sleep(0.3)

    # Fetch nationality for each unique referee
    unique_refs = sorted(set(m["referee"] for m in all_matches))
    print(f"\n{len(unique_refs)} unique referees to check...")

    for name in unique_refs:
        nat = fetch_referee_nationality(name)
        referee_nationalities[name] = nat
        print(f"  {name}: {nat or 'NOT FOUND'}")
        time.sleep(0.3)

    # Attach nationality to each match entry
    for m in all_matches:
        m["referee_nationality"] = referee_nationalities.get(m["referee"])

    output_path = get_output_path()
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with open(output_path, "w") as f:
        json.dump({
            "matches": all_matches,
            "referees": referee_nationalities,
        }, f, indent=2)

    print(f"\n{'='*50}")
    print(f"Total match-referee entries: {len(all_matches)}")
    print(f"Unique referees: {len(unique_refs)}")
    print(f"With nationality: {sum(1 for v in referee_nationalities.values() if v)}")
    print(f"Output: {output_path}")


if __name__ == "__main__":
    main()
