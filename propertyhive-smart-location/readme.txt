=== Property Hive Smart Location 3.0 ===

Assigns each property's suburb as a Location in a fixed City › Region › Suburb structure
(levels 1, 2 and 3 - the depth Property Hive fully supports), and optionally fills neighbourhood
and quarter custom fields.

How it decides
1. Skip: the property already has a suburb, i.e. any level-3 Location (for example matched from
   its address by the import add-on). No lookups.
2. OpenStreetMap reverse lookup (coordinates; address search if none), max 1 request/second.
   The suburb is OSM's "suburb"; if OSM has none, Claude is asked.
3. Existing suburbs (level-3 Locations) are matched by name, ignoring case/punctuation, preferring
   one in the property's city. A new suburb is created under a REGION of the property's CITY:
     city   = OSM city/town/village/municipality/county matched to a level-1 Location,
              else the "Default city" setting, else the only city
     region = the city's only region, else the one Claude chooses (it is given the regions and
              the suburbs already in each), else the "Fallback region" setting,
              else the property is held as "Needs region" - pick the region on the Results tab
              and click "Create & assign".
   Nothing is ever created deeper than level 3. Locations are only ever ADDED, never removed.
4. Neighbourhood / quarter (optional, off by default): OSM's values are saved to their own custom
   fields (default _neighbourhood / _quarter), only when empty. OSM only, no Claude.
A custom field can be used for the suburb instead of Locations.

Modes: Suggest only (default; review on the Results tab, Apply / Apply all) or Assign automatically.

Imports
Runs after each property via propertyhive_property_imported (Property Import add-on, all formats)
and propertyhive_property_imported_property_sales_online_json. The Property Import add-on's address
matching REPLACES a property's Locations on every run, so suburbs Smart Location added are
re-applied straight afterwards from the property's saved list - no lookups. New lookups: max
20 / 60 seconds per import run, the rest via the 10-minute cron (new properties only).

Post meta (kept minimal)
_phsl_status  assigned | suggested | review | not_found | retry | error | skipped
_phsl         flags: t (time), o (OSM result), a (Claude: used/unsure/error/nokey), src,
              l (Location ids assigned, for re-applying), n (retry count), v (pending suggestion)

Log
Monthly files in wp-content/uploads/ph-smart-location-logs/ (protected; random file-name suffix),
last 6 months kept. Viewable/filterable/downloadable on the Log tab, including per property.

Claude API key: define( 'PHSL_ANTHROPIC_API_KEY', 'sk-ant-...' ); in wp-config.php (or Settings).
Adding a key automatically re-queues properties that were skipped for lack of one.
