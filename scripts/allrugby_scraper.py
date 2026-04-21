#!/usr/bin/env python3
"""
Scrape rugby player and team data from all.rugby.

All.rugby has server-rendered HTML pages with rich player profiles including
nationality, height, weight, DOB, position, team, contract length.

Usage:
    python3 scripts/allrugby_scraper.py --squads urc                # URC squads
    python3 scripts/allrugby_scraper.py --squads urc premiership    # Multiple leagues
    python3 scripts/allrugby_scraper.py --squads all                # All leagues
    python3 scripts/allrugby_scraper.py --club stormers             # Single club
    python3 scripts/allrugby_scraper.py --list-leagues              # Show leagues
    python3 scripts/allrugby_scraper.py --player vernon-matongo     # Single player career
    python3 scripts/allrugby_scraper.py --careers                   # All player careers (needs squads.json)
    python3 scripts/allrugby_scraper.py --careers --careers-limit 5 # Test with 5 players
    python3 scripts/allrugby_scraper.py --match 24326               # Single match page
    python3 scripts/allrugby_scraper.py --matches-from-careers      # Match pages for imported careers

Output: storage/app/allrugby/
Then import with: php artisan rugby:import-allrugby
"""

import argparse
import json
import re
import sys
import time
from html.parser import HTMLParser
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError

BASE_URL = "https://all.rugby"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml",
    "Accept-Language": "en-US,en;q=0.9",
}

# Club slugs organized by league
LEAGUES = {
    "urc": [
        "bulls", "cardiff", "connacht", "dragons", "edinburgh", "glasgow",
        "leinster", "lions", "munster", "ospreys", "scarlets", "sharks",
        "stormers", "benetton", "ulster", "zebre",
    ],
    "premiership": [
        "bath", "bristol", "exeter", "gloucester", "harlequins",
        "leicester", "newcastle", "northampton", "sale", "saracens",
    ],
    "top14": [
        "bayonne", "bordeaux", "castres", "clermont", "la-rochelle",
        "lyon", "montauban", "montpellier", "paris", "pau",
        "perpignan", "racing-92", "toulon", "toulouse",
    ],
    "super_rugby": [
        "blues", "brumbies", "chiefs", "crusaders", "fijian-drua",
        "highlanders", "hurricanes", "moana-pasifika", "reds",
        "waratahs", "western-force",
    ],
    "internationals": [
        "south-africa", "new-zealand", "england", "france", "ireland",
        "australia", "wales", "scotland", "argentina", "japan",
        "fiji", "italy", "tonga", "usa",
    ],
    "champions_cup": [
        "bath", "bayonne", "bordeaux", "bristol", "bulls", "castres",
        "clermont", "edinburgh", "glasgow", "gloucester", "harlequins",
        "la-rochelle", "leicester", "leinster", "munster", "northampton",
        "pau", "sale", "saracens", "scarlets", "sharks", "stormers",
        "toulon", "toulouse",
    ],
}


def get_output_dir():
    script_dir = Path(__file__).parent.parent
    output_dir = script_dir / "storage" / "app" / "allrugby"
    output_dir.mkdir(parents=True, exist_ok=True)
    return output_dir


def fetch_html(url, retries=3, delay=1.0):
    """Fetch HTML from a URL with retries."""
    for attempt in range(retries):
        try:
            req = Request(url, headers=HEADERS)
            with urlopen(req, timeout=20) as resp:
                return resp.read().decode("utf-8", errors="replace")
        except HTTPError as e:
            if e.code == 404:
                return None
            if e.code == 429:
                wait = 3 * (attempt + 1)
                print(f"    Rate limited, waiting {wait}s...")
                time.sleep(wait)
                continue
            if e.code >= 500:
                time.sleep(delay * (attempt + 1))
                continue
            print(f"    HTTP {e.code} for {url}")
            return None
        except (URLError, TimeoutError) as e:
            if attempt < retries - 1:
                time.sleep(delay * (attempt + 1))
                continue
            print(f"    Error: {e}")
            return None
    return None


def parse_squad_page(html, club_slug):
    """Parse a club squad page to extract player data.

    Table columns (from inspecting the DOM):
      0: [pay/premium indicator, empty]
      1: Name (with <a href="/player/slug">First LAST</a>)
      2: Position
      3: Age (e.g. "39 y/o")
      4: Birthdate (dd/mm/yyyy)
      5: Height (e.g. "1.79 m")
      6: Weight (e.g. "120 kg")
      7: Height ft/in
      8: Weight st/lb
      9: Contract
      10: Length
      11: Misc
    """
    players = []

    # Split into table rows
    rows = re.findall(r'<tr[^>]*>(.*?)</tr>', html, re.DOTALL)

    for row in rows:
        # Skip header rows
        if '<th' in row:
            continue

        # Find the player link anywhere in the row first
        name_match = re.search(
            r'<a\s+href="/player/([^"]+)"[^>]*>\s*(.+?)\s*</a>',
            row, re.DOTALL
        )
        if not name_match:
            continue

        player_slug = name_match.group(1).strip()
        full_name = re.sub(r'<[^>]+>', '', name_match.group(2)).strip()

        # Extract all cells
        cells = re.findall(r'<td[^>]*>(.*?)</td>', row, re.DOTALL)
        if len(cells) < 7:
            continue

        # Find which cell has the player link (usually index 1)
        name_cell_idx = None
        for i, cell in enumerate(cells):
            if '/player/' in cell:
                name_cell_idx = i
                break

        if name_cell_idx is None:
            continue

        # Offsets relative to the name cell
        pos_idx = name_cell_idx + 1
        age_idx = name_cell_idx + 2
        dob_idx = name_cell_idx + 3
        height_idx = name_cell_idx + 4
        weight_idx = name_cell_idx + 5

        # Split name: last name is typically ALL UPPERCASE
        name_parts = full_name.split()
        first_name_parts = []
        last_name_parts = []
        hit_upper = False
        for part in name_parts:
            # Check if this word is all uppercase (and not a single char like "J")
            if part == part.upper() and len(part) > 1 and part.isalpha():
                hit_upper = True
                last_name_parts.append(part)
            elif hit_upper:
                # Mixed case after uppercase = still last name (e.g., "DE VILLIERS")
                last_name_parts.append(part)
            else:
                first_name_parts.append(part)

        if not last_name_parts and len(name_parts) > 1:
            first_name_parts = name_parts[:-1]
            last_name_parts = [name_parts[-1]]
        elif not last_name_parts:
            first_name_parts = name_parts
            last_name_parts = []

        first_name = " ".join(first_name_parts)
        last_name = " ".join(last_name_parts).title()

        def cell_text(idx):
            if idx < len(cells):
                return re.sub(r'<[^>]+>', '', cells[idx]).strip()
            return ""

        # Position
        position = cell_text(pos_idx)

        # Age
        age = None
        age_text = cell_text(age_idx)
        age_m = re.search(r'(\d+)', age_text)
        if age_m:
            age = int(age_m.group(1))

        # DOB (dd/mm/yyyy)
        dob = None
        dob_text = cell_text(dob_idx)
        dob_m = re.match(r'(\d{1,2})/(\d{1,2})/(\d{4})', dob_text)
        if dob_m:
            day, month, year = dob_m.groups()
            dob = f"{year}-{month.zfill(2)}-{day.zfill(2)}"

        # Height (e.g., "1.85 m")
        height_cm = None
        height_text = cell_text(height_idx)
        h_m = re.search(r'(\d+\.\d+)\s*m', height_text)
        if h_m:
            height_cm = int(round(float(h_m.group(1)) * 100))

        # Weight (e.g., "120 kg")
        weight_kg = None
        weight_text = cell_text(weight_idx)
        w_m = re.search(r'(\d+)\s*kg', weight_text)
        if w_m:
            weight_kg = int(w_m.group(1))

        # Contract (look for a 4-digit year in later cells)
        contract = ""
        for ci in range(weight_idx + 1, len(cells)):
            ct = cell_text(ci)
            if re.match(r'20\d{2}$', ct):
                contract = ct
                break

        players.append({
            "slug": player_slug,
            "first_name": first_name,
            "last_name": last_name,
            "full_name": full_name,
            "position": position,
            "age": age,
            "dob": dob,
            "height_cm": height_cm,
            "weight_kg": weight_kg,
            "contract_until": contract,
            "club_slug": club_slug,
            "source": "all.rugby",
        })

    return players


def scrape_player_nationality(player_slug, delay=0.3):
    """Fetch a player's profile page to get nationality."""
    url = f"{BASE_URL}/player/{player_slug}"
    html = fetch_html(url)
    if not html:
        return None

    return _extract_nationality(html)


def scrape_club_squad(club_slug, fetch_nationality=False, delay=0.5):
    """Scrape a club's squad page."""
    url = f"{BASE_URL}/club/{club_slug}/squad"
    print(f"  Fetching {club_slug} squad...")

    html = fetch_html(url)
    if not html:
        print(f"    No data for {club_slug}")
        return []

    players = parse_squad_page(html, club_slug)
    print(f"    Found {len(players)} players")

    if fetch_nationality and players:
        print(f"    Fetching nationalities ({len(players)} players)...")
        for i, player in enumerate(players):
            nat = scrape_player_nationality(player["slug"], delay)
            if nat:
                player["nationality"] = nat
            if (i + 1) % 10 == 0:
                print(f"      {i + 1}/{len(players)}...")
            time.sleep(delay)

    return players


def _strip_tags(html_str):
    """Remove all HTML tags and decode HTML entities from a string."""
    import html
    text = re.sub(r'<[^>]+>', '', html_str)
    return html.unescape(text).strip()


def _extract_nationality(html):
    """
    Extract nationality from a player page's bio section.

    The bio text looks like:
        <b>Siya KOLISI</b> ... is a 34-year-old
        <a href="/players/south-africa">South African rugby player</a>

    The nationality adjective ("South African") is inside an <a> tag,
    so we must strip tags before matching.
    """
    # Find the bio section
    bio_match = re.search(r'class="bio">(.*?)</div>', html, re.DOTALL)
    if not bio_match:
        # Try broader approach
        bio_match = re.search(r'<p>(.*?)</p>', html, re.DOTALL)

    if bio_match:
        bio_text = _strip_tags(bio_match.group(1))
        # "is a 34-year-old South African rugby player"
        nat_match = re.search(
            r'is\s+a\s+\d+-year-old\s+(.+?)\s+rugby\s+player',
            bio_text, re.IGNORECASE
        )
        if nat_match:
            return nat_match.group(1).strip()

    # Alternative: nationality link href="/players/south-africa"
    nat_link = re.search(r'href="/players/([^"]+)"[^>]*>[^<]*rugby\s+player', html, re.IGNORECASE)
    if nat_link:
        slug = nat_link.group(1)
        # Convert slug to demonym: "south-africa" → "South African"
        # We'll return the slug and let the importer handle mapping
        return slug.replace('-', ' ').title()

    # Alternative: flag icon
    country_match = re.search(r'class="flag-icon[^"]*flag-icon-(\w+)"', html)
    if country_match:
        return country_match.group(1).upper()

    return None


def scrape_player_career(player_slug, delay=0.3):
    """
    Fetch a player's full profile page to get career stats and match history.

    all.rugby player pages contain:
    - Bio: nationality, DOB, height, weight, position
    - Career history: team stints with years
    - Overall stats table (class="JOverall"):
        Has rowspan cells — Season spans multiple tournament rows, Team spans multiple rows.
        Columns: Season | (logo) | Team | Tournament | Matches | W/D/L | Starter | T | D | P | C | Points | Pen.Cards | Min
    - Per-season game sheet tables (class="JSaison"):
        One row per match, no rowspan issues.
        Columns: (color) | Tournament | Team | Opponent | Place | Res | Date | N° | T | D | P | C | Points | Pen.Cards | Min

    Data goes back to ~2013 for established players like Siya Kolisi.
    """
    url = f"{BASE_URL}/player/{player_slug}"
    html = fetch_html(url)
    if not html:
        return None

    profile = {
        "slug": player_slug,
        "nationality": None,
        "career_history": [],
        "overall_stats": [],
        "game_sheets": [],
    }

    # ── Nationality from bio ──
    profile["nationality"] = _extract_nationality(html)

    # ── Career history (team stints) ──
    # Career section has <li> entries like: "Stormers (2013 - 2020)"
    # Some may have links: <a href="/club/stormers">Stormers</a> (2013 - 2020)
    career_section = re.search(r'Career.*?<ul>(.*?)</ul>', html, re.DOTALL)
    if career_section:
        items = re.findall(r'<li>(.*?)</li>', career_section.group(1), re.DOTALL)
        for item in items:
            text = _strip_tags(item).strip()
            m = re.match(r'(.+?)\s*\((\d{4})\s*-\s*(\d{4})\)', text)
            if m:
                team_name = m.group(1).strip()
                # Try to extract slug from link if present
                link = re.search(r'href="/club/([^"]+)"', item)
                profile["career_history"].append({
                    "team_slug": link.group(1) if link else team_name.lower().replace(' ', '-'),
                    "team_name": team_name,
                    "from_year": int(m.group(2)),
                    "to_year": int(m.group(3)),
                })

    # ── Overall stats table (class="JOverall") ──
    # Uses rowspan for Season and Team cells. The KEY insight is that the LAST 11 cells
    # in every data row are always: Tournament | Matches | W/D/L | Starter | T | D | P | C | Points | Pen.Cards | Min
    # The first 0-3 cells are Season/Logo/Team which vary due to rowspan.
    #
    # Cell counts by row type:
    #   14 cells = new season + new team (Season + Logo + Team + 11 stat cols)
    #   13 cells = continuing season, new team (Logo + Team + 11 stat cols)
    #   11 cells = continuing season+team (just 11 stat cols)
    overall_match = re.search(r'<table[^>]*class="[^"]*JOverall[^"]*"[^>]*>(.*?)</table>', html, re.DOTALL)
    if overall_match:
        table_html = overall_match.group(1)
        tbody_match = re.search(r'<tbody[^>]*>(.*?)</tbody>', table_html, re.DOTALL)
        body_html = tbody_match.group(1) if tbody_match else table_html

        rows = re.findall(r'<tr[^>]*>(.*?)</tr>', body_html, re.DOTALL)

        current_season = ""
        current_team = ""
        current_team_slug = ""

        for row in rows:
            if '<th' in row:
                continue

            cells_raw = re.findall(r'<td[^>]*>(.*?)</td>', row, re.DOTALL)
            if len(cells_raw) < 11:
                continue

            cell_texts = [_strip_tags(c) for c in cells_raw]

            # Skip aggregate rows (TOURNAMENT, TEAM summary sections at bottom)
            all_text = ' '.join(cell_texts).upper()
            if 'TOURNAMENT' in all_text or 'TEAM' in all_text:
                # Check if it's actually a summary header row
                if any(ct.upper().strip() in ('TOURNAMENT', 'TEAM') for ct in cell_texts[:3]):
                    continue

            # Update season/team from the leading cells (before the fixed 11-cell tail)
            prefix_count = len(cells_raw) - 11  # 0, 2, or 3

            if prefix_count >= 3:
                # Full row: Season | Logo | Team | ...11 stats...
                season_text = cell_texts[0].strip()
                if re.match(r'\d{2}/\d{2}', season_text):
                    current_season = season_text
                # Team name is in a cell with class="tdclub"
                for i, raw in enumerate(cells_raw[:prefix_count]):
                    if 'tdclub' in raw:
                        current_team = _strip_tags(raw)
                        break
                # Team slug from image
                team_img = re.search(r'/img/logo/clubs/\d+/([^.]+)\.png', row)
                if team_img:
                    current_team_slug = team_img.group(1)

            elif prefix_count >= 2:
                # New team within same season: Logo | Team | ...11 stats...
                for i, raw in enumerate(cells_raw[:prefix_count]):
                    if 'tdclub' in raw:
                        current_team = _strip_tags(raw)
                        break
                team_img = re.search(r'/img/logo/clubs/\d+/([^.]+)\.png', row)
                if team_img:
                    current_team_slug = team_img.group(1)

            # The last 11 cells are always: Tournament | Matches | W/D/L | Starter | T | D | P | C | Points | Pen.Cards | Min
            stat_cells = cell_texts[-11:]

            tournament = stat_cells[0].strip()
            if not tournament:
                continue

            matches = _parse_int(stat_cells[1])
            wdl = stat_cells[2].strip()
            starter = _parse_int(stat_cells[3])
            tries = _parse_int(stat_cells[4])
            drops = _parse_int(stat_cells[5])
            pens = _parse_int(stat_cells[6])
            convs = _parse_int(stat_cells[7])
            points = _parse_int(stat_cells[8])
            # stat_cells[9] = Pen. Cards
            minutes = _parse_int(stat_cells[10])

            # Parse W/D/L
            wdl_parts = re.findall(r'\d+', wdl)
            wins = int(wdl_parts[0]) if len(wdl_parts) > 0 else 0
            draws = int(wdl_parts[1]) if len(wdl_parts) > 1 else 0
            losses = int(wdl_parts[2]) if len(wdl_parts) > 2 else 0

            profile["overall_stats"].append({
                "season": current_season,
                "team": current_team,
                "team_slug": current_team_slug,
                "tournament": tournament,
                "matches": matches,
                "wins": wins,
                "draws": draws,
                "losses": losses,
                "starter": starter,
                "tries": tries,
                "drop_goals": drops,
                "penalties": pens,
                "conversions": convs,
                "points": points,
                "minutes": minutes,
            })

    # ── Game sheet tables (class="JSaison") ──
    # One row per match, no rowspan issues. 15 cells per row.
    # Columns: (color) | Tournament | Team | Opponent | Place | Res | Date | N° | T | D | P | C | Points | Pen.Cards | Min
    season_tables = re.findall(r'<table[^>]*class="[^"]*JSaison[^"]*"[^>]*>(.*?)</table>', html, re.DOTALL)

    for table_html in season_tables:
        # Get tbody content
        tbody_match = re.search(r'<tbody[^>]*>(.*?)</tbody>', table_html, re.DOTALL)
        body_html = tbody_match.group(1) if tbody_match else table_html

        rows = re.findall(r'<tr[^>]*>(.*?)</tr>', body_html, re.DOTALL)

        for row in rows:
            # Skip header rows
            if '<th' in row:
                continue

            cells = re.findall(r'<td[^>]*>(.*?)</td>', row, re.DOTALL)
            if len(cells) < 10:
                continue

            cell_texts = [_strip_tags(c) for c in cells]

            # Skip "Total" summary rows
            joined = ' '.join(cell_texts[:3]).lower()
            if 'total' in joined:
                continue

            # Extract match link from opponent cell
            match_link = re.search(r'href="/match/(\d+)/([^"]+)"', row)

            # Columns (15 cells):
            # 0: (color indicator, empty)
            # 1: Tournament (e.g. "URC - R4")
            # 2: Team
            # 3: Opponent (has match link)
            # 4: Place (HOME/AWAY)
            # 5: Result (W/L/D)
            # 6: Date (DD/MM)
            # 7: Jersey N°
            # 8: Tries (T)
            # 9: Drop goals (D)
            # 10: Penalties (P)
            # 11: Conversions (C)
            # 12: Points
            # 13: Pen. Cards
            # 14: Minutes (e.g. "63'")

            tournament = cell_texts[1].strip() if len(cell_texts) > 1 else ""
            team = cell_texts[2].strip() if len(cell_texts) > 2 else ""
            opponent = cell_texts[3].strip() if len(cell_texts) > 3 else ""
            place = cell_texts[4].strip() if len(cell_texts) > 4 else ""
            result = cell_texts[5].strip() if len(cell_texts) > 5 else ""
            date = cell_texts[6].strip() if len(cell_texts) > 6 else ""
            jersey_number = _parse_int(cell_texts[7]) if len(cell_texts) > 7 else 0
            tries = _parse_int(cell_texts[8]) if len(cell_texts) > 8 else 0
            drops = _parse_int(cell_texts[9]) if len(cell_texts) > 9 else 0
            pens = _parse_int(cell_texts[10]) if len(cell_texts) > 10 else 0
            convs = _parse_int(cell_texts[11]) if len(cell_texts) > 11 else 0
            points = _parse_int(cell_texts[12]) if len(cell_texts) > 12 else 0
            # cell_texts[13] = pen/cards (usually empty or a number)
            minutes = _parse_int(cell_texts[14]) if len(cell_texts) > 14 else 0

            if not opponent and not team:
                continue

            entry = {
                "tournament": tournament,
                "team": team,
                "opponent": opponent,
                "place": place,  # HOME or AWAY
                "result": result,  # W, L, D
                "date": date,  # DD/MM format
                "jersey_number": jersey_number,
                "tries": tries,
                "drop_goals": drops,
                "penalties": pens,
                "conversions": convs,
                "points": points,
                "minutes": minutes,
            }

            if match_link:
                entry["match_id"] = match_link.group(1)
                entry["match_slug"] = match_link.group(2)

            profile["game_sheets"].append(entry)

    return profile


def _parse_int(val):
    """Safely parse an integer from a string."""
    if not val:
        return 0
    m = re.search(r'(\d+)', str(val))
    return int(m.group(1)) if m else 0


def scrape_player_careers_from_squads(squads_file, output_dir, delay=0.5, limit=None):
    """
    Read the squads.json file and scrape career data for each player.
    Saves individual player career files and a combined careers.json.
    """
    with open(squads_file) as f:
        players = json.load(f)

    print(f"Loaded {len(players)} players from {squads_file}")

    careers_dir = output_dir / "careers"
    careers_dir.mkdir(exist_ok=True)

    all_careers = []
    skipped = 0
    errors = 0

    player_list = players[:limit] if limit else players

    for i, player in enumerate(player_list):
        slug = player.get("slug")
        if not slug:
            continue

        # Skip if already fetched
        career_file = careers_dir / f"{slug}.json"
        if career_file.exists():
            with open(career_file) as f:
                career_data = json.load(f)
            all_careers.append(career_data)
            skipped += 1
            continue

        print(f"  [{i+1}/{len(player_list)}] {player.get('first_name', '')} {player.get('last_name', '')} ({slug})...")
        career = scrape_player_career(slug, delay)

        if career:
            # Merge squad data with career data
            career_data = {**player, **career}

            with open(career_file, "w") as f:
                json.dump(career_data, f, indent=2, default=str)

            stat_count = len(career.get("overall_stats", []))
            season_count = len(career.get("game_sheets", []))
            nat = career.get("nationality", "")
            print(f"    Career: {stat_count} competition entries, {season_count} game sheet entries, nationality: {nat or 'n/a'}")
            all_careers.append(career_data)
        else:
            errors += 1
            print(f"    No data")

        time.sleep(delay)

        # Progress checkpoint
        if (i + 1) % 50 == 0:
            print(f"  ** Progress: {i+1}/{len(player_list)} ({skipped} cached, {errors} errors) **")
            # Save checkpoint
            path = output_dir / "careers.json"
            with open(path, "w") as f:
                json.dump(all_careers, f, indent=2, default=str)

    # Save combined file
    path = output_dir / "careers.json"
    with open(path, "w") as f:
        json.dump(all_careers, f, indent=2, default=str)

    print(f"\n{'='*50}")
    print(f"Exported {len(all_careers)} player careers to {path}")
    print(f"  New: {len(all_careers) - skipped}, Cached: {skipped}, Errors: {errors}")

    # Stats summary
    total_career = sum(len(c.get("overall_stats", [])) for c in all_careers)
    total_matches = sum(len(c.get("game_sheets", [])) for c in all_careers)
    with_nat = sum(1 for c in all_careers if c.get("nationality"))
    print(f"  Career entries: {total_career}")
    print(f"  Game sheet entries: {total_matches}")
    print(f"  With nationality: {with_nat}/{len(all_careers)}")

    return all_careers


def find_match_slug_in_careers(output_dir, match_id):
    careers_dir = output_dir / "careers"
    if not careers_dir.exists():
        return None

    target = str(match_id).strip()
    for career_file in sorted(careers_dir.glob("*.json")):
        try:
            with open(career_file) as f:
                data = json.load(f)
        except Exception:
            continue

        for entry in data.get("game_sheets", []):
            if str(entry.get("match_id", "")).strip() == target:
                slug = (entry.get("match_slug") or "").strip()
                if slug:
                    return slug

    return None


def scrape_match_page(match_id, match_slug=None, delay=0.3):
    """
    Scrape an all.rugby match page for lineup, events, and stats.

    HTML structure (from DOM inspection):
      - Metadata div (class="txtcenter"): Date, Kick Off, Venue, Tournament, Round
      - Score div (class="match_entete"): team logos/names + scores + bonus indicators
      - Table 0 (class="rtable ... fdm"): main lineup + events + substitutions
        - Header row: Card | Drop | Penalty | Conversion | Try | Players | [jersey] | Players | Try | Conv | Pen | Drop | Card
        - Rows 1-15: Starters (13 cells). Cell[5]=home player, [6]=jersey, [7]=away player.
          Stats in cells [0-4] (home) and [8-12] (away) contain minute markers (e.g. "62' 67'")
        - Separator row (colspan=15): blank divider between starters and replacements
        - Rows after separator: Replacements (16-23), same 13-cell layout
        - Second separator, then substitution rows: 3 cells (colspan=6 | #N | colspan=6)
          Sub cell HTML: <b>SURNAME_OFF <span class="error">↓</span></b> <b><span class="success">↑</span> SURNAME_ON</b> 52'
      - Table 1 (class="rtable ... rtablecenter", no "fdm"): Team stats (3 cols: home | stat | away)
        Pack weight, forwards/backs average age, tallest player, nationality breakdowns
    """
    slug_tail = None
    if match_slug:
        slug_tail = str(match_slug).strip('/').split('/')[-1]

    urls = []
    if slug_tail:
        urls.append(f"{BASE_URL}/match/{match_id}/{slug_tail}")
    urls.append(f"{BASE_URL}/match/{match_id}")

    html = None
    for url in urls:
        html = fetch_html(url)
        if html:
            break

    if not html:
        return None

    match_data = {
        "match_id": str(match_id),
        "url": url,
        "teams": [],
        "home_score": None,
        "away_score": None,
        "date": None,
        "kickoff": None,
        "venue": None,
        "tournament": None,
        "round": None,
        "lineups": {"home": [], "away": []},
        "events": [],
        "substitutions": {"home": [], "away": []},
        "team_stats": [],
    }

    # ── Teams ── from <a href="/club/slug/squad"> links
    team_matches = re.findall(
        r'<a\s+[^>]*href="/club/([^"/]+)/squad"[^>]*>\s*([^<]+?)\s*</a>',
        html
    )
    seen_teams = []
    for slug, name in team_matches:
        name = _strip_tags(name).strip()
        if name and slug not in [t.get("slug") for t in seen_teams]:
            seen_teams.append({"slug": slug, "name": name})
    if not seen_teams:
        # Fallback: any /club/ link
        team_matches = re.findall(
            r'<a\s+[^>]*href="/club/([^"]+)"[^>]*>\s*([^<]+?)\s*</a>',
            html
        )
        for slug, name in team_matches:
            slug = slug.strip().rstrip('/').split('/')[0]
            name = _strip_tags(name).strip()
            if name and slug not in [t.get("slug") for t in seen_teams]:
                seen_teams.append({"slug": slug, "name": name})
    match_data["teams"] = seen_teams[:2]

    # ── Metadata ── from <strong>Date : </strong>... block
    meta_block = re.search(
        r'<strong>\s*Date\s*:\s*</strong>\s*(.+?)(?=<em|<h2|<div\s+class="match)',
        html, re.DOTALL
    )
    if meta_block:
        meta_text = _strip_tags(meta_block.group(0))
        # Date
        dm = re.search(r'Date\s*:\s*\w+,\s+(\w+\s+\d+,\s+\d{4})', meta_text)
        if dm:
            from datetime import datetime
            try:
                match_data["date"] = datetime.strptime(dm.group(1), "%B %d, %Y").strftime("%Y-%m-%d")
            except ValueError:
                pass
        if not match_data["date"]:
            dm = re.search(r'(\d{1,2})/(\d{1,2})/(\d{4})', meta_text)
            if dm:
                d, m, y = dm.groups()
                match_data["date"] = f"{y}-{m.zfill(2)}-{d.zfill(2)}"
        # Kick Off
        km = re.search(r'Kick\s+Off\s*:\s*(\d{1,2}:\d{2})', meta_text)
        if km:
            match_data["kickoff"] = km.group(1)
        # Venue
        vm = re.search(r'Venue\s*:\s*(.+?)(?:Tournament|Round|$)', meta_text)
        if vm:
            match_data["venue"] = vm.group(1).strip()
        # Tournament
        tm = re.search(r'Tournament\s*:\s*(.+?)(?:Round|$)', meta_text)
        if tm:
            match_data["tournament"] = tm.group(1).strip()
        # Round
        rm = re.search(r'Round\s*:\s*(.+?)$', meta_text)
        if rm:
            match_data["round"] = rm.group(1).strip()

    # ── Score ── from <div class="match_entete">
    score_section = re.search(
        r'<div\s+class="match_entete[^"]*">(.*?)</div>\s*</div>\s*</div>\s*</div>',
        html, re.DOTALL
    )
    if not score_section:
        score_section = re.search(
            r'class="match_entete[^"]*">(.*?)</div>(?:\s*</div>){2,4}',
            html, re.DOTALL
        )
    if score_section:
        score_html = score_section.group(1)
        scores = re.findall(r'<div\s+class="score">\s*([\s\S]*?)\s*</div>', score_html)
        if len(scores) >= 2:
            home_score_text = re.sub(r'<[^>]+>', '', scores[0]).strip()
            away_score_text = re.sub(r'<[^>]+>', '', scores[1]).strip()
            hs = re.match(r'(\d+)', home_score_text)
            as_ = re.match(r'(\d+)', away_score_text)
            if hs:
                match_data["home_score"] = int(hs.group(1))
            if as_:
                match_data["away_score"] = int(as_.group(1))
    # Fallback score from title
    if match_data["home_score"] is None:
        title_score = re.search(r'(\d+)\s*[-–]\s*(\d+)', html[:3000])
        if title_score:
            match_data["home_score"] = int(title_score.group(1))
            match_data["away_score"] = int(title_score.group(2))

    # ── Parse the main lineup table ──
    # Find the table with class containing "fdm" (the lineup table)
    tables = re.findall(r'<table[^>]*>(.*?)</table>', html, re.DOTALL)
    lineup_table = None
    stats_table = None
    for t_html in tables:
        if '<th' in t_html and ('Players' in t_html or 'Joueurs' in t_html):
            lineup_table = t_html
        elif 'Pack weight' in t_html or 'average age' in t_html or 'Poids du pack' in t_html:
            stats_table = t_html

    if lineup_table:
        _parse_lineup_table(match_data, lineup_table)

    # ── Team stats table ──
    if stats_table:
        _parse_stats_table(match_data, stats_table)

    return match_data


def _parse_lineup_table(match_data, table_html):
    """Parse the main lineup table: players, events, and substitutions."""
    rows = re.findall(r'<tr[^>]*>(.*?)</tr>', table_html, re.DOTALL)
    if not rows:
        return

    # Skip header row
    header_cols = ['card', 'drop', 'penalty', 'conversion', 'try']

    phase = 'starters'  # starters → replacements → substitutions
    jersey_num = 0

    for row_html in rows[1:]:  # skip header
        cells = re.findall(r'<t[dh][^>]*>(.*?)</t[dh]>', row_html, re.DOTALL)

        # Detect separator rows (colspan)
        if re.search(r'colspan\s*=\s*["\']?\d{2}', row_html):
            if phase == 'starters':
                phase = 'replacements'
            elif phase == 'replacements':
                phase = 'substitutions'
            continue

        # Substitution rows: 3 cells (home_sub | #N | away_sub)
        if phase == 'substitutions' and len(cells) == 3:
            _parse_sub_row(match_data, cells)
            continue

        # Player rows: 13 cells
        if len(cells) != 13:
            continue

        # Cells: [0]card [1]drop [2]penalty [3]conversion [4]try [5]home_player [6]jersey [7]away_player [8]try [9]conv [10]pen [11]drop [12]card
        jersey_text = _strip_tags(cells[6]).strip()
        if not jersey_text.isdigit():
            continue
        jersey_num = int(jersey_text)
        role = 'starter' if phase == 'starters' else 'replacement'

        # Home player
        home_player = _extract_player_from_cell(cells[5])
        if home_player:
            home_player['jersey_number'] = jersey_num
            home_player['role'] = role
            # Extract events from home stat cells
            home_events = _extract_events_from_cells(
                cells[0:5], header_cols, home_player, 'home', jersey_num
            )
            match_data['events'].extend(home_events)
            match_data['lineups']['home'].append(home_player)

        # Away player
        away_player = _extract_player_from_cell(cells[7])
        if away_player:
            away_player['jersey_number'] = jersey_num
            away_player['role'] = role
            # Extract events from away stat cells (reversed order)
            away_cols = ['try', 'conversion', 'penalty', 'drop', 'card']
            away_events = _extract_events_from_cells(
                cells[8:13], away_cols, away_player, 'away', jersey_num
            )
            match_data['events'].extend(away_events)
            match_data['lineups']['away'].append(away_player)


def _extract_player_from_cell(cell_html):
    """Extract player slug and name from a lineup cell."""
    link = re.search(r'<a\s+href="/player/([^"]+)"[^>]*>\s*(.+?)\s*</a>', cell_html, re.DOTALL)
    if not link:
        return None
    return {
        'slug': link.group(1).strip(),
        'name': _strip_tags(link.group(2)).strip(),
    }


def _extract_events_from_cells(cells, col_names, player, side, jersey):
    """Extract match events (tries, conversions, etc.) from stat cells.

    Each cell may contain minute markers like "62' 67'" or "5' 31' 41'".
    """
    events = []
    for cell_html, event_type in zip(cells, col_names):
        text = _strip_tags(cell_html).strip()
        if not text:
            continue
        # Extract all minute markers
        minutes = re.findall(r"(\d+)'", text)
        for minute in minutes:
            events.append({
                'type': event_type,
                'minute': int(minute),
                'player_name': player['name'],
                'player_slug': player['slug'],
                'side': side,
                'jersey_number': jersey,
            })
    return events


def _parse_sub_row(match_data, cells):
    """Parse a substitution row (3 cells: home | #N | away).

    Cell HTML format:
      <b>SURNAME_OFF <span class="error">🡇</span></b>
      <b><span class="success">🡅</span> SURNAME_ON</b>
      52'

    The <b> tags reliably contain exactly two entries: the OFF player first, ON player second.
    """
    for idx, side in [(0, 'home'), (2, 'away')]:
        cell_html = cells[idx]
        cell_text = _strip_tags(cell_html).strip()
        if not cell_text:
            continue

        # Extract minute from the cell text
        minute_match = re.search(r"(\d+)'", cell_text)
        minute = int(minute_match.group(1)) if minute_match else None

        # Extract names from <b> tags - there are exactly 2: off player and on player
        bold_tags = re.findall(r'<b>(.*?)</b>', cell_html, re.DOTALL)
        if len(bold_tags) >= 2:
            off_name = _strip_tags(bold_tags[0]).strip()
            on_name = _strip_tags(bold_tags[1]).strip()
            # Remove any remaining arrow/special characters
            off_name = re.sub(r'[^\w\s\'-]', '', off_name).strip()
            on_name = re.sub(r'[^\w\s\'-]', '', on_name).strip()

            if off_name and on_name:
                match_data['substitutions'][side].append({
                    'off': off_name,
                    'on': on_name,
                    'minute': minute,
                })


def _parse_stats_table(match_data, table_html):
    """Parse the team stats comparison table (3 columns: home | stat | away)."""
    rows = re.findall(r'<tr[^>]*>(.*?)</tr>', table_html, re.DOTALL)

    for row_html in rows:
        cells = re.findall(r'<t[dh][^>]*>(.*?)</t[dh]>', row_html, re.DOTALL)
        if len(cells) != 3:
            continue

        home_val = _strip_tags(cells[0]).strip()
        stat_name = _strip_tags(cells[1]).strip()
        away_val = _strip_tags(cells[2]).strip()

        # Skip header row (team names / "VS")
        if stat_name.upper() == 'VS':
            continue

        if stat_name:
            match_data['team_stats'].append({
                'stat': stat_name,
                'home': home_val,
                'away': away_val,
            })


def scrape_match_nationalities(match_url):
    """
    Scrape a match page for player nationalities.
    Match pages show nationality breakdown which can be used to infer
    player nationalities from lineup data.
    """
    html = fetch_html(match_url)
    if not html:
        return {}

    players = {}
    return players


def scrape_matches_from_careers(output_dir, delay=0.5, limit=None, force=False):
    """
    Scrape all.rugby match pages for match IDs referenced in saved career files.
    Saves individual match files to storage/app/allrugby/matches/.
    """
    careers_dir = output_dir / "careers"
    if not careers_dir.exists():
        print(f"Error: careers directory not found: {careers_dir}")
        print("Run: python3 scripts/allrugby_scraper.py --careers")
        return []

    match_ids = {}
    for career_file in sorted(careers_dir.glob("*.json")):
        try:
            with open(career_file) as f:
                data = json.load(f)
        except Exception:
            continue

        for entry in data.get("game_sheets", []):
            match_id = str(entry.get("match_id", "")).strip()
            if not match_id:
                continue
            match_ids[match_id] = {
                "match_slug": entry.get("match_slug"),
                "date": entry.get("date"),
            }

    ordered_ids = sorted(
        match_ids.keys(),
        key=lambda value: (0, int(value)) if value.isdigit() else (1, value)
    )

    if limit:
        ordered_ids = ordered_ids[:limit]

    print(f"Found {len(ordered_ids)} unique match IDs in {careers_dir}")

    matches_dir = output_dir / "matches"
    matches_dir.mkdir(exist_ok=True)

    saved = 0
    skipped = 0
    errors = 0

    for i, match_id in enumerate(ordered_ids):
        path = matches_dir / f"match_{match_id}.json"
        if path.exists() and not force:
            skipped += 1
            continue

        print(f"  [{i+1}/{len(ordered_ids)}] Match {match_id}...")
        match_data = scrape_match_page(
            match_id,
            match_slug=match_ids[match_id].get("match_slug"),
            delay=delay,
        )

        if not match_data:
            errors += 1
            print("    No data")
            time.sleep(delay)
            continue

        with open(path, "w") as f:
            json.dump(match_data, f, indent=2, default=str)

        saved += 1
        teams = [t.get("name") for t in match_data.get("teams", []) if t.get("name")]
        score = f"{match_data.get('home_score', '?')}-{match_data.get('away_score', '?')}"
        print(f"    Saved: {score} {' vs '.join(teams) if teams else ''}".rstrip())

        if (i + 1) % 50 == 0:
            print(f"  ** Progress: {i+1}/{len(ordered_ids)} ({saved} saved, {skipped} cached, {errors} errors) **")

        time.sleep(delay)

    print(f"\n{'=' * 50}")
    print(f"Exported {saved} match pages to {matches_dir}")
    print(f"  Cached: {skipped}, Errors: {errors}")
    print("Next step: php artisan rugby:import-allrugby-matches")

    return ordered_ids


def main():
    parser = argparse.ArgumentParser(description="Scrape all.rugby data")
    parser.add_argument("--squads", nargs="+", metavar="LEAGUE",
                        help="Scrape squads for league(s): urc, premiership, top14, super_rugby, internationals, all")
    parser.add_argument("--club", type=str, nargs="+",
                        help="Scrape specific club(s) by slug")
    parser.add_argument("--player", type=str,
                        help="Scrape a single player profile")
    parser.add_argument("--careers", action="store_true",
                        help="Scrape career stats for all players in squads.json (run --squads first)")
    parser.add_argument("--careers-limit", type=int, default=None,
                        help="Limit number of players to scrape careers for (for testing)")
    parser.add_argument("--matches-from-careers", action="store_true",
                        help="Scrape match pages for match IDs found in saved career files")
    parser.add_argument("--matches-limit", type=int, default=None,
                        help="Limit number of match pages to scrape (for testing)")
    parser.add_argument("--match", type=str,
                        help="Scrape a single match page by ID")
    parser.add_argument("--refresh-existing", action="store_true",
                        help="Re-fetch files even if they already exist")
    parser.add_argument("--with-nationality", action="store_true",
                        help="Also fetch nationality from player pages (slower, 1 req per player)")
    parser.add_argument("--list-leagues", action="store_true",
                        help="List available leagues")
    parser.add_argument("--delay", type=float, default=0.5,
                        help="Delay between requests (default: 0.5s)")
    args = parser.parse_args()

    if args.list_leagues:
        print("Available leagues:")
        for key, clubs in sorted(LEAGUES.items()):
            print(f"  {key:20s} ({len(clubs)} clubs): {', '.join(clubs[:5])}...")
        return

    output_dir = get_output_dir()
    print(f"Output directory: {output_dir}")

    if args.match:
        print(f"Fetching match: {args.match}")
        match_slug = find_match_slug_in_careers(output_dir, args.match)
        match_data = scrape_match_page(args.match, match_slug=match_slug, delay=args.delay)
        if match_data:
            matches_dir = output_dir / "matches"
            matches_dir.mkdir(exist_ok=True)
            path = matches_dir / f"match_{args.match}.json"
            with open(path, "w") as f:
                json.dump(match_data, f, indent=2, default=str)
            print(f"  Saved to {path}")
            teams = [t.get('name') for t in match_data.get('teams', [])]
            print(f"  Teams: {' vs '.join(teams) if teams else '?'}")
            print(f"  Score: {match_data.get('home_score', '?')}-{match_data.get('away_score', '?')}")
            print(f"  Date: {match_data.get('date', '?')} Kick Off: {match_data.get('kickoff', '?')}")
            print(f"  Venue: {match_data.get('venue', '?')}")
            print(f"  Tournament: {match_data.get('tournament', '?')} Round: {match_data.get('round', '?')}")
            print(f"  Home lineup: {len(match_data.get('lineups', {}).get('home', []))} players")
            print(f"  Away lineup: {len(match_data.get('lineups', {}).get('away', []))} players")
            print(f"  Events: {len(match_data.get('events', []))}")
            print(f"  Home subs: {len(match_data.get('substitutions', {}).get('home', []))}")
            print(f"  Away subs: {len(match_data.get('substitutions', {}).get('away', []))}")
            print(f"  Team stats: {len(match_data.get('team_stats', []))}")
            # Show events
            for e in match_data.get('events', []):
                print(f"    {e['minute']}' {e['type']:12s} {e['player_name']} ({e['side']})")
        else:
            print("  No data found")
        return

    if args.careers:
        squads_file = output_dir / "squads.json"
        if not squads_file.exists():
            print("Error: squads.json not found. Run --squads first to generate it.")
            return
        scrape_player_careers_from_squads(squads_file, output_dir, args.delay, args.careers_limit)
        return

    if args.player:
        print(f"Fetching player: {args.player}")
        career = scrape_player_career(args.player, args.delay)
        if career:
            nat = career.get("nationality")
            print(f"  Nationality: {nat or 'not found'}")
            print(f"  Overall stats: {len(career.get('overall_stats', []))}")
            print(f"  Game sheets: {len(career.get('game_sheets', []))}")
            print(f"  Career history: {len(career.get('career_history', []))}")

            # Print overall stats summary
            for entry in career.get("overall_stats", []):
                comp = entry.get("tournament", "?")
                team = entry.get("team", "?")
                matches = entry.get("matches", 0)
                tries = entry.get("tries", 0)
                pts = entry.get("points", 0)
                print(f"    {comp:30s} {team:20s} M:{matches} T:{tries} Pts:{pts}")

            path = output_dir / f"career_{args.player}.json"
            with open(path, "w") as f:
                json.dump(career, f, indent=2, default=str)
            print(f"  Saved to {path}")
        else:
            print("  No data found")

        # Also save raw HTML for inspection
        url = f"{BASE_URL}/player/{args.player}"
        html = fetch_html(url)
        if html:
            path = output_dir / f"player_{args.player}.html"
            with open(path, "w") as f:
                f.write(html)
            print(f"  Saved HTML to {path}")
        return

    if args.matches_from_careers:
        scrape_matches_from_careers(
            output_dir,
            delay=args.delay,
            limit=args.matches_limit,
            force=args.refresh_existing,
        )
        return

    # Determine clubs to scrape
    clubs_to_scrape = []

    if args.club:
        clubs_to_scrape = args.club
    elif args.squads:
        for league in args.squads:
            league = league.lower()
            if league == "all":
                for clubs in LEAGUES.values():
                    clubs_to_scrape.extend(clubs)
                break
            elif league in LEAGUES:
                clubs_to_scrape.extend(LEAGUES[league])
            else:
                print(f"Unknown league: {league}")
                print(f"Available: {', '.join(sorted(LEAGUES.keys()))}")
                return
        # Deduplicate while preserving order
        seen = set()
        deduped = []
        for c in clubs_to_scrape:
            if c not in seen:
                seen.add(c)
                deduped.append(c)
        clubs_to_scrape = deduped
    else:
        parser.print_help()
        print("\nQuick start:")
        print("  python3 scripts/allrugby_scraper.py --squads urc")
        print("  python3 scripts/allrugby_scraper.py --squads urc --with-nationality")
        print("  python3 scripts/allrugby_scraper.py --squads all")
        return

    # Scrape squads
    all_players = []
    errors = 0

    print(f"\nScraping {len(clubs_to_scrape)} clubs...")
    try:
        for i, club_slug in enumerate(clubs_to_scrape):
            players = scrape_club_squad(
                club_slug,
                fetch_nationality=args.with_nationality,
                delay=args.delay
            )
            if players:
                all_players.extend(players)
            else:
                errors += 1

            # Checkpoint every 10 clubs
            if (i + 1) % 10 == 0 and all_players:
                path = output_dir / "squads.json"
                with open(path, "w") as f:
                    json.dump(all_players, f, indent=2, default=str)
                print(f"  ** Checkpoint: {len(all_players)} players saved **")

            time.sleep(args.delay)

    except KeyboardInterrupt:
        print("\n  Interrupted! Saving partial results...")

    # Save final results
    path = output_dir / "squads.json"
    with open(path, "w") as f:
        json.dump(all_players, f, indent=2, default=str)

    # Summary
    club_counts = {}
    for p in all_players:
        c = p["club_slug"]
        club_counts[c] = club_counts.get(c, 0) + 1

    print(f"\n{'=' * 50}")
    print(f"Exported {len(all_players)} players to {path}")
    print(f"({errors} clubs had no data)")
    for club, count in sorted(club_counts.items()):
        print(f"  {club:25s} {count} players")

    if args.with_nationality:
        with_nat = sum(1 for p in all_players if p.get("nationality"))
        print(f"\nNationality data: {with_nat}/{len(all_players)} players")

    print(f"\nNext step: php artisan rugby:import-allrugby")


if __name__ == "__main__":
    main()
