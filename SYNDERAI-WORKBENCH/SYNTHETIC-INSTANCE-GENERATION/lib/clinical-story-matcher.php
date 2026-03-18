<?php

/* GENERAL SYNDERAI INCLUDES */
include_once("config.php");

/**
 * Find synthetic patient candidates whose records satisfy a set of preselection criteria.
 *
 * Evaluates up to three independent filters against the Synthea-generated CSV
 * dataset and returns the candidate IDs that pass every filter that is present
 * in $preselarr. Filters are ANDed together; omitted filters are treated as
 * "no constraint" (all candidates pass).
 *
 * Supported filters (all optional):
 *
 *   $preselarr->inpatient   (bool)   — if TRUE, restricts candidates to those
 *       present in the global $INPATIENTENCOUNTERS list (i.e. encounters with
 *       appropriate admission reasons and discharge codes).
 *
 *   $preselarr->diagnosis   (array)  — list of SNOMED CT codes; a candidate
 *       must have ALL of the listed codes as *active* conditions.
 *
 *   $preselarr->medication  (array)  — list of medication name fragments; a
 *       candidate must have at least one active medication whose name contains
 *       each listed fragment (case-insensitive substring match).
 *
 * Internally each passing candidate is tagged with "D" (diagnosis), "M"
 * (medication) and/or "I" (inpatient) and the final result set is built by
 * requiring all applicable tags to be present.
 *
 * @global array $INPATIENTENCOUNTERS  Array of inpatient encounter records,
 *                                     each containing at least ["candid" => <id>].
 *
 * @param  object $preselarr  An object (typically decoded from JSON) with
 *                            optional properties: inpatient (bool),
 *                            diagnosis (string[]), medication (string[]).
 *
 * @return array|null  Flat array of matching candidate ID strings, or NULL if
 *                     no candidates satisfy all active filters.
 */
function getClinicalStoryCandidatesWithMatchingPreselections($preselarr) {

    global $INPATIENTENCOUNTERS;

    $foundd = [];   // candidates matching diagnosis filter
    $foundm = [];   // candidates matching medication filter
    $founde = [];   // candidates matching inpatient filter

    // ------------------------------------------------------------------
    // Filter I: inpatient encounters
    // ------------------------------------------------------------------
    if ($preselarr->inpatient === TRUE) {
        lognl(2, "...... Finding clinical stories that have encounters with 'appropriate' admission reasons and discharge information");
        foreach ($INPATIENTENCOUNTERS as $c) {
            $founde[] = $c["candid"];
        }
    }

    // ------------------------------------------------------------------
    // Filter D: active diagnoses (SNOMED CT codes — ALL must be present)
    // ------------------------------------------------------------------
    if (isset($preselarr->diagnosis)) {
        lognl(2, "...... Finding candidates that matches preselected diagnoses (snomeds: " . implode(", ", $preselarr->diagnosis) . ")\n");

        $handle = @fopen(SYNTHEADIR . "/conditions.csv", "r");
        rewind($handle);
        $seendiagnosis = [];

        while (($item = fgetcsv($handle, 10000, ",", '"', '\\')) !== FALSE) {
            $snomed = isset($item[4]) ? trim($item[4]) : "";
            $candid = $item[2];
            // col 1 holds the stop date; empty stop date means the condition is still active
            $active = strlen(trim($item[1])) === 0 ? TRUE : FALSE;

            if ($active && in_array($snomed, $preselarr->diagnosis, TRUE)) {
                $seendiagnosis[$candid][$snomed] = TRUE;
            }
        }
        fclose($handle);

        // A candidate passes only if ALL requested diagnoses were found
        $wantedcount = count($preselarr->diagnosis);
        foreach ($seendiagnosis as $candid => $sd) {
            if (count($seendiagnosis[$candid]) === $wantedcount) {
                $foundd[] = $candid;
            }
        }
        lognl(2, "......... Candidates with matching conditions: " . count($foundd) . "\n");
    }

    // ------------------------------------------------------------------
    // Filter M: medications (name substring match — ALL must be present)
    // ------------------------------------------------------------------
    if (isset($preselarr->medication)) {
        lognl(2, "...... Finding candidates that matches preselected medications (names: " . implode(", ", $preselarr->medication) . ")\n");

        $handle = @fopen(SYNTHEADIR . "/medications.csv", "r");
        $seenmednames = [];

        while (($item = fgetcsv($handle, 10000, ",", '"', '\\')) !== FALSE) {
            $medname = isset($item[6]) ? strtolower(trim($item[6])) : "";
            $candid  = $item[2];

            foreach ($preselarr->medication as $pn) {
                if (str_contains($medname, strtolower($pn))) {
                    $seenmednames[$candid][$medname] = TRUE;
                }
            }
        }
        fclose($handle);

        // A candidate passes only if ALL requested medication fragments were found
        $wantedcount = count($preselarr->medication);
        foreach ($seenmednames as $candid => $mn) {
            if ($wantedcount === count($seenmednames[$candid])) {
                $foundm[] = $candid;
            }
        }
        lognl(2, "......... Candidates with matching medications: " . count($foundm) . "\n");
    }

    // ------------------------------------------------------------------
    // Combine: tag each candidate with the filters it passed, then
    // require all active filter tags to be present.
    // ------------------------------------------------------------------
    $tmp = [];

    foreach ($foundd as $cd) {
        $tmp[$cd] = "D";
    }
    foreach ($foundm as $cm) {
        $tmp[$cm] = isset($tmp[$cm]) ? $tmp[$cm] . "M" : "M";
    }
    foreach ($founde as $ce) {
        $tmp[$ce] = isset($tmp[$ce]) ? $tmp[$ce] . "I" : "I";
    }

    $found = NULL;
    foreach ($tmp as $key => $value) {
        $iscandid = isset($preselarr->diagnosis)  ? str_contains($value, "D") : TRUE;
        if ($iscandid) $iscandid = isset($preselarr->medication) ? str_contains($value, "M") : TRUE;
        if ($iscandid) $iscandid = isset($preselarr->inpatient)  ? str_contains($value, "I") : FALSE;
        if ($iscandid) $found[] = $key;
    }

    if ($found) {
        lognl(2, "...... Combined catches: " . count($found) . "\n");
    }

    return $found;
}

?>