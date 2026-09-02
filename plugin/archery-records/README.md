# Archery Ireland Records

A WordPress plugin that builds the Irish Records tables on archery.ie from the records database, with a "+" on each row that expands to show the previous holders of that record.

It replaces the current arrangement, where each table is hand-typed HTML pasted into an Elementor text-editor widget and updated by hand whenever a record falls.

## State of play

The plugin is complete and works. It is currently running on **sample data**, because the real records database has not been reached yet: the current record holders in `data/fixtures.json` are real, copied from the live site on 2 September 2026, but every previous holder is invented placeholder data with names like "A. Sample".

Connecting the real database means replacing one file. See `DATA-CONTRACT.md`.

## The three things worth knowing

**One shortcode per page.** `[archery_records page="target-indoor-individual"]` renders every round on that page, headings and all. Seven pages, seven shortcodes. After the switch-over, a new round or a new category appears on the site because it appeared in the database — nobody edits a page again.

**The plugin works out what a record is.** The database holds every score submitted, including many that were never records. The plugin sorts each classification by date and keeps the scores that beat everything before them. That derived progression is what the "+" shows. Doing it this way means the site cannot drift out of step with the data.

**All the history is already in the page.** There is no request when you press "+". Every previous holder is rendered as a hidden table row and the button just unhides it — the same approach the Archery Europe records pages use. That is why it is fast and why it degrades sensibly: with JavaScript off, the history shows expanded rather than being locked behind a dead button.

## Files

```
archery-records.php        Plugin header, shortcode, asset registration
DATA-CONTRACT.md           What the data source must return. Read this first.
INSTALL.md                 Installing, and switching the seven pages over
includes/
  data-source.php          >>> THE FILE TO REPLACE <<< currently returns sample data
  normalise.php            Validates and tidies whatever the source returns
  cache.php                Cached read-through, with a fallback to the last good copy
  layout.php               Loads config/layout.json
  records.php              Works out the record progression from the raw scores
  render.php               Builds the HTML
assets/
  archery-records.css      Table styling. No icon fonts, no external anything.
  archery-records.js       The "+" toggle. ~40 lines, no jQuery.
config/
  layout.json              Which rounds are on which page, and the expected rows
data/
  fixtures.json            Sample data. Delete once the real source is connected.
examples/
  data-source-mysql.php    Worked example: MySQL, same server
  data-source-csv.php      Worked example: CSV or Excel export on disk
  data-source-rest.php     Worked example: JSON web service
```

## Why there is a config file

`config/layout.json` lists the rows each table is expected to have. It exists for one reason: the records database only holds scores that were actually shot, so it has no way of saying "Gents Barebow is a category on this round but nobody has ever set a record in it". The current site shows those as "no current record", and the config file is what lets the plugin keep doing that.

It was generated from the existing pages by `tools/extract_fixtures.py` in the parent project. Editing it by hand is fine.

If a record turns up in the database for a classification the config does not list, it is rendered anyway, at the bottom of its table. The config controls the empty rows; it does not gate real data.

## Dependencies

None. No jQuery, no icon font, no table plugin, no external requests. The "+" icon is drawn in CSS.

## Assumptions to revisit

These are guesses about a database nobody has seen yet. Each is in one place and cheap to change.

- **Equalling a record does not take it.** Filter `archery_records_ties_take_record` to reverse.
- **Two-digit years are this century unless that would be in the future.** So `15` is 2015 and `95` is 1995. Delete this guesswork if the real source stores proper dates — see `archery_records_date_sort_key()`.
- **A record is identified by round, classification and bow**, not by the database's own row id, which is random and identifies a single score rather than a record.
- **Fifteen-minute cache.** Filter `archery_records_cache_seconds`.
