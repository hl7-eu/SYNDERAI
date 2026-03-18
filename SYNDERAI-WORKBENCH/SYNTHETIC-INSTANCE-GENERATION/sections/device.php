<?php

// prepare devices
$FSHdev = "";
$HTMLdev = "";
$HEADdev = "";
$pdat->deviceentries = array();
$indicateEmptyListReason = FALSE;

// as alignment with the EHDS model, devices section must be present, if empty, emit emptyReason for the list

if ($pdat->devices !== NULL) { 
  foreach ($pdat->devices as $sdata) {
    $deinstance = uuid();
    $duinstance = uuid();
    list($tmpfsh, $tmphtml, $HEADdev, $instancede, $instancedu) =
      twigit([
        "deinstanceid" => $deinstance,
        "duinstanceid" => $duinstance, 
        "patient" => $pdat,
        "device" => $sdata], 
        "device-use-eps");
    $pdat->deviceentries[] = [
        "id" => $duinstance,
        "instance" => $instancedu,
        "bundleentryslicename" => "deviceusestatement",
        "sectionentryslicename" => "deviceStatement"
      ];
    $pdat->deviceentries[] = [
      "id" => $deinstance,
      "instance" => $instancede,
      "nosectionentry" => TRUE,
      "bundleentryslicename" => "device",
      "sectionentryslicename" => "device"
    ];
    $FSHdev .= $tmpfsh;
    $HTMLdev .= $tmphtml;
  }
} else {
  // no devices, add empty indication if requested
  $FSHdev = "";  // no FSJ for procedure entries...
  $HTMLdev = "<tr><td>No known devices</td></tr>";
  $indicateEmptyListReason = TRUE;
}

// unconditonally to be included, mandatory section
$sections['sectionMedicalDevices'] = [
  'title' => 'Device Use',
  'code' => '$loinc#46264-8',
  'display' => "History of medical device use",
  'text' => "<table class='hl7__ips'>$HEADdev $HTMLdev</table>",
  'entries' => $pdat->deviceentries,
  'fsh' => $FSHdev,
  'indicateEmptyListReason' => $indicateEmptyListReason
];

?>