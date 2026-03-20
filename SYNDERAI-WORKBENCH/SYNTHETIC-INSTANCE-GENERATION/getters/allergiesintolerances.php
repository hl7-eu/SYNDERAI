<?php

// ***
// *** get all random allergies/intolerances for this candidate
// ***
$found = array();
lognl(1, "...... List of allergies/intolerances for this patient\n");
// open procedures
$pdat->allergies = NULL;
$allergyhandle = fopen(SYNTHEADIR . "/allergies.csv","r");
while (($item = fgetcsv($allergyhandle, 10000, ",", '"', '\\')) !== FALSE) {
  if (strpos($item[2], $candid) !== FALSE) {
    $start = substr($item[0], 0, 10);
    $end = substr($item[1], 0, 10);
    $snomed = trim($item[4]);
    $snomeddisplay = trim($item[6]);

    // last minute corrections: tweak some codes
    if ($snomed === "419199007") {
      // correction of outdated concept
      $snomed = "416098002";
      $snomeddisplay = "Drug allergy (disorder)";
    }
    if ($snomed === "1191") {
      // correction of rxnorm code
      $snomed = "387458008";
      $snomeddisplay = "Aspirin";
    }
     if ($snomed === "29046") {
      // correction of rxnorm code
      $snomed = "386873009";
      $snomeddisplay = "Drug Lisinopril)";
    }

    $type = trim($item[7]);
    $category = trim($item[8]);
    $snomedproperties = get_SNOMED_properties ($snomed, $snomeddisplay);
    if ($snomedproperties["code"] !== $snomed) $snomed = $snomedproperties["code"]; // this is a replacement
    $snomeddisplay = strlen($snomedproperties["fullySpecifiedName"]) > 0 ? $snomedproperties["fullySpecifiedName"] : $snomeddisplay;

    $reaction1 = trim($item[9]);
    $reactiondisplay1 = trim($item[10]);
    $reaction1properties = get_SNOMED_properties ($reaction1, $reactiondisplay1);
    if ($reaction1properties["code"] !== $reaction1) $reaction1 = $reaction1properties["code"]; // this is a replacement
    $reactiondisplay1 = strlen($reaction1properties["fullySpecifiedName"]) > 0 ? $reaction1properties["fullySpecifiedName"] : $reactiondisplay1;
    $severity1 = trim($item[11]);

    $reaction2 = trim($item[12]);
    $reactiondisplay2 = trim($item[13]);
    $reaction2properties = get_SNOMED_properties ($reaction2, $reactiondisplay2);
    if ($reaction2properties["code"] !== $reaction2) $reaction2 = $reaction2properties["code"]; // this is a replacement
    $reactiondisplay2 = strlen($reaction2properties["fullySpecifiedName"]) > 0 ? $reaction2properties["fullySpecifiedName"] : $reactiondisplay2;
    $severity2 = trim($item[14]);

    if (strlen($snomed) > 0 and strlen($snomedproperties["fullySpecifiedName"]) > 0) {
      // add only if there is a proper fullySpecifiedName for the SNOMED code (and the code itself of course)
      $found[] = [
            "snomed" => [
              "code" => $snomed,
              "display" => $snomeddisplay
            ],
            "preferredTerm" => $snomedproperties["preferredTerm"],
            "start" => $start,
            "end" => $end,
            "type" => "" . $type,
            "category" => "" . $category,
            "reaction1" => [
              "code" => $reaction1,
              "display" => $reactiondisplay1,
              "severity" => "" . $severity1,
            ],
            "reaction2" => [
              "code" => $reaction2,
              "display" => $reactiondisplay2,
              "severity" => "" . $severity2,
            ],
            "sectionentryslicename" => "allergyOrIntolerance"
      ];
      lognl(3, sprintf(
        "......... %10s %10s %s",
        $snomed,
        $start,
        $snomeddisplay
      ));
    }
    // var_dump($found);exit;
  }
}
fclose($allergyhandle);
// funny: under very limited circumstances (5% probability) we will assign another intolerance to the patient:
// 1343356001	Inadequate digital access affecting health care
if (rand(0,100) > 94) {
  $found[] = [
    "snomed" => [
      "code" => "1343356001",
      "display" => "Inadequate digital access affecting health care"
    ],
    "preferredTerm" => "Inadequate digital access affecting health care",
    "start" => date('Y-m-d'),
    "end" => "",
    "type" => "intolerance",
    "category" => "environment"
  ];
  lognl(3, "......... 1343356001 (Inadequate digital access affecting health care) at " . date('Y-m-d') . "\n");
}

if (count($found) === 0) {
   lognlsev(3, WARNING, "......... +++ No allergies/intolerances found\n");
   $pdat->allergies = NULL;
} else {
  // store / handle result
  array_multisort(array_column($found, 'start'), SORT_DESC, $found); // sort by date and report
  $pdat->allergies = $found;
}


?>