<?php

$pdat->procedures = NULL;

if ($PROCESSISH) {
  $found = NULL;
  // var_dump($pdat->section);exit;
  if (isset($pdat->section)) {
      foreach ($pdat->section as $s) {
          // var_dump($s);
          if (isset($s->entry)) {
              foreach ($s->entry as $e) {
                  // var_dump($e);
                  if (isset($e->type) && $e->type === "procedure") {
                    // var_dump($e);
                    $site = NULL;
                    if (isset($e->site))
                      $site = [
                        "code" => $e->site->code,
                        "system" => $e->site->system,
                        "display" => $e->site->display,
                      ];
                    $found[$e->code->code] = [
                    "code" => [
                      "code" => $e->code->code,
                      "system" => $e->code->system,
                      "display" => $e->code->display,
                      "preferredTerm" => "",
                    ],
                    "date" => $e->date,
                    "reason" => NULL,
                    "site" => $site,
                    "encounter" => NULL,
                  ];
                  }
              }
          }
      }
  }
  // var_dump($found);exit;
  $pdat->procedures = $found;

} else {
  // ***
  // *** get all random procedures for this candidate
  // ***
  $found = array();
  lognl(1, "...... List of procedures for this patient\n");
  // open procedures
  
  $procedureshandle = fopen(
    is_file(SYNTHEADIR . "/procedures/$candid") ? 
    SYNTHEADIR . "/procedures/$candid" : 
    SYNTHEADIR . "/procedures.csv", "r"
  );
  while (($item = fgetcsv($procedureshandle, 10000, ",", '"', '\\')) !== FALSE) {
    if (strpos($item[2], $candid) !== FALSE) {

      $snomed = trim($item[4]);
      $snomeddisplay = trim($item[5]);

      // last minute corrections: tweak some codes
      if ($snomed === "449381000124108") {
        // correction of US extension concept
        $snomed = "308283009";
        $snomeddisplay = "Discharge from hospital (procedure)";
      }
      if ($snomed === "454711000124102") {
        // correction of US extension concept
        $snomed = "171207006";
        $snomeddisplay = "Depression screening (procedure)";
      }
      if ($snomed === "452331000124102") {
        // correction of US extension concept
        $snomed = "308113008";
        $snomeddisplay = "Review of imaging findings (procedure)";
      }
      /*
      if ($snomed === "713106006") {
        // correction of outdated concept
        $snomed = "171126003";
        $snomeddisplay = "Screening for drug misuse (procedure)";
      }
      if ($snomed === "725351001") {
        // correction of outdated concept
        $snomed = "233258006";
        $snomeddisplay = "Percutaneous transluminal angioplasty of artery using fluoroscopic guidance with contrast (procedure)";
      }
      if ($snomed === "74016001") {
        // correction of outdated concept
        $snomed = "396181006";
        $snomeddisplay = "Plain X-ray of knee (procedure)";
      }
      if ($snomed === "1225002") {
        // correction of outdated concept
        $snomed = "713026007";
        $snomeddisplay = "Plain X-ray of humerus (procedure)";
      }
      */

      $snomedproperties = get_SNOMED_properties($snomed, $snomeddisplay);
      if ($snomedproperties["code"] !== $snomed) $snomed = $snomedproperties["code"]; // this is a replacement
      $snomeddisplay = strlen($snomedproperties["fullySpecifiedName"]) > 0 ? $snomedproperties["fullySpecifiedName"] : $snomeddisplay;
      
      $start = substr($item[0], 0, 10);
      $end = substr($item[1], 0, 10);
      $date = $start;
      if ($start !== $end) $date = $end;

      $reason = trim($item[7]);
      $reasondisplay = trim($item[8]);
      if (strlen($reason) > 0) {
        $reasonproperties = get_SNOMED_properties ($reason, $reasondisplay);
      if ($reasonproperties["code"] !== $reason) $reason = $reasonproperties["code"]; // this is a replacement
        $reasondisplay = strlen($reasonproperties["fullySpecifiedName"]) > 0 ? $reasonproperties["fullySpecifiedName"] : $reasondisplay;
      }

      if (
        in_array(trim($item[3]), $CLINICALPROCEDUREENCOUNTERS) and
        strlen($snomedproperties["fullySpecifiedName"]) > 0
      ) { 
        // add only if this is a usefull encounterclass for these procedure, e.g. not wellness
        // and there is a proper fullySpecifiedName for the SNOMED code
        $found[$snomed] = [
          "code" => [
            "code" => $snomed,
            "system" => "\$sct",
            "display" => $snomeddisplay,
            "preferredTerm" => $snomedproperties["preferredTerm"],
          ],
          "date" => $date,
          "reason" => [
            "code" => "" . $reason,
            "system" => "\$sct",
            "display" => $reasondisplay,
          ],
          "encounter" => trim($item[3]),
        ];
        lognl(3, "......... $snomed $snomeddisplay $date");
        // var_dump($found);exit;
      }
    }
  }
  fclose($procedureshandle);

  if (count($found) === 0) {
    lognlsev(3, WARNING, "......... +++ No procedures found\n");
    $pdat->procedures = NULL;
  } else {
    // store / handle result
    array_multisort(array_column($found, 'date'), SORT_DESC, $found); // sort by date and report
    $pdat->procedures = $found;
  }

  // if ($pdat->eci === "1298-659206-6") {var_dump($found);exit;}
}

?>