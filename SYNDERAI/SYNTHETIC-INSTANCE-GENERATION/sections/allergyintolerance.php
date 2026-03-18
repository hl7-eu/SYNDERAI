<?php

// prepare allergies/intolerances, CAVE this is mandatory in EPS, if none found, sumbit NULL as data

$FSHalg = "";
$HTMLalg = "";
$HEADalg = "";

if ($pdat->allergies === NULL) {
  $instanceid = uuid();
  // var_dump($m);
  list($tmpfsh, $tmphtml, $HEADalg, $instance) =
    twigit([
      "instanceid" => $instanceid, 
      "patient" => $pdat,
      "allergyintolerance" => NULL
    ], "allergy-intolerance-eu-core");
  $FSHalg = $tmpfsh;
  $HTMLalg = $tmphtml;
  $pdat->allergiesentries[] = [
    'id' => $instanceid,
    'instance' => $instance,
    "bundleentryslicename" => "allergyintolerance",
    "sectionentryslicename" => "allergyOrIntolerance"
  ];
} else {
  // add heading
  foreach ($pdat->allergies as $sdata) {
    $instanceid = uuid();
    list($tmpfsh, $tmphtml, $HEADalg, $instance) =
      twigit([
        "instanceid" => $instanceid, 
        "patient" => $pdat,
        "allergyintolerance" => $sdata
      ], "allergy-intolerance-eu-core");
    $FSHalg .= $tmpfsh;
    $HTMLalg .= $tmphtml;
    $pdat->allergiesentries[] = [
      'id' => $instanceid,
      'instance' => $instance,
      "bundleentryslicename" => "allergyintolerance",
      "sectionentryslicename" => "allergyOrIntolerance"
    ];
  }
}

// unconditonally to be included, mandatory section
$sections['sectionAllergies'] = [
  'title' => 'Allergies and Intolerances',
  'code' => '$loinc#48765-2',
  'display' => "Allergies and adverse reactions Document",
  'text' => "<table class='hl7__ips'>$HEADalg $HTMLalg</table>",
  'entries' => $pdat->allergiesentries,
  'fsh' => $FSHalg
];

?>