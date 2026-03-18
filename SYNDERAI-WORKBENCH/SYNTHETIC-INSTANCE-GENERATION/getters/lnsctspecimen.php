<?php

/* 
  PRE-REQUISITES
  CVX to SNOMED mapping (selection) including site and route as SNOMED
*/
  lognl(1, "... load LOINC system to SNOMED specimen mapping\n");
  $handle = fopen(MAPPINGS . "/LOINC_SNOMED_specimen_mapping.csv",'r');
  $rowcnt = 0;
  $LOINC_SNOMED_SPECIMENS = array ();
  while ( ($csvline = fgetcsv($handle, 10000, ";", '"', '\\') ) !== FALSE ) {
    $rowcnt++;
    if ($rowcnt > 1) {
      $rc = trim($csvline[0]);
      $LOINC_SNOMED_SPECIMENS[$rc] = [
        "snomed" => [
          "code" => trim($csvline[2]),
          "display" => trim($csvline[3]),
        ]
      ];
    }
  }
  fclose($handle);
  // var_dump($LOINC_SNOMED_SPECIMENS);exit;

?>