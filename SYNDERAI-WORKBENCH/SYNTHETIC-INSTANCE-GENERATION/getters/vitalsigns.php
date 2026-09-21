<?php

require_once __DIR__ . "/../lib/vitalsign-precision.php";

// ***
// *** get all random vital signs observations for this candidate and report the most recent ones only
// ***
$found = array();
lognl(1, "...... List of vital signs for this patient");
// open observations
$pdat->vitalsigns = NULL;
$observationhandle = fopen(
  is_file(SYNTHEADIR . "/observations/$candid") ? SYNTHEADIR . "/observations/$candid" : SYNTHEADIR . "/observations.csv", "r");
while (($item = fgetcsv($observationhandle, 10000, ",", '"', '\\')) !== FALSE) {
  if (strpos($item[1], $candid) !== FALSE) {
    // vital-signs,8302-2,Body Height,157.6,cm,numeric
    if ($item[3] === 'vital-signs') {
      // var_dump($item);
      $date = substr($item[0], 0, 10);
      $loinc = $item[4];
      $loincdisplay = trim($item[5]);
      $value = $item[6];
      // Synthea's CSV exporter prints EVERY numeric observation with exactly one
      // decimal place - ExportHelper.getObservationValue() formats any Double as
      // "%.1f" unconditionally - so the corpus carries "146.5 mm[Hg]" for a blood
      // pressure and "18.7 /min" for a respiratory rate. No sphygmomanometer
      // reports half a mmHg and nobody counts a fraction of a breath, while for
      // body temperature the one decimal is right. vitalSignRound() applies the
      // precision the measurement actually has, per LOINC code; see
      // lib/vitalsign-precision.php for the table and for why the fix sits here
      // rather than in the Synthea module or in its exporter. It still removes a
      // trailing ".0" as this line used to, and leaves an unlisted code alone.
      $value = vitalSignRound($loinc, $value);
      $unit = $item[7];  // shall be a ucum unit
      $scale = $item[8];
      /*
       * TODO: no ranges for vital signs now, maybe later
      $AI = getAIReferenceRange ($pdat->age, $pdat->gender, $loincdisplay . " unit=" . $unit);
      var_dump($AI );
      $rr1 = json_decode($AI['rr'], TRUE);
      */
      // determine the unit code
      $found[$date][$loinc] = [
        "date" => $date,
        "code" => [
          [
            "code" => $loinc,
            "system" => "\$loinc",
            "display" => $loincdisplay
          ]
        ],
        "value" => [
          "value" => $value,
          "unit" => $unit,
          "code" => $unit,
          "system" => "\$ucum",
          "scale" => $scale,
        ],
        "encounter" => trim($item[2])
      ];
      lognl(3, sprintf(
        "......... %-10s %-10s %10s %10s %10s %s",
        $loinc,
        $date,
        $value,
        $unit,
        $scale,
        $loincdisplay
      ));
    }         
  }
}
fclose($observationhandle);

if (count($found) === 0) {
   lognlsev(3, WARNING, "......... +++ No vital signs found");
   $pdat->vitalsigns = NULL;
} else {

  /*
   * we must correct blood pressure measurements as two single and separated observations
   * $loinc#8480-6 "Systolic blood pressure"
   * $loinc#8462-4 "Diastolic blood pressure"
   * 
   * they must appear under 
   * code $loinc#85354-9 "Blood pressure panel with all children optional"
   * with two components
   *  * component[SystolicBP].code = $loinc#8480-6 "Systolic blood pressure"
      * component[SystolicBP].valueQuantity.value = {sys}
      * component[SystolicBP].valueQuantity.unit = "mm[Hg]"
      * component[SystolicBP].valueQuantity.code = http://unitsofmeasure.org#mm[Hg]
      * component[SystolicBP].valueQuantity.system = "http://unitsofmeasure.org"
      * component[DiastolicBP].code = $loinc#8462-4 "Diastolic blood pressure"
      * component[DiastolicBP].valueQuantity.value = {dia}
      * component[DiastolicBP].valueQuantity.unit = "mm[Hg]"
      * component[DiastolicBP].valueQuantity.code = http://unitsofmeasure.org#mm[Hg]
      * component[DiastolicBP].valueQuantity.system = "http://unitsofmeasure.org"
   *
   * for that purpose go through all found vitals PER DATE, search for systolic and diastolic and
   * if found both that date replace them by the component one.

   */
  foreach ($found as $thisdate => $thisdatevitals) {
    if (isset($thisdatevitals["8480-6"]) and isset($thisdatevitals["8462-4"])) {
      $systolic = $thisdatevitals["8480-6"];
      $distolic = $thisdatevitals["8462-4"];
      // Both components must belong to the same encounter, otherwise the panel
      // claims one measurement where there were two. Counted over the corpus:
      // 232,581 patient/date pairs carry both components and not one has them
      // in different encounters - but 1,922 patient/date/code combinations
      // occur more than once, and $found[$date][$loinc] keeps only the last of
      // them, which is exactly how a mismatch would arise. Leave the two
      // readings uncollapsed rather than inventing a panel: both codes are
      // admitted individually, and the scalar branch of the FSH template
      // renders them, so nothing is lost.
      if (($systolic["encounter"] ?? "") !== ($distolic["encounter"] ?? "")) {
        lognlsev(2, WARNING, "......... +++ Blood pressure components of $thisdate "
          . "belong to different encounters, not collapsed into a panel\n");
        continue;
      }
      // eliminate single measurements from found array
      unset($found[$thisdate]["8480-6"]);
      unset($found[$thisdate]["8462-4"]);
      // create a new extra here, to be added to found array
      $found[$thisdate]["85354-9"] = [
          "date" => $thisdate,
          // The panel inherits the encounter of its components. Without it the
          // HDR collection loop in synderai-v7.php skips the entry - it keeps
          // only readings whose encounter belongs to the stay - so no blood
          // pressure ever reached an HDR, however the selection was written.
          // Issue #125. EPS was unaffected: its section runner reads
          // $pdat->vitalsigns directly and never asks about the encounter.
          "encounter" => $systolic["encounter"],
          "code" => [
            [
              "code" => "85354-9",
              "system" => "\$loinc",
              "display" => "Blood pressure panel with all children optional"
            ]
          ],
          "component" => [
            [
              // "slice" => "SystolicBP",
              "code" => [
                [
                  "code" => "8480-6",
                  "system" => "\$loinc",
                  "display" => "Systolic blood pressure"
                ]
              ],
              "value" => $systolic["value"]
            ],
            [
              // "slice" => "DiastolicBP",
              "code" => [
                [
                  "code" => "8462-4",
                  "system" => "\$loinc",
                  "display" => "Diastolic blood pressure"
                ]
              ],
              "value" => $distolic["value"]
            ]
          ]
        ];
    }
  }
  // var_dump($found);exit;

  // determine the last set of measurement, and the one before that if existing
  $ofkeys = array_keys($found);
  rsort($ofkeys); // last one is in $ofkeys[0]
  $vslast = $ofkeys[0];
  $vslastbutone = isset($ofkeys[1]) ? $ofkeys[1] : NULL;
  // store / handle result
  $pdat->vitalsigns = $found;
  $pdat->vitalsignslastdate = $vslast;
  $pdat->vitalsignslastbutonedate = $vslastbutone;
}


?>