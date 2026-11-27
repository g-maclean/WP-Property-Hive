=== Property Hive Smart Location 1.7 ===

Fills a "smart suburb" field (meta key _smart_suburb by default) for each property, compares it with
the manually entered suburb (_suburb by default), and can organise Location terms into a hierarchy.
It never touches the Location taxonomy assignments or the manual suburb field.

How the smart suburb is found
1. Postcode list (first). Built from the manual suburb field: for each full postcode, the suburbs
   used by properties there and how many (one postcode can have several). See the Postcodes tab:
   generate / rebuild, search, and your own edits (kept separately, survive a rebuild; "-" ignores
   a postcode). If the full postcode isn't listed, its sector (e.g. "B30 2") is used (optional).
   - one suburb: used, no other lookups
   - several: OpenStreetMap's suburb picks one of them; otherwise Claude chooses (restricted to
     those names); otherwise the most common is used (marked as such)
   When an existing property is checked, its own manual suburb is left out of the counts so it
   isn't matched against itself - the comparison stays an honest test.
2. Postcode not in the list: OpenStreetMap reverse lookup (coordinates; address search if none),
   max 1 request/second - OSM's "suburb".
3. If OSM has none, Claude is asked (answers below the minimum confidence are not used).
   Claude is encouraged to give EVERY suburb name that genuinely applies - e.g. a local area and
   the better-known suburb it sits in, or both suburbs on a boundary - most fitting first, up to 3
   (when choosing from the postcode list's suburbs, any number of those). Several names are saved
   comma-separated, like the manual field ("Jewellery Quarter, Birmingham City Centre").
4. If the same suburb is already used on the site (in either field) with a particular spelling,
   that spelling is used ("King's Norton" -> "Kings Norton").
Fields are only ever filled when EMPTY, so a smart suburb edited by hand is never overwritten.
To redo one, clear the field and click Retry. A smart suburb typed in by hand (or changed after
Smart Location saved it) is shown with the source "Entered by hand". Optional neighbourhood / quarter custom fields are
filled from OSM in the same way.

Comparison (Overview tab, Results tab filter, CSV export)
  match          same, ignoring case/punctuation; with several values on either side, any one
                 in common (each smart value is compared and the best result is used)
  near match     small spelling slip (1 character; 2 for names of 10+ letters)
  partial match  one contains the other as whole words ("Harborne" / "Harborne Village")
  mismatch       both filled in and different
  smart only     no manual suburb, smart suburb found
  manual only    looked up, no smart suburb found
  not checked    not looked up yet
  both empty     neither has a value
In Suggest mode the pending suggestion is compared (marked "suggested" in the export).

CSV export columns: Property ID, Property, Postcode, Manual suburb, Smart suburb, Comparison,
Smart suburb status (saved/suggested), Smart suburb source (postcode list / sector list, + OSM /
+ Claude / most common, OpenStreetMap, Claude), Claude confidence, Lookup status, the postcode
list's suburbs for that postcode (excluding the property itself),
plus any enabled neighbourhood/quarter fields. "Export all" or one comparison value at a time.

Modes: Suggest only (default; review on the Results tab, Apply / Apply all) or Assign automatically.

When it runs
- After each imported property (propertyhive_property_imported, and the Property Sales Online
  add-on's own hook); max 20 lookups / 60 seconds per import run.
- Every 10 minutes for new properties (added since activation).
- Existing properties: Overview tab > Process existing properties.

Organise Locations (tab)
Separate, manual tool: Claude proposes a hierarchy (max 3 levels) for the Location terms using their
names, parents, property counts and postcode districts, optionally following your own instructions.
Review the proposed tree and moves, apply selected, undo the last apply. Never renames or deletes.

Post meta: _phsl_status (one word) and _phsl (small flags array). Detail goes to monthly log files
in wp-content/uploads/ph-smart-location-logs/ (protected), viewable on the Log tab.

Starting again from scratch
Happens once, automatically, when a site is updated to 1.6 (not on every activation), and can be
run again at any time with "Start again from scratch" on the Overview tab:
- smart suburbs Smart Location wrote are cleared; ones edited by hand since are kept (from 1.6 the
  saved value is recorded so this can be told apart - values saved by 1.5 and earlier are treated
  as plugin-written), and values on properties Smart Location never assigned are kept
- every property's tracking state is reset
- the postcode list is rebuilt from the manual suburbs
Then run "Process existing properties" to fill them in again.

Upgrading to 1.5: the postcode list is generated automatically the first time.

Upgrading from 1.4: values saved under the old default key smart_suburb are renamed to
_smart_suburb (unless a different key had been set in Settings).

Upgrading from 1.3.x: the Location tracking used by earlier builds is removed and properties are
re-queued so the smart suburb field gets filled. Locations assigned or created earlier are left as
they are.

Claude API key: define( 'PHSL_ANTHROPIC_API_KEY', 'sk-ant-...' ); in wp-config.php (or Settings).
