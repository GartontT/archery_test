"""Build a static preview of the records pages, with no WordPress and no PHP.

This is a development tool, NOT part of the plugin. It mirrors the markup that
includes/render.php produces and loads the plugin's real CSS and JavaScript, so
that the styling and the "+" behaviour can be looked at and clicked before the
plugin is installed anywhere.

It deliberately re-implements the record-progression logic rather than importing
it, which also gives a second opinion on that logic: tools/check_logic.py
compares the two.

Run:  python tools/build_preview.py
Then open preview/index.html in a browser.
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

MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec']
PIVOT = date.today().year % 100


def esc(value):
    return html.escape(str(value), quote=True)


def tidy(value):
    value = str(value).replace('\xa0', ' ')
    return re.sub(r'\s+', ' ', value).strip()


def record_key(round_key, classification, bow):
    parts = {k.lower().strip(): tidy(v) for k, v in (classification or {}).items()}
    ordered = [parts[k] for k in sorted(parts)]
    return '%s|%s|%s' % (round_key, '|'.join(ordered), tidy(bow))


def date_sort_key(value):
    """Mirror of archery_records_date_sort_key() in includes/records.php."""
    value = str(value).strip()
    if not value:
        return 0

    m = re.fullmatch(r'(\d{4})-(\d{2})-(\d{2})', value)
    if m:
        return int(m.group(1) + m.group(2) + m.group(3))

    m = re.fullmatch(r'(\d{1,2})[-/ ]([A-Za-z]{3,})[-/ ](\d{2}|\d{4})', value)
    if m:
        key = m.group(2)[:3].lower()
        if key in MONTHS:
            month = MONTHS.index(key) + 1
            year = int(m.group(3))
            if len(m.group(3)) == 2:
                year = 2000 + year if year <= PIVOT else 1900 + year
            return int('%04d%02d%02d' % (year, month, int(m.group(1))))

    m = re.fullmatch(r'(\d{4})', value)
    if m:
        return int(m.group(1) + '0000')

    return 0


def build_progressions(submissions, ties_take_record=False):
    """Mirror of archery_records_build_progressions()."""
    grouped = {}
    for s in submissions:
        grouped.setdefault(record_key(s['round'], s['classification'], s['bow']), []).append(s)

    progressions = {}
    for key, entries in grouped.items():
        entries.sort(key=lambda e: (date_sort_key(e['date']), e['score'], e['id']))
        progression, best = [], None
        for entry in entries:
            beats = best is None or (entry['score'] >= best if ties_take_record else entry['score'] > best)
            if beats:
                progression.append(entry)
                best = entry['score']
        progressions[key] = list(reversed(progression))
    return progressions


def cells(round_def, columns, classification, bow, entry, previous, is_history):
    ckeys = round_def.get('classification_keys', [])
    archer_index = 0
    out = []

    for label in columns:
        if label == '':
            continue
        field = label.lower()
        classes, value = [], ''

        if field in ckeys:
            value = classification.get(field, '')
            if is_history or (previous is not None and previous.get(field) == value):
                classes.append('archery-records-repeat')
        elif field == 'bow':
            value = bow
            if is_history:
                classes.append('archery-records-repeat')
        elif field == 'score':
            value = str(entry['score'])
            classes.append('archery-records-score')
        elif field == 'archer':
            archers = entry.get('archers', [])
            value = archers[archer_index] if archer_index < len(archers) else ''
            archer_index += 1
        elif field == 'date':
            value = entry.get('date', '')
        elif field == 'club':
            value = entry.get('club', '')
        elif field in ('venue', 'place'):
            value = entry.get('venue', '')

        cls = ' class="%s"' % ' '.join(classes) if classes else ''
        out.append('<td%s>%s</td>' % (cls, esc(value)))

    return ''.join(out)


def vacant_row(round_def, columns, classification, bow, previous, stripe):
    ckeys = round_def.get('classification_keys', [])
    leading = 0
    for label in columns:
        field = label.lower()
        if field in ckeys or field == 'bow':
            leading += 1
        else:
            break

    out = ['<tr class="archery-records-row archery-records-vacant%s">' % stripe]
    for label in columns[:leading]:
        field = label.lower()
        value = bow if field == 'bow' else classification.get(field, '')
        repeats = field != 'bow' and previous is not None and previous.get(field) == value
        cls = ' class="archery-records-repeat"' if repeats else ''
        out.append('<td%s>%s</td>' % (cls, esc(value)))

    out.append('<td class="archery-records-none" colspan="%d">no current record</td>'
               % max(1, len(columns) - leading))
    out.append('</tr>')
    return ''.join(out)


def render_record(key, round_def, columns, classification, bow, progression, previous, row_index):
    row_id = 'ar-' + hashlib.md5(key.encode()).hexdigest()[:12]
    stripe = ' archery-records-row--alt' if row_index % 2 == 1 else ''

    if not progression:
        return vacant_row(round_def, columns, classification, bow, previous, stripe)

    history = progression[1:]
    history_ids = ['%s-h%d' % (row_id, i + 1) for i in range(len(history))]

    out = ['<tr class="archery-records-row%s">' % stripe]
    out.append(cells(round_def, columns, classification, bow, progression[0], previous, False))
    out.append('<td class="archery-records-toggle-col">')
    if history:
        out.append(
            '<button type="button" class="archery-records-toggle" aria-expanded="false" '
            'aria-controls="%s"><span class="archery-records-toggle-icon" aria-hidden="true"></span>'
            '<span class="archery-records-sr">Show %d previous holder%s</span></button>'
            % (esc(' '.join(history_ids)), len(history), '' if len(history) == 1 else 's')
        )
    out.append('</td></tr>')

    for i, entry in enumerate(history):
        out.append('<tr class="archery-records-history" id="%s" hidden>' % esc(history_ids[i]))
        out.append(cells(round_def, columns, classification, bow, entry, None, True))
        out.append('<td class="archery-records-toggle-col"></td></tr>')

    return ''.join(out)


def render_round(round_key, round_def, progressions, used_keys):
    columns = list(round_def['columns']) + ['']
    ckeys = round_def.get('classification_keys', [])
    heading_id = 'ar-' + hashlib.md5(round_key.encode()).hexdigest()[:10]

    out = ['<h3 class="archery-records-heading" id="%s">%s</h3>' % (heading_id, esc(round_def['heading']))]
    out.append('<div class="archery-records-scroller">')
    out.append('<table class="archery-records-table" aria-labelledby="%s"><thead><tr>' % heading_id)
    for label in columns:
        if label == '':
            out.append('<th scope="col" class="archery-records-toggle-col">'
                       '<span class="archery-records-sr">Previous holders</span></th>')
        else:
            out.append('<th scope="col">%s</th>' % esc(label))
    out.append('</tr></thead><tbody>')

    previous_row, row_index = None, 0

    for grid_row in round_def['grid']:
        classification = {k: grid_row.get(k, '') for k in ckeys}
        bow = grid_row.get('bow', '')
        key = record_key(round_key, classification, bow)
        if key in used_keys:
            continue
        used_keys.add(key)

        this_row = dict(classification, bow=bow)
        out.append(render_record(key, round_def, columns, classification, bow,
                                 progressions.get(key, []), previous_row, row_index))
        previous_row = this_row
        row_index += 1

    prefix = round_key + '|'
    for key, progression in progressions.items():
        if key in used_keys or not key.startswith(prefix):
            continue
        used_keys.add(key)
        current = progression[0]
        classification = {k: current['classification'].get(k, '') for k in ckeys}
        out.append(render_record(key, round_def, columns, classification, current['bow'],
                                 progression, None, row_index))
        row_index += 1

    out.append('</tbody></table></div>')
    return ''.join(out)


PAGE_TEMPLATE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} - preview</title>
<link rel="stylesheet" href="../plugin/archery-records/assets/archery-records.css">
<style>
 body {{ font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; padding: 1.5rem 2rem 4rem;
        color: #1c1e21; background: #fff; max-width: 1100px; }}
 .preview-banner {{ padding: .8rem 1rem; margin-bottom: 1.5rem; border-left: 4px solid #b45309;
        background: #fff7ed; font-size: .9rem; line-height: 1.5; }}
 .preview-nav {{ margin-bottom: 1.5rem; font-size: .9rem; }}
 .preview-nav a {{ margin-right: 1rem; }}
</style>
</head>
<body>
<div class="preview-banner">
 <strong>Preview only.</strong> This is a static rendering of the plugin's markup, built by
 <code>tools/build_preview.py</code>. The <strong>current</strong> record holders are the real ones
 scraped from archery.ie on 2 September 2026. Every <strong>previous</strong> holder shown behind a
 "+" is invented sample data - the names are all "A. Sample", "B. Sample" and so on - because we do
 not have the real database yet.
</div>
<div class="preview-nav">{nav}</div>
<h1>{title}</h1>
{body}
<script src="../plugin/archery-records/assets/archery-records.js"></script>
</body>
</html>
"""


def main():
    layout = json.load(open(os.path.join(PLUGIN, 'config', 'layout.json'), encoding='utf-8'))
    fixtures = json.load(open(os.path.join(PLUGIN, 'data', 'fixtures.json'), encoding='utf-8'))

    progressions = build_progressions(fixtures['submissions'])
    os.makedirs(PREVIEW, exist_ok=True)

    nav = ' '.join('<a href="%s.html">%s</a>' % (p['slug'], esc(p['title'])) for p in layout['pages'])

    for page in layout['pages']:
        used_keys = set()
        body, rendered, section_shown = [], 0, ''

        for round_key in page['rounds']:
            round_def = layout['rounds'][round_key]
            section = round_def.get('section') or ''
            if section and section != section_shown and rendered > 0:
                body.append('<h3 class="archery-records-section">%s</h3>' % esc(section))
                section_shown = section
            body.append(render_round(round_key, round_def, progressions, used_keys))
            rendered += 1

        out = PAGE_TEMPLATE.format(
            title=esc(page['title']),
            nav=nav,
            body='<div class="archery-records-page">' + ''.join(body) + '</div>',
        )
        with open(os.path.join(PREVIEW, page['slug'] + '.html'), 'w', encoding='utf-8') as f:
            f.write(out)

    index = ['<!doctype html><html lang="en"><head><meta charset="utf-8">',
             '<meta name="viewport" content="width=device-width, initial-scale=1">',
             '<title>Records preview</title></head><body>',
             '<h1>Irish Records - plugin preview</h1><ul>']
    for page in layout['pages']:
        index.append('<li><a href="%s.html">%s</a> (%d tables)</li>'
                     % (page['slug'], esc(page['title']), len(page['rounds'])))
    index.append('</ul></body></html>')

    with open(os.path.join(PREVIEW, 'index.html'), 'w', encoding='utf-8') as f:
        f.write('\n'.join(index))

    with_history = sum(1 for p in progressions.values() if len(p) > 1)
    print('pages written  :', len(layout['pages']))
    print('records        :', len(progressions))
    print('with history   :', with_history)


if __name__ == '__main__':
    main()
