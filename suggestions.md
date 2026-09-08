# Suggestions for Archery Ireland

Everything worth acting on that came out of building the records plugin, in one place. None of it is required for the plugin to work — it works today, around all of it. But most of these are things the plugin is currently compensating for, and it is better to fix them at source.

Ordered by how much they matter. The first section is the one to read even if nothing else gets read.

---

## 1. Security — worth acting on soon

### 1.1 The old website is still live, and it has SQL injection holes

`httpdocs/registration/` holds a registration and rankings application from around 2013. It is still publicly reachable, and several of its files put form input straight into SQL with no escaping. For example, in `rankingquery.php`:

```php
$archer_class = $_POST['archer_class'];
$sql = mysqli_query( $dbo, "SELECT ... WHERE archer_class = '$archer_class' ..." );
```

Anyone who can post to that page can run their own SQL. Several other files in the folder follow the same pattern.

This matters more than it would on a standalone site, because that hosting account also carries `db1249072_Membership`, which holds members' names, addresses, dates of birth, email addresses, phone numbers, emergency contacts, vetting status and children's-officer course records. That is exactly the kind of data a breach is expensive to explain.

**Suggested fix:** take the old site down. It is a decade out of date, the WordPress site has replaced it, and the record-claim form in it does not even work (see 3.1). If any of it is still in use, that part should be moved behind a login and rewritten with prepared statements. Deleting is cheaper than patching.

### 1.2 Database passwords sit inside the web root

`httpdocs/registration/config.php` contains live MySQL credentials in plain text, inside the folder the web server serves. While PHP is executing normally this is fine — the file is run, not sent. But any misconfiguration that stops PHP from executing (a bad `.htaccess`, a failed upgrade, a module disabled) turns that file into a plain-text download.

**Suggested fix:** move credentials above the web root, or into environment variables. If the old site goes away entirely this solves itself.

### 1.3 Give the records plugin a read-only database user

The plugin only ever reads. Do not give it `u1249072_admin`, which can drop tables.

**Suggested fix:** in Plesk → Databases, create a user against `db1249072_registration` with `SELECT` permission and nothing else, and use that in `wp-config.php`. Two minutes of work, and it means a mistake in the plugin can never damage the records.

### 1.4 Rotate the FTP password if plain FTP was used

Plain FTP sends the password in clear text. If the account has been used over plain FTP rather than SFTP or FTPS, particularly on a shared or public network, the password should be changed and the connection switched to SFTP.

---

## 2. Data errors in the `Records` table

These are specific rows that are wrong today. The plugin handles all of them without falling over, but the underlying data should be corrected.

### 2.1 Ten records have two rows flagged as current

Ten groupings by round, bow, class, gender and peg have two rows with `Active = 1`. One example, on WA18 120 Arrow, 50+ Men Compound:

| RecordCode | Score | Archer | Date | Active |
|---|---|---|---|---|
| 2269 | 1021 | John Keenan | 2022-03-20 | 1 |
| 2597 | 1119 | James O'Neill | 2026-01-04 | 1 |

Keenan's row should have been switched off when the record was beaten. The plugin derives the current record from the scores rather than trusting the flag, so it shows O'Neill correctly, but the flag is misleading anyone who reads the table directly.

**Suggested fix:** find them with a query that groups by round, bow, class, gender and peg and counts `Active`, then clear the flag on the superseded rows.

### 2.2 A hundred and sixteen categories have history but no current holder

The mirror of the above: groupings where no row is flagged `Active`. Some of these are legitimate — a category that has been retired — but they are worth reviewing, because a record with no current holder disappears from the flag's point of view.

### 2.3 Accented names are stored double-encoded

Róisín Mooney's name is stored as `RÃ³isÃ­n Mooney`. This is the classic latin1-versus-UTF-8 mix-up: UTF-8 bytes have been written into a column declared as latin1, then read back as UTF-8. Every name with a fada is affected.

The plugin detects and reverses this so the published pages are correct. But the data itself is wrong, and anything else reading the table — a report, an export, a mail merge — will show the mojibake.

**Suggested fix:** convert the affected columns properly, with `ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4`, after taking a backup and testing on a copy. Once fixed, the plugin's repair becomes a no-op and can be left in place or removed.

### 2.4 Peg colours are recorded two different ways

The `Peg` column holds both `Red Peg` and `RED` for the same peg, and likewise for blue, white and yellow. 194 rows use the long form and 89 the short one.

This is not cosmetic. A record is identified by its round, class, bow and peg, so the two spellings split one record into two. Darrel Wilson's 390 on 24 targets marked from 2016 and his 394 from 2023 are the same record, but they were being published as two separate rows rather than one with the earlier score behind the "+". Forty-five records were affected.

The plugin now settles both spellings on the long form, so the pages are right. But the data is still inconsistent, and anything else reading the table will hit the same problem.

**Suggested fix:** `UPDATE Records SET Peg = 'Red Peg' WHERE Peg = 'RED'`, and the same for the other three, after a backup.

### 2.5 A club name is misspelled

Mel Lawther's row on WA18 120 Arrow, Ladies Compound, gives the club as "Wickow Archers". The website says "Wicklow Archers". Here the hand-typed page is the more accurate of the two, so switching over would put the typo on the site.

### 2.6 Names are spelled inconsistently

Comparing the database with the published pages turned up several disagreements. Some are website typos, some are database typos, and only Archery Ireland can say which is which:

| Website | Database |
|---|---|
| Beua Poydence | Beau Poydence |
| Zach Wade | Zack Wade |
| Deirdre Rogers | Deirdre Rogers MhicRuaidhri |
| Ann Marie Murray | Ann-Marie Murray |
| Stephen Wall-Morris | Stephen Wall Morris |
| Alan Convery | Alan convery |

The last one is a capitalisation slip in the database. The others are genuine questions about how a name should be written.

**Suggested fix:** decide on the correct spelling for each and settle it in the database, since that is now the source. It would also be worth agreeing a convention — whether hyphenated surnames keep their hyphen, whether a married name is recorded in full — so this does not keep recurring.

### 2.7 Four rows use round codes that do not exist

Four rows have a `RoundCode` that is not in `RoundTypes` at all: `Team` (two rows), `Mixed Team`, and `WAF24`. They render with the raw code as their heading rather than a proper description, and they are almost certainly meant to be one of the real team rounds.

### 2.8 Round codes disagree between the two tables

`Records` has `3d - 24 unmarked targets` where `RoundTypes` has `3D - 24 unmarked targets`. Only the capitalisation differs, but a straight join between the tables will miss on a case-sensitive server. The plugin matches case-insensitively to work around it.

---

## 3. Schema and application issues

### 3.1 The record-claim form is broken

`registration/record-ranking.php` is a form for submitting a score, with a "Record Claim" option. It posts to `insert_record.php` — and that file does not exist anywhere on the server. So the rankings path works and the record-claim path posts into nothing.

Worth knowing if anyone believes that form is how records get submitted. It is not, and has not been for some time.

### 3.2 `Active` does not mean what it is described as meaning

`Active` was described to us as a verification flag. The data says it is a current-holder flag: across 811 groupings, every multi-row group has exactly one `Active` row regardless of whether it holds two rows or seven.

That is not a fault — the flag is used consistently — but the description and the behaviour have drifted apart. Worth writing down what it actually means, because the next person to use this table will otherwise make the same wrong assumption we did.

Note also that `Active = 0` does **not** mean "was once the record". It includes scores that were never records: on WA18 120 Arrow, 50+ Women Compound, Deidre Shannon's 1065 from 2020 sits inactive because Deirdre Rogers' 1069 from 2017 was already standing. This is why the plugin derives the progression by date rather than simply listing the inactive rows.

### 3.3 `RoundTypes.Oder` and `RoundTypes.Archived` are empty

Both columns are `0` on all sixty rows. `Oder` — spelled that way in the schema — looks like it was meant to control the order rounds are listed in, and `Archived` looks like it was meant to mark retired rounds. Neither was ever filled in.

The plugin gets ordering from a configuration file instead, and takes archived-ness from `Records.Archived`, which is populated. If `Oder` were filled in, the plugin could take its ordering from the database too and the configuration file could go away.

**Suggested fix:** either populate both columns and correct the spelling to `Order`, or drop them so they stop looking authoritative.

### 3.4 `Type` disagrees between the two tables

In `Records`, 3D rounds have `Type = '3D'`. In `RoundTypes`, the same rounds have `Type = 'Field'`. The plugin uses the `Records` value.

### 3.5 Smaller schema observations

- `Records.Gender` is declared as `text` to hold a single character. `varchar(1)` or an enum would be clearer and smaller.
- `Records.Arrows` duplicates `RoundTypes.Arrows`. Two places to change means two places to get wrong.
- `Records.Class` uses an empty string for "Senior" while being nullable, so there are two ways to express the same thing.

---

## 4. The website itself

### 4.1 The published pages are out of date

The database is ahead of the site in several places, which is the whole reason for this project:

| Round | Category | Site shows | Database has |
|---|---|---|---|
| 70m 144 arrows | Ladies Recurve | 1239, Roisin Mooney | 1247 |
| 70m 144 arrows | U21 Gents Recurve | 1000, Zach Wade | 1161 |
| WA1440 Provisional | 50+ Men Compound | 1350, Gerry Finnegan | 1355, Alan Convery |

### 4.2 Two records are published under the wrong category

On the Archived Records page, hand-typing errors have put records in the wrong place. In the "48 targets" table, a blank Class cell means Naomi Murtagh's record inherits "Junior Gents" from the row above, and it collides with Ian Carey's record for the same category. In the "24 Targets Marked" table, a row is missing a cell, so "Recurve" has landed in the Class column and the Bow column is empty.

These are on a public page now. They disappear when the pages are switched over, provided the database is right.

### 4.3 Class labels are inconsistent between pages

The indoor pages say "50+ Men" and "50+ Women". The outdoor pages say "50+ Gents" and "50+ Ladies". One archived outdoor table says "Youth U14 Ladies" where the current scheme says "U15 Ladies".

The database has one code for each, so the generated pages will be internally consistent. That is an improvement, but it is a visible change on the outdoor pages and people will notice.

**Suggested fix:** agree the wording once. The plugin's labels are in a single array in the data source and take a minute to change.

### 4.4 Other artefacts of hand-typing that go away

Recorded here so it is clear what the switch-over fixes: ten dates that no parser can read (`05-Mat-18` for March, `10-Jun13` missing a hyphen, `10-06-18` which could be June or October), 37 rows with a blank Bow cell, non-breaking spaces scattered through scores and club names, and "no current record" spelled two different ways.

### 4.5 Decide what to do with the Archived Records page

The seventh page holds pre-2010 junior records and pre-six-class field records — categories that no longer exist and are not separable in the database as a page of their own. The plugin does not cover it. Retired *rounds* now appear at the foot of their own page instead, which covers most of what it was for.

Either leave it as a static historical page, or retire it once the new pages are live.

---

## 5. Process

### 5.1 Test on a staging copy

The plugin's PHP has been checked but not executed — there was no PHP available on the machine it was built on. Install and test it somewhere that is not the live site first. Blacknight's Plesk can usually clone a site for this.

### 5.2 Take a backup before switching over

Both the WordPress site and `db1249072_registration`, before Stage 2 and Stage 3 of `INSTALL.md`. The Elementor edits are individually reversible through its own revision history, but a full backup is cheaper than finding out otherwise.

### 5.3 Watch for a caching plugin

If one is active, it may serve stale tables or interfere with the plugin's JavaScript. The symptom to look for is all the previous holders showing expanded with no "+" button, which means the script is not loading.

### 5.4 Agree who maintains this

The plugin is written to be handed on — plain PHP, no dependencies, one file to change if the data moves. But somebody should know it exists and where it lives before the person who installed it moves on.

### 5.5 Fix at source rather than in the plugin

Several things in this list are currently worked around in code: the encoding repair, the case-insensitive round matching, the tolerance for two rows flagged current. Each one is a small piece of complexity that exists only because the data is not quite right. If the data gets fixed, the code can get simpler — and it is worth doing in that order rather than accumulating more workarounds.
