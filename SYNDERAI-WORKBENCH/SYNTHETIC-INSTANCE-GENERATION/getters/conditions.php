<?php

// ***
// *** get all random conditions for this candidate
// ***
// open conditions 
$pdat->conditions = NULL;
$pdat->pastillnessentries = array();
$conditionshandle = @fopen(SYNTHEADIR . "/conditions.csv", "r");
$found = array();
$activeconditions = 0;
lognl(1, "...... List of conditions for this patient");
rewind($conditionshandle);
while (!feof($conditionshandle)) {
  $buffer = fgets($conditionshandle);
  if (strpos($buffer, $candid) !== FALSE) {
    $item = explode(",", $buffer);
    // var_dump($item);exit;
    // take condition if active
    $snomed = trim($item[4]);
    $display = trim($item[5]);

    // last minute corrections: tweak some codes
    if ($snomed === "15777000") {
      $snomed = "714628002";
      $display = "Prediabetes (disorder)";
    }

    $snomedproperties = get_SNOMED_properties($snomed);
    if ($snomedproperties["code"] !== $snomed) $snomed = $snomedproperties["code"]; // this is a replacement
    $display = strlen($snomedproperties['fullySpecifiedName']) > 0 ? $snomedproperties['fullySpecifiedName'] : $display;
    if (str_contains($display . " " . $snomedproperties['fullySpecifiedName'], "(disorder)")) {
      $active = strlen(trim($item[1])) === 0 ? '1' : '0';
      if ($active === '1') $activeconditions++;
      $found[] = [
        "code" => [
          "code" => $snomed,
          "system" => "\$sct",
          "display" => $display,
          "preferredTerm" => $snomedproperties['preferredTerm'],
          "fullySpecifiedName" => $snomedproperties['fullySpecifiedName'],
        ],
        "start" => trim($item[0]),
        "end" => trim($item[1]),
        "active" => "$active" . trim($item[0]),  // is SYYYY-MM-DD with S status 1=active, 0=inactive
        "encounter" => trim($item[3])
      ];
      lognl (3, sprintf(
        "......... %-10s %-10s %-10s %s",
        $snomed,
        $item[0],
        $display,
       ($active === '1' ? "active" : "inactive")
      ));
    }
  }
}
fclose($conditionshandle);
if (count($found) === 0) {
  lognlsev (3, "WARN", "......... +++ No conditions found\n");
  $pdat->conditions = NULL;
} else {
  // store / handle result
  if ($activeconditions === 0) lognlsev (3, "WARN", "......... +++ No active conditions found\n");
  array_multisort(array_column($found, 'active'), SORT_DESC, $found); // sort by date and report
  // var_dump($found);
  $pdat->conditions = $found;
}

?>