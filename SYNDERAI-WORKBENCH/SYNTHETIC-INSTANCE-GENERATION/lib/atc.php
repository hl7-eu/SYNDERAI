<?php

/**
 * ATC Concept Property Resolver
 *
 * Resolves a ATC concept code to its human-readable properties by querying
 * the ART-DECOR terminology server. Results are cached locally (via the cache
 * helpers in cache.php) to avoid redundant network requests across runs.
 *
 * External dependencies:
 *   - ART-DECOR API  https://art-decor.org/exist/apps/api/terminology/
 *   - cache.php      inCACHE() / toCACHE() helpers
 *   - CACHEITEMSEPARATOR  constant used to delimit fields within a cache entry
 *
 * OID 2.16.840.1.113883.6.73 is the registered OID for the ATC code system.
 *
 * Returned property array shape (all keys always present):
 * [
 *   "code"    => string   ATC concept code (e.g. "J07AH08")
 *   "display" => string   en-US preferred designation
 * ]
 *
 */


/**
 * Retrieve clinical properties for a ATC concept code.
 *
 * Resolution follows a two-stage strategy:
 *
 *   1. **Cache hit** — if the code has been resolved before, the cached
 *      pipe-delimited record is split on CACHEITEMSEPARATOR and returned
 *      immediately. No network request is made.
 *
 *   2. **Live lookup** — the ART-DECOR REST API is queried for the concept
 *      in XML format. On a successful HTTP 200 response, the function extracts:
 *        - the preferred en-US designation
 *        - the ATC class
 *        - the measurement system (from the LFSN designation)
 *      The result is then written to the cache and returned.
 *
 *      Non-200 HTTP responses cause the function to return void (implicit NULL)
 *      as no explicit fallback is implemented for error cases.
 *
 * Differences from get_SNOMED_properties():
 *   - Simpler XML structure: no relation-type traversal needed.
 *   - No empty-input guard: passing an empty string will still trigger a
 *     (pointless) API call.
 *   - No retry loop on the HTTP status code.
 *   - No handling for inactive / retired concepts.
 *
 * @param  string $c  ATC concept code (e.g. "J07AH08" for heart rate).
 *
 * @return array|void  Associative array on success, or void (implicit NULL)
 *                     if the API returns a non-200 response. Array keys:
 *                     - "code"    (string)  The ATC code.
 *                     - "display" (string)  Preferred en-US designation.
 */
function get_ATC_properties($c) {

    // Stage 1: guard against empty input
    if (strlen($c) === 0) return [
        "code"    => "",
        "display" => "",
    ];

    // -------------------------------------------------------------------------
    // Stage 2: cache lookup
    // -------------------------------------------------------------------------
    $sincache = inCACHE("atc", $c);
    if ($sincache !== FALSE) {
        // Cache hit — split the stored pipe-delimited record back into fields.
        // Field order in cache: code | display | class | system
        $item = explode(CACHEITEMSEPARATOR, $sincache);
        return [
            "code"    => $item[0],
            "display" => trim($item[1])
        ];
    }

    // -------------------------------------------------------------------------
    // Stage 3: live lookup via ART-DECOR terminology API
    //
    // Endpoint pattern:
    //   GET https://art-decor.org/exist/apps/api/terminology/
    //           codesystem/2.16.840.1.113883.6.73/concept/{code}
    //           ?language=en-US&format=xml
    //
    // OID 2.16.840.1.113883.6.1 is the registered OID for ATC.
    // -------------------------------------------------------------------------
    $curl = curl_init();
    $url  = "https://art-decor.org/exist/apps/api/terminology/codesystem/2.16.840.1.113883.6.73/concept/"
          . $c
          . "?language=en-US&format=xml";

    curl_setopt_array($curl, [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/xml',
            'Content-Type: application/xml',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,   // seconds
    ]);

    $maxretries = 3;
    $code       = -1;
    // Re-read the HTTP status code up to 3 times (curl_exec is called only once;
    // this loop guards against a transient -1 return from curl_getinfo).
    while (200 !== $code && $maxretries-- > 0) {
        $response = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    }

    if (200 === $code) {
        $xml = simplexml_load_string($response);

        // Preferred en-US designation
        $cinfo         = $xml->xpath("/concept/designation[@lang='en-US'][@use='pref']");
        $preferredTerm = isset($cinfo[0]) ? (string) $cinfo[0] : "";

        // Persist to cache so subsequent runs skip the API call.
        // Cache record format (fields separated by CACHEITEMSEPARATOR):
        //   code | preferredTerm | class | system
        //
        toCACHE("atc", $c,   // TODO: fix to toCACHE("ATC", ...)
            $c             . CACHEITEMSEPARATOR .
            $preferredTerm . CACHEITEMSEPARATOR .
            "\n"
        );

        return [
            "code"    => trim($c),
            "display" => trim($preferredTerm),
        ];

        // Non-200 responses fall through and return void (implicit NULL).
        // Consider adding an explicit error return or log entry here.
    }
}