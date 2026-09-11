<?php
/*
 * we get a list of all conditions
 *   this set appears as $pdat->allconditions = NULL; initially as $pdat->allconditions = NULL;
 * 
 * drop all episodical, resolved acute self-limiting illness, resolved obstetric events, resolved surgical or traumatic episode
 * that are not usefull for a patient summary longitudinal record (in contrast to the episodical records)
 *   this set appears as $pdat->conditions = NULL; initially as $pdat->conditions = NULL;
 * 
 * all condition ingested from ish definitions are equally treated as all conditions and the 
 * dropped one so $pdat->allconditions equals $pdat->conditions 
 * 
 * @see https://synderai.net/index.php?menu=epsca
 */

$pdat->allconditions = NULL;
$pdat->conditions = NULL;
$pdat->pastillnessentries = array();

if ($PROCESSISH) {
  $found = NULL;
  if (isset($pdat->section)) {
      foreach ($pdat->section as $s) {
          if (isset($s->entry)) {
              foreach ($s->entry as $e) {
                  if (isset($e->type) && $e->type === "condition") {
                    $ccode = array();
                    foreach ($e->code as $cc) {
                      if (isset($cc->code))
                        $ccode[] = [
                          "code" => $cc->code,
                          "system" => $cc->system,
                          "display" => $cc->display
                        ];
                    }
                    $found[] = [
                      "code" => $ccode,
                      "start" => $e->date,
                      "end" => $e->status === "resolved" ? $e->date: NULL,
                      "active" => $e->status === "resolved" ? FALSE : TRUE,
                      "encounter" => NULL
                    ];
                  }
              }
          }
      }
  }
  $pdat->conditions = $found;
  $pdat->allconditions = $found;
 
} else {
  // ***
  // *** get all random conditions for this candidate
  // ***
  // open conditions 

  $conditionshandle = fopen(
    is_file(SYNTHEADIR . "/conditions/$candid") ?
    SYNTHEADIR . "/conditions/$candid" :
    SYNTHEADIR . "/conditions.csv",
    "r"
  );
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
      $snomed = trim($item[5]);
      $display = trim($item[6]);

      // last minute corrections: tweak some codes
      if ($snomed === "15777000") {
        $snomed = "714628002";
        $display = "Prediabetes (disorder)";
      }
      $snomedproperties = get_SNOMED_properties($snomed, $display);
      if ($snomedproperties["code"] !== $snomed && strlen($snomedproperties["code"]) > 0)
        $snomed = $snomedproperties["code"]; // this is a replacement
      $display = strlen($snomedproperties['fullySpecifiedName']) > 0 ? $snomedproperties['fullySpecifiedName'] : $display;
      if (str_contains($display . " " . $snomedproperties['fullySpecifiedName'], "(disorder)")) {
        $active = strlen(trim($item[1])) === 0 ? '1' : '0';
        if ($active === '1')
          $activeconditions++;
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
    lognlsev (3, WARNING, "......... +++ No conditions found\n");
    $pdat->allconditions = NULL;
    $pdat->conditions = NULL;
  } else {
    // store / handle result
    if ($activeconditions === 0) 
      lognlsev (3, WARNING, "......... +++ No active conditions found\n");
    array_multisort(array_column($found, 'active'), SORT_DESC, $found); // sort by date and report
    // var_dump($found);
    $pdat->allconditions = $found;
    // make $pdat->conditions the new $filteredConditions for proper EPS condition emission
    $pdat->conditions = filterAndAdaptConditions($pdat->allconditions);
  }
}


?>