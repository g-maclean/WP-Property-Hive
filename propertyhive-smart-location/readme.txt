=== Property Hive Smart Location 1.4 ===

Fills a "smart suburb" field (meta key smart_suburb by default) for each property, compares it with
the manually entered suburb (_suburb by default), and can organise Location terms into a hierarchy.
It never touches the Location taxonomy assignments or the manual suburb field.

How the smart suburb is found
1. OpenStreetMap reverse lookup (coordinates; address search if none), max 1 request/second.
   The smart suburb is OSM's "suburb".
2. If OSM has none, Claude is asked (answers below the minimum confidence are not used).
3. If the same suburb is already used on the site (in either field) with a particular spelling,
   that spelling is used ("King's Norton" -> "Kings Norton").
Fields are only ever filled when EMPTY, so a smart suburb edited by hand is never overwritten.
To redo one, clear the field and click Retry. Optional neighbourhood / quarter custom fields are
filled from OSM in the same way.

Comparison (Overview tab, Results tab filter, CSV export)
  match          same, ignoring case/punctuation, or one of several manual values
  near match     small spelling slip (1 character; 2 for names of 10+ letters)
  partial match  one contains the other as whole words ("Harborne" / "Harborne Village")
  mismatch       both filled in and different
  smart only     no manual suburb, smart suburb found
  manual only    looked up, no smart suburb found
  not checked    not looked up yet
  both empty     neither has a value
In Suggest mode the pending suggestion is compared (marked "suggested" in the export).

CSV export columns: Property ID, Property, Postcode, Manual suburb, Smart suburb, Comparison,
Smart suburb status (saved/suggested), Smart suburb source, Claude confidence, Lookup status,
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

Upgrading from 1.3.x: the Location tracking used by earlier builds is removed and properties are
re-queued so the smart suburb field gets filled. Locations assigned or created earlier are left as
they are.

Claude API key: define( 'PHSL_ANTHROPIC_API_KEY', 'sk-ant-...' ); in wp-config.php (or Settings).
