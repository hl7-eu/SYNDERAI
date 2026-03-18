<?php

// prepapre Pregnancy History

$FSHpregnan = "";
$HTMLpregnan = "";
$pdat->pregnancyentries = array();

if ($pdat->childbirths !== -1) {
  $pregnancy = [
    "code" => "11640-0",
    "system" => "\$loinc",
    "display" => "[#] Births total",
    "effective" => date('Y-m-d'),
    "value" => $pdat->childbirths
  ];
  $pregnaninstanceid = uuid();
  list($tmpfsh,
    $tmphtml,
    $tmphead,
    $pregnaninstance) =
    twigit(["instanceid" => $pregnaninstanceid, "patient" => $pdat, "pregnancy" => $pregnancy], "observation-pregnancy-outcome-ips");
  $FSHpregnan .= $tmpfsh;
  $HTMLpregnan .= $tmphtml;
  $pdat->pregnancyentries[] = [
    'id' => $pregnaninstanceid,
    'instance' => $pregnaninstance,
    "bundleentryslicename" => "observation",
    "sectionentryslicename" => "pregnancyOutcome"
  ];
}

if ($pdat->pregnancy !== -1) {
  $pregnaninstanceid = uuid();
  list($tmpfsh, $tmphtml, $nohead, $pregnaninstance) =
    twigit(["instanceid" => $pregnaninstanceid, "patient" => $pdat, "pregnancy" => $pdat->pregnancy], "observation-pregnancy-status-uv-ips");
  $FSHpregnan .= $tmpfsh;
  $HTMLpregnan .= $tmphtml;
  $pdat->pregnancyentries[] = [
    'id' => $pregnaninstanceid,
    'instance' => $pregnaninstance,
    "bundleentryslicename" => "observation",
    "sectionentryslicename" => "pregnancyStatus"
  ];
}

// non-mandatory section
if (count($pdat->pregnancyentries) > 0) {
  $sections['sectionPregnancyHx'] = [
    'title' => 'Pregnancy History',
    'code' => '$loinc#10162-6',
    'display' => "History of pregnancies Narrative",
    'text' => "<table class='hl7__ips'>$HTMLpregnan</table>",
    'entries' => array(),
    'fsh' => $FSHpregnan
  ];
}

?>