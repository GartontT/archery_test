# archery.ie — live records tables

Making the Irish Records pages on [archery.ie](https://archery.ie/competition-results/irish-records/) build themselves from the records database, instead of being typed by hand, and giving each row a "+" that expands to show the previous holders of that record — the way the [Archery Europe](https://www.archeryeurope.org/record/indoor-compound-records/) pages do it.

Started 2 September 2026.

## Where things stand

The plugin is written and works. It runs on sample data, because nobody has had access to the real records database yet. Connecting it is a one-file job — see `plugin/archery-records/DATA-CONTRACT.md`.

Look at `preview/index.html` in a browser to see what it produces.

## What is here

```
plugin/archery-records/   The WordPress plugin. This is the deliverable.
preview/                  Static preview of all seven pages. Open index.html.
research/                 What was learned from the live site.
  2026-09-02_recon-findings.md       How the pages are built today, and how
                                     Archery Europe's "+" actually works
  2026-09-02_source-data-issues.md   Errors found in the current hand-typed
                                     tables, including two that misattribute
                                     a record on a public page
  raw/                               The downloaded HTML, kept as evidence
tools/                    One-off scripts. Not part of the plugin.
  extract_fixtures.py     Scrapes research/raw/ into the sample data and the
                          layout configuration
  build_preview.py        Builds preview/ from those two files
```

## The shape of the thing

**The data source is one file.** `plugin/archery-records/includes/data-source.php` is the only code that knows where records come from. It currently reads a bundled JSON file. Replacing it with real database access changes nothing else. Three worked examples — MySQL, a CSV or Excel export, a JSON web service — are in the plugin's `examples/` folder.

**The plugin decides what counts as a record.** The database holds every score submitted, most of which were never records. The plugin sorts each classification by date and keeps the ones that beat everything before them. So the published tables cannot drift out of step with the data, and nobody has to flag rows by hand.

**Seven shortcodes, once.** Each of the seven records pages gets one shortcode that renders all of its tables. That is a one-off edit of seven Elementor pages, and then nobody edits a records page again — a new round or category appears on the site because it appeared in the database.

## Running the tools

Python 3 only, no packages needed:

```
python tools/extract_fixtures.py    # re-scrape research/raw/ into the plugin's data + config
python tools/build_preview.py       # rebuild preview/
```

The preview uses relative paths into `plugin/`, so open it through a local server rather than as a `file://` URL:

```
python -m http.server 8765
```

then go to `http://127.0.0.1:8765/preview/`.

## Open questions

These need someone with access to the server or the database.

1. **What is the records database, and can the web server reach it?** Best guess so far is a file on the same machine. This decides whether the plugin reads it directly or something syncs it.
2. **Does it hold the history?** We have been told it does. If it turns out to hold only current holders, the "+" has nothing to show and the feature needs rethinking.
3. **Does it hold the club, and the round?** The tables show a Club column and are grouped by round, so both have to come from somewhere.
4. **Are the two misattributed records in `research/2026-09-02_source-data-issues.md` wrong in the database too, or only in the hand-typed page?** If only in the page, they fix themselves at switch-over.
