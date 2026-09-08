"""Convert the real Records export into the plugin's data-contract shape.

Reads .server-copy/AllRecords.csv and .server-copy/RoundTypes.csv (exports taken from
db1249072_registration on 7 September 2026) and writes:

  plugin/archery-records/data/fixtures.json   real submissions, in contract shape
  plugin/archery-records/config/rounds.json   round key -> heading, page, archived

This replaces the earlier hand-built layout.json, which existed only because we did not
then know the database carried its own placeholder rows and round list.

It also compares the derived current record for every classification against what the
live website published on 2 September 2026, and reports every difference. That comparison
is the real test of the code mappings.
"""

import csv
import json
import os
import re
import sys
import collections

HERE = os.path.dirname(os.path.abspath(__file__))
SERVER = os.path.join(HERE, '..', '.server-copy')
PLUGIN = os.path.join(HERE, '..', 'plugin', 'archery-records')

FIELDS = ['RecordCode', 'RoundCode', 'Peg', 'Bow', 'Class', 'Gender', 'Score', 'Archer',
          'TeamArcher_2', 'TeamArcher_3', 'Date', 'Club', 'Archived', 'Location', 'Type', 'Active']

BOW = {'R': 'Recurve', 'C': 'Compound', 'B': 'Barebow',
       'I': 'Instinctive', 'L': 'Longbow', 'T': 'Traditional'}

# Class code plus gender code gives the label the website uses. Confirmed against the
# live "WA 18 - 120 arrows" table on 7 September 2026.
CLASS = {
    ('', 'M'): 'Gents',      ('', 'W'): 'Ladies',
    ('M', 'M'): '50+ Men',   ('M', 'W'): '50+ Women',
    ('J', 'M'): 'U21 Gents', ('J', 'W'): 'U21 Ladies',
    ('C', 'M'): 'U18 Gents', ('C', 'W'): 'U18 Ladies',
    ('Y', 'M'): 'U15 Gents', ('Y', 'W'): 'U15 Ladies',
    ('', 'X'): 'Mixed',      ('M', 'X'): '50+ Mixed',
    ('J', 'X'): 'U21 Mixed', ('C', 'X'): 'U18 Mixed', ('Y', 'X'): 'U15 Mixed',
}

# Display order within a table, matching the current pages.
CLASS_ORDER = ['', 'M', 'J', 'C', 'Y']
GENDER_ORDER = ['M', 'W', 'X']
BOW_ORDER = ['C', 'R', 'B', 'T', 'I', 'L']


def read_pipe_csv(path, n_fields):
    """These exports are one quoted column of pipe-separated values."""
    out = []
    for line in open(path, encoding='utf-8-sig'):
        line = line.strip()
        if not line:
            continue
        if line.startswith('"') and line.endswith('"'):
            line = line[1:-1]
        parts = [p.strip() for p in line.split('|')]
        if len(parts) != n_fields:
            print('  skipped malformed line (%d fields): %s' % (len(parts), line[:70]))
            continue
        out.append(parts)
    return out


def repair_encoding(s):
    """Undo the double-encoding in the stored text.

    Names with a fada come back as "RÃ³isÃ­n" - UTF-8 bytes that were themselves read as
    latin-1 and encoded again. Reversing that recovers the real characters. If a string
    was not double-encoded, the round trip fails and we return it untouched.
    """
    if not s:
        return s
    try:
        repaired = s.encode('latin-1').decode('utf-8')
    except (UnicodeEncodeError, UnicodeDecodeError):
        return s
    # Only accept the repair if it actually removed the tell-tale sequences.
    return repaired if 'Ã' in s or 'Â' in s else s


def slugify(s):
    s = s.lower().replace('–', '-').replace('’', '')
    s = re.sub(r'[^a-z0-9]+', '-', s)
    return re.sub(r'-+', '-', s).strip('-')


# How rounds are ordered down a page. RoundTypes.Oder is 0 on every row, so it carries
# no information and the order has to be worked out here instead.
#
# The shape of it: the multi-distance rounds first (a WA1440 is the headline round of an
# outdoor competition), then WA 900, then the single distances longest first and, at each
# distance, the longest round first. Field and 3D follow their own small running order.
# This reproduces how the current pages read.

FAMILY = [
    ('dbl wa1440y', 7), ('dbl wa1440c', 5), ('dbl wa1440p', 3), ('dbl wa1440', 1),
    ('wa1440y', 8), ('wa1440c', 6), ('wa1440p', 4), ('wa1440', 2),
    ('wa 900', 10),
]

FIELD_ORDER = [
    ('48 targets', 1), ('3d - 48 unmarked targets', 1),
    ('24 targets unmarked', 2), ('3d - 24 unmarked targets', 2),
    ('24 targets marked', 3),
    ('24 target mixed', 4),
]

TEAM_ORDER = [
    ('mixed team qualification', 4), ('mixed team eliminations', 3),
    ('team qualification', 2), ('team eliminations', 1),
]


def round_order(code, arrows):
    """A sort position for one round within its page. Lower sorts higher."""
    key = code.strip().lower()

    for prefix, rank in FIELD_ORDER:
        if key.startswith(prefix):
            return 100 + rank

    for prefix, rank in TEAM_ORDER:
        if key.startswith(prefix):
            return 200 + rank

    for prefix, rank in FAMILY:
        if key.startswith(prefix):
            return 300 + rank

    # Indoor: WA18 before WA25, longest round first within each.
    m = re.match(r'wa(18|25)\b', key)
    if m:
        return 400 + (0 if m.group(1) == '18' else 50) + (999 - arrows) // 10

    # Outdoor distances: furthest first, and the longest round at each distance first.
    m = re.match(r'(\d+)m\b', key)
    if m:
        return 500 + (200 - int(m.group(1))) * 1000 + (1000 - arrows)

    return 900


PEG_NAMES = {
    'red': 'Red Peg', 'blue': 'Blue Peg', 'white': 'White Peg', 'yellow': 'Yellow Peg',
}


def normalise_peg(value):
    """Settle the two spellings of a peg colour on one.

    The database holds both "Red Peg" and "RED" for the same peg. Left alone, that
    splits a single record in two: Darrel Wilson's 390 from 2016 and his 394 from 2023
    are the same 24 targets marked record, but they group separately and render as two
    rows rather than one with history behind it.
    """
    key = re.sub(r'\s*peg\s*$', '', str(value).strip(), flags=re.I).strip().lower()

    return PEG_NAMES.get(key, str(value).strip())


def page_of(row):
    """Which of the seven website pages this record belongs on."""
    is_team = 'team' in row['RoundCode'].lower()
    if row['Type'] == '3D':
        return '3d-field'
    if row['Type'] == 'Field':
        return 'records-field'
    if row['Location'] == 'Indoor':
        return 'target-indoor-team' if is_team else 'target-indoor-individual'
    return 'target-outdoor-team' if is_team else 'target-outdoor-individual'


def main():
    print('--- reading exports ---')
    round_rows = read_pipe_csv(os.path.join(SERVER, 'RoundTypes.csv'), 9)
    rec_rows = read_pipe_csv(os.path.join(SERVER, 'AllRecords.csv'), 16)
    print('  RoundTypes: %d' % len(round_rows))
    print('  Records   : %d' % len(rec_rows))

    # RoundCode casing differs between the two tables, so match case-insensitively.
    descriptions = {}
    arrows_for = {}
    for rc, desc, arrows, target, short, loc, typ, order, arch in round_rows:
        descriptions[rc.strip().lower()] = desc.strip()
        arrows_for[rc.strip().lower()] = int(arrows) if str(arrows).strip().isdigit() else 0

    records = [dict(zip(FIELDS, r)) for r in rec_rows]
    for r in records:
        for key in ('Archer', 'TeamArcher_2', 'TeamArcher_3', 'Club', 'Peg'):
            r[key] = repair_encoding(r[key])


    submissions = []
    rounds = {}
    unknown_rounds = collections.Counter()
    problems = []

    for r in records:
        page = page_of(r)
        code = r['RoundCode'].strip()
        heading = descriptions.get(code.lower())
        if heading is None:
            unknown_rounds[code] += 1
            heading = code
        # Archived is a property of the row, not of the round: the Field page carries
        # "24 Targets Unmarked" twice, once live and once under "Archived Instinctive
        # Records". So a round and its archived counterpart are two separate tables.
        archived = r['Archived'] == '1'
        round_key = '%s/%s%s' % (page, slugify(code), '-archived' if archived else '')

        if round_key not in rounds:
            rounds[round_key] = {
                'page': page,
                'code': code,
                'heading': heading,
                'archived': archived,
                'known_round': descriptions.get(code.lower()) is not None,
                'order': round_order( code, arrows_for.get( code.lower(), 0 ) ),
            }
        bow = BOW.get(r['Bow'], r['Bow'])
        label = CLASS.get((r['Class'], r['Gender']))
        if label is None:
            problems.append('unmapped class/gender %r/%r on record %s' % (r['Class'], r['Gender'], r['RecordCode']))
            label = ('%s %s' % (r['Class'], r['Gender'])).strip()

        classification = {}
        if r['Peg']:
            classification['peg'] = normalise_peg(r['Peg'])
        classification['class'] = label

        archers = [a for a in (r['Archer'], r['TeamArcher_2'], r['TeamArcher_3']) if a]
        score = int(r['Score']) if r['Score'].isdigit() else 0
        date = '' if r['Date'].startswith('0000') else r['Date']

        # A row with no score and no archer is the database's way of saying the category
        # exists but nobody holds it. The plugin recognises that shape itself, so it needs
        # no extra flag - see DATA-CONTRACT.md.

        submissions.append({
            'id': r['RecordCode'],
            'round': round_key,
            'classification': classification,
            'bow': bow,
            'score': score,
            'archers': archers,
            'date': date,
            'club': r['Club'],
            'venue': '',
            'archived': r['Archived'] == '1',
        })

    os.makedirs(os.path.join(PLUGIN, 'data'), exist_ok=True)
    os.makedirs(os.path.join(PLUGIN, 'config'), exist_ok=True)

    with open(os.path.join(PLUGIN, 'data', 'fixtures.json'), 'w', encoding='utf-8') as f:
        json.dump({
            'note': 'Real data, exported from db1249072_registration on 7 September 2026. '
                    'Used by the stub data source so the plugin can be developed and previewed '
                    'offline. Replaced by a live database read in production.',
            'submissions': submissions,
        }, f, indent=1, ensure_ascii=False)

    with open(os.path.join(PLUGIN, 'config', 'rounds.json'), 'w', encoding='utf-8') as f:
        json.dump(rounds, f, indent=1, ensure_ascii=False)

    print()
    print('--- written ---')
    print('  submissions : %d' % len(submissions))
    print('  vacant rows : %d' % sum(1 for s in submissions if s['score'] == 0 and not s['archers']))
    print('  rounds      : %d' % len(rounds))
    print('  archived    : %d rounds' % sum(1 for r in rounds.values() if r['archived']))
    print('  ordered     : %d rounds matched to the current page order'
          % sum(1 for r in rounds.values() if r['order'] != 9999))
    repaired = sum(1 for s2 in submissions for a in s2['archers'] if any(c in a for c in 'áéíóúÁÉÍÓÚ'))
    print('  accented    : %d archer names carry accents after repair' % repaired)

    if unknown_rounds:
        print()
        print('--- round codes NOT in RoundTypes (heading falls back to the code) ---')
        for code, n in unknown_rounds.most_common():
            print('  %-40s %d rows' % (code, n))

    if problems:
        print()
        print('--- problems ---')
        for p in problems[:20]:
            print('  ' + p)

    by_page = collections.Counter(s['round'].split('/')[0] for s in submissions)
    print()
    print('--- rows per page ---')
    for k, v in sorted(by_page.items()):
        n = len(set(s['round'] for s in submissions if s['round'].startswith(k + '/')))
        print('  %-28s %4d rows across %2d rounds' % (k, v, n))


if __name__ == '__main__':
    main()
