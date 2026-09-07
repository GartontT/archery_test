# The records database, and how it compares to the published pages

Date: 7 September 2026. Based on a full export of `db1249072_registration.Records` (1110 rows) taken from Plesk, compared against the seven live pages scraped on 2 September 2026.

## The table

`Records`, on `mysql1996int.cp.blacknight.com`, database `db1249072_registration`.

| Column | Type | Notes |
|---|---|---|
| `RecordCode` | int, primary key | Unique per row. Stable. |
| `RoundCode` | varchar(30) | Joins to `RoundTypes`. Case is inconsistent between the two tables. |
| `Peg` | varchar(10) | Field and 3D only: "Red Peg", "Blue Peg", "White Peg". |
| `Arrows` | int | Redundant with `RoundTypes.Arrows`. |
| `Bow` | varchar(1) | `R` `C` `B` `I` `L` `T` |
| `Class` | varchar(1) | blank, `M`, `J`, `C`, `Y` |
| `Gender` | text | `M`, `W`, `X` |
| `Score` | int | `0` on placeholder rows. |
| `Archer`, `TeamArcher_2`, `TeamArcher_3` | varchar(50) | Second and third are used only by team rounds. |
| `Date` | date | `0000-00-00` on placeholder rows. |
| `Club` | varchar(50) | |
| `Archived` | tinyint | The round is retired. 89 rows. |
| `Location` | varchar(7) | `Indoor` / `Outdoor` |
| `Type` | varchar(6) | `Target` / `Field` / `3D` |
| `Active` | tinyint | 705 set, 405 clear. |

## The code mappings, confirmed against the live site

Verified row by row against the published "WA 18 – 120 arrows" table.

**Bow:** `R` Recurve, `C` Compound, `B` Barebow, `I` Instinctive, `L` Longbow, `T` Traditional.

**Class and gender combine into the website's single label:**

| Class | Gender `M` | Gender `W` |
|---|---|---|
| *(blank)* | Gents | Ladies |
| `M` | 50+ Men | 50+ Women |
| `J` | U21 Gents | U21 Ladies |
| `C` | U18 Gents | U18 Ladies |
| `Y` | U15 Gents | U15 Ladies |

Gender `X` appears on four rows and is the mixed-team category.

## Three things the database already solves for us

**Placeholder rows are in the data.** 204 rows carry score `0`, no archer and a zero date. They are the database's way of saying a category exists but is unclaimed, and they are what the website prints as "no current record". The hand-built `layout.json` grid can be deleted.

**The grid matches exactly.** The "WA 18 – 120 arrows" round has 30 bow/class/gender combinations in the database; the live page has exactly 30 rows.

**The round list drives the page structure.** `Type`, `Location` and whether the round name contains "Team" place a round on one of the seven pages with no configuration. Only four rows out of 1110 use a `RoundCode` absent from `RoundTypes` (`Team`, `Mixed Team`, `WAF24`).

## `Active` is not quite what it was described as

It was described as a verification flag. The data says otherwise: across 802 groupings by round, bow, class, gender and peg, every multi-row group has exactly one `Active` row, regardless of whether it holds two rows or seven. That is a current-holder flag.

But it is not the same as "was once the record". 50+ Women Compound on WA18 120 holds Deirdre Rogers with 1069 from 2017, and Deidre Shannon's 1065 from 2020 sits alongside it with `Active = 0` — a score that never beat the standing record. So the inactive rows are a mix of genuine previous holders and submissions that never were records.

This confirms the original brief and validates the approach already built: derive the progression by walking each classification in date order and keeping only the scores that beat everything before them. Do not simply list the inactive rows as history.

## Data problems found

**Two rows flagged current for the same record.** Ten groups have two `Active` rows. Example: 50+ Men Compound on WA18 120 has both John Keenan's 1021 from 2022 and James O'Neill's 1119 from 2026. The site shows O'Neill. The plugin takes the higher score, which is right here, but the underlying rows want fixing.

**Character encoding is broken in the stored text.** Róisín Mooney's name comes back as `RÃ³isÃ­n Mooney`. That is UTF-8 that has been encoded a second time — the classic latin1/utf8 mix-up. It affects every name with a fada or an accent. The plugin has to detect and repair this on the way in, or every such name will be mangled on the public site.

**A club name is misspelled.** "Wickow Archers" in the database against "Wicklow Archers" on the page. Here the hand-typed page is the more accurate of the two.

**Names are spelled inconsistently between the two sources.** The website has "Beua Poydence" where the database has "Beau Poydence", and "Zach Wade" against "Zack Wade". Some of these are website typos; some may be database typos. Only Archery Ireland can adjudicate.

## The website is out of date, which is the point of the project

The comparison turned up records where the database is ahead of the published page:

| Round | Category | Site shows | Database has |
|---|---|---|---|
| 70m 144 arrows | Ladies Recurve | 1239, Roisin Mooney | **1247**, Róisín Mooney |
| 70m 144 arrows | U21 Gents Recurve | 1000, Zach Wade | **1161**, Zack Wade |
| WA1440 Provisional | 50+ Men Compound | 1350, Gerry Finnegan | **1355**, Alan Convery |

These are exactly the drift that a manual process produces, and exactly what switching to a live read fixes.

## One presentational decision needed

The website labels the same category differently on different pages. The indoor pages say "50+ Men" and "50+ Women"; the outdoor pages say "50+ Gents" and "50+ Ladies"; one archived outdoor table says "Youth U14 Ladies" where the current scheme says "U15 Ladies".

The database has one code for each, so generated pages will be internally consistent. That is an improvement, but it is a visible change on the outdoor pages, and it is worth telling Archery Ireland rather than letting them notice it.
