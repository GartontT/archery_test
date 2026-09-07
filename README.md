# archery.ie — live records tables

Making the Irish Records pages on [archery.ie](https://archery.ie/competition-results/irish-records/) build themselves from the records database instead of being typed by hand, and giving each row a "+" that expands to show the previous holders — the way the [Archery Europe](https://www.archeryeurope.org/record/indoor-compound-records/) pages do it.

Started 2 September 2026.

## Where things stand

The plugin is written, working, and running on a real export of the records database: 65 tables across six pages, 675 records, 133 with history behind the "+".

It is not yet reading the *live* database. That is one file swap plus four credentials — see `plugin/archery-records/INSTALL.md`.

Open `preview/index.html` through a local server to see what it produces.

## What is here

```
plugin/archery-records/   The WordPress plugin. This is the deliverable.
preview/                  Static preview of all six pages, built from real data.
research/                 What was learned along the way.
  2026-09-02_recon-findings.md        How the pages are built today, and how
                                      Archery Europe's "+" actually works
  2026-09-02_source-data-issues.md    Errors in the current hand-typed tables
  2026-09-07_records-database-trail.md  How the database was found, and what
                                      else is on that hosting account
  2026-09-07_database-vs-website.md   The schema, the code mappings, and every
                                      difference between database and website
  raw/                                The downloaded pages, kept as evidence
tools/                    One-off scripts. Not part of the plugin.
  extract_fixtures.py     Scraped the old pages (superseded, kept as the record
                          of how the site looked on 2 September 2026)
  load_real_data.py       Turns a database export into the plugin's data and config
  build_preview.py        Builds preview/ without WordPress or PHP
.server-copy/             Files copied down from the web server. GITIGNORED —
                          contains live database passwords. Never commit.
```

## Where the data actually lives

`db1249072_registration.Records` on `mysql1996int.cp.blacknight.com` — **not** the WordPress database, which is on a different server. 1110 rows covering every round, every category, current holders and previous ones.

Getting there took some archaeology. The hosting account carries the whole pre-WordPress archery.ie alongside the current site, nine databases spread across three MySQL servers, and the records turned out to belong to a decade-old registration application in `httpdocs/registration/`. That trail is written up in `research/2026-09-07_records-database-trail.md`.

## The shape of the thing

**The data source is one file.** `includes/data-source.php` is the only code that knows where records come from. `examples/data-source-archery-ireland.php` is the finished live implementation; the CSV and REST examples cover the data moving somewhere else later.

**The plugin decides what counts as a record.** The table holds scores that were never records. The plugin sorts each classification by date and keeps only those that beat everything before them. This is also what makes it correct where the data is not: ten records have two rows flagged current, 116 have none, and deriving gives the right answer in every case.

**Everything on the page comes from the database** — which rounds exist, their headings, which categories each table lists, and which of those read "no current record".

**Six shortcodes, once.** Then nobody edits a records page again.

## Running the tools

Python 3, no packages:

```
python tools/load_real_data.py     # database export -> plugin data + config
python tools/build_preview.py      # rebuild preview/
python -m http.server 8765         # then open http://127.0.0.1:8765/preview/
```

The preview uses relative paths into `plugin/`, so serve the project root rather than opening the files directly.

## What is left

1. **Connect the live database.** One file swap, four constants, a read-only MySQL user. Stage 2 of `INSTALL.md`.
2. **Run the PHP.** The plugin's PHP has been statically checked but never executed — there is no PHP on this machine and php.net was down through 7–8 September. The preview you can click is a Python mirror of the renderer, so the markup, styling and interaction are verified, but the PHP itself is not.
3. **Switch the six pages over** in Elementor.

## Things to raise with Archery Ireland

- The `Records` table has ten records with two rows flagged as current, and a club misspelled as "Wickow Archers".
- Accented names are double-encoded in the database (`RÃ³isÃ­n`). The plugin repairs this, but it is wrong at source.
- `RoundTypes.Oder` and `RoundTypes.Archived` are `0` on every row — both look like they were meant to be used.
- Four rows use round codes absent from `RoundTypes`.
- The old site at `httpdocs/registration/` has SQL injection holes and sits on the same account as a membership database holding personal data. It is a decade stale and should probably be taken down.
