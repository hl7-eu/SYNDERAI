<?php

/* 
  PRE-REQUISITES
  CVX to SNOMED mapping (selection) including site and route as SNOMED
*/
  lognl(1, "... load CVX to SNOMED mapping\n");
  $handle = fopen(MAPPINGS . "/vaccine_route_site_mapping_with_atc.csv",'r');
  $rowcnt = 0;
  $MAP_CVX_2_SNOMED = array ();
  while ( ($csvline = fgetcsv($handle, 10000, ";", '"', '\\') ) !== FALSE ) {
    $rowcnt++;
    // var_dump($csvline);exit;
    if ($rowcnt > 1) {
      // var_dump($csvline);exit;
      $rc = $csvline[0];
      $MAP_CVX_2_SNOMED[$rc] = [
        "cvx" => [
          "code" => trim($csvline[0]),
          "display" => trim($csvline[1])
        ],
        "snomed" => [
          "code" => trim($csvline[2]),
          "display" => trim($csvline[3])
        ],
        "atc" => [
          "code" => trim($csvline[8]),
          "display" => trim($csvline[9])
        ],
        "route" => [
          "code" => trim($csvline[5]),
          "display" => trim($csvline[4]),
        ],
        "site" => [
          "code" =>  trim($csvline[7]),
          "display" => trim($csvline[6]),
        ],
      ];
    }
  }
  fclose($handle);
  // var_dump($MAP_CVX_2_SNOMED);exit;

?>