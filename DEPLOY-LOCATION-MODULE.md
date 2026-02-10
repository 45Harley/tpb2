# Location Module Deployment - December 22, 2025

## Overview

This deployment introduces a database-driven location lookup system that **completely eliminates external API dependencies**. The zip_codes and towns tables (populated December 21, 2025) serve as the source of truth for all location data.

**No more Nominatim. No more geolocation prompts. Just zip code → confirm → done.**

## New Files to Deploy

### PHP Files
- `includes/location-lookup.php` - Core PHP functions for zip/town queries
- `api/zip-lookup.php` - API endpoint for JavaScript to call
- `test-location.php` - Test page (optional, for verification)

### JavaScript Files  
- `assets/location-module.js` - Client-side location modal and API calls

### Modified Files
- `save-profile.php` - Removed "create town on fly" logic
- `api/save-profile.php` - Same change

## Deployment Steps

1. **Create includes directory** (if not exists):
   ```bash
   mkdir -p /home/sandge5/public_html/tpb2/includes
   ```

2. **Upload new files:**
   - `/includes/location-lookup.php`
   - `/api/zip-lookup.php`
   - `/assets/location-module.js`
   - `/test-location.php` (optional)

3. **Replace modified files:**
   - `/save-profile.php`
   - `/api/save-profile.php`

4. **Test the API:**
   Visit: `https://tpb2.sandgems.net/test-location.php`
   - Test zip lookup (try 06260 for Putnam)
   - Test town search (try "Put" or "Hart")
   - Test full modal flow

## The New Flow

**Simple two-step process:**
1. User enters zip code → instant lookup from database
2. "You're in Putnam, CT. Correct?" → Yes saves, No lets them try again

**Fallback option:**
- If user doesn't know zip, they can search by town name
- Autocomplete queries local `towns` table

**What happens on confirm:**
- Town and state saved to user profile
- Coordinates from zip_codes used for district lookup
- Districts saved to user profile

## What Changed

| Before | After |
|--------|-------|
| Nominatim API for town search | Local `towns` table query |
| Nominatim API for coordinates | Local `zip_codes` table |
| Nominatim for reverse geocode | Eliminated (no geolocation) |
| Browser geolocation prompts | Eliminated |
| Create town if not exists | Lookup only (all towns pre-exist) |
| External API dependencies | Zero |

## API Endpoints

### POST /api/zip-lookup.php

**action=lookup_zip**
```json
Request:  { "action": "lookup_zip", "zip_code": "06260" }
Response: { 
  "status": "success", 
  "data": { 
    "zip_code": "06260", 
    "place": "Putnam", 
    "state_code": "CT", 
    "state_name": "Connecticut", 
    "state_id": 7, 
    "town_id": 123, 
    "county": "Windham", 
    "latitude": 41.9137, 
    "longitude": -71.9087 
  }
}
```

**action=search_towns**
```json
Request:  { "action": "search_towns", "query": "Put", "limit": 10 }
Response: { 
  "status": "success", 
  "data": [
    { "town_id": 123, "town_name": "Putnam", "state_code": "CT", ... }
  ], 
  "count": 8 
}
```

**action=get_coords**
```json
Request:  { "action": "get_coords", "town_name": "Putnam", "state_code": "CT" }
Response: { 
  "status": "success", 
  "data": { 
    "town_name": "Putnam", 
    "state_code": "CT", 
    "town_id": 123, 
    "latitude": 41.9137, 
    "longitude": -71.9087 
  }
}
```

## Next Steps (Not in this deployment)

After verifying the API works:
1. Update demo.php to use `TPBLocation.showZipEntryModal()` 
2. Update join.php to use the same
3. Remove all inline Nominatim/geolocation code from both files

## Database Dependencies

Requires these pre-populated tables:
- `zip_codes` - 41,481 US zip codes (imported Dec 21)
- `towns` - ~29,500 US towns (populated from zip_codes Dec 21)
- `states` - 50 US states + DC/territories

## Rollback

If issues occur:
1. Restore original `save-profile.php` and `api/save-profile.php`
2. New files can remain - they don't affect existing functionality until demo.php/join.php are updated
