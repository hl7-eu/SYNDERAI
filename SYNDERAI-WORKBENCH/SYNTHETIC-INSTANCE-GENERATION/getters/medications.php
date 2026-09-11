<?php

// ***
// *** get all random medications for this candidate
// ***
// open medications
$pdat->medications = NULL;
$medicationshandle = @fopen(SYNTHEADIR . "/medications.csv", "r");
$found = array();
lognl (1, "...... List of medications for this patient\n");
rewind($medicationshandle);
while (!feof($medicationshandle)) {
  $buffer = fgets($medicationshandle);
  if (strpos($buffer, $candid) !== FALSE) {
    $item = explode(",", $buffer);
    // var_dump($item);exit;
    // take condition if active
    if (strlen(trim($item[1])) === 0 and (strlen(trim($item[5])) > 0)) {
      $rxnorm = trim($item[5]);
      // var_dump($cfound);exit;
      $themap = map_concept($rxnorm, "cm-rxnorm-snomed-ct");
      lognl(3, sprintf(
        "......... %-10s %s",
        $rxnorm,
        isset($themap["sourceDisplay"]) ? $themap["sourceDisplay"] : "???" . trim($item[6])
      ));
      if ($themap !== NULL) {
        $snomedproperties = get_SNOMED_properties($themap["code"], trim($item[6]));
        if ($snomedproperties["code"] !== $themap["code"]) $themap["code"] = $snomedproperties["code"]; // this is a replacement
        $routecode = map_concept($themap["code"], "cm-medication-to-route-of-administration");  // get route code for this medication
        $cfound = [
          "rxnorm" => [
            "code" => $rxnorm,
            "display" => $themap["sourceDisplay"],
          ],
          "snomed" => [
            "code" => $themap["code"],
            "display" => $themap["display"],
            "preferredTerm" => $snomedproperties["preferredTerm"],
          ],
          "activeIngredient" => [
            "code" => $snomedproperties["activeIngredientCode"],
            "display" => $snomedproperties["activeIngredient"]
          ],
          "manufacturedDoseForm" => [
            "code" => $snomedproperties["manufacturedDoseForm"],
            "display" => $snomedproperties["manufacturedDoseFormDisplay"]
          ],
          "routeOfAdministration" => [
            "code" => isset($routecode["code"]) ? $routecode["code"] : "",
            "display" => isset($routecode["display"]) ? $routecode["display"] : ""
          ],
          "start" => substr($item[0], 0, 10),
          "end" => "",
          "encounter" => trim($item[4]),
          "reason" => [
            "code" => trim($item[11]),
            "display" => trim($item[12])
          ],
          "sectionentryslicename" => "medicationStatementOrRequest"
        ];
        lognl(3, "............ " . "SNOMED " . $themap["code"] . " " . $themap["display"]);

      } else {
        lognlsev(1, ERROR, "......... Cannot map RXNORM $rxnorm " . trim($item[6]));
        registerMapMissing("......... Cannot map RXNORM $rxnorm " . trim($item[6]));
        $cfound = [
          "rxnorm" => [
            "code" => $rxnorm,
            "display" => trim($item[6]),
          ],
          "snomed" => [
            "code" => "",
            "display" => "",
            "preferredTerm" => trim($item[6]),
          ],
          "activeIngredient" => [
            "code" => "",
            "display" => ""
          ],
          "manufacturedDoseForm" => [
            "code" => "",
            "display" => ""
          ],
          "routeOfAdministration" => [
            "code" => "",
            "display" => ""
          ],
          "start" => substr($item[0], 0, 10),
          "end" => "",
          "reason" => [
            "code" => trim($item[11]),
            "display" => trim($item[12])
          ],
          "sectionentryslicename" => "medicationStatementOrRequest"
        ];
      }
              
      // using AI: add condition as their displays for appropriate dosage findings
      if (USE_AI) {
        $conditions4ai = "";
        if ($pdat->conditions !== NULL) {
          foreach ($pdat->conditions as $c) {
            $conditions4ai .= " " . $c["code"]["display"] . ",";
          }
        }
        // var_dump($conditions4ai);exit;
        $md5 = md5($pdat->age . $pdat->gender . $cfound["snomed"]["preferredTerm"]);
        $fai = inCACHE('dosage', $md5);
        if ($fai !== FALSE) {
          // is in cache
          lognl(5, "......... MD5: $md5\n");
          $cfound["dosagefsh"] = $fai;
        } else {
          lognl(3, "............ " . "Inventing appropiate dosage for " . $cfound["snomed"]["preferredTerm"]);
          // not in cache, ask AI if we have a medication as text
          if ($themap) {

          }
          $themed = $snomedproperties["preferredTerm"];
          if (strlen($themed) === 0)
            $themed = $themap["display"];
          $AI = getAIsuggestedMedicationDosage ($pdat->age, $pdat->gender, $conditions4ai, $themed);
          if (isset($AI['text'])) {
            // double check doseQuantity.system is mentioned if doseQuantity.code is present
            if (str_contains($AI['text'], "dosage.doseAndRate.doseQuantity.code")) {
              if (!str_contains($AI['text'], "dosage.doseAndRate.doseQuantity.system"))
                $AI['text'] .= "\n* dosage.doseAndRate.doseQuantity.system = \$ucum";
            }
            // var_dump($AI); exit;
            // echo $AI['text'] . "\n";
            $cfound["dosagefsh"] = $AI['text']; // eliminate any " but add embracing " to dosage.text
            // var_dump($cfound); // exit;
            toCACHE('dosage', $md5, $AI['text']);
          }
        }
        $dosagedisplay = "";
        if (strlen($cfound["dosagefsh"]) > 0 && str_contains($cfound["dosagefsh"], "dosage.text")) {
          foreach (explode("\n", $cfound["dosagefsh"]) as $dl) {
            // evaluate dosage text for human read
            if (substr($dl, 0, 17) === '* dosage.text = "')
              $dosagedisplay = str_replace("\"", "", substr($dl, 17, strlen($dl) - 2)) . "\"";
            if (substr($dosagedisplay, strlen($dosagedisplay) - 1) === '"')
              $dosagedisplay = substr($dosagedisplay, 0, strlen($dosagedisplay) - 1);
          }
          $cfound["dosagedisplay"] = $dosagedisplay;
          lognl(3, "............ " . "Dosage: " . $dosagedisplay);
        }
      } else {
        $cfound["dosagefsh"] = "";
        $cfound["dosagedisplay"] = "";
      }
      if (strlen($cfound["routeOfAdministration"]["display"])> 0) lognl(3, "............ " . "Route: " . $cfound["routeOfAdministration"]["code"] . " " . $cfound["routeOfAdministration"]["display"]);
      // store the overall result in the array
      $found[] = $cfound;
    }
  }
}
fclose($medicationshandle);
if (count($found) === 0) {
  lognlsev (3, WARNING, "......... +++ No medications found\n");
  $pdat->medications = NULL;
} else {
  // store / handle result
  array_multisort(array_column($found, 'start'), SORT_DESC, $found); // sort by date and report
  $pdat->medications = $found;
}

?>