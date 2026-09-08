# The records plugin — what it is and what to do with it

This repo holds a WordPress plugin that builds the Irish Records pages from the records database, with a "+" on each row that expands to show previous holders. It's working, running on a real export of your data — 630 records, 157 of them with history.

Each page is a section per bow type - Compound, Recurve, Barebow and so on. Each has its own strip of class tabs sitting on top of its table, and the table lists the rounds as its rows. Because every bow has its own tabs, two of them can show different classes at once: Gents Compound alongside Ladies Recurve.

That is the reverse of the old archery.ie pages, which had a table per round with the classes as rows.

## Read in this order

1. `README.md` — what the project is and where the data lives
2. `suggestions.md` — everything we found that's worth fixing, most important first. **Read section 1 today**; it's a live security issue on the old site.
3. `plugin/archery-records/INSTALL.md` — the actual instructions, in three stages

## To see it working before installing anything

```
python -m http.server 8765
```

from the repo root, then open `http://127.0.0.1:8765/preview/`.

## Three stages, each safe to stop after

1. **Install the plugin** and test it on a draft page. It runs on a frozen snapshot of the data, so nothing depends on the database yet.
2. **Connect the live database** — create a read-only MySQL user, add four constants to `wp-config.php`, swap one file. Details in `INSTALL.md`.
3. **Switch the six pages over** in Elementor. One shortcode per page, then nobody edits a records page again.

## One thing to know before you start

The PHP has been checked but never actually executed — there was no PHP available on the machine it was built on, and php.net was down for two days. **Test it on a staging copy first**, not the live site.
