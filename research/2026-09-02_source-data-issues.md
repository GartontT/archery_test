# Data quality problems in the current records pages

Date: 2 September 2026. Found while scraping the seven live pages to build the sample data. Every one of these is a symptom of the tables being typed by hand; none of them is a fault in the new plugin. They are listed here because they are worth fixing at source, and because a couple of them are currently misrepresenting somebody's record on a public page.

## 1. Blank cells that put a record in the wrong category

On the Archived Records page, two rows have a blank Class cell, which by the convention of these tables means "same as the row above". The result is that the page currently reads as though a woman's record is held in a men's class.

In the "48 Targets" table (Field records prior to the 6-class change):

```
Red Peg | Junior Gents | Compound | 535 | Harry Lyster   | 26-Feb-06
        |              | Recurve  | 395 | Ian Carey      | 02-Sep-07
        |              | Recurve  | 407 | Naomi Murtagh  | 26-Feb-06   <- Class cell left blank
```

Both Recurve rows inherit "Junior Gents". Naomi Murtagh is presumably a Junior Ladies record. As published, the two rows also collide: they claim to be the same record with two different scores.

## 2. A missing cell that shifts a whole row

In the "24 Targets Marked" table on the same page, one row has six cells where the others have seven, so every value after the gap lands in the wrong column:

```
Peg | Class   | Bow | Score | Archer     | Date      | Club
    | Recurve |     |  205  | Ian Carey  | 17-Mar-07 | Woodbrook Archers
```

"Recurve" is sitting in the Class column and the Bow column is empty. The same thing happens in the "24 Targets Mixed" table.

## 3. Dates that cannot be read

Ten rows out of 678 have a date that no reasonable parser can interpret. Four are typos, four are blank, and two use a numeric format that is genuinely ambiguous.

| Page and round | Class | Bow | Archer | Date as written | Problem |
|---|---|---|---|---|---|
| target-outdoor-individual / Ladies/Provisional Double WA1440 | Ladies | Recurve | Sinead Cuthbert | `(empty)` | blank |
| target-outdoor-individual / Ladies/Provisional Double WA1440 | U18 Gents | Compound | Eimhin Gaynor | `(empty)` | blank |
| target-outdoor-individual / Ladies/Provisional Single WA1440 | U18 Ladies | Compound | Saffron Cullen | `10-06-18` | ambiguous: 10 June 2018 or 6 October 2018? |
| target-outdoor-individual / 70m – 72 arrows | Gents | Recurve | Keith Hanlon | `10-Jun13` | missing hyphen |
| target-outdoor-individual / 70m – 36 arrows | U18 Ladies | Compound | Saffron Cullen | `10-06-18` | ambiguous: 10 June 2018 or 6 October 2018? |
| target-outdoor-individual / 60m – 36 arrows | U18 Ladies | Compound | Saffron Cullen | `10-06-18` | ambiguous: 10 June 2018 or 6 October 2018? |
| target-outdoor-team / Mixed Team Round 70m - H2H (Archived) | Senior | Recurve | Keith Hanlon, Sinead Cuthbert | `(empty)` | blank |
| records-field / 24 Targets Unmarked | White Peg / U14 Ladies (Youth) | (blank) | Jessica Wall-Morris | `05-Mat-18` | "Mat" should be "Mar" |
| archived-results / 24 Targets Mixed | Red Peg / Recurve | (blank) | Sinead Cuthbert | `Mar-06` | no day |
| archived-results / 24 Targets Mixed | Blue Peg / Gents | Barebow | Rohan O’Duill | `Mar-06` | no day |

## 4. Blank Bow cells

Thirty-seven rows have no Bow value. Most are in tables where the bow is implied by the round, but the column still exists, so the cell reads as empty on the page. Counted by table:

| Page and round | Rows with no bow |
|---|---|
| records-field / 48 Targets | 6 |
| records-field / 24 Targets Mixed | 6 |
| 3d-field / 24 Targets Unmarked | 6 |
| records-field / 24 Targets Unmarked | 5 |
| 3d-field / 48 Targets Unmarked | 5 |
| records-field / 24 Targets Marked | 4 |
| archived-results / 24 Targets Mixed | 4 |
| archived-results / 24 Targets Unmarked | 1 |

## 5. Stray non-breaking spaces

Many cells contain a non-breaking space before or inside the value - `" 935"`, `"Wicklow Archers"`. Harmless to look at, but it means a search for "Wicklow Archers" does not match, and any future sorting or grouping on club name would treat the two spellings as different clubs. The plugin strips these on the way in.

## 6. Inconsistent placeholder wording

Empty categories are written as "no current record" in most places and "no current Record" in others. Cosmetic, but it is the kind of thing that makes automated checks harder than they need to be. The plugin now generates this text itself, so it is consistent from here on.

## What this means for the project

None of this blocks anything. The plugin handles all of it without falling over: bad dates sort to the start of a progression rather than crashing, blank bows just produce an empty cell, and the duplicate-category rows collapse into one record.

It is worth passing items 1 and 2 to whoever maintains the records database, because those two are wrong on a public page today and the new system will faithfully reproduce whatever the database says. If the errors exist only in the hand-typed HTML and the database is right, they disappear the moment the pages are switched over - which would be the best outcome, and is worth checking first.
