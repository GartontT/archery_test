# The data contract

This is the agreement between the plugin and the records database. If you are the person connecting the real database, this is the only document you need.

You implement one function, in `includes/data-source.php`:

```php
function archery_records_fetch_submissions() {
    // ... read the database ...
    return $rows;
}
```

Nothing else in the plugin needs to change.

## What the function returns

A plain PHP array. Each element is one **submission** — one score, shot by one archer or one team, on one occasion.

```php
array(
    'id'             => 'a7f3c918',        // required, string, unique and stable
    'round'          => 'target-indoor-individual/wa-18-120-arrows',
    'classification' => array( 'class' => 'Gents' ),
    'bow'            => 'Compound',
    'score'          => 1168,
    'archers'        => array( 'Robert Hall' ),
    'date'           => '25-Jan-15',
    'club'           => 'Wicklow Archers',
    'venue'          => '',                // optional, not shown on any page today
)
```

### Field by field

| Field | Required | Notes |
|---|---|---|
| `id` | yes | Any string, as long as it is unique across all rows and does not change between reads. The database's own random identifier is ideal. If you leave it out the plugin derives one from the row's contents, which works but is fragile. |
| `round` | yes | Which table this belongs to. Must match a key in `config/layout.json`. See "Round keys" below. |
| `classification` | yes | The columns that describe the category rather than the result. Keys are lowercase: `class`, and additionally `peg` for field and 3D, or `category` on the archived page. Values are exactly as they should read on the page, e.g. `"50+ Men"`, `"U18 Ladies"`, `"Red Peg"`. |
| `bow` | yes | `"Compound"`, `"Recurve"`, `"Barebow"`, `"Traditional"`, and so on. |
| `score` | yes | A whole number. Rows with a non-numeric score are dropped. |
| `archers` | yes | A list of names, in the order the columns should read. One name for an individual record, two for a mixed team, three for a team. A single `archer` string is also accepted. |
| `date` | yes | See "Dates" below. |
| `club` | no | Shown in the Club column. |
| `venue` | no | Not displayed anywhere today. Include it if the database has it and it may be wanted later. |

## Return everything, not just the current records

**Return every score the database holds, including the ones that were never records.** The plugin sorts each classification by date, walks forward, and keeps only the scores that beat everything before them. That derived progression is what fills the "+" expansion.

This is deliberate. It means the published tables are correct by construction, rather than depending on somebody having flagged the right rows in the database. It also means a correction to an old score automatically ripples through the history.

The rule used: a score takes the record only if it is **strictly greater** than the standing record. Equalling it leaves the record with the archer who got there first. If Archery Ireland's rule is the other way round, add this to the theme's `functions.php`:

```php
add_filter( 'archery_records_ties_take_record', '__return_true' );
```

## Round keys

A round key is `<page>/<round>`, for example `target-indoor-individual/wa-18-120-arrows`. The full list is in `config/layout.json` — every key under `"rounds"`.

The page part matters because the same heading appears on more than one page: "24 Targets Unmarked" exists on both Field and 3D Field and they are different records.

If the database uses its own names for rounds, map them to these keys inside `data-source.php`. Keep the mapping in one array at the top of that file so it is easy to find:

```php
$round_map = array(
    'WA18-120' => 'target-indoor-individual/wa-18-120-arrows',
    'WA18-60'  => 'target-indoor-individual/wa-18-60-arrows',
    // ...
);
```

Any round the plugin does not recognise is skipped, and logged-in editors see a notice at the bottom of the page listing the unknown keys. That notice is the thing to watch for after connecting the real source.

## Dates

Hand the date over in whichever of these the database has, best first:

1. `2015-01-25` — unambiguous, and what you should aim for.
2. `25-Jan-15` — what the current pages use. The plugin reads a two-digit year as this century unless that would put it in the future, so `15` is 2015 and `95` is 1995. This works but it is guesswork, and it will start misreading things as the years go on.
3. `1995` — a bare year. Sorts to the start of that year.

Anything the plugin cannot parse sorts as "older than everything else known". A record whose date cannot be read still appears; it just may sit oddly in the progression.

There is no need to format dates for display — whatever string you return is what the Date column shows.

## Failure

**If the data cannot be read, throw an exception.** Do not return an empty array.

```php
if ( ! $connection ) {
    throw new RuntimeException( 'Could not connect to the records database.' );
}
```

An empty array means "there are genuinely no records anywhere", and the plugin will believe it. An exception makes the plugin keep serving the last copy it read successfully, log the reason, and show a notice to logged-in editors while visitors carry on seeing the tables.

## Performance

The function is called at most once every fifteen minutes per site, not once per page load and not once per table, so it is fine for it to read the whole dataset in one go. Roughly seven hundred records with their history is a small amount of data.

Do not try to filter by round inside this function. Return everything and let the plugin sort it out.

## Security

Whatever you write here runs on the public web server.

- Nothing from the visitor's request reaches this function, and nothing should. There is no user input to pass through, so there is nothing to sanitise on the way in.
- Do not put database credentials in this file if you can avoid it. Put them in `wp-config.php` as constants and read them here.
- If the source is a file on disk, keep it outside the web root, or the whole records database is downloadable by anyone who guesses the URL.
- The plugin escapes everything on the way out, so you do not need to escape values here. Return them raw.
