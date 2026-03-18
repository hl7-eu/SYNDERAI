<?php

  // prepare medications, CAVE this is mandatory in EPS, if none found, sumbit NULL as data

  $FSHmed = "";
  $HTMLmed = "";
  $HEADmed = "";

  if ($pdat->medications === NULL) {
    // no medication just emit the "No medication" resource
    $instanceid = uuid();
    list($FSHmed, $HTMLmed, $HEADmed, $instance) =
      twigit([
        "instanceid" => $instanceid, 
        "druginstanceid" => NULL,
        "patient" => $pdat,
        "medication" => NULL
      ], "medicationstatement-eu-eps");
    $pdat->medicationsentries[] = [
      'id' => $instanceid,
      'instance' => $instance,
      "bundleentryslicename" => "medicationstatement",
      "sectionentryslicename" => "medicationStatementOrRequest"
    ];
  } else {
    // emit all medications for the patient
    foreach ($pdat->medications as $sdata) {
      $druginstanceid = uuid();
      $instanceid = uuid();
      // var_dump($m);
      list($tmpfsh, $tmphtml, $HEADmed, $druginstance, $instance) =
        twigit([
          "instanceid" => $instanceid, 
          "druginstanceid" => $druginstanceid,
          "patient" => $pdat,
          "medication" => $sdata
        ], "medicationstatement-eu-eps");
      // $r = FSH_MedicationStatementEPS($thispatientid, $pdat->given . " " . $pdat->family, $sdata);
      $FSHmed .= $tmpfsh;
      $HTMLmed .= $tmphtml;
      $pdat->medicationsentries[] = [
        'id' => $druginstanceid,
        'instance' => $druginstance,
        'nosectionentry' => TRUE,
        "bundleentryslicename" => "medication",
      ];
      $pdat->medicationsentries[] = [
        'id' => $instanceid,
        'instance' => $instance,
        "bundleentryslicename" => "medicationstatement",
        "sectionentryslicename" => "medicationStatementOrRequest"
      ];
    }
  }
  
  // unconditonally to be included, mandatory section
  $sections['sectionMedications'] = [
    'title' => 'Medication list',
    'code' => '$loinc#10160-0',
    'display' => "History of Medication use Narrative",
    'text' => "<table class='hl7__ips'>$HEADmed $HTMLmed</table>",
    'entries' => $pdat->medicationsentries,
    'fsh' => $FSHmed
  ];

?>