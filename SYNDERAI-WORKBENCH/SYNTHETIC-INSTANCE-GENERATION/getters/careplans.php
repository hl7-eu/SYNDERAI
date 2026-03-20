<?php

// ***
// *** get all care plan items for this candidate
// ***
// open care plan
$pdat->careplans = NULL;
$careplanhandle = @fopen(SYNTHEADIR . "/careplans.csv", "r");
$found = array();
lognl(1, "...... List of care plan items for this patient\n");
rewind($careplanhandle);
while (!feof($careplanhandle)) {
  $buffer = fgets($careplanhandle);
  if (strpos($buffer, $candid) !== FALSE) {
    $item = explode(",", $buffer);
    //var_dump($item);exit;
    // take care plan item only if active
    if (strlen(trim($item[2])) === 0) {

      $snomed = trim($item[5]);
      $display = trim($item[6]);
      $snomedproperties = get_SNOMED_properties($snomed, $display);
      if ($snomedproperties["code"] !== $snomed) $snomed = $snomedproperties["code"]; // this is a replacement
      if (strlen($snomedproperties['fullySpecifiedName']) > 0) $display = $snomedproperties['fullySpecifiedName'];
      
      // check reason, can be empty
      $reason = trim($item[7]);
      $reasondisplay = trim($item[8]);
      if (strlen($reason) > 0) {
        $reasonproperties = get_SNOMED_properties($reason, $reasondisplay);
        if ($reasonproperties["code"] !== $reason) $reason = $reasonproperties["code"]; // this is a replacement
        if (strlen($reasonproperties['fullySpecifiedName']) > 0) $reasondisplay = $reasonproperties['fullySpecifiedName'];
      }
      $found[] = [
        "activity" => [
          "code" => $snomed,
          "system" => "\$sct",
          "display" => $display,
          "preferredTerm" => $snomedproperties['preferredTerm'],
          "fullySpecifiedName" => $snomedproperties['fullySpecifiedName'],
        ],
        "start" => trim($item[1]),
        "reason" => [
          "code" => $reason,
          "system" => "\$sct",
          "display" => $reasondisplay
        ]
      ];
      lognl(3, sprintf(
        "......... %-10s %-10s %30.30s reason=%s",
        $snomed,
        trim($item[1]),
        $display,
        $reasondisplay
      ));
    } 
  }
}
fclose($careplanhandle);
if (count($found) === 0) {
  lognlsev(3, WARNING, "......... +++ No care plan items found\n");
  $pdat->careplans = NULL;
} else {
  // store / handle result
  array_multisort(array_column($found, 'start'), SORT_DESC, $found); // sort by date and report
  // var_dump($found);
  $pdat->careplans = $found;
}
// influenza vaccination recommendation? Comes later in
if ($pdat->age >= 60) {
  lognl(3, "......... 1181000221105 Influenza virus antigen only vaccine product\n");
}

?>