# The data contract

This is the agreement between the plugin and the records database. If you are connecting a data source, this is the only document you need.

You implement one function, in `includes/data-source.php`:

```php
function archery_records_fetch_submissions() {
    // ... read the data ...
    return $rows;
}
```

Nothing else in the plugin changes.

**For archery.ie this is already written.** `examples/data-source-archery-ireland.php` is the finished implementation against `db1249072_registration`. Copy it over `includes/data-source.php` and add four constants to `wp-config.php`. The rest of this document matters only if the data ever moves.

## What the function returns

A plain PHP array. Each element is one **submission** — one score, shot by one archer or one team, on one occasion.

```php
array(
    'id'             => '1695',
    'round'          => 'target-indoor-individual/wa18-120-arrow',
    'classification' => array( 'class' => 'Gents' ),
    'bow'            => 'Compound',
    'score'          => 1168,
    'archers'        => array( 'Robert Hall' ),
    'date'           => '2015-01-25',
    'club'           => 'Wicklow Archers',
    'venue'          => '',
)
```

| Field | Required | Notes |
|---|---|---|
| `id` | yes | Unique across all rows and stable between reads. The database's own row id is ideal. |
| `round` | yes | Which table this belongs to. See "Round keys" below. |
| `classification` | yes | Lowercase keys. `class` always; `peg` as well for field and 3D. Values are the labels as they should read on the page — `"50+ Men"`, `"U18 Ladies"`, `"Red Peg"`. |
| `bow` | yes | `"Compound"`, `"Recurve"`, `"Barebow"`, `"Traditional"`, `"Instinctive"`, `"Longbow"`. |
| `score` | yes | A whole number. |
| `archers` | yes | Names in column order: one for an individual, two for a mixed team, three for a team. A single `archer` string is also accepted. |
| `date` | yes | `YYYY-MM-DD` preferred. See "Dates". |
| `club` | no | |
| `venue` | no | Not displayed today. |

## Three rules that matter more than the field list

**Return every score, not just the current records.** The database holds scores that were never records — a 1065 shot in 2020 when the standing record was already 1069. The plugin sorts each classification by date, walks forward, and keeps only the scores that beat everything before them. That derived progression is what the "+" shows.

Doing it this way means the published tables are correct by construction rather than depending on a flag being maintained. It is also what makes the plugin robust against the two known data faults in the Archery Ireland table: ten records have two rows flagged as current, and 116 have none.

**A row with no score and no archer means "this category exists but nobody holds it".** Return those rows. They are what the pages print as "no current record", and they are how the plugin knows which rows a table should have. Without them, unclaimed categories vanish from the site.

```php
array(
    'id'             => '1714',
    'round'          => 'target-indoor-individual/wa18-120-arrow',
    'classification' => array( 'class' => 'U15 Gents' ),
    'bow'            => 'Compound',
    'score'          => 0,
    'archers'        => array(),
    'date'           => '',
)
```

A row with a score but no archer is malformed and gets dropped, with a note for editors.

**Throw an exception if the data cannot be read.** Do not return an empty array — that means "there are genuinely no records anywhere", and the plugin will believe it. An exception makes it keep serving the last good copy and log the reason.

## Round keys

A round key is `<page>/<round>`, for example `target-indoor-individual/wa18-120-arrow`. The full list is `config/rounds.json`, which also carries each round's heading and its position in the running order.

The page part matters because the same round name appears on more than one page. The `-archived` suffix matters too: a retired round is a separate table from the live one of the same name, which is how the Field page shows "24 Targets Unmarked" twice, once current and once under "Archived Instinctive Records".

Any round key the plugin does not recognise renders nothing and is not reported, so keep `rounds.json` in step if rounds are added. `tools/load_real_data.py` regenerates it from a database export.

## Dates

`YYYY-MM-DD` is what the Archery Ireland database stores and what you should return. The plugin formats it as `25-Jan-15` for display, matching the current pages.

Two older formats are still parsed, for data that predates the current table: `25-Jan-15` (a two-digit year is read as this century unless that would be in the future) and a bare `1995`. Anything unparseable sorts to the start of a progression rather than being dropped.

The zero date `0000-00-00` must be converted to an empty string. It means "no date", not a date in year zero.

## Character encoding

The Archery Ireland text is UTF-8 stored in latin1 columns, so accented names arrive double-encoded — Róisín Mooney comes back as `RÃ³isÃ­n Mooney`. The plugin detects and repairs this on the way in, so return whatever the database gives you without trying to fix it yourself.

If the columns are ever converted properly, the repair becomes a no-op. It is safe to leave in place either way.

## Performance and security

The function is called at most once every fifteen minutes per site, not once per page load and not once per table. Read everything in one query; do not filter by round.

Nothing from the visitor's request reaches this function, so there is nothing to sanitise on the way in, and the plugin escapes everything on the way out. Return values raw.

Keep credentials in `wp-config.php`, not in the plugin. Use a MySQL user with `SELECT` and nothing else — the plugin never writes.
