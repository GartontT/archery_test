"""Build a static preview of the records pages, with no WordPress and no PHP.

This is a development tool, NOT part of the plugin. It mirrors what includes/render.php
produces and loads the plugin's real CSS and JavaScript, so the styling, the class tabs
and the "+" behaviour can be looked at and clicked before the plugin is installed
anywhere.

It is a mirror, not the real thing: if render.php changes, this has to change with it.
It exists only because there is no PHP runtime on this machine. Once there is one, the
right move is to render through the plugin itself and delete this file.

Run:  python tools/build_preview.py
Then serve the project root and open preview/index.html.
"""

import json
import os
import re
import html
import hashlib
from datetime import date

HERE = os.path.dirname(os.path.abspath(__file__))
PLUGIN = os.path.join(HERE, '..', 'plugin', 'archery-records')
PREVIEW = os.path.join(HERE, '..', 'preview')

MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
PIVOT = date.today().year % 100

PAGES = [
    ('target-indoor-individual', 'Target Indoor Individual'),
    ('target-indoor-team', 'Target Indoor Team'),
    ('target-outdoor-individual', 'Target Outdoor Individual'),
    ('target-outdoor-team', 'Target Outdoor Team'),
    ('records-field', 'Field'),
    ('3d-field', '3D Field'),
]

CLASS_RANK = {
    'Gents': 10, 'Ladies': 11, 'Mixed': 12,
    '50+ Men': 20, '50+ Women': 21, '50+ Mixed': 22,
    'U21 Gents': 30, 'U21 Ladies': 31, 'U21 Mixed': 32,
    'U18 Gents': 40, 'U18 Ladies': 41, 'U18 Mixed': 42,
    'U15 Gents': 50, 'U15 Ladies': 51, 'U15 Mixed': 52,
}
BOW_RANK = {'Compound': 10, 'Recurve': 20, 'Barebow': 30,
            'Traditional': 40, 'Instinctive': 50, 'Longbow': 60}
PEG_RANK = {'Red Peg': 10, 'Blue Peg': 20, 'White Peg': 30, 'Yellow Peg': 40}

LABELS = {'round': 'Round', 'peg': 'Peg', 'score': 'Score',
          'archer': 'Archer', 'date': 'Date', 'club': 'Club'}


def esc(value):
    return html.escape(str(value), quote=True)


def tidy(value):
    return re.sub(r'\s+', ' ', str(value).replace('\xa0', ' ')).strip()


def slug(value):
    return re.sub(r'-+', '-', re.sub(r'[^a-z0-9]+', '-', str(value).lower())).strip('-')


def record_key(round_key, classification, bow):
    parts = {k.lower().strip(): tidy(v) for k, v in (classification or {}).items()}
    return '%s|%s|%s' % (round_key, '|'.join(parts[k] for k in sorted(parts)), tidy(bow))


def date_sort_key(value):
    value = str(value).strip()
    if not value:
        return 0
    m = re.fullmatch(r'(\d{4})-(\d{2})-(\d{2})', value)
    if m:
        return int(m.group(1) + m.group(2) + m.group(3))
    m = re.fullmatch(r'(\d{1,2})[-/ ]([A-Za-z]{3,})[-/ ](\d{2}|\d{4})', value)
    if m:
        key = m.group(2)[:3].capitalize()
        if key in MONTHS:
            year = int(m.group(3))
            if len(m.group(3)) == 2:
                year = 2000 + year if year <= PIVOT else 1900 + year
            return int('%04d%02d%02d' % (year, MONTHS.index(key) + 1, int(m.group(1))))
    m = re.fullmatch(r'(\d{4})', value)
    if m:
        return int(m.group(1) + '0000')
    return 0


def format_date(value):
    m = re.fullmatch(r'(\d{4})-(\d{2})-(\d{2})', str(value).strip())
    if m:
        return '%s-%s-%s' % (m.group(3), MONTHS[int(m.group(2)) - 1], m.group(1)[2:])
    return value


def is_vacant(s):
    return s['score'] == 0 and not s['archers']


def build_progressions(submissions, ties_take_record=False):
    grouped = {}
    for s in submissions:
        if is_vacant(s):
            continue
        grouped.setdefault(record_key(s['round'], s['classification'], s['bow']), []).append(s)

    progressions = {}
    for key, entries in grouped.items():
        entries.sort(key=lambda e: (date_sort_key(e['date']), e['score'], str(e['id'])))
        prog, best = [], None
        for e in entries:
            if best is None or (e['score'] >= best if ties_take_record else e['score'] > best):
                prog.append(e)
                best = e['score']
        progressions[key] = list(reversed(prog))
    return progressions


def columns_for(has_peg, archers, show_history):
    cols = ['round'] + (['peg'] if has_peg else []) + ['score']
    cols += ['archer'] * max(1, archers)
    cols += ['date', 'club']
    if show_history:
        cols.append('toggle')
    return cols


def cells(entry, columns, row, previous_round, is_history):
    archer_index = 0
    out = []
    for column in columns:
        if column == 'toggle':
            continue
        classes, value = [], ''

        if column == 'round':
            value = entry['heading']
            if is_history or (previous_round is not None and previous_round == value):
                classes.append('archery-records-repeat')
        elif column == 'peg':
            value = entry['peg']
            if is_history:
                classes.append('archery-records-repeat')
        elif column == 'score':
            value = str(row['score'])
            classes.append('archery-records-score')
        elif column == 'archer':
            archers = row['archers']
            value = archers[archer_index] if archer_index < len(archers) else ''
            archer_index += 1
        elif column == 'date':
            value = format_date(row['date'])
        elif column == 'club':
            value = row['club']

        cls = ' class="%s"' % ' '.join(classes) if classes else ''
        out.append('<td%s>%s</td>' % (cls, esc(value)))
    return ''.join(out)


def vacant_row(entry, columns, previous_round, stripe):
    leading = 0
    for c in columns:
        if c in ('round', 'peg'):
            leading += 1
        else:
            break

    out = ['<tr class="archery-records-row archery-records-vacant%s">' % stripe]
    for column in columns[:leading]:
        if column == 'peg':
            value, repeats = entry['peg'], False
        else:
            value = entry['heading']
            repeats = previous_round is not None and previous_round == value
        out.append('<td%s>%s</td>' % (' class="archery-records-repeat"' if repeats else '', esc(value)))

    out.append('<td class="archery-records-none" colspan="%d">no current record</td>'
               % max(1, len(columns) - leading))
    out.append('</tr>')
    return ''.join(out)


def render_entry(entry, columns, show_history, previous_round, index):
    stripe = ' archery-records-row--alt' if index % 2 == 1 else ''
    if not entry['progression']:
        return vacant_row(entry, columns, previous_round, stripe)

    row_id = 'ar-' + hashlib.md5(entry['key'].encode()).hexdigest()[:12]
    history = entry['progression'][1:] if show_history else []
    ids = ['%s-h%d' % (row_id, i + 1) for i in range(len(history))]

    out = ['<tr class="archery-records-row%s">' % stripe]
    out.append(cells(entry, columns, entry['progression'][0], previous_round, False))
    if 'toggle' in columns:
        out.append('<td class="archery-records-toggle-col">')
        if history:
            out.append(
                '<button type="button" class="archery-records-toggle" aria-expanded="false" '
                'aria-controls="%s"><span class="archery-records-toggle-icon" aria-hidden="true"></span>'
                '<span class="archery-records-sr">Show %d previous holder%s</span></button>'
                % (esc(' '.join(ids)), len(history), '' if len(history) == 1 else 's'))
        out.append('</td>')
    out.append('</tr>')

    for i, row in enumerate(history):
        out.append('<tr class="archery-records-history" id="%s" hidden>' % esc(ids[i]))
        out.append(cells(entry, columns, row, None, True))
        if 'toggle' in columns:
            out.append('<td class="archery-records-toggle-col"></td>')
        out.append('</tr>')

    return ''.join(out)


def render_table(entries):
    has_peg = any(e['peg'] for e in entries)
    archers = max([len(r['archers']) for e in entries for r in e['progression']] or [1])
    show_history = any(len(e['progression']) > 1 for e in entries)
    columns = columns_for(has_peg, archers, show_history)

    out = ['<div class="archery-records-scroller">']
    out.append('<table class="archery-records-table"><thead><tr>')
    for column in columns:
        if column == 'toggle':
            out.append('<th scope="col" class="archery-records-toggle-col">'
                       '<span class="archery-records-sr">Previous holders</span></th>')
        else:
            out.append('<th scope="col">%s</th>' % esc(LABELS[column]))
    out.append('</tr></thead><tbody>')

    previous_round, archived_seen = None, False
    for i, entry in enumerate(entries):
        if entry['archived'] and not archived_seen:
            out.append('<tr class="archery-records-divider"><td colspan="%d">No longer shot for</td></tr>'
                       % len(columns))
            archived_seen, previous_round = True, None
        out.append(render_entry(entry, columns, show_history, previous_round, i))
        previous_round = entry['heading']

    out.append('</tbody></table></div>')
    return ''.join(out)


def make_entry(round_key, round_def, key, sample, progression):
    return {
        'key': key,
        'heading': round_def['heading'],
        'order': round_def.get('order', 9999),
        'archived': bool(round_def.get('archived')),
        'class': sample['classification'].get('class', ''),
        'peg': sample['classification'].get('peg', ''),
        'bow': sample['bow'],
        'progression': progression,
    }


def entry_sort_key(e):
    return (1 if e['archived'] else 0, e['order'], PEG_RANK.get(e['peg'], 900), e['heading'])


def build_page(page_slug, rounds, progressions, vacancies):
    """Return (html, table_count) for one page."""
    page_rounds = {k: v for k, v in rounds.items() if v.get('page') == page_slug}

    entries, seen = [], set()
    for round_key, round_def in page_rounds.items():
        prefix = round_key + '|'
        for key, prog in progressions.items():
            if key.startswith(prefix) and key not in seen:
                seen.add(key)
                entries.append(make_entry(round_key, round_def, key, prog[0], prog))
        for key, vac in vacancies.items():
            if key.startswith(prefix) and key not in seen:
                seen.add(key)
                entries.append(make_entry(round_key, round_def, key, vac, []))

    # Bow first, then class: each bow gets its own tab strip, so two bows can show
    # different classes at the same time.
    bows = {}
    for e in entries:
        bows.setdefault(e['bow'], {}).setdefault(e['class'], []).append(e)

    for bow in list(bows):
        for cls in list(bows[bow]):
            if not any(e['progression'] for e in bows[bow][cls]):
                del bows[bow][cls]
            else:
                bows[bow][cls].sort(key=entry_sort_key)
        if not bows[bow]:
            del bows[bow]

    ordered_bows = sorted(bows, key=lambda b: (BOW_RANK.get(b, 900), b))
    if not ordered_bows:
        return '', 0

    page_id = 'ar-' + hashlib.md5(page_slug.encode()).hexdigest()[:8]

    out = ['<div class="archery-records-page" data-page="%s">' % esc(page_slug)]
    out.append('<noscript><style>'
               '.archery-records-page .archery-records-tabs{display:none}'
               '.archery-records-page .archery-records-panel[hidden]{display:block !important}'
               '.archery-records-page .archery-records-history{display:table-row !important}'
               '.archery-records-page .archery-records-toggle{display:none}'
               '</style></noscript>')

    tables = 0
    for bow in ordered_bows:
        bow_id = '%s-%s' % (page_id, slug(bow))
        ordered_classes = sorted(bows[bow], key=lambda c: (CLASS_RANK.get(c, 900), c))

        out.append('<div class="archery-records-bow">')
        out.append('<h3 class="archery-records-bow-heading">%s</h3>' % esc(bow))

        out.append('<div class="archery-records-tabs" role="tablist" aria-label="%s record class">'
                   % esc(bow))
        for i, cls in enumerate(ordered_classes):
            out.append('<button type="button" role="tab" class="archery-records-tab" id="%s-tab-%s" '
                       'aria-controls="%s-panel-%s" aria-selected="%s" tabindex="%s">%s</button>'
                       % (bow_id, slug(cls), bow_id, slug(cls),
                          'true' if i == 0 else 'false', '0' if i == 0 else '-1', esc(cls)))
        out.append('</div>')

        for i, cls in enumerate(ordered_classes):
            out.append('<section class="archery-records-panel" role="tabpanel" id="%s-panel-%s" '
                       'aria-labelledby="%s-tab-%s"%s>'
                       % (bow_id, slug(cls), bow_id, slug(cls), '' if i == 0 else ' hidden'))
            out.append('<h4 class="archery-records-class-heading">%s</h4>' % esc(cls))
            out.append(render_table(bows[bow][cls]))
            tables += 1
            out.append('</section>')

        out.append('</div>')

    out.append('</div>')
    return ''.join(out), tables


PAGE_TEMPLATE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} - preview</title>
<link rel="stylesheet" href="../plugin/archery-records/assets/archery-records.css?v={assets}">
<style>
 body {{ font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0;
        padding: 1.5rem 2rem 4rem; color: #1c1e21; background: #fff; max-width: 1100px; }}
 .preview-banner {{ padding: .8rem 1rem; margin-bottom: 1.5rem; border-left: 4px solid #2f6f3e;
        background: #f0f7f1; font-size: .9rem; line-height: 1.5; }}
 .preview-nav {{ margin-bottom: 1.5rem; font-size: .9rem; }}
 .preview-nav a {{ margin-right: 1rem; }}
</style>
</head>
<body>
<div class="preview-banner">
 <strong>Preview.</strong> Built by <code>tools/build_preview.py</code> from a real export of the
 Archery Ireland records database taken on 7 September 2026. Every row, and everything behind a
 "+", is real data. This is a static rendering of the plugin's markup, not the live site.
</div>
<div class="preview-nav">{nav}</div>
<h1>{title}</h1>
{body}
<script src="../plugin/archery-records/assets/archery-records.js?v={assets}"></script>
</body>
</html>
"""


def main():
    rounds = json.load(open(os.path.join(PLUGIN, 'config', 'rounds.json'), encoding='utf-8'))
    fixtures = json.load(open(os.path.join(PLUGIN, 'data', 'fixtures.json'), encoding='utf-8'))
    submissions = fixtures['submissions']

    progressions = build_progressions(submissions)
    vacancies = {record_key(s['round'], s['classification'], s['bow']): s
                 for s in submissions if is_vacant(s)}

    # Stamp the asset links with the files' modification times. Without this the
    # browser happily serves a cached copy of the JavaScript from before the tabs
    # existed, and the tabs then appear to do nothing at all.
    assets_dir = os.path.join(PLUGIN, 'assets')
    assets = str(int(max(os.path.getmtime(os.path.join(assets_dir, name))
                         for name in ('archery-records.css', 'archery-records.js'))))

    os.makedirs(PREVIEW, exist_ok=True)
    nav = ' '.join('<a href="%s.html">%s</a>' % (p, esc(t)) for p, t in PAGES)

    totals = {}
    for page_slug, page_title in PAGES:
        body, tables = build_page(page_slug, rounds, progressions, vacancies)
        totals[page_slug] = tables
        with open(os.path.join(PREVIEW, page_slug + '.html'), 'w', encoding='utf-8') as f:
            f.write(PAGE_TEMPLATE.format(title=esc(page_title), nav=nav, body=body, assets=assets))

    index = ['<!doctype html><html lang="en"><head><meta charset="utf-8">',
             '<meta name="viewport" content="width=device-width, initial-scale=1">',
             '<title>Irish Records preview</title></head><body>',
             '<h1>Irish Records - plugin preview</h1>',
             '<p>Real data, exported 7 September 2026.</p><ul>']
    for page_slug, title in PAGES:
        index.append('<li><a href="%s.html">%s</a> - %d tables</li>'
                     % (page_slug, esc(title), totals[page_slug]))
    index.append('</ul></body></html>')
    with open(os.path.join(PREVIEW, 'index.html'), 'w', encoding='utf-8') as f:
        f.write('\n'.join(index))

    with_history = sum(1 for p in progressions.values() if len(p) > 1)
    print('pages          :', len(PAGES))
    print('tables         :', sum(totals.values()))
    print('records        :', len(progressions))
    print('with history   :', with_history)
    print('vacant slots   :', len(vacancies))
    for page_slug, title in PAGES:
        print('   %-28s %3d tables' % (page_slug, totals[page_slug]))


if __name__ == '__main__':
    main()
