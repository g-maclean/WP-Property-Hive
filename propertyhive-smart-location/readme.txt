=== Property Hive Smart Location ===

Fills in a property's suburb (and optionally region) automatically. Only EMPTY fields are ever filled.

How it decides
1. OpenStreetMap reverse lookup from _latitude/_longitude (address search if no coordinates),
   max 1 request/second. The suburb comes from the OSM fields ticked in Settings
   (suburb / neighbourhood / quarter, in that order; only "suburb" by default).
2. If OSM has none of those, Claude is asked (address, postcode, coordinates and OSM's area names).
   Answers below the minimum confidence are recorded but not used.
3. Region (optional): rules first ("Birmingham South = Edgbaston, Selly Oak, B30, B15 2"),
   then Claude choosing from the allowed region values.
Suburbs already used on the site keep their existing spelling; new ones are flagged.

Modes
- Suggest only (default): results listed on the Results tab, apply one by one or all at once.
- Assign automatically: values are saved straight to the property.

When it runs
- New properties (created after activation): every 10 minutes via WP-Cron.
- Existing properties: Overview tab > Process existing properties (time/count limited, resumable).
- Test a property: Overview tab, nothing saved.

Claude API key
Preferred: define( 'PHSL_ANTHROPIC_API_KEY', 'sk-ant-...' ); in wp-config.php.
Alternatively paste it in Settings. Token usage of every call is written to the Log.
