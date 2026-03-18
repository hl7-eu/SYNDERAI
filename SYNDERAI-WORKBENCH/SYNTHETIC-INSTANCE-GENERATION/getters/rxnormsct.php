<?php
/* 
  PRE-REQUISITES
  RX-Norm - SNOMED mapping
*/

lognl(1, "... load RX-Norm - SNOMED mapping\n");
$handle = fopen(MAPPINGS . "/RXNORM-2-SNOMED.csv",'r');
$rowcnt = 0;
$RXNORM2SNOMED = array ();
$arnames = array();
while ( ($csvline = fgetcsv($handle, 10000, "\t", '"', '\\') ) !== FALSE ) {
  $rowcnt++;
  // var_dump($csvline);exit;
  if ($rowcnt > 1) {
    // var_dump($csvline);exit;
    $rc = $csvline[0];
    $RXNORM2SNOMED[$rc] = [
      "rxnorm" => $csvline[0],
      "rxnormdisplay" => trim($csvline[2]),
      "snomed" => trim($csvline[3]),
      "snomeddisplay" => trim($csvline[5]),
    ];
  }
}
fclose($handle);
// var_dump($RXNORM2SNOMED);exit;

?>