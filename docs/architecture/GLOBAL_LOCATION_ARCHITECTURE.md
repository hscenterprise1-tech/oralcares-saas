# OralCares Global Location & Address Intelligence Architecture
**Document Version:** 2.0  
**Phase Status:** Phase 1 (Database Foundation) & Phase 2 (Service, API & Importer) Complete  
**Layer:** Core Enterprise Foundation

---

## 1. Entity Hierarchy Standard

The global location intelligence subsystem provides a standardized geographic hierarchy for Indian healthcare enterprises and cross-border expansion:

```
Country (GeoCountry)
  └── State / Union Territory (GeoState)
        └── District (GeoDistrict)
              └── City / Urban Agglomeration (GeoCity)
                    └── Area / Locality (GeoArea)
                          └── Pincode (GeoPincode)
```

Address details specific to a physical premise remain separate:
- `Address Line 1` (Building / Premise / Unit)
- `Address Line 2` (Street / Crossroad / Sector)
- `Landmark`
- `Latitude` / `Longitude`

---

## 2. Master Tables & Normalized Schema

### `geo_countries`
- Primary country records.
- Fields: `id`, `code` (ISO 3166-1 alpha-2, e.g. `IN`), `name`, `phone_code` (`+91`), `currency_code` (`INR`), `is_active`, `timestamps`.

### `geo_states`
- States and Union Territories (all 36 States/UTs seeded).
- Fields: `id`, `country_id` (FK), `code` (ISO 3166-2:IN, e.g. `GJ`, `MH`), `name`, `gst_code` (2-digit GST state code), `type` (`state` | `union_territory`), `is_active`, `timestamps`.
- Unique: `[country_id, code]`.

### `geo_districts`
- Administrative revenue districts.
- Fields: `id`, `state_id` (FK), `name`, `is_active`, `timestamps`.
- Unique: `[state_id, name]`.

### `geo_cities`
- Municipal corporations, metropolitan areas, and urban hubs.
- Fields: `id`, `district_id` (FK), `state_id` (FK for direct query optimization), `name`, `slug`, `tier` (`tier_1`, `tier_2`, `tier_3`), `is_active`, `timestamps`.
- Unique: `[district_id, name]`.

### `geo_areas`
- Neighborhoods, commercial zones, and clinic cluster localities.
- Fields: `id`, `city_id` (FK), `name`, `slug`, `is_active`, `timestamps`.
- Unique: `[city_id, name]`.

### `geo_pincodes`
- 6-digit postal index numbers.
- Fields: `id`, `pincode` (6 digits), `area_id` (FK, nullable), `city_id` (FK), `district_id` (FK), `state_id` (FK), `latitude`, `longitude`, `is_active`, `timestamps`.
- Index: `pincode`, composite `[city_id, pincode]`, `[state_id, pincode]`, `[area_id, pincode]`.

---

## 3. Relationships & Model Architecture

- `GeoCountry` `hasMany(GeoState)`
- `GeoState` `belongsTo(GeoCountry)`, `hasMany(GeoDistrict)`, `hasMany(GeoCity)`, `hasMany(GeoPincode)`
- `GeoDistrict` `belongsTo(GeoState)`, `hasMany(GeoCity)`, `hasMany(GeoPincode)`
- `GeoCity` `belongsTo(GeoDistrict)`, `belongsTo(GeoState)`, `hasMany(GeoArea)`, `hasMany(GeoPincode)`
- `GeoArea` `belongsTo(GeoCity)`, `hasMany(GeoPincode)`
- `GeoPincode` `belongsTo(GeoArea)`, `belongsTo(GeoCity)`, `belongsTo(GeoDistrict)`, `belongsTo(GeoState)`

---

## 4. Normalization Rules

1. **Name Trimming & Casing:** All lookup and storage keys are stripped of leading/trailing whitespace and normalized with Title Case standard display.
2. **Slug Generation:** URL-safe lowercase slugs generated automatically via `Str::slug()`.
3. **Pincode Format:** Strict 6-digit regex `/^[1-9][0-9]{5}$/` (no alphabetic characters, cannot start with 0).
4. **Relational Integrity:** A city must strictly belong to its corresponding state and district.

---

## 5. GeoLocationService API Contract

The centralized `App\Services\GeoLocationService` encapsulates all resolution and lookup queries:

| Method | Parameters | Return Type | Description |
| :--- | :--- | :--- | :--- |
| `resolvePincode` | `string $pincode` | `?array` | Resolves 6-digit PIN code to country, state, district, city, areas, and coordinates. Cached 24h. |
| `getCountries` | `?string $search = null` | `Collection` | Lists active countries. |
| `getStates` | `?int $countryId, ?string $search` | `Collection` | Lists states and union territories for a country. |
| `getDistricts` | `?int $stateId, ?string $search` | `Collection` | Lists administrative districts for a state. |
| `getCities` | `?int $stateId, ?int $districtId, ?string $search` | `Collection` | Lists cities filtered by state or district. |
| `getAreas` | `int $cityId, ?string $search` | `Collection` | Lists localities/neighborhoods for a city. |
| `getPincodes` | `?int $areaId, ?int $cityId, ?string $search` | `Collection` | Lists postal codes for an area or city. |
| `search` | `string $query, array $filters, int $limit` | `array` | Multi-tier search across cities, areas, and pincodes with starts-with prioritization. |
| `validateHierarchy`| `array $location` | `array{valid: bool, errors: list<string>}` | Validates relational integrity between state, district, city, area, and pincode. |

---

## 6. HTTP API Endpoints (`/api/v1/geo/*`)

All endpoints require authentication (`auth`) and return uniform JSON shapes:

```
GET  /api/v1/geo/pincodes/{pincode}  # Resolve 6-digit PIN code hierarchy
GET  /api/v1/geo/countries           # List active countries
GET  /api/v1/geo/states?country_id=  # List states & union territories
GET  /api/v1/geo/districts?state_id= # List districts for state
GET  /api/v1/geo/cities?state_id=    # List cities for state/district
GET  /api/v1/geo/areas?city_id=      # List areas for city (city_id required)
GET  /api/v1/geo/pincodes?city_id=   # List pincodes
GET  /api/v1/geo/search?q=           # Multi-tier search
POST /api/v1/geo/validate            # Validate location payload consistency
```

### Pincode Lookup Response Schema:
```json
{
  "data": {
    "pincode": "380015",
    "country": { "id": 1, "code": "IN", "name": "India", "phone_code": "+91" },
    "state": { "id": 7, "code": "GJ", "name": "Gujarat", "gst_code": "24", "type": "state" },
    "district": { "id": 1, "name": "Ahmedabad" },
    "city": { "id": 1, "name": "Ahmedabad", "slug": "ahmedabad", "tier": "tier_1" },
    "areas": [
      { "id": 2, "name": "Satellite", "slug": "satellite", "latitude": 23.0304, "longitude": 72.5178 },
      { "id": 3, "name": "Vastrapur", "slug": "vastrapur", "latitude": 23.0350, "longitude": 72.5293 },
      { "id": 5, "name": "Prahlad Nagar", "slug": "prahlad-nagar", "latitude": 23.0076, "longitude": 72.5085 }
    ],
    "latitude": 23.0304,
    "longitude": 72.5178
  },
  "meta": {
    "resolved": true,
    "pincode": "380015"
  }
}
```

---

## 7. Caching Strategy

- **Driver:** Application standard cache store (Redis / Database / File).
- **Standard Key Structure:**
  - `geo:pincode:{pincode}`
  - `geo:countries`
  - `geo:states:{countryId}`
  - `geo:districts:{stateId}`
  - `geo:cities:{stateId}:{districtId}`
  - `geo:areas:{cityId}`
  - `geo:pincodes:{cityId}:{areaId}`
- **TTL:** 86,400 seconds (24 hours) for deterministic, fast `<10ms` response times.

---

## 8. Pincode Importer (`geo:import-pincodes`)

Artisan command for streaming ingestion of nationwide India postal directory:

```bash
# Dry run validation
php artisan geo:import-pincodes storage/app/geo/india-pincodes.csv --dry-run

# Live chunked import (500 rows per transaction)
php artisan geo:import-pincodes storage/app/geo/india-pincodes.csv --chunk=500
```

- **Features:** Streaming `fopen` chunking (zero memory ballooning), in-memory relationship resolution caches, header auto-mapping, transaction rollback on chunk failure, and idempotent resume safety.
- **Reporting Metrics:** Processed, Created, Updated, Skipped, and Malformed count table.

### 8.1 Verified Database Statistics (Nationwide Postal Dataset)
- **Source File:** `storage/app/geo/india-pincodes.csv` (39,737 source rows)
- **Distinct 6-Digit PIN Codes:** **23,915**
- **Postal Localities & Areas:** **38,433**
- **Cities & Postal Divisions:** **3,161**
- **Administrative Revenue Districts:** **593**
- **States & Union Territories:** **36** (All Indian States & UTs covered)
- **Total Pincode Mapping Records:** **39,109**
- **Import Audit Error Log:** `storage/logs/geo-import-errors.csv` (4 malformed rows flagged and documented)

---

## 9. Phase 3 Roadmap (OVS Frontend & Visual Studio Lab)

- Reusable React Component `@/ovs/location/OVSAddressFieldGroup.tsx`.
- Client Hook `useLocationResolver.ts` with React Query caching and pincode-first auto-population.
- Visual Studio **Location Intelligence Lab** showcasing:
  1. Pincode-first auto-completion.
  2. State → City → Area cascading selector.
  3. City → Area shortcut.
  4. Real-time relational mismatch shield.
