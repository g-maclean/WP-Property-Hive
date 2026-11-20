=== Property Hive Smart Location 2.0 ===

Assigns each property's suburb / neighbourhood / quarter as Property Hive Locations, creating
Locations that don't exist yet, and optionally fills a region custom field.

How it decides
1. Skip: the property already has a Location known to be a suburb, neighbourhood or quarter
   (e.g. matched from its address by the import add-on). No lookups.
2. OpenStreetMap reverse lookup (coordinates; address search if none), max 1 request/second.
   Every ticked area (suburb, neighbourhood, quarter - only suburb ticked by default) is assigned.
3. If OSM has none of them, Claude is asked for the suburb (answers below the minimum
   confidence are not used).
Matching is by name ignoring case/punctuation. New suburbs are created under the OSM town/city
Location (or as configured); new neighbourhoods/quarters under their suburb.
Locations are only ever ADDED, never removed. A custom field can be used instead of Locations.

Known Location types
The option `phsl_location_types` = array( 'suburb' => ids, 'neighbourhood' => ids, 'quarter' => ids )
grows as properties are looked up and is what step 1 checks against.

Imports
Runs after each property via propertyhive_property_imported (Property Import add-on, all formats)
and propertyhive_property_imported_property_sales_online_json. The Property Import add-on's address
matching REPLACES a property's Locations on every run (and clears them when nothing matches), so
Locations Smart Location added are re-applied straight afterwards from the property's saved list -
no lookups. New lookups: max 20 / 60 seconds per import run, the rest via the 10-minute cron.

Post meta (kept minimal)
_phsl_status  assigned | suggested | not_found | retry | error | skipped
_phsl         flags: t (time), o (OSM result), a (Claude: used/unsure/error/nokey), src,
              l (Location ids assigned, for re-applying), n (retry count), v/r (pending suggestion)

Log
Monthly files in wp-content/uploads/ph-smart-location-logs/ (protected; random file-name suffix),
last 6 months kept. Viewable/filterable/downloadable on the Log tab, including per property.

Claude API key: define( 'PHSL_ANTHROPIC_API_KEY', 'sk-ant-...' ); in wp-config.php (or Settings).
Re-queuing after adding a key happens automatically for properties skipped for lack of one.
