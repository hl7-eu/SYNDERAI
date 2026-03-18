<?php

  // prepare conditions, CAVE this is mandatory in EPS, if none found, sumbit NULL as data

  $FSHcond = "";     // for active conditions/problems
  $HTMLcond = "";    // for active conditions/problems
  $FSHhistill = "";  // for history of past illnesses
  $HTMLhistill = ""; // for history of past illnesses

  // check whether there are any active conditions
  $activeconditions = 0;
  if ($pdat->conditions !== NULL) {
    foreach ($pdat->conditions as $c) {
      $activeconditions += (substr($c["active"], 0, 1) == '1') ? 1 : 0;
    }
  }
  // pre-prepare patient mini
  $thispat = [
    "instanceid" => $pdat->instanceid,
    "name" => $pdat->name
  ];
  if ($activeconditions === 0) {
    // no active problems at all
    $conditioninstanceid = uuid();
    list($FSHcond, $HTMLcond, $tmphead, $conditioninstancename) = 
      twigit([
        "instanceid" => $conditioninstanceid,
        "patient" => $pdat,
        "asserter" => NULL,
        "condition" => NULL
      ], "condition-eps");
    $pdat->conditionsentries[] = [
      'id' => $conditioninstanceid,
      'instance' => $conditioninstancename,
      "bundleentryslicename" => "condition",
      "sectionentryslicename" => "problem"
    ];
  } else {
    // list here all conditions and use $FSHcond and $HTMLcond
    // add heading
    foreach ($pdat->conditions as $sdata) {
      $conditioninstanceid = uuid();
      list($tmpfsh, $tmphtml, $notused, $conditioninstancename) =
        twigit([
          "instanceid" => $conditioninstanceid,
          "patient" => $thispat,
          "asserter" => $pdat->provider,
          "condition" => $sdata
        ], "condition-eps");
      if (strlen($HTMLcond) == 0) $HTMLcond = "<tr><th>Condition</th><th>Onset Date / Period</th><th>Status</th></tr>";  // inital value, set heading
      $pdat->conditionsentries[] = [
        'id' => $conditioninstanceid,
        'instance' => $conditioninstancename,
        "bundleentryslicename" => "condition",
        "sectionentryslicename" => "problem"
      ];
      $FSHcond .= $tmpfsh;
      $HTMLcond .= $tmphtml;
    }
  }
  
  // unconditonally to be included, mandatory section
  $sections['sectionProblems'] = [
    'title' => 'Problem list',
    'code' => '$loinc#11450-4',
    'display' => "Problem list - Reported",
    'text' => "<table class='hl7__ips'>$HTMLcond</table>",
    'entries' => $pdat->conditionsentries,
    'fsh' => $FSHcond
  ];

?>