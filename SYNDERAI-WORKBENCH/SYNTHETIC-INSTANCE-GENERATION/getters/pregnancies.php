<?php

// *** pregnancy and childbirth information

// derive number of childbirths from the procedures identified as 66348005,Childbirth or 65588006,Premature birth of newborn
$pdat->childbirths = -1;
if ($pdat->procedures !== NULL)
    foreach ($pdat->procedures as $p) {
      $thecode = $p["code"]["code"];
      if ($thecode === '66348005' or $thecode === '65588006') {
        $pdat->childbirths = $pdat->childbirths === -1 ? 1 : $pdat->childbirths + 1;
      }
    }

 // derive from conditions if an active pregnancy is present
 // 72892002=Normal pregnancy 161744009=Past pregnancy history of miscarriage 47200007,Non-low risk pregnancy 79586000,Tubal pregnancy
$pdat->pregnancy = -1; // stands for unknown, not creating an entry
if ($pdat->conditions !== NULL)
  foreach ($pdat->conditions as $c) {
    $thecode = $c["code"]["code"];
    if ($thecode === '72892002' or $thecode === '47200007' or $thecode === '79586000') {
      $started = abs(time() - strtotime($c["start"]));  // pregnancy start in seconds from today
      $days = floor($started / (60*60*24));   // total seconds in a days (60*60*24)
      if ($days < 300 and strlen($c["end"] === 0)) {
        $pdat->pregnancy = 1;
      }
    }
  }
  
lognl(3, "......... Pregnancy Information: births=$pdat->childbirths, currently pregnant=$pdat->pregnancy \n");

?>