<?php

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