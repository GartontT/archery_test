# Recon findings — archery.ie Irish Records

Date: 2 September 2026. Everything below was established from the public pages only (raw HTML saved in `research/raw/`). No backend access was used or available.

## How the pages are built today

archery.ie runs WordPress 7.0 with **Elementor 4.1.4** and Elementor Pro, plus Piotnet Addons for Elementor. There is no table plugin of any kind — no TablePress, no wpDataTables, no DataTables.js.

Every records table is **hand-typed raw HTML pasted into an Elementor "text-editor" widget**. The markup is literally `<table border="2" cellspacing="0" cellpadding="2">` with `<td><strong>Class</strong></td>` for headers — there is no `<thead>`, no CSS classes, no IDs. Each table sits under an Elementor `heading` widget that gives the round name (e.g. "WA 18 – 120 arrows").

Signs of hand entry are everywhere: stray non-breaking spaces inside score and date cells (`"\xa0935"`, `"Wicklow\xa0Archers"`), inconsistent capitalisation of the same phrase ("no current record" / "no current Record"), and repeated-value cells left as a bare `&nbsp;` rather than repeating the class name.

**Consequence:** replacing these tables means editing each Elementor page and swapping the text-editor widget for a shortcode. That is a manual, one-off migration of roughly 79 widgets across 7 pages. It is not hard, but it is not zero either, and it is worth knowing up front.

## Scale

| Page | Tables |
|---|---|
| Target Indoor Individual | 8 |
| Target Indoor Team | 3 |
| Target Outdoor Individual | 40 |
| Target Outdoor Team | 9 |
| Field | 8 |
| 3D Field | 4 |
| Archived Records | 7 |
| **Total** | **~79** |

## The four table shapes

The tables are not all the same shape. Any data contract has to cover all four.

1. **Individual** (indoor, outdoor) — `Class | Bow | Score | Archer | Date | Club`
2. **Field and 3D** — `Peg | Class | Bow | Score | Archer | Date | Club` (an extra leading classification column, e.g. "Red Peg")
3. **Team** — `Class | Bow | Score | Archer | Archer | Archer | Date | Club` — three archer columns for a team, and **two** for a mixed team, so the archer count varies within the same page
4. **Archived** — `Category | Bow | Score | Archer | Date | Club` (the first column is a round name rather than an age class)

The generalisation that covers all four: a record has an ordered list of **classification fields** (peg / class / category), a bow type, a score, an ordered list of **archers** (one or more), a date, and a club. The column headers are then a property of the table, not of the row.

## Two different meanings of "archived"

This matters and is easy to conflate.

- **Archived rounds** — a round that is no longer shot for (e.g. "FITA 18 – 12 arrows", the pre-2010 junior classes). These appear as whole tables, either at the bottom of a live page under a heading like "Archived records – no longer shot for", or on the separate Archived Records page. The record still stands; the *round* is retired.
- **Previous holders** — the record progression we actually want to expose behind the "+" button. This is not currently published anywhere on the site.

## Empty records

Some rows carry no record at all and are written as a single cell reading "no current record" spanning the archer columns, with score, date and club blank. The new implementation needs a first-class representation of this, not a hack.

## Date formats

Predominantly `DD-Mon-YY` (`25-Jan-15`), but the archived tables contain bare years (`1995`) and some cells are blank. A two-digit year is ambiguous and there are records from the 1990s alongside 2026, so the display layer should not try to reparse these — the data source should hand over something unambiguous, and the contract should say so.

## How Archery Europe does the "+" — solved, and it is pleasingly simple

Their page is plain server-rendered HTML with **no JavaScript library at all**. The pattern:

- All rows — current holder *and* every previous holder — are rendered into the same `<table>`, in score order.
- Every row carries a `RecType="37"` attribute identifying which record it belongs to.
- The current holder's row is visible. Every historical row gets `class="RecBroken Hidden"`.
- The current row's last cell holds `<i class="fa fa-plus-square fa-lg" onclick="expandRecord(this)"></i>`, which unhides the sibling rows sharing that `RecType`.

So there is no AJAX, no second request, no client-side data store. The history ships with the page and is simply hidden. That is the right model to copy: it is boring, it works without JavaScript for anyone who has it disabled (they see the current records, just not the history), and it needs no extra endpoint.

I would improve two things on their version: use a real `<button>` with `aria-expanded` instead of an `onclick` on an `<i>` so it is keyboard-accessible and screen-reader-correct, and use a data attribute rather than a made-up `RecType` HTML attribute.

## Their columns vs ours

Archery Europe shows `Record | Name | NOC | WAE Rec. | Prev. Rec. | Date | Place`. Note they carry a **"Prev. Rec."** column — the score this record beat. archery.ie has no equivalent. Worth asking whether the Irish data has it.
