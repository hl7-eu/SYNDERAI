<?php

/*
  EU LAB MODULE
  Get common UCUM units and their synonyms

  used to properly show human readable UCUM units to the user
  (rather the real UCUM codes)
  Example: "[iU]/L"	= InternationalUnitsPerLiter => is displayed as "IU/L"
*/
$ucumunits = array();
$ucumf = file_get_contents(MAPPINGS . "/UCUM-CONCEPTS.csv");
$ucuml = explode("\n", $ucumf);
foreach ($ucuml as $l) {
  $item = explode("\t", $l);
  $ucumcode = trim($item[0]);
  $ucumdisplay = trim($item[5]);
  // echo "$ucumcode - $ucumdisplay\n";
  if (strlen($ucumcode) > 0) {
    $ucumunits[$ucumcode] = $ucumdisplay;
  }
}

// get all valid lab codes
$labcodes = array();
$rlabf = file_get_contents(MAPPINGS . "/observation-labcodesonly58000.txt");
$rlabs = explode("\n", $rlabf);
foreach ($rlabs as $l) {
  $items = explode("\t", $l);
  $item = $items[0];
  if (strlen($item) > 3) {
      $labcodes[] = trim($item);
  }
}

?>