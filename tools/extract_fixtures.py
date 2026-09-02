"""Extract fixture data and layout configuration from the saved archery.ie pages.

This is a one-off scraper. It reads the raw HTML in research/raw/ and writes two files:

  plugin/archery-records/data/fixtures.json  - sample submissions for the stub data source
  plugin/archery-records/config/layout.json  - which rounds appear on which page, and the
                                               expected class/bow grid for each round

The CURRENT record holders in fixtures.json are real, scraped from the live site.
The PREVIOUS holders are synthetic, invented here so that the "+" expansion has something
to show before we have access to the real database. They are deliberately named so that
nobody can mistake them for real people. See SYNTHETIC_SURNAME below.
"""

import re
import json
import html
import os
import hashlib

HERE = os.path.dirname(os.path.abspath(__file__))
RAW = os.path.join(HERE, '..', 'research', 'raw')
OUT_DATA = os.path.join(HERE, '..', 'plugin', 'archery-records', 'data')
OUT_CONF = os.path.join(HERE, '..', 'plugin', 'archery-records', 'config')

SYNTHETIC_SURNAME = 'Sample'
SYNTHETIC_CLUB = 'Example Archers'

PAGES = [
    ('page_indoor_individual', 'target-indoor-individual', 'Target Indoor Individual'),
    ('target-indoor-team', 'target-indoor-team', 'Target Indoor Team'),
    ('target-outdoor-individual', 'target-outdoor-individual', 'Target Outdoor Individual'),
    ('target-outdoor-team', 'target-outdoor-team', 'Target Outdoor Team'),
    ('records-field', 'records-field', 'Field'),
    ('3d-field', '3d-field', '3D Field'),
    ('archived-results', 'archived-results', 'Archived Records'),
]

MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

NBSP = ' '
ENDASH = '–'
EMDASH = '—'
RSQUO = '’'


def clean(s):
    """Strip tags and entities, normalise whitespace, drop the stray nbsp characters."""
    s = re.sub(r'<[^>]+>', ' ', s)
    s = html.unescape(s)
    s = s.replace(NBSP, ' ')
    s = re.sub(r'\s+', ' ', s).strip()
    return s


def slugify(s):
    s = s.lower()
    s = s.replace(ENDASH, '-').replace(EMDASH, '-').replace(RSQUO, '')
    s = re.sub(r'[^a-z0-9]+', '-', s)
    return re.sub(r'-+', '-', s).strip('-')


def parse_page(path):
    """Return an ordered list of (heading, section_heading_or_None, table_html)."""
    src = open(path, encoding='utf-8', errors='replace').read()

    items = []
    for m in re.finditer(r'<h3 class="elementor-heading-title[^"]*">(.*?)</h3>', src, re.S):
        items.append((m.start(), 'heading', clean(m.group(1))))
    for m in re.finditer(r'<table.*?</table>', src, re.S):
        items.append((m.start(), 'table', m.group(0)))
    items.sort(key=lambda x: x[0])

    out = []
    pending = None
    section = None
    for _, kind, value in items:
        if kind == 'heading':
            # A heading with no table before the next heading is a section marker, not a round.
            # Match only headings that START with "Archived" - one of the pages carries a note
            # mentioning the word "archived" mid-sentence, which must not flip the section.
            if pending is not None and re.match(r'archived\b', pending.strip(), re.I):
                section = pending.strip()
            pending = value
        else:
            if pending is None:
                continue
            out.append((pending, section, value))
    return out


def parse_table(table_html):
    """Return (column_headers, rows_as_lists)."""
    trs = re.findall(r'<tr[^>]*>(.*?)</tr>', table_html, re.S)
    if not trs:
        return [], []

    headers = [clean(c) for c in re.findall(r'<t[dh][^>]*>(.*?)</t[dh]>', trs[0], re.S)]
    if not headers:
        return [], []

    rows = []
    for tr in trs[1:]:
        cells = [clean(c) for c in re.findall(r'<t[dh][^>]*>(.*?)</t[dh]>', tr, re.S)]
        if not cells or not any(cells):
            continue
        cells += [''] * (len(headers) - len(cells))
        rows.append(cells[:len(headers)])
    return headers, rows


def shape_of(headers):
    low = [h.lower() for h in headers]
    archers = low.count('archer')
    if 'peg' in low:
        return 'peg', archers
    if 'category' in low:
        return 'category', archers
    return ('individual' if archers <= 1 else 'team'), archers


def classification_keys(headers):
    """Which leading columns describe the classification rather than the result."""
    keys = []
    for h in headers:
        hl = h.lower()
        if hl in ('peg', 'class', 'category'):
            keys.append(hl)
    return keys


def norm_score(s):
    s = s.replace(' ', '')
    return int(s) if re.fullmatch(r'\d+', s) else None


def synth_history(key, current_score, current_date):
    """Invent one to three earlier holders below the current score, deterministically."""
    if current_score is None:
        return []
    h = hashlib.sha256(key.encode()).digest()
    count = 1 + (h[0] % 3)

    year = 24
    m = re.search(r'-(\d{2})$', current_date or '')
    if m:
        year = int(m.group(1))
    elif re.fullmatch(r'\d{4}', (current_date or '').strip()):
        year = int(current_date.strip()) % 100

    out = []
    score = current_score
    for i in range(count):
        score = max(1, score - (1 + (h[i + 1] % 8)))
        year = (year - 1 - (h[i + 5] % 3)) % 100
        day = 1 + (h[i + 9] % 27)
        month = MONTHS[h[i + 13] % 12]
        out.append({
            'letter': chr(ord('A') + i),
            'score': score,
            'date': '%02d-%s-%02d' % (day, month, year),
        })
    return out


def main():
    layout_pages = []
    layout_rounds = {}
    submissions = []

    for filestem, page_slug, page_title in PAGES:
        path = os.path.join(RAW, filestem + '.html')
        if not os.path.exists(path):
            print('MISSING', path)
            continue

        page_rounds = []
        used_slugs = set()

        for heading, section, table_html in parse_page(path):
            headers, rows = parse_table(table_html)
            if not headers or not rows:
                continue

            archived = section is not None

            base = slugify(heading) or 'round'
            if archived:
                base += '-archived'
            slug = base
            n = 2
            while slug in used_slugs:
                slug = '%s-%d' % (base, n)
                n += 1
            used_slugs.add(slug)
            # Round keys are scoped by page: the same heading (e.g. "24 Targets Unmarked")
            # appears on more than one page and must not collide in the global round map.
            round_key = '%s/%s' % (page_slug, slug)

            shape, n_archers = shape_of(headers)
            ckeys = classification_keys(headers)
            lower_headers = [h.lower() for h in headers]
            grid = []
            carry = {}

            for cells in rows:
                row = dict(zip(lower_headers, cells))

                # A blank leading cell means "same as the row above".
                classification = {}
                for k in ckeys:
                    if row.get(k, ''):
                        carry[k] = row[k]
                    classification[k] = carry.get(k, '')

                bow = row.get('bow', '')
                archer_cells = [c for h, c in zip(lower_headers, cells) if h == 'archer']
                archers = [a for a in archer_cells if a]
                score = norm_score(row.get('score', ''))
                date = row.get('date', '')
                club = row.get('club', '')

                vacant = (not archers) or any(re.search(r'no current record', a, re.I) for a in archers)

                entry = dict(classification)
                entry['bow'] = bow
                grid.append(entry)

                if vacant or score is None:
                    continue

                key = '%s|%s|%s' % (round_key, json.dumps(classification, sort_keys=True), bow)
                digest = hashlib.sha1(key.encode()).hexdigest()[:12]

                submissions.append({
                    'id': 'live-' + digest,
                    'round': round_key,
                    'classification': classification,
                    'bow': bow,
                    'score': score,
                    'archers': archers,
                    'date': date,
                    'club': club,
                })

                for i, prev in enumerate(synth_history(key, score, date)):
                    submissions.append({
                        'id': 'synthetic-%s-%d' % (digest, i),
                        'round': round_key,
                        'classification': classification,
                        'bow': bow,
                        'score': prev['score'],
                        'archers': ['%s. %s' % (prev['letter'], SYNTHETIC_SURNAME)] * max(1, len(archers)),
                        'date': prev['date'],
                        'club': SYNTHETIC_CLUB,
                    })

            layout_rounds[round_key] = {
                'page': page_slug,
                'heading': heading,
                'archived': archived,
                'section': section,
                'shape': shape,
                'archer_columns': max(1, n_archers),
                'classification_keys': ckeys,
                'columns': headers,
                'grid': grid,
            }
            page_rounds.append(round_key)

        layout_pages.append({'slug': page_slug, 'title': page_title, 'rounds': page_rounds})

    os.makedirs(OUT_DATA, exist_ok=True)
    os.makedirs(OUT_CONF, exist_ok=True)

    with open(os.path.join(OUT_CONF, 'layout.json'), 'w', encoding='utf-8') as f:
        json.dump({'pages': layout_pages, 'rounds': layout_rounds}, f, indent=1, ensure_ascii=False)

    with open(os.path.join(OUT_DATA, 'fixtures.json'), 'w', encoding='utf-8') as f:
        json.dump({
            'note': 'Current holders are real, scraped from archery.ie on 2 September 2026. '
                    'Previous holders are SYNTHETIC placeholder data invented for the demo.',
            'submissions': submissions,
        }, f, indent=1, ensure_ascii=False)

    real = sum(1 for s in submissions if s['id'].startswith('live-'))
    print('pages  :', len(layout_pages))
    print('rounds :', len(layout_rounds))
    print('rows   :', sum(len(r['grid']) for r in layout_rounds.values()))
    print('subs   :', len(submissions), '(%d real, %d synthetic)' % (real, len(submissions) - real))


if __name__ == '__main__':
    main()
