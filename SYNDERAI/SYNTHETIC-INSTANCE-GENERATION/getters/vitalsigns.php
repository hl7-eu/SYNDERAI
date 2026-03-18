<?php

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
      $value = preg_replace('/\.0$/', '', $value);  // remove the ".0" from eg 134.0
      $unit = $item[7];  // shall be a ucum unit
      $scale = $item[8];
      /*
       * TODO: no ranges for vital signs now, maybe later
      $AI = getAIReferenceRange ($pdat->age, $pdat->gender, $loincdisplay . " unit=" . $unit);
      var_dump($AI );
      $rr1 = json_decode($AI['rr'], TRUE);
      */
      // determine the unit code
      $theunitcode = $unit;
      $found[$date][$loinc] = [
        "date" => $date,
        "code" => [
          "code" => $loinc,
          "system" => "\$loinc",
          "display" => $loincdisplay
        ],
        "value" => $value,
        "unit" => $unit,
        "unitcode" => $theunitcode,
        "scale" => $scale,
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
   lognlsev(3, "WARN", "......... +++ No vital signs found");
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
      // eliminate single measurements from found array
      unset($found[$thisdate]["8480-6"]);
      unset($found[$thisdate]["8462-4"]);
      // create a new extra here, to be added to found array
      $found[$thisdate]["85354-9"] = [
          "date" => $thisdate,
          "code" => [
            "code" => "85354-9",
            "system" => "\$loinc",
            "display" => "Blood pressure panel with all children optional"
          ],
          "component" => [
            [
              // "slice" => "SystolicBP",
              "code" => [
                "code" => "8480-6",
                "system" => "\$loinc",
                "display" => "Systolic blood pressure"
              ],
              "value" => $systolic["value"],
              "unit" => $systolic["unit"],
              "unitcode" => $systolic["unit"],
              "scale" => $systolic["scale"]
            ],
            [
              // "slice" => "DiastolicBP",
              "code" => [
                "code" => "8462-4",
                "system" => "\$loinc",
                "display" => "Diastolic blood pressure"
              ],
              "value" => $distolic["value"],
              "unit" => $distolic["unit"],
              "unitcode" => $distolic["unit"],
              "scale" => $distolic["scale"]
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