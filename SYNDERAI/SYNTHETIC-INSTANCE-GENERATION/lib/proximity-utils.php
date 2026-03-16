<?php

/**
 * Proximity Utilities
 *
 * Provides geospatial helper functions for locating the nearest healthcare
 * provider (hospital or primary-care facility) and the nearest individual
 * practitioner to a given patient position.
 *
 * Provider data is read from CSV files stored under the path defined by the
 * SYNTHEAINTL constant, following this directory convention:
 *
 *   <SYNTHEAINTL>/<country_code>/src/main/resources/providers/<type>.csv
 *
 * Two provider types are supported, each with a distinct CSV column layout:
 *
 *   primary_care_facilities.csv
 *     col 0  – (unused header guard)
 *     col 1  – name
 *     col 2  – address
 *     col 3  – city
 *     col 4  – state
 *     col 5  – zip / postcode
 *     col 6  – phone
 *     col 7  – latitude
 *     col 8  – longitude
 *
 *   hospitals.csv
 *     col 0  – id
 *     col 1  – (unused header guard)
 *     col 2  – name
 *     col 3  – address
 *     col 4  – city
 *     col 5  – state
 *     col 6  – zip / postcode
 *     col 7  – county
 *     col 8  – phone
 *     col 9  – type
 *     col 10 – ownership
 *     col 11 – emergency
 *     col 12 – quality
 *     col 13 – latitude
 *     col 14 – longitude
 *
 * Individual practitioner records are sourced from the global
 * $SYNTHETICPROVIDERS array (loaded via constants.php).
 *
 * Geocoding reference: OpenCage Data API (https://opencagedata.com/api).
 * A sample API response is included at the bottom of this file.
 */

/* INCLUDES */
include_once("../CONSTANTS/constants.php");


/**
 * Find the closest healthcare provider and practitioner to a patient's location.
 *
 * The function performs two independent nearest-neighbour searches:
 *
 *   1. **Facility search** — scans the appropriate CSV file for the patient's
 *      country and returns the facility (hospital or primary-care centre)
 *      with the shortest great-circle distance to the patient.
 *
 *   2. **Practitioner search** — iterates over the global $SYNTHETICPROVIDERS
 *      array and returns the individual provider person closest to the patient,
 *      regardless of country boundaries.
 *
 * The two results are combined into a single associative array. If no facility
 * is found, NULL is returned and a warning is logged.
 *
 * Known issue: when an invalid $providertype is supplied, the fallback
 * assignment uses `===` (comparison) instead of `=` (assignment), so the
 * invalid value is silently kept rather than corrected.
 *
 * @global array $SYNTHETICPROVIDERS  Flat array of practitioner records, each
 *                                    containing at least the keys:
 *                                    "lat", "long", "prefix", "given", "family".
 *
 * @param  string      $pcountry      Patient's country, accepted as either an
 *                                    ISO 3166-1 alpha-2 code or a full country
 *                                    name. Normalised internally via
 *                                    {@see unifyCountryCodeName()}.
 * @param  float       $platitude     Patient's latitude in decimal degrees.
 * @param  float       $plongitude    Patient's longitude in decimal degrees.
 * @param  string      $providertype  Type of facility to search for. Must be
 *                                    either "hospitals" or
 *                                    "primary_care_facilities".
 *
 * @return array|null  Associative array describing the closest provider, or
 *                     NULL if no facility could be found. Array keys:
 *                     - "identifier"  (string)      UUID for this result record.
 *                     - "type"        (string)      The resolved provider type.
 *                     - "name"        (string)      Facility name.
 *                     - "address"     (string)      Street address.
 *                     - "city"        (string)      City.
 *                     - "postcode"    (string)      Postal / ZIP code.
 *                     - "country"     (string)      Full country name.
 *                     - "distance"    (int)         Distance to patient in km (rounded).
 *                     - "practitioner" (array|null) Closest individual practitioner:
 *                         - "prefix"   (string)  Honorific (e.g. "Dr.").
 *                         - "given"    (string)  First name.
 *                         - "family"   (string)  Last name.
 *                         - "distance" (int)     Distance to patient in km (rounded).
 */
function getClosestProvider($pcountry, $platitude, $plongitude, $providertype) {

    global $SYNTHETICPROVIDERS;

    // Normalise the country input to a consistent code + full name pair
    list($eucountrycode, $eucountryname) = unifyCountryCodeName($pcountry);

    // Validate provider type; fall back to primary_care_facilities if unknown.
    // NOTE: the fallback line below uses === (comparison) instead of = (assignment)
    // and therefore does NOT actually reset $providertype — this is a known bug.
    if (!($providertype === "hospitals" or $providertype === "primary_care_facilities")) {
        lognlsev(0, "ERROR", "......... +++ Provider type $providertype not allowed.\n");
        $providertype === "primary_care_facilities";  // BUG: should be = not ===
    }

    // -------------------------------------------------------------------------
    // Pass 1: find the nearest facility (hospital or primary-care centre)
    // -------------------------------------------------------------------------

    $closestkm    = 384400;  // Initialise to lunar distance (km) — effectively infinity
    $closestcand  = NULL;

    $providerhandle = fopen(
        SYNTHEAINTL . "/" . $eucountrycode . "/src/main/resources/providers/" . $providertype . ".csv",
        "r"
    );

    while (($csvline = fgetcsv($providerhandle, 1000, ",", '"', '\\')) !== FALSE) {

        // Skip the header row — hospitals use col 1 as guard, PCFs use col 2
        if ($csvline[1] !== 'name' and $csvline[2] !== 'name') {

            // Latitude/longitude column indices differ between file types
            if ($providertype === 'hospitals') {
                $providerlat  = $csvline[13];
                $providerlong = $csvline[14];
            } else {
                $providerlat  = $csvline[7];
                $providerlong = $csvline[8];
            }

            $dist = distance($platitude, $plongitude, $providerlat, $providerlong);

            // Keep this facility if it is closer than the current best candidate
            if ($dist < $closestkm) {
                $closestkm    = $dist;
                $closestcand  = $csvline;
            }
        }
    }

    fclose($providerhandle);

    // -------------------------------------------------------------------------
    // Pass 2: find the nearest individual practitioner from the global list
    // -------------------------------------------------------------------------

    $providerdistkm       = 384400;  // Initialise to lunar distance (km) — effectively infinity
    $closestproviderperson = NULL;

    foreach ($SYNTHETICPROVIDERS as $sp) {
        $dist = distance($platitude, $plongitude, $sp["lat"], $sp["long"]);
        if ($dist < $providerdistkm) {
            $providerdistkm        = $dist;
            $closestproviderperson = $sp;
        }
    }


    // Build the practitioner sub-array (or leave it NULL if no person was found)
    $hprovider = NULL;
    if ($closestproviderperson !== NULL) {
        $hprovider = [
            "prefix"   => $closestproviderperson["prefix"],
            "given"    => $closestproviderperson["given"],
            "family"   => $closestproviderperson["family"],
            "gender"   => $closestproviderperson["gender"],
            "distance" => round($providerdistkm),
        ];
    }

    // -------------------------------------------------------------------------
    // Build and return the final result array
    // -------------------------------------------------------------------------

    if ($closestcand !== NULL) {
        if ($providertype === 'hospitals') {
            $RES = [
                "identifier"   => uuid(),
                "type"         => $providertype,
                "name"         => $closestcand[2],
                "address"      => trim($closestcand[3]),
                "city"         => trim($closestcand[4]),
                "postcode"     => trim($closestcand[6]),
                "country"      => $eucountryname,
                "distance"     => round($closestkm),
                "practitioner" => $hprovider,   // nearest individual practitioner
            ];
        } else {
            // primary_care_facilities — column offsets are different from hospitals
            // attached: also a named practitioner specific to this facility type
            $RES = [
                "identifier"   => uuid(),
                "type"         => $providertype,
                "name"         => $closestcand[1],
                "address"      => trim($closestcand[2]),
                "city"         => trim($closestcand[3]),
                "postcode"     => trim($closestcand[5]),
                "country"      => $eucountryname,
                "distance"     => round($closestkm),
                "practitioner" => $hprovider,   // nearest individual practitioner
            ];
        }
    } else {
        // No facility was found for this country / provider type combination
        $RES = NULL;
        lognlsev(3, "WARN",
            "......... +++ No provider $providertype found for patient in $eucountrycode, " .
            "lastest distance $dist: Patient $platitude $plongitude Provider $providerlat $providerlong\n"
        );
    }

    return $RES;
}


/**
 * Calculate the great-circle distance between two geographic coordinates.
 *
 * Uses the spherical law of cosines to approximate the shortest path over
 * the Earth's surface. The result is accurate to within ~0.5 % for most
 * practical distances and is sufficient for provider proximity searches.
 *
 * Formula:
 *   d = acos( sin(φ₁)·sin(φ₂) + cos(φ₁)·cos(φ₂)·cos(Δλ) ) · R
 *
 * where φ is latitude, λ is longitude, and R = 6 371 km (mean Earth radius).
 * Internally the function converts the arc to miles via the nautical-mile
 * factor (1° = 60 NM × 1.1515 statute miles) and then converts to kilometres.
 *
 * @param  float|string $lat1  Latitude of point A in decimal degrees.
 * @param  float|string $lon1  Longitude of point A in decimal degrees.
 * @param  float|string $lat2  Latitude of point B in decimal degrees.
 * @param  float|string $lon2  Longitude of point B in decimal degrees.
 *
 * @return float  Distance between the two points in kilometres. Returns 0
 *                immediately when both points are identical.
 */
function distance($lat1, $lon1, $lat2, $lon2) {
    // Short-circuit for identical coordinates
    if (($lat1 == $lat2) && ($lon1 == $lon2)) {
        return 0;
    }

    $theta = (float) $lon1 - (float) $lon2;

    // Spherical law of cosines
    $dist = sin(deg2rad((float) $lat1)) * sin(deg2rad((float) $lat2))
          + cos(deg2rad((float) $lat1)) * cos(deg2rad((float) $lat2)) * cos(deg2rad($theta));

    $dist  = acos($dist);       // Arc in radians
    $dist  = rad2deg($dist);    // Arc in degrees
    $miles = $dist * 60 * 1.1515;  // Convert to statute miles (1° ≈ 60 NM × 1.1515)

    return $miles * 1.609344;   // Convert statute miles → kilometres
}


/*
 * ---------------------------------------------------------------------------
 * Geocoding reference — OpenCage Data API
 * ---------------------------------------------------------------------------
 * Endpoint:
 *   https://api.opencagedata.com/geocode/v1/json
 *
 * Example query (address → coordinates):
 *   https://api.opencagedata.com/geocode/v1/json
 *     ?q=Rue%20Henri%20Lambert%2C%205570%20Felenne%2C%20Belgium
 *     &key=<API_KEY>
 *     &no_annotations=1
 *     &language=en
 *
 * Relevant response fields used by this application:
 *   results[n].geometry.lat  — decimal latitude
 *   results[n].geometry.lng  — decimal longitude
 *   results[n].components.country_code  — ISO 3166-1 alpha-2 country code
 *   results[n].components.country       — full country name
 *   results[n].confidence               — result quality score (1–10)
 *
 * Sample response (abbreviated):
 * {
 *   "results": [
 *     {
 *       "components": {
 *         "ISO_3166-1_alpha-2": "BE",
 *         "country": "Belgium",
 *         "country_code": "be",
 *         "postcode": "5570",
 *         "road": "Rue Henri Lambert",
 *         "village": "Felenne"
 *       },
 *       "confidence": 9,
 *       "formatted": "Rue Henri Lambert, 5570 Felenne, Belgium",
 *       "geometry": { "lat": 50.0698377, "lng": 4.8483229 }
 *     }
 *   ],
 *   "status": { "code": 200, "message": "OK" }
 * }
 *
 * Full API documentation: https://opencagedata.com/api
 * ---------------------------------------------------------------------------
 */