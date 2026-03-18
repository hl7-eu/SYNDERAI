<?php

// ***
// *** get all vital signs codes
// *** used in loinc.php isVitaLSignCode()
// ***
lognl(1, "... load vital signs codes\n");
$handle = fopen(MAPPINGS . "/vital-signs-codes.csv","r");
$VITALSIGNSCODES = array();
while (($buffer = fgetcsv($handle, 10000, "\t", '"', '\\')) !== FALSE) {
  $loinc = trim($buffer[1]);
  $VITALSIGNSCODES[$loinc] = [
    "loinc" => $loinc,
    "display" => trim($buffer[2])
  ];
}
fclose($handle);
// var_dump($VITALSIGNSCODES);exit;
?>