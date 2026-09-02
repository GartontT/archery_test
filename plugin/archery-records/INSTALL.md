# Installing and switching the pages over

Written for whoever administers archery.ie. It assumes you can get into the WordPress dashboard and edit pages in Elementor. Nothing here needs a developer.

Do the whole thing on a staging copy of the site first if there is one. If there isn't, do one page and look at it before doing the rest.

## 1. Install the plugin

1. Zip the `archery-records` folder so you have `archery-records.zip`.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Choose the zip, click **Install Now**, then **Activate**.

Nothing changes on the site yet. The plugin does nothing until a page uses its shortcode.

At this point the plugin is running on **sample data** bundled with it. The current record holders are real (they were copied from the live site), but every previous holder behind a "+" is invented placeholder data with names like "A. Sample". That is deliberate, so the tables can be looked at before the real database is connected.

## 2. Check it works, on one page, before touching the real ones

1. Create a new page, call it something like "Records test", and leave it as a draft.
2. Edit it and add a **Shortcode** widget (in Elementor, search the widget panel for "Shortcode").
3. Put this in it:

   ```
   [archery_records page="target-indoor-individual"]
   ```

4. Preview the page. You should get every Target Indoor Individual table, with a "+" at the end of each row that has history.

If you see nothing at all, the shortcode name is wrong. If you see a message in an orange box, read it — those messages only appear for logged-in editors, never for visitors.

## 3. Switch a real page over

Take **Target Indoor Individual** first, because it is a middling size.

1. Edit the page in Elementor.
2. Find the first round heading ("WA 18 – 120 arrows") and the table under it.
3. Delete the heading widget and the text-editor widget holding the table. Then do the same for every other heading-and-table pair on the page. Yes, all of them — the shortcode renders the headings too.
4. In the space where they were, add one **Shortcode** widget containing:

   ```
   [archery_records page="target-indoor-individual"]
   ```

5. **Update**, then look at the live page.

Keep anything else on the page — the intro text, the sponsor blocks, the page title. Only the round headings and their tables go.

If it goes wrong, Elementor keeps revision history: **the history panel at the bottom left → Revisions**, and pick the version from before you started.

## 4. Do the other six

Same again, one shortcode per page:

| Page | Shortcode |
|---|---|
| Target Indoor Individual | `[archery_records page="target-indoor-individual"]` |
| Target Indoor Team | `[archery_records page="target-indoor-team"]` |
| Target Outdoor Individual | `[archery_records page="target-outdoor-individual"]` |
| Target Outdoor Team | `[archery_records page="target-outdoor-team"]` |
| Field | `[archery_records page="records-field"]` |
| 3D Field | `[archery_records page="3d-field"]` |
| Archived Records | `[archery_records page="archived-results"]` |

Target Outdoor Individual has forty tables on it, so that one takes a while. The rest are quick.

That is the last time anybody has to do this. From then on, a new round or a new category appears on the site because it appeared in the database.

## 5. Options you probably will not need

The shortcode takes three extra settings:

```
[archery_records page="records-field" heading_level="2" archived="no" history="no"]
```

- `heading_level` — the heading tag used for round titles, 2 to 6. Default 3, which matches the current pages.
- `archived="no"` — leaves out the "no longer shot for" tables at the bottom of a page.
- `history="no"` — no "+" buttons anywhere on that page, current holders only.

## 6. When the real database is connected

Someone with access to the server replaces one file, `includes/data-source.php`, following `DATA-CONTRACT.md`. Nothing on any page changes and no page needs re-editing.

After that switch, load each of the seven pages **while logged in** and look for an orange box at the bottom. That is where the plugin reports rounds it found in the database but does not have a table for. It is the one failure worth checking for by eye.

## Troubleshooting

**A table shows "no current record" for something that does have a record.** The class or bow name in the database does not exactly match what `config/layout.json` expects — "50+ Men" versus "50 + Men", say. Fix it in the database if you can; otherwise edit `config/layout.json`.

**A whole round is missing.** Look for the orange notice at the bottom of the page when logged in. It lists rounds the database has that the plugin does not recognise.

**The tables are showing old data.** The plugin re-reads at most every fifteen minutes. Saving any page clears that immediately.

**Everything is showing the previous holders already expanded.** JavaScript is not loading. The plugin deliberately falls back to showing the full history rather than hiding it behind a button that cannot work — so this is the safe failure, but it means something is blocking the plugin's script, usually a caching or minifying plugin.
