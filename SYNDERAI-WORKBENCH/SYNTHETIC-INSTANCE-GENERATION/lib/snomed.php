<?php

/**
 * SNOMED CT Concept Property Resolver
 *
 * Resolves a SNOMED CT concept code to its human-readable properties by
 * querying the ART-DECOR terminology server. Results are cached locally
 * (via the cache helpers in cache.php) to avoid redundant network requests
 * across runs.
 *
 * External dependencies:
 *   - ART-DECOR API  https://art-decor.org/exist/apps/api/terminology/
 *   - cache.php      inCACHE() / toCACHE() helpers
 *   - common-utils   lognlsev() for levelled, colour-coded log output
 *   - CACHEITEMSEPARATOR  constant used to delimit fields within a cache entry
 *
 * SNOMED CT relation type codes used:
 *   762949000  — "Has precise active ingredient"
 *   411116001  — "Has manufactured dose form"
 *
 * Returned property array shape (all keys always present):
 * [
 *   "code"                       => string   SNOMED CT concept code
 *   "preferredTerm"              => string   en-US preferred designation
 *   "fullySpecifiedName"         => string   en-US fully specified name (FSN)
 *   "activeIngredient"           => string   Active ingredient label
 *   "activeIngredientCode"       => string   Active ingredient SNOMED code
 *   "manufacturedDoseForm"       => string   Dose-form SNOMED code
 *   "manufacturedDoseFormDisplay"=> string   Dose-form human-readable label
 * ]
 */


/**
 * Retrieve clinical properties for a SNOMED CT concept code.
 *
 * Resolution follows a three-stage strategy:
 *
 *   1. **Empty guard** — if $c is an empty string, an array of empty strings
 *      is returned immediately without any I/O.
 *
 *   2. **Cache hit** — if the code has been resolved before, the cached
 *      pipe-delimited record is split on CACHEITEMSEPARATOR and returned.
 *      No network request is made.
 *
 *   3. **Live lookup** — the ART-DECOR REST API is queried for the concept
 *      in XML format. The function then branches on the concept's statusCode:
 *
 *        a. "active" — extracts the preferred term, FSN, active ingredient,
 *           and manufactured dose form from the XML, stores the result in the
 *           cache, and returns the property array.
 *
 *        b. retired / inactive — follows the concept's replacement association
 *           (if present) by recursively calling get_SNOMED_properties() with
 *           the replacement code. If no replacement is available, the empty
 *           properties array is returned and an error is logged.
 *
 *        c. non-200 HTTP response — logs nothing (commented out) and returns
 *           the empty properties array.
 *
 * The live lookup retries reading the HTTP status code up to 3 times before
 * accepting the result; note that curl_exec() is only called once and the
 * retry loop only re-reads CURLINFO_HTTP_CODE, which is idempotent.
 *
 * @param  string $c     SNOMED CT concept code (numeric string, e.g. "372687004").
 *                       An empty string triggers an immediate empty-result return.
 * @param  string $hint  A hint string (from the sources) shown in case of errors
 *                       that may indicate what the code display was for a code
 *
 * @return array  Associative array with the following keys (all strings):
 *                - "code"                        The resolved SNOMED CT code.
 *                - "preferredTerm"               Preferred en-US designation.
 *                - "fullySpecifiedName"           Fully specified name (FSN).
 *                - "activeIngredient"             Active ingredient label
 *                                                 (relation type 762949000).
 *                - "activeIngredientCode"         Active ingredient SNOMED code.
 *                - "manufacturedDoseForm"         Dose-form SNOMED code
 *                                                 (relation type 411116001).
 *                - "manufacturedDoseFormDisplay"  Dose-form human-readable label.
 *                All values are empty strings when the concept cannot be resolved.
 */
function get_SNOMED_properties($c, $hint = "no hints") {

    // Canonical empty result — returned whenever resolution is not possible
    $EMPTYPROPERTIES = [
        "code"                        => "",
        "preferredTerm"               => "",
        "fullySpecifiedName"          => "",
        "activeIngredient"            => "",
        "activeIngredientCode"        => "",
        "manufacturedDoseForm"        => "",
        "manufacturedDoseFormDisplay" => "",
    ];
    // init
    $preferredTerm = "";

    // Stage 1: guard against empty input
    if (strlen($c) === 0) return $EMPTYPROPERTIES;

    // temporary shortcut patches from validation marathon
    if ($c === "66348005") $c = "386216000";  // Childbirth
    if ($c === "308113008") $c = "282290005"; // Review of imaging findings
    if ($c === "281790002") $c = "281790008"; // Intravenous antibiotic therapy
    if ($c === "281790004") $c = "281790008"; // Intravenous antibiotic therapy
    if ($c === "304449002") $c = "117722009"; // Measurement of circulating antiplatelet antibody
    if ($c === "surprisingly") $c = "773996000"; // Transcatheter aortic valve implantation
    if ($c === "25132001") $c = "26212005"; // Replacement of aortic valve
    if ($c === "14442009") $c = "63697000"; // Cardiopulmonary bypass operation

    // -------------------------------------------------------------------------
    // Stage 2: cache lookup snomed and snomed-replacements
    // -------------------------------------------------------------------------
    $sincache = inCACHE("snomed", $c);
    if ($sincache !== FALSE) {
        // Cache hit — split the stored pipe-delimited record back into fields.
        // Field order in cache: code | preferredTerm | fullySpecifiedName |
        //                       activeIngredient | activeIngredientCode |
        //                       manufacturedDoseForm | manufacturedDoseFormDisplay
        $item = explode(CACHEITEMSEPARATOR, $sincache);

        return [
            "code"                        => $item[0],
            "preferredTerm"               => $item[1],
            "fullySpecifiedName"          => $item[2],
            "activeIngredient"            => $item[3],
            "activeIngredientCode"        => $item[4],
            "manufacturedDoseForm"        => $item[5],
            "manufacturedDoseFormDisplay" => trim($item[6])
        ];
    }
    $sincache = inCACHE("snomed-replacements", $c);
    if ($sincache !== FALSE) {
        // Cache hit — split the stored pipe-delimited record back into fields.
        // Field order in cache: code | preferredTerm | fullySpecifiedName |
        //                       activeIngredient | activeIngredientCode |
        //                       manufacturedDoseForm | manufacturedDoseFormDisplay
        $item = explode(CACHEITEMSEPARATOR, $sincache);

        lognlsev(2, INFO, "......... SNOMED code $c immediate replacement by $item[0] $item[1]");

        return [
            "code"                        => $item[0],
            "preferredTerm"               => $item[1],
            "fullySpecifiedName"          => $item[2],
            "activeIngredient"            => $item[3],
            "activeIngredientCode"        => $item[4],
            "manufacturedDoseForm"        => $item[5],
            "manufacturedDoseFormDisplay" => trim($item[6])
        ];
    }

    // -------------------------------------------------------------------------
    // Stage 3: live lookup via ART-DECOR terminology API
    //
    // Endpoint pattern:
    //   GET https://art-decor.org/exist/apps/api/terminology/
    //           codesystem/2.16.840.1.113883.6.96/concept/{code}
    //           ?language=en-US&format=xml
    //
    // OID 2.16.840.1.113883.6.96 is the registered OID for SNOMED CT.
    // -------------------------------------------------------------------------
    $curl = curl_init();
    $url  = "https://art-decor.org/exist/apps/api/terminology/codesystem/2.16.840.1.113883.6.96/concept/"
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

        // Check whether the concept is active or retired
        $cinfo      = $xml->xpath("/concept/@statusCode");
        $statusCode = isset($cinfo[0]) ? (string) $cinfo[0] : "";

        if ($statusCode === 'active') {
            // -----------------------------------------------------------------
            // Stage 3a: active concept — extract all required properties
            // -----------------------------------------------------------------

            // Preferred en-US designation
            $cinfo         = $xml->xpath("/concept/designation[@lang='en-US'][@use='pref']");
            $preferredTerm = isset($cinfo[0]) ? (string) $cinfo[0] : "";
            if (strlen($preferredTerm) === 0) {
                lognlsev(2, ERROR,
                "......... +++ SNOMED $c code not found or no preferred term ($hint)");
            };

            // Fully specified name (FSN)
            $cinfo             = $xml->xpath("/concept/designation[@lang='en-US'][@use='fsn']");
            $fullySpecifiedName = isset($cinfo[0]) ? trim((string) $cinfo[0]) : "";

            // Relation 762949000 — "Has precise active ingredient" (code)
            $cinfo               = $xml->xpath("/concept/relGrp/relation[@typeCode='762949000']/@destCode");
            $activeIngredientCode = isset($cinfo[0]) ? trim((string) $cinfo[0]) : "";

            // Relation 762949000 — "Has precise active ingredient" (label)
            $cinfo           = $xml->xpath("/concept/relGrp/relation[@typeCode='762949000']/label");
            $activeIngredient = isset($cinfo[0]) ? trim((string) $cinfo[0]) : "";

            // Relation 411116001 — "Has manufactured dose form" (code)
            $cinfo               = $xml->xpath("/concept/relGrp/relation[@typeCode='411116001']/@destCode");
            $manufacturedDoseForm = isset($cinfo[0]) ? trim((string) $cinfo[0]) : "";

            // Relation 411116001 — "Has manufactured dose form" (display label)
            $cinfo                    = $xml->xpath("/concept/relGrp/relation[@typeCode='411116001']/label");
            $manufacturedDoseFormDisplay = isset($cinfo[0]) ? trim((string) $cinfo[0]) : "";

            // Persist to cache so subsequent runs skip the API call.
            // Cache record format (fields separated by CACHEITEMSEPARATOR):
            //   code | preferredTerm | fullySpecifiedName | activeIngredient |
            //   activeIngredientCode | manufacturedDoseForm | manufacturedDoseFormDisplay
            toCACHE("snomed", $c,
                $c                        . CACHEITEMSEPARATOR .
                $preferredTerm            . CACHEITEMSEPARATOR .
                $fullySpecifiedName       . CACHEITEMSEPARATOR .
                $activeIngredient         . CACHEITEMSEPARATOR .
                $activeIngredientCode     . CACHEITEMSEPARATOR .
                $manufacturedDoseForm     . CACHEITEMSEPARATOR .
                $manufacturedDoseFormDisplay . "\n"
            );

            return [
                "code"                        => $c,
                "preferredTerm"               => $preferredTerm,
                "fullySpecifiedName"          => $fullySpecifiedName,
                "activeIngredient"            => $activeIngredient,
                "activeIngredientCode"        => $activeIngredientCode,
                "manufacturedDoseForm"        => $manufacturedDoseForm,
                "manufacturedDoseFormDisplay" => $manufacturedDoseFormDisplay,
            ];

        } else {
            // -----------------------------------------------------------------
            // Stage 3b: inactive / retired concept — follow the replacement
            //           association, if one exists, via recursion.
            // -----------------------------------------------------------------
            $cinfo       = $xml->xpath("/concept/association/@targetComponentId");
            $replacecode = isset($cinfo[0]) ? (string) $cinfo[0] : "";

            $cinfo          = $xml->xpath("/concept/association[@targetComponentId='$replacecode']/targetLabel[@lang='en-US']");
            $replacedisplay = isset($cinfo[0]) ? (string) $cinfo[0] : "";

            if (strlen($replacecode) > 0) {
                // Recurse with the replacement code
                lognlsev(2, INFO, "......... SNOMED code $c is outdated, replaced by $replacecode $replacedisplay");
                $repinfo = get_SNOMED_properties($replacecode);
                toCACHE("snomed-replacements", $c,
                    $repinfo["code"]                        . CACHEITEMSEPARATOR .
                    $repinfo["preferredTerm"]               . CACHEITEMSEPARATOR .
                    $repinfo["fullySpecifiedName"]          . CACHEITEMSEPARATOR .
                    $repinfo["activeIngredient"]            . CACHEITEMSEPARATOR .
                    $repinfo["activeIngredientCode"]        . CACHEITEMSEPARATOR .
                    $repinfo["manufacturedDoseForm"]        . CACHEITEMSEPARATOR .
                    $repinfo["manufacturedDoseFormDisplay"] . "\n");
                return $repinfo;
            } else {
                // No replacement found — log and return empty result
                lognlsev(2, ERROR,
                    "......... SNOMED code $c is outdated, finding a replacement was not successful, " .
                    "terms: |$preferredTerm|$hint|"
                );
                return $EMPTYPROPERTIES;
            }
        }

    } else {
        // ---------------------------------------------------------------------
        // Stage 3c: non-200 HTTP response — return empty result silently.
        // (The API is expected to always return 200; other codes are anomalies.)
        // ---------------------------------------------------------------------
        return $EMPTYPROPERTIES;
    }
}