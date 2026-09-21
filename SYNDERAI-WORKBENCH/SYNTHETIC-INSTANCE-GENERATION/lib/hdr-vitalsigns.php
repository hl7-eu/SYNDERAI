<?php

/**
 * Which vital signs an HDR carries, and from which days.
 *
 * The rule is not a matter of taste. The HDR Composition profile states it,
 * in the description of sectionVitalSigns (input/fsh/profiles/composition-hdr.fsh):
 *
 *   "Vital signs observed during the encounter and at or before discharge,
 *    comprising systolic and diastolic blood pressure including the site of
 *    measurement, pulse rate and respiratory rate, and optionally oxygen
 *    saturation, body temperature and pain score. Notable values such as the
 *    most recent, the maximum or minimum, the baseline, or a relevant trend
 *    may be reported. Anthropometric observations such as body weight, height,
 *    body mass index and circumferences are reported in the Physical findings
 *    section."
 *
 * Two things follow.
 *
 * A SELECTION IS SANCTIONED, A THRESHOLD IS NOT
 *   "Notable values such as the most recent ... the baseline" is exactly the
 *   reduction this code performs. What it used to do instead was discard every
 *   vital sign of a stay that had 15 or fewer of them:
 *
 *     if ($lastdayofstay !== NULL and $overallvitals > 15) {
 *         $thisencountervitalsigns = [$thisencountervitalsigns[$lastdayofstay]];
 *     } else {
 *         $thisencountervitalsigns = NULL;   // <- everything thrown away
 *     }
 *
 *   The comment above it described a reduction; the code was a filter. 55 of 61
 *   discharge reports came out with an empty vital signs section.
 *
 * THE SECTION IS NOT FOR EVERY MEASUREMENT CALLED A VITAL SIGN
 *   Synthea's "vital-signs" category is wider than what this section takes.
 *   Body weight, height, BMI and circumferences belong to Physical findings by
 *   the text above, and intraocular pressure belongs nowhere near it. Filtering
 *   by code rather than by count is what keeps the section honest.
 *
 *   SYNDERAI does not generate sectionPhysicalFindings at present, so the
 *   anthropometric readings are dropped rather than moved. They are still in
 *   $pdat->vitalsigns for whoever builds that section.
 */

/**
 * Codes admitted to sectionVitalSigns, in the order the IG names them.
 * Anything not listed here is not a vital sign for the purposes of this
 * section, however Synthea categorised it.
 */
const HDR_VITALSIGN_CODES = [
    // named explicitly by the section description
    "85354-9",   // Blood pressure panel with all children optional
    "8480-6",    // Systolic blood pressure  (when it arrives unpaired)
    "8462-4",    // Diastolic blood pressure (when it arrives unpaired)
    "8867-4",    // Heart rate
    "9279-1",    // Respiratory rate
    // "optionally" in the section description
    "2708-6",    // Oxygen saturation in Arterial blood
    "59408-5",   // Oxygen saturation by pulse oximetry
    "8310-5",    // Body temperature
    "72514-3",   // Pain severity, 0-10 verbal numeric rating
];

/**
 * Upper bound on the readings handed to the HDR, applied AFTER the day
 * selection rather than as a gate before it. A stay with continuous monitoring
 * can produce hundreds of readings on its last day alone; a discharge report
 * showing all of them is a telemetry dump, not a report. Zero disables it.
 */
const HDR_VITALSIGN_MAX = 40;

if (!function_exists("hdrVitalSignCode")) {
    /**
     * The LOINC code of a reading, whatever shape the getter produced.
     * `code` is a list of codings; older callers may pass a single coding.
     */
    function hdrVitalSignCode($reading)
    {
        $code = is_array($reading) ? ($reading["code"] ?? null)
                                   : ($reading->code ?? null);
        if ($code === null) {
            return null;
        }
        if (is_array($code) && isset($code[0])) {
            $code = $code[0];
        }
        if (is_array($code)) {
            return $code["code"] ?? null;
        }
        if (is_object($code)) {
            return $code->code ?? null;
        }
        return null;
    }
}

if (!function_exists("hdrSelectVitalSigns")) {
    /**
     * @param array $byDate  date => list of readings, as collected for one stay.
     * @param int   $max     cap applied after selection; 0 disables it.
     * @return array|null    list of day blocks, or NULL when nothing qualifies.
     *                       The shape is what generic-hdr.ish.twig iterates:
     *                       an outer list, each element a list of readings.
     */
    function hdrSelectVitalSigns(array $byDate, int $max = HDR_VITALSIGN_MAX)
    {
        // 1. keep only the codes this section is for
        $kept = [];
        foreach ($byDate as $date => $readings) {
            foreach ($readings as $reading) {
                $code = hdrVitalSignCode($reading);
                if ($code !== null && in_array($code, HDR_VITALSIGN_CODES, true)) {
                    $kept[$date][] = $reading;
                }
            }
        }
        if (!$kept) {
            return null;
        }

        // 2. "the most recent" and, when the stay spans more than one day with
        //    readings, "the baseline" as well.
        $dates = array_keys($kept);
        sort($dates);                       // ISO dates sort lexically
        $discharge = end($dates);
        $admission = $dates[0];
        $chosen = $admission === $discharge
            ? [$discharge]
            : [$admission, $discharge];     // baseline first, most recent last

        $out = [];
        foreach ($chosen as $date) {
            $out[] = $kept[$date];
        }

        // 3. cap, newest day first so the most recent survives a truncation
        if ($max > 0) {
            $total = array_sum(array_map("count", $out));
            if ($total > $max) {
                $budget = $max;
                $trimmed = [];
                foreach (array_reverse($out) as $block) {
                    if ($budget <= 0) {
                        break;
                    }
                    $trimmed[] = array_slice($block, 0, $budget);
                    $budget -= count($trimmed[count($trimmed) - 1]);
                }
                $out = array_reverse($trimmed);
            }
        }

        return $out;
    }
}
