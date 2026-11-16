=== Property Hive OSM Location Checker ===

Standalone helper plugin. Copy the `propertyhive-osm-location-checker` folder into wp-content/plugins and activate it
(Property Hive must be active). Find it under Property Hive > OSM Location Checker.

What it does
- Looks up each property on OpenStreetMap (Nominatim reverse geocode from _latitude/_longitude; optional address/postcode
  search fallback when no coordinates).
- Compares the OSM neighbourhood / suburb (and broader areas: quarter, village, town, city, etc.) against the
  property's assigned Location terms. Names are normalised (case, accents, punctuation, "&"/"and", "St"/"Saint").
- "Would assign" = the first OSM field, most specific first, whose value exists as a Location.
- Runs via AJAX, one property per request, for a chosen number of minutes / properties. At least 1s between
  requests to the public OSM server (default 1.5s), enforced server-side with a lock so multiple tabs can't exceed it.
  Stops automatically on HTTP 429/403. Resumable: already-checked properties are skipped.
- Stats: headline accuracy, outcome breakdown, per-OSM-field accuracy, common mismatches, OSM areas with no Location,
  suggestions for unassigned properties. Results tab (filter/search), CSV export, and a log.
- "Re-compare using cached OSM data" re-runs the comparison against your current Locations without hitting OSM.

Outcomes
match, partial (an OSM value matches but a different field would be picked first), broader (would pick a parent),
narrower (would pick a child), mismatch, no_term (OSM area has no Location), no_osm_data, unassigned, error.

Aliases
add_filter( 'phosm_location_aliases', function () { return array( 'Clifton Village' => 123 ); } );
