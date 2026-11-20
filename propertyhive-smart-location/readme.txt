=== Property Hive Smart Location 4.0 ===

Assigns each property's suburb as a Property Hive Location, creating new suburbs as TOP-LEVEL
Locations for the site to arrange in whatever hierarchy it wants. Optionally fills neighbourhood
and quarter custom fields. Makes no assumptions about the Location hierarchy or its depth.

How it decides
1. Skip: the property already has a Location known to be a suburb (see below), for example matched
   from its address by the import add-on. No lookups.
2. OpenStreetMap reverse lookup (coordinates; address search if none), max 1 request/second.
   The suburb is OSM's "suburb"; if OSM has none, Claude is asked.
3. An existing Location with that name is reused wherever it sits in the hierarchy (matched
   ignoring case/punctuation; if several share the name, the one whose parent appears in the OSM
   address wins). Otherwise a new top-level Location is created. Locations are only ever ADDED.
4. Neighbourhood / quarter (optional, off by default): OSM's values are saved to their own custom
   fields (default _neighbourhood / _quarter), only when empty. OSM only, no Claude.
A custom field can be used for the suburb instead of Locations.

Known suburbs
The option `phsl_suburb_terms` lists the Location ids Smart Location has matched or created as
suburbs. Moving them around the hierarchy doesn't matter. Deleted Locations are removed from it.
Upgrading from 3.x seeds it from the suburbs already assigned.

Modes: Suggest only (default; review on the Results tab, Apply / Apply all) or Assign automatically.

Imports
Runs after each property via propertyhive_property_imported (Property Import add-on, all formats)
and propertyhive_property_imported_property_sales_online_json. The Property Import add-on's address
matching REPLACES a property's Locations on every run, so suburbs Smart Location added are
re-applied straight afterwards from the property's saved list - no lookups. New lookups: max
20 / 60 seconds per import run, the rest via the 10-minute cron (new properties only).

Post meta (kept minimal)
_phsl_status  assigned | suggested | not_found | retry | error | skipped
_phsl         flags: t (time), o (OSM result), a (Claude: used/unsure/error/nokey), src,
              l (Location ids assigned, for re-applying), n (retry count), v (pending suggestion)

Log
Monthly files in wp-content/uploads/ph-smart-location-logs/ (protected; random file-name suffix),
last 6 months kept. Viewable/filterable/downloadable on the Log tab, including per property.

Claude API key: define( 'PHSL_ANTHROPIC_API_KEY', 'sk-ant-...' ); in wp-config.php (or Settings).
Adding a key automatically re-queues properties that were skipped for lack of one.
