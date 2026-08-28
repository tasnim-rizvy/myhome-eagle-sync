# Eagle API v3 — Field Check Summary

## Fields Checked

advertisedPrice, price, landSize, landSizeUnits, houseSizes, houseSizeUnits, propertyType, listingType, frontage, leftDepth, rightDepth, rearDepth, crossOver, toilets, carportSpaces, livingAreas, heatingCoolingFeatures, indoorFeatures, outdoorFeatures, ecoFriendlyFeatures, formattedAddress

## Data Availability

| ID | Type | Title | advertisedPrice | price | landSize | landSizeUnits | houseSizes | houseSizeUnits | propertyType | frontage | leftDepth | rightDepth | rearDepth | crossOver | toilets | carportSpaces | livingAreas | heatingCooling | indoor | outdoor | ecoFriendly |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1821640 | LAND | Discover Modern Comfort in Sought-After Leppington \| 2027 Q1 Registration | $785,000 | 785000 | 327.2 | Square metres | null | null | null | 12.7 | null | null | null | NONE | null | null | null | null | null | null | null |
| 1823043 | LAND | Premium Acreage Opportunity \| Q3 2026 Registration | $1,300,000 | 1300000 | 5689 | Square metres | null | null | null | 30.995 | null | null | null | NONE | null | null | null | null | null | null | null |
| 1798771 | RESIDENTIAL_SALE | Quality Family Living in One of Gilead's Fastest-Growing Communities | $1,530,000 | 1530000 | 616 | Square metres | null | SQUARE_METRES | House | null | null | null | null | null | null | null | 3 | REVERSE_CYCLE_AIR_CONDITIONING | ALARM_SYSTEM, BUILT_IN_WARDROBES, DISHWASHER, FLOORBOARDS, INTERCOM | FULLY_FENCED, OUTDOOR_ENTERTAINMENT_AREA, REMOTE_GARAGE | WATER_TANK |
| 1797615 | RESIDENTIAL_SALE | Turn the Key and Move In \| Spacious, Stylish & Brand New | $1,215,000 | 1215000 | 455 | Square metres | 223 | SQUARE_METRES | House | null | null | null | null | null | 0 | null | 3 | REVERSE_CYCLE_AIR_CONDITIONING | ALARM_SYSTEM, BUILT_IN_WARDROBES, DISHWASHER, DUCTED_VACUUM_SYSTEM, FLOORBOARDS | FULLY_FENCED, REMOTE_GARAGE, SECURE_PARKING | (empty) |
| 1817507 | LAND | Prime Austral Opportunity in a High-Growth South-West Corridor \| Flat Block \| Registration Expected Q3 (August) | $735,000 | 735000 | 357.4 | Square metres | null | null | null | 9.5 | null | null | null | NONE | null | null | null | null | null | null | null |
| 1817489 | LAND | Prime Leppington Land Opportunity on the Corner of George & Hulls Rd \| Registration soon | $710,000 | 710000 | 312.5 | Square metres | null | null | null | 12.5 | null | null | null | NONE | null | null | null | null | null | null | null |
| 1802959 | LAND | Premium Gilead Land Opportunity in a Growing New Community \| Registered Block \| Vendor Instructions Are Clear – Sell It! | Offers Invited | 740000 | 640.1 | Square metres | null | null | null | 38.47 | null | null | null | NONE | null | null | null | null | null | null | null |
| 1804093 | LAND | Premium Austral Land Opportunity in a Fast-Growing South West Sydney Pocket | $735,000 | 735000 | 324.6 | Square metres | null | null | null | null | null | null | null | NONE | null | null | null | null | null | null | null |
| 1803748 | LAND | Prime Spring Farm Land Opportunity \| 2028, Q1 Registration | $785,000 | 785000 | 455.3 | Square metres | null | null | null | null | null | null | null | NONE | null | null | null | null | null | null | null |
| 1804375 | LAND | Premium Austral Land Opportunity in a Fast-Growing South West Sydney Pocket \| Registered Land Ready for Immediate Construction | $685,000 | 685000 | 327.5 | Square metres | null | null | null | null | null | null | null | NONE | null | null | null | null | null | null | null |
| 1804400 | LAND | Premium Austral Land Opportunity in a Fast-Growing South West Sydney Pocket \| Registered Land Ready for Immediate Construction | $710,000 | 710000 | 322.4 | Square metres | null | null | null | null | null | null | null | NONE | null | null | null | null | null | null | null |
| 1804418 | LAND | Modern Leppington Living in a Prime Park Road Address \| Registered Land Ready for Immediate Construction | $740,000 | 740000 | 300.5 | Square metres | null | null | null | null | null | null | null | NONE | null | null | null | null | null | null | null |
| 1804454 | LAND | Prime Leppington Land Opportunity on the Corner of George & Hulls Rd \| Registration soon | $710,000 | 710000 | 300 | Square metres | null | null | null | 12 | null | null | null | NONE | null | null | null | null | null | null | null |
| 1825422 | RESIDENTIAL_SALE | Rare 640.1m² Registered Block \| Impressive 38m Frontage \| Home & Land Package Available \| Figtree Hill | Contact for price | 1400000 | 640.1 | Square metres | null | SQUARE_METRES | House | null | null | null | null | null | null | null | null | AIR_CONDITIONING | ALARM_SYSTEM, BROADBAND_INTERNET_AVAILABLE, BUILT_IN_WARDROBES, DISHWASHER, FLOORBOARDS, INTERCOM | COURTYARD, FULLY_FENCED, OUTDOOR_ENTERTAINMENT_AREA, REMOTE_GARAGE, SECURE_PARKING | (empty) |
| 1808611 | LAND | Prime Gilead Land Opportunity in a Growing South-West Sydney Community \| Registered Block | Contact Agent! | 750000 | 480 | Square metres | null | null | null | 15 | null | null | null | NONE | null | null | null | null | null | null | null |

## Notes

- **formattedAddress** — all 15 listings have values (not shown in table, checked separately)
- **advertisedPrice** — top-level Property field, not inside listingDetails; mix of numeric ("$785,000") and text ("Offers Invited", "Contact for price", "Contact Agent!")
- **price** — top-level Property field, numeric (dollars)
- **landSize / landSizeUnits** — top-level Property fields, all 15 have values
- **houseSizes / houseSizeUnits** — inside listingDetails; only `1797615` has a value (223); other RESIDENTIAL_SALE have units but no value
- **propertyType** — inside listingDetails; only RESIDENTIAL_SALE have "House"; all LAND are null
- **frontage** — inside listingDetails; only some LAND listings have values
- **leftDepth / rightDepth / rearDepth** — all null for every listing
- **crossOver** — all LAND = "NONE", all RESIDENTIAL_SALE = null
- **toilets** — only `1797615` = 0
- **carportSpaces** — all null
- **livingAreas** — only `1798771` = 3, `1797615` = 3
- **heatingCoolingFeatures** — only 3 RESIDENTIAL_SALE have data
- **indoorFeatures** — only 3 RESIDENTIAL_SALE have data
- **outdoorFeatures** — only 3 RESIDENTIAL_SALE have data
- **ecoFriendlyFeatures** — only `1798771` has WATER_TANK; others empty
