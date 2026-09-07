# Installing, connecting, and switching the pages over

Written for whoever administers archery.ie. It assumes you can get into the WordPress dashboard, edit pages in Elementor, and put four lines into `wp-config.php`. Nothing here needs a developer.

Three stages, in this order. Each is safe to stop after.

---

## Stage 1 — install the plugin

1. Zip the `archery-records` folder so you have `archery-records.zip`.
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, **Install Now**, then **Activate**.

Nothing changes on the site. The plugin does nothing until a page uses its shortcode.

At this point it is running on a **snapshot** of the records database taken on 7 September 2026. The data is real, but it is frozen — new records will not appear until Stage 2.

### Check it works before touching anything real

1. Create a new page called something like "Records test" and leave it as a draft.
2. Edit it in Elementor, add a **Shortcode** widget, and put this in it:

   ```
   [archery_records page="target-indoor-individual"]
   ```

3. Preview. You should get eight tables, with a "+" at the end of rows that have previous holders.

If you see nothing, the shortcode name is wrong. If you see an orange box, read it — those messages appear only for logged-in editors, never for visitors.

---

## Stage 2 — connect the live database

The records are **not** in the WordPress database. They are in `db1249072_registration` on `mysql1996int.cp.blacknight.com`, while WordPress is on `mysql4543int`. Different server, same hosting account, so the plugin needs its own connection.

**First, make a read-only user.** In Plesk → **Databases**, add a user against `db1249072_registration` with **SELECT** permission and nothing else. Do not reuse `u1249072_admin` — that account can drop tables, and this plugin only ever reads.

**Then add four lines to `wp-config.php`**, above the line that says `/* That's all, stop editing! */`:

```php
define( 'ARCHERY_RECORDS_DB_HOST', 'mysql1996int.cp.blacknight.com' );
define( 'ARCHERY_RECORDS_DB_NAME', 'db1249072_registration' );
define( 'ARCHERY_RECORDS_DB_USER', 'the read-only user you just made' );
define( 'ARCHERY_RECORDS_DB_PASS', 'its password' );
```

**Then swap one file.** In the plugin folder, copy `examples/data-source-archery-ireland.php` over `includes/data-source.php`, replacing it.

**Then check.** Reload your test page while logged in. If the tables still appear, it is reading live. If you get an orange box saying the database could not be read, the connection failed — the reason is in the site's error log, and the likeliest causes are a typo in the four constants or the read-only user not having been granted access to that database.

You can also delete `data/fixtures.json` once this works. It is only the offline snapshot.

---

## Stage 3 — switch the real pages over

Take **Target Indoor Individual** first; it is a middling size.

1. Edit the page in Elementor.
2. Delete every round heading and every table on it. The shortcode renders the headings too, so they all go — the whole run of heading-and-table pairs.
3. In the space where they were, add one **Shortcode** widget:

   ```
   [archery_records page="target-indoor-individual"]
   ```

4. **Update**, then look at the live page.

Keep everything else — the intro text, the sponsor blocks, the page title. Only the round headings and their tables go.

If it goes wrong, Elementor keeps history: the panel at the bottom left → **Revisions** → pick the version from before you started.

### Then the other five

| Page | Shortcode |
|---|---|
| Target Indoor Individual | `[archery_records page="target-indoor-individual"]` |
| Target Indoor Team | `[archery_records page="target-indoor-team"]` |
| Target Outdoor Individual | `[archery_records page="target-outdoor-individual"]` |
| Target Outdoor Team | `[archery_records page="target-outdoor-team"]` |
| Field | `[archery_records page="records-field"]` |
| 3D Field | `[archery_records page="3d-field"]` |

Target Outdoor Individual has thirty-five tables, so that one takes a while. The rest are quick.

That is the last time anybody edits a records page.

### Leave the Archived Records page alone

The seventh page, **Archived Records**, is deliberately not covered. It holds pre-2010 junior records and pre-six-class field records — categories that no longer exist in the current scheme, and which are not separable in the database as a page of their own. Retired *rounds* now appear at the foot of their own page under "Archived records — no longer shot for", which covers most of what that page was for.

Decide separately whether to keep it as it is, or retire it once the new pages are live. Nothing breaks either way.

---

## Options you probably will not need

```
[archery_records page="records-field" heading_level="2" archived="no" history="no"]
```

- `heading_level` — heading tag for round titles, 2 to 6. Default 3, matching the current pages.
- `archived="no"` — leaves out the retired rounds at the foot of the page.
- `history="no"` — no "+" buttons on that page, current holders only.

## Troubleshooting

**A category shows "no current record" but should have one.** The database has a placeholder row for that category and no scoring row, or the scoring row has a different bow or class code. Fix it in the database.

**A whole round is missing.** Its `RoundCode` is not in `config/rounds.json`. Four rows in the database currently use codes that are not in the `RoundTypes` table at all (`Team`, `Mixed Team`, `WAF24`); those need tidying at source.

**Names with a fada look wrong.** They should not — the plugin repairs the double-encoding in the database. If they still look wrong, the encoding problem has been fixed at the database end and the repair is now doing damage; say so and it can be turned off.

**The tables show old data.** The plugin re-reads at most every fifteen minutes. Saving any page clears that immediately.

**Previous holders are all showing, expanded, with no "+".** JavaScript is not loading. The plugin deliberately falls back to showing the history rather than hiding it behind a button that cannot work, so this is the safe failure — but something is blocking the plugin's script, usually a caching or minifying plugin.
