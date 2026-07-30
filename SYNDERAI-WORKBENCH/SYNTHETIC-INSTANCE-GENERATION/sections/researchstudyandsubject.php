<?php

// prepare research study and subject

$FSHresearchstudy = "";
$HTMLresearchstudy = "";
$HEADresearchstudy = "";

$researchstudyinstanceid = uuid();
$researchsubjectinstanceid = uuid();

list($FSHresearchstudy, $HTMLresearchstudy, $HEADresearchstudy, $instancestudy, $instancesubject) =
  twigit(
    [
      "researchstudyinstanceid" => $researchstudyinstanceid,
      "researchsubjectinstanceid" => $researchsubjectinstanceid,
      "patient" => $pdat
    ],
    "research-study-and-subject-eps"
  );

$pdat->researchstudysentries = array();
$pdat->researchstudysentries[] = [
  'id' => $researchstudyinstanceid,
  "instance" => $instancestudy,
  "bundleentryslicename" => "",
  "sectionentryslicename" => ""
];
$pdat->researchstudysentries[] = [
  'id' => $researchsubjectinstanceid,
  "instance" => $instancesubject,
  "bundleentryslicename" => "",
  "sectionentryslicename" => ""
];

// non-mandatory section, no-name
$sections[] = [
  'title' => 'Research study consent',
  'code' => '$loinc#77602-1',
  'display' => "Research study consent",
  'text' => "<table class='hl7__ips'>$HEADresearchstudy $HTMLresearchstudy</table>",
  'entries' => $pdat->researchstudysentries,
  'fsh' => $FSHresearchstudy
];

?>