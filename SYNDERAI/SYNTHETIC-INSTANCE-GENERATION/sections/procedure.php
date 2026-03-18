<?php

// prepare procedures
$FSHproc = "";
$HTMLproc = "";
$HEADproc = "";
$pdat->proceduresentries = array();
$indicateEmptyListReason = FALSE;

// as alignment with the EHDS model, procedure section must be present, if empty, emit emptyReason for the list

if ($pdat->procedures !== NULL) {
  foreach ($pdat->procedures as $sdata) {
    // var_dump($sdata);
    $procedureinstanceid = uuid();
    list($tmpfsh, $tmphtml, $HEADproc, $procedureinstancename) =
      twigit([
        "instanceid" => $procedureinstanceid, 
        "patient" => $thispat, 
        "procedure" => $sdata
      ], "procedure-eu");
    
    $FSHproc .= $tmpfsh;
    $HTMLproc .= $tmphtml;
    
    // FSH_ProcedureEPS($thispatientid, $pdat->given . " " . $pdat->family, $sdata);
    $pdat->proceduresentries[] = [
      'id' => $procedureinstanceid,
      'instance' => $procedureinstancename,
      "bundleentryslicename" => "procedure",
      "sectionentryslicename" => "procedure"
    ];
  }
} else {
  // no procedures, add empty indication if requested
  $FSHproc = "";  // no FSJ for procedure entries...
  $HTMLproc = "<tr><td>No known procedures</td></tr>";
  $indicateEmptyListReason = TRUE;
}

// unconditonally to be included, mandatory section
$sections['sectionProceduresHx'] = [
  'title' => 'Procedure History list',
  'code' => '$loinc#47519-4',
  'display' => "History of Procedures Document",
  'text' => "<table class='hl7__ips'>$HEADproc $HTMLproc</table>",
  'entries' => $pdat->proceduresentries,
  'fsh' => $FSHproc,
  'indicateEmptyListReason' => $indicateEmptyListReason
];

?>