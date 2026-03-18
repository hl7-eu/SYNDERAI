<?php

// prepare immunizations
// a note: a care plan record with an immunization recommendation for the next influenza vaccination is created, see care plan later

$FSHvac = "";
$HTMLvac = "";
$pdat->immunizationsentries = array();
if ($pdat->immunizations !== NULL) {
  // add heading
  foreach ($pdat->immunizations as $sdata) {
    $immunizationinstanceid = uuid();
    list($tmpfsh, $tmphtml, $HEADvac, $iminstance) =
      twigit(["instanceid" => $immunizationinstanceid, "patient" => $pdat, "immunization" => $sdata], "immunization-ips");
    $pdat->immunizationsentries[] = [
      'id' => $immunizationinstanceid,
      'instance' => $iminstance,
      "bundleentryslicename" => "immunization",
      "sectionentryslicename" => "immunization"
    ];
    $FSHvac .= $tmpfsh;
    $HTMLvac .= $tmphtml;
  }
}

// non-mandatory section
if ($pdat->immunizations !== NULL) {
  $sections['sectionImmunizations'] = [
    'title' => 'Immunizations list',
    'code' => '$loinc#11369-6',
    'display' => "History of Immunization note",
    'text' => "<table class='hl7__ips'>$HEADvac $HTMLvac</table>",
    'entries' => $pdat->immunizationsentries,
    'fsh' => $FSHvac
  ];
}

?>