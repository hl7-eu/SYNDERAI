<?php

/**
 * How many decimal places a vital sign is reported with.
 *
 * WHY THIS EXISTS
 *   Synthea's CSV exporter prints every numeric observation with exactly one
 *   decimal place, unconditionally. ExportHelper.getObservationValue():
 *
 *       } else if (observation.value instanceof Double) {
 *         // round to 1 decimal place for display
 *         value = String.format(Locale.US, "%.1f", observation.value);
 *       }
 *
 *   The simulated value is a real double such as 146.53…, and that line cuts it
 *   to one decimal rather than to the precision the measurement would have. So
 *   the corpus carries "146.5 mm[Hg]" for a blood pressure, "97.3 /min" for a
 *   heart rate and "18.7 /min" for a respiratory rate. No sphygmomanometer
 *   reports half a millimetre of mercury — manual reading is in 2 mmHg steps,
 *   oscillometric devices report whole mmHg — and nobody counts a third of a
 *   breath. For body temperature, by contrast, one decimal is exactly right.
 *
 * WHY IT IS FIXED HERE AND NOT IN SYNTHEA
 *   Two other places could have carried it and each falls short.
 *
 *   The `decimals` field on a module `range` rounds at generation time, but it
 *   only applies to range-based observations. The main blood pressure producer,
 *   `encounter/vitals.json`, draws both components from `vital_sign`, where
 *   there is no such option — and across the whole module tree only 16 of 957
 *   ranges set it at all.
 *
 *   Patching ExportHelper would cover everything, including the simulated
 *   values, at the price of forking the Synthea core and of a regeneration for
 *   every change. The precision a FHIR example should show is a decision about
 *   the FHIR output, so it belongs on this side, where it also applies whatever
 *   module produced the reading and needs no new corpus.
 *
 *   The cost of that choice: the CSV corpus keeps the odd values. Anyone
 *   reading observations.csv directly still sees 146.5.
 *
 * PRECISION, NOT ROUNDING FOR ITS OWN SAKE
 *   The table below says what a device reports, not what looks tidy. Extending
 *   it is the intended way to handle a new code; a code that is not listed is
 *   passed through unchanged and logged, so the log names what is missing
 *   rather than a guess being applied silently.
 */

/**
 * LOINC code => decimal places, grounded in the codes the corpus actually
 * carries under the `vital-signs` category.
 */
const VITALSIGN_DECIMALS = [
    // whole units: this is the resolution of the instrument
    "8480-6"  => 0,   // Systolic blood pressure          mm[Hg]
    "8462-4"  => 0,   // Diastolic blood pressure         mm[Hg]
    "8478-0"  => 0,   // Mean blood pressure              mm[Hg]
    "8867-4"  => 0,   // Heart rate                       /min
    "9279-1"  => 0,   // Respiratory rate                 /min
    "2708-6"  => 0,   // Oxygen saturation, arterial      %
    "59408-5" => 0,   // Oxygen saturation by pulse ox    %
    "79892-6" => 0,   // Right eye intraocular pressure   mm[Hg]
    "79893-4" => 0,   // Left eye intraocular pressure    mm[Hg]
    "72514-3" => 0,   // Pain severity 0-10               {score}
    "71425-3" => 0,   // NT-proBNP                        pg/mL

    // one decimal: this is how the value is read off and reported
    "8310-5"  => 1,   // Body temperature                 Cel / [degF]
    "29463-7" => 1,   // Body weight                      kg
    "8302-2"  => 1,   // Body height                      cm
    "39156-5" => 1,   // Body mass index                  kg/m2
    "8280-0"  => 1,   // Waist circumference              cm
];

if (!function_exists("vitalSignRound")) {
    /**
     * Round a vital sign value to the precision its measurement has.
     *
     * @param string $loinc  the observation's own LOINC code.
     * @param mixed  $value  the raw value from the Synthea CSV.
     * @return string        the value as it should appear in FHIR.
     *
     * A non-numeric value is returned untouched: some observations under this
     * category are text.
     *
     * A trailing ".0" is still removed, as it was before this function existed.
     * FHIR's decimal keeps significant digits, so "37.0" would in fact state
     * the precision better than "37" — changing that is a separate decision and
     * would alter every existing example, so it is not made here.
     */
    function vitalSignRound(string $loinc, $value)
    {
        if (!is_numeric($value)) {
            return $value;
        }

        if (!array_key_exists($loinc, VITALSIGN_DECIMALS)) {
            // Not a guess: pass it through as before, and say so once so the
            // table can be extended from the log rather than from memory.
            if (function_exists("registerMapMissing")) {
                registerMapMissing("+++ No vital sign precision for LOINC $loinc");
            }
            return preg_replace('/\.0$/', '', (string) $value);
        }

        $decimals = VITALSIGN_DECIMALS[$loinc];
        $rounded = number_format((float) $value, $decimals, ".", "");
        return preg_replace('/\.0$/', '', $rounded);
    }
}
