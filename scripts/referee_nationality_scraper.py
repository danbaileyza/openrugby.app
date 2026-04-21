#!/usr/bin/env python3
"""
Scrape referee nationality data from rugby365.com appointment pages.

Rugby365 publishes URC and Champions Cup referee appointments per round,
listing each official with their nationality in parentheses.

Usage:
    python3 scripts/referee_nationality_scraper.py

Output: storage/app/referee_nationalities.json
Then import with: php artisan rugby:import-referee-nationalities
"""

import json
import re
import time
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36",
    "Accept": "text/html,application/xhtml+xml",
}

# Rugby365 appointment page URL patterns
# They follow: /laws-referees/news/urc-round-{N}-referee-appointments{suffix}/
# Suffix varies: "", "-2", "-3", "-4" across seasons
APPOINTMENT_URLS = []

# Build URL list for URC rounds
for round_num in range(1, 19):
    for suffix in ["", "-2", "-3", "-4", "-5"]:
        APPOINTMENT_URLS.append(
            f"https://rugby365.com/laws-referees/news/urc-round-{round_num}-referee-appointments{suffix}/"
        )

# Champions Cup appointments
for round_num in range(1, 5):
    for suffix in ["", "-2", "-3", "-4"]:
        APPOINTMENT_URLS.append(
            f"https://rugby365.com/laws-referees/news/champions-cup-round-{round_num}-referee-appointments{suffix}/"
        )


def fetch_html(url, retries=2, delay=0.5):
    """Fetch HTML from a URL with retries."""
    for attempt in range(retries):
        try:
            req = Request(url, headers=HEADERS)
            with urlopen(req, timeout=15) as resp:
                return resp.read().decode("utf-8", errors="replace")
        except HTTPError as e:
            if e.code == 404:
                return None
            if attempt < retries - 1:
                time.sleep(delay * (attempt + 1))
                continue
            return None
        except (URLError, TimeoutError) as e:
            if attempt < retries - 1:
                time.sleep(delay * (attempt + 1))
                continue
            return None
    return None


def extract_officials_from_html(html):
    """
    Extract official names and nationalities from rugby365 appointment HTML.

    Pattern: **Name** (Country) or Name (Country)
    """
    officials = {}

    # Match patterns like: "Name (Country)" or "**Name** (Country)"
    # Rugby365 uses various formats
    patterns = [
        r"\*\*([A-Z][a-zA-ZÀ-ÿ\s\-']+?)\*\*\s*\(([A-Za-z\s]+)\)",  # **Name** (Country)
        r"[–\-•]\s*\*?\*?([A-Z][a-zA-ZÀ-ÿ\s\-']+?)\*?\*?\s*\(([A-Za-z\s]+)\)",  # - Name (Country)
        r">\s*([A-Z][a-zA-ZÀ-ÿ\s\-']+?)\s*\(([A-Za-z\s]+)\)\s*<",  # HTML: >Name (Country)<
    ]

    for pattern in patterns:
        for match in re.finditer(pattern, html):
            name = match.group(1).strip()
            country = match.group(2).strip()

            # Skip obviously wrong matches
            if len(name) < 4 or len(name) > 40:
                continue
            if country.lower() in ("tmo", "referee", "assistant"):
                continue

            # Normalize country names
            country = normalize_country(country)
            if country:
                officials[name] = country

    return officials


def normalize_country(country):
    """Normalize country name variations."""
    country = country.strip()
    aliases = {
        "SA": "South Africa",
        "RSA": "South Africa",
        "Sth Africa": "South Africa",
        "S Africa": "South Africa",
        "NZ": "New Zealand",
        "Eng": "England",
        "Wal": "Wales",
        "Sco": "Scotland",
        "Ire": "Ireland",
        "Ita": "Italy",
        "Fra": "France",
        "Aus": "Australia",
        "Arg": "Argentina",
        "Geo": "Georgia",
    }
    return aliases.get(country, country) if country else None


def get_output_path():
    script_dir = Path(__file__).parent.parent
    return script_dir / "storage" / "app" / "referee_nationalities.json"


def main():
    all_officials = {}
    pages_fetched = 0
    pages_with_data = 0

    print(f"Scraping referee nationalities from rugby365.com...")
    print(f"Checking {len(APPOINTMENT_URLS)} appointment page URLs...\n")

    for url in APPOINTMENT_URLS:
        html = fetch_html(url)
        if not html:
            continue

        pages_fetched += 1
        officials = extract_officials_from_html(html)

        if officials:
            pages_with_data += 1
            for name, country in officials.items():
                if name not in all_officials:
                    all_officials[name] = country
                    print(f"  {name}: {country}")

        time.sleep(0.3)  # Be polite

    # Save results
    output_path = get_output_path()
    output_path.parent.mkdir(parents=True, exist_ok=True)

    with open(output_path, "w") as f:
        json.dump(all_officials, f, indent=2, sort_keys=True)

    print(f"\n{'='*50}")
    print(f"Pages fetched: {pages_fetched}")
    print(f"Pages with data: {pages_with_data}")
    print(f"Unique officials: {len(all_officials)}")
    print(f"Output: {output_path}")
    print(f"\nNext step: php artisan rugby:import-referee-nationalities")


if __name__ == "__main__":
    main()
