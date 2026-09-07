# Finding the records database

Date: 7 September 2026. What we learned from the Blacknight hosting account.

## Where things actually are

The hosting account holds two generations of website in `/webspace/httpdocs/`:

- `httpdocs/archery.ie/` — the current WordPress site
- everything else in `httpdocs/` — the previous, pre-WordPress archery.ie, dated mostly 2012–2014 and never cleared out. 379 items, 65.6 MB.

The nine databases on the account belong to applications in that older tree — `registration`, `student`, `Membership`, and a couple of old WordPress installs — rather than being leftovers.

## The databases sit on more than one MySQL server

This is what made the search confusing, and it is worth remembering.

| Database | MySQL host |
|---|---|
| `db1249072_archeryUc24` (WordPress) | `mysql4543int.cp.blacknight.com` |
| `db1249072_registration` | `mysql1996int.cp.blacknight.com` |
| `db1249072_archeryiewp` (old WordPress) | `mysql1576int.cp.blacknight.com` |

Because each database has its own MySQL user on its own server, a phpMyAdmin session opened against one of them cannot see the others at all. Searching `information_schema` from the WordPress database was always going to come back empty, no matter what the records were called.

**Consequence for the plugin:** the records database is not the WordPress database and not on the same server. The plugin will need its own connection with its own credentials, not `$wpdb`. Those credentials should go in `wp-config.php` as constants, never in the plugin.

## The `scores` table

`httpdocs/registration/` holds the old registration and ranking application. Two files matter.

**`rankingquery.php`** reads a table called `scores`, and tells us these columns exist:

`score_id`, `archer_firstname`, `archer_secondname`, `archer_score`, `score_type`, `competition_type`, `archer_class`, `archer_gender`, `bow_type`, `discipline`, `score_date`

**`record-ranking.php`** is a score-submission form, and shows the values the application uses:

| Field | Values |
|---|---|
| `score_type` | `RC` = Record Claim, `RK` = Rankings |
| `bow_type` | `R` Recurve, `C` Compound, `B` Barebow, `I` Instinctive, `L` Longbow |
| `archer_class` | *(blank)* Senior, `C` Cadet, `J` Junior, `Y` Youth, `M` Master |
| `archer_gender` | `M` Male, `W` Female |
| `competition_type` | `Indoor`, `Outdoor` (from the ranking query's average-arrow logic) |
| `score_class` | `720`, `1440` |

The form also collects `AI_membershipNo`, `archer_dob`, `archer_email`, `selection_score`, `event`, `ianseo_link`.

`score_type = 'RC'` is the record claims. That is almost certainly the data behind the records pages.

## Three problems this turns up

**1. The record-claim form is broken.** `record-ranking.php` posts to `insert_record.php`, and that file does not exist anywhere in the copied tree. The Rankings path works; the Record Claim path posts into a void. So it is an open question whether anything has ever written `score_type = 'RC'` rows through this form, and that needs settling before we build on it.

**2. The database taxonomy does not match the website taxonomy.** The database stores a bow type and a broad class (Senior / Cadet / Junior / Youth / Master) plus a gender. The website tables use combined labels: "Gents", "Ladies", "50+ Men", "50+ Women", "U21 Gents", "U18 Ladies", "U15 Gents". There is no direct correspondence — the website's age bands are finer than the database's classes, and the database has no "50+" concept at all, only "Master".

This is the single biggest open design question. Either the database has more columns than `rankingquery.php` uses, or the mapping cannot be derived and something else supplies it.

**3. There is no club column in what we can see.** The website's tables all carry a Club column. `scores` as used by `rankingquery.php` has no club field. The likely route is `AI_membershipNo` → the `Membership` database's `Membership_No` → `Club_Name`. That is a join across two databases on two different MySQL servers, which cannot be done in a single SQL query — the plugin would have to read both and join in PHP.

It is also worth noting that a club stored against a member is their club *now*, not the club they belonged to when they set the record in 2007. The website's historic rows would slowly become wrong. Worth raising.

## A security problem to pass on, unrelated to this project

`registration/rankingquery.php` interpolates `$_POST` values straight into SQL:

```php
$sql = mysqli_query($dbo, "SELECT ... WHERE archer_class = '$archer_class' ...");
```

There is no escaping and no prepared statement. This is a textbook SQL injection hole, on a public server, against a database that sits alongside a membership database holding names, addresses, dates of birth and children's-officer records.

Several other files in that folder follow the same pattern. This is not part of our brief, but whoever maintains archery.ie should know. The cheapest fix is to take the old site down: it is a decade out of date and appears to be superseded by the WordPress site.

## What to check next

Open phpMyAdmin **from the `db1249072_registration` row** in the Databases list, so the session connects to `mysql1996int`, and run:

```sql
SELECT CONCAT(table_name, '  —  ', IFNULL(table_rows, 0), ' rows') AS tables_here
FROM information_schema.tables
WHERE table_schema = 'db1249072_registration'
ORDER BY table_rows DESC;

SHOW FULL COLUMNS FROM scores;

SELECT score_type, COUNT(*) AS n, MIN(score_date) AS earliest, MAX(score_date) AS latest
FROM scores GROUP BY score_type;

SELECT * FROM scores ORDER BY score_date DESC LIMIT 20;
```

The `GROUP BY score_type` query is the decisive one. If there are no `RC` rows, or they only go back a year or two, then `scores` is not the source of the published records and the search continues.
