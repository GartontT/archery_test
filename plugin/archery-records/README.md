# Archery Ireland Records

A WordPress plugin that builds the Irish Records tables on archery.ie from the records database, with a "+" on each row that expands to show the previous holders of that record.

It replaces the current arrangement, where each table is hand-typed HTML pasted into an Elementor text-editor widget and updated by hand whenever a record falls.

## State of play

Complete and working, against a real snapshot of the records database taken on 7 September 2026: 630 records across six pages, 157 of which carry history.

It is not yet reading the live database. That is one file — copy `examples/data-source-archery-ireland.php` over `includes/data-source.php` and add four credentials to `wp-config.php`. See `INSTALL.md`.

## How a page is laid out

A section for each bow type - Compound, Recurve, Barebow and so on. Each section has its own strip of class tabs sitting directly on top of its table, and the table lists the rounds as its rows.

So a reader sees every distance and every round together for the class they care about, instead of scanning down thirty-five separate tables looking for their own line in each. And because each bow carries its own tabs, two of them can show different classes at the same time - Gents Compound alongside Ladies Recurve.

Which classes appear in a bow's tabs is decided by the data: a class with no record at all for that bow is left out rather than given an empty table.

It inverts what the old archery.ie pages did, which was a table per round with the classes as rows.

With JavaScript off, every class shows one after another with a heading each, so the page is still complete.

## The four things worth knowing

**One shortcode per page.** `[archery_records page="target-indoor-individual"]` renders every round on that page, headings and all. Six pages, six shortcodes, done once. After that a new round or category appears on the site because it appeared in the database.

**The plugin works out what a record is.** The `Records` table holds scores that were never records — a 1065 shot in 2020 when the standing record was already 1069. The plugin sorts each classification by date and keeps only the scores that beat everything before them. That derived progression is what the "+" shows, and it means the site cannot drift out of step with the data.

It also means the plugin is right where the data is wrong. Ten records have two rows flagged as current and 116 have none; deriving rather than trusting the flag gives the correct answer in every case.

**Everything on the page comes from the database.** Which rounds exist, what they are called, which categories each table lists, and which of those read "no current record" — all of it. There is no grid of expected categories to keep in step, because the database holds a row for a category nobody has claimed.

**All the history is already in the page.** There is no request when you press "+". Every previous holder is rendered as a hidden table row and the button just unhides it — the approach the Archery Europe records pages use. Fast, and it degrades sensibly: with JavaScript off the history shows expanded rather than being locked behind a dead button.

## Files

```
archery-records.php        Plugin header, shortcode, asset registration
DATA-CONTRACT.md           What a data source must return
INSTALL.md                 Installing, connecting the database, switching the pages over
includes/
  data-source.php          >>> THE FILE TO REPLACE <<< currently reads the bundled snapshot
  normalise.php            Validation, tidying, and the encoding repair
  cache.php                Cached read-through, with a fallback to the last good copy
  rounds.php               Page list, round list, and display order
  records.php              Derives the record progression from the raw scores
  render.php               Builds the HTML
assets/
  archery-records.css      Table styling. No icon fonts, no external anything.
  archery-records.js       The "+" toggle. About 40 lines, no jQuery.
config/
  rounds.json              Round key -> page, heading, archived, order. Generated.
data/
  fixtures.json            Snapshot of the real database, 7 September 2026
examples/
  data-source-archery-ireland.php   The live implementation. This is the one to use.
  data-source-csv.php               If the data ever moves to a file
  data-source-rest.php              If the data ever moves behind a web service
```

## Where the data lives

Not in the WordPress database. WordPress is on `mysql4543int.cp.blacknight.com`; the records are in `db1249072_registration` on `mysql1996int.cp.blacknight.com`, a different server on the same hosting account. That is why the plugin opens its own connection rather than using `$wpdb`.

## Assumptions, and how to change them

- **Equalling a record does not take it** — the first archer to a score keeps it. Filter `archery_records_ties_take_record` to reverse.
- **A record is identified by round, classification and bow**, not by the database's row id, which identifies a single score.
- **Fifteen-minute cache.** Filter `archery_records_cache_seconds`.
- **Class and bow display order**, and the order the tabs appear in, are in `includes/rounds.php`, with filters on each.
- **The order rounds run down a table** comes from `config/rounds.json`, generated by `tools/load_real_data.py`. It exists because `RoundTypes.Oder` is empty in the database; if that were filled in, the order could come from the database instead.
- **Age-group labels** (`M` to "50+ Men", `J` to "U21 Gents", and so on) are in the data source, in one array.
