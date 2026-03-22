<?php


$pdat->inpatientencounters = NULL;

if ($PROCESSISH) {
  $found = NULL;
  // for ISH patients there is only one encounter
  // var_dump($pdat->encounter);exit;
  // encounter procdures
  $pcode = array();
  foreach ($pdat->encounter->procedure as $cc) {
    if (isset($cc->code))
      $pcode[] = [
        "code" => $cc->code,
        "system" => $cc->system,
        "display" => $cc->display
      ];
  };
  // encounter reasons
  $rcode = array();
  foreach ($pdat->encounter->reason as $cc) {
    if (isset($cc->code))
      $rcode[] = [
        "code" => $cc->code,
        "system" => $cc->system,
        "display" => $cc->display
      ];
  };
  // discharge mini text is in section synthesis
  $discharge = "";
  if (isset($pdat->section))
      foreach ($pdat->section as $s)
          if (isset($s->type) && $s->type === "synthesis" && isset($s->text)) $discharge = $s->text;
  $found[] = [
     "procedure" => $pcode,
     "reason" => $rcode,
     "discharge" => $discharge,
     "start" => substr($pdat->encounter->start, 0, 10),
     "end" => substr($pdat->encounter->end, 0, 10),
     "startexact" => $pdat->encounter->start,
     "endexact" => $pdat->encounter->end,
     "encounterid" => NULL
   ];
  // var_dump($found);exit;
  $pdat->inpatientencounters = $found;

} else {
  // ***
  // *** get all inpatient encounters for this candidate
  // ***
  // populate this patient's inpatientencounters 
  // $INPATIENTENCOUNTERS contains all encounters with class "inpatient"
  // has encounter id, story (candidate) id and if appropriate admission reason also the discharge information 

  if (!isset($INPATIENTENCOUNTERS)) {
    lognlsev (1, ERROR, "......... +++ No inpatient encounter list found, 'encounter' getters must be called before this function\n");
  } else {
    
    lognl(1, "...... List of inpatient encounters for this patient");

    $found = array();
    foreach ($INPATIENTENCOUNTERS as $ipe) {
      if ($ipe["candid"] === $candid) {

        $proceduredisplay = $ipe["procedure"]["display"];
        $procedureproperties = get_SNOMED_properties($ipe["procedure"]["code"], $proceduredisplay);
        if ($procedureproperties["code"] !== $ipe["procedure"]["code"]) $ipe["procedure"]["code"] = $procedureproperties["code"]; // this is a replacement
        if (strlen($procedureproperties['fullySpecifiedName']) > 0) $proceduredisplay = $procedureproperties['fullySpecifiedName'];
        
        $reasondisplay = $ipe["reason"]["display"];
        $reasonproperties = get_SNOMED_properties($ipe["reason"]["code"], $reasondisplay);
        if ($reasonproperties["code"] !== $ipe["reason"]["code"]) $ipe["procedure"]["code"] = $reasonproperties["code"]; // this is a replacement
        if (strlen($reasonproperties['fullySpecifiedName']) > 0) $reasondisplay = $reasonproperties['fullySpecifiedName'];

        $found[] = [
          "procedure" => [
            "code" => $ipe["procedure"]["code"],
            "system" => "\$sct",
            "display" => $proceduredisplay,
            "preferredTerm" => $procedureproperties['preferredTerm'],
            "fullySpecifiedName" => $procedureproperties['fullySpecifiedName'],
          ],
          "reason" => [
            "code" => $ipe["reason"]["code"],
            "system" => "\$sct",
            "display" => $reasondisplay,
            "preferredTerm" => isset($reasonproperties['preferredTerm']) ? $reasonproperties['preferredTerm'] : "",
            "fullySpecifiedName" => isset($reasonproperties['fullySpecifiedName']) ? $reasonproperties['fullySpecifiedName'] : "",
          ],
          "discharge" => $ipe["discharge"],
          "start" => $ipe["start"],
          "end" => $ipe["end"],
          "startexact" => $ipe["startexact"],
          "endexact" => $ipe["endexact"],
          "encounterid" => $ipe["encounterid"]
        ];
        // var_dump($found);
        lognl (2, sprintf(
          "......... Hospital encounter from %s to %s (ID %s)",
          $ipe["start"],
          $ipe["end"],
          $ipe["encounterid"]
        ));
        lognl (3, sprintf(
          "............ admission reason: %s -> procedure performed: %s",
          $reasondisplay,
          $proceduredisplay
        ));
        lognl (2, sprintf(
          "............ discharge synthesis: %s",
          $ipe["discharge"]["text"]
        ));
        lognl (3, sprintf(
          "............ discharge code: %s %s",
          $ipe["discharge"]["code"],
          $ipe["discharge"]["display"]
        ));
      }
    }
  }

  if (count($found) === 0) {
    lognlsev (3, WARNING, "......... +++ No inpatient encounters found\n");
  } else {
    // store / handle result
    // var_dump($found);
    $pdat->inpatientencounters = $found;
  }

  /*
        $encounterhandle = @fopen(SYNTHEADIR . "/encounters.csv", "r");
        $found = array();
        lognl(1, "...... List of inpatient encounters for this patient");
        rewind($encounterhandle);
        while (!feof($encounterhandle)) {
          $buffer = fgets($encounterhandle);
          if (strpos($buffer, $candid) !== FALSE) {
            $item = explode(",", $buffer);
            $encid = trim($item[0]);
            $encclass = trim($item[7]);
            if ($encclass === "inpatient") {
              // var_dump($item);exit;
              $procedure = trim($item[8]);
              $proceduredisplay = trim($item[9]);
              $reason = trim($item[13]);
              $reasondisplay = trim($item[14]);
              $start = substr(trim($item[1]), 0, 10);
              $end = substr(trim($item[2]), 0, 10);
              $startexact = trim($item[1]);
              $endexact = trim($item[2]);
              $procedureproperties = get_SNOMED_properties($procedure);
              if (strlen($procedureproperties['fullySpecifiedName']) > 0) $proceduredisplay = $procedureproperties['fullySpecifiedName'];
              if (strlen($reason) > 0) {
                $reasonproperties = get_SNOMED_properties($reason);
                if (strlen($reasonproperties['fullySpecifiedName']) > 0) $reasondisplay = $reasonproperties['fullySpecifiedName'];
              }
              // merge the $APPROPRIATEREASONS->discharge array into found items for this reason if exists
              if (isset($APPROPRIATEREASONS[$reason]["discharge"])) {
                $dischargeinfo = $APPROPRIATEREASONS[$reason]["discharge"];
              } else {
                $dischargeinfo = [];
              }
              // if (count($dischargeinfo) > 0) { // only register hospital stays that do have discharge information
                $found[] = [
                  "procedure" => [
                    "code" => $procedure,
                    "system" => "\$sct",
                    "display" => $proceduredisplay,
                    "preferredTerm" => $procedureproperties['preferredTerm'],
                    "fullySpecifiedName" => $procedureproperties['fullySpecifiedName'],
                  ],
                  "reason" => [
                    "code" => $reason,
                    "system" => "\$sct",
                    "display" => $reasondisplay,
                    "preferredTerm" => isset($reasonproperties['preferredTerm']) ? $reasonproperties['preferredTerm'] : "",
                    "fullySpecifiedName" => isset($reasonproperties['fullySpecifiedName']) ? $reasonproperties['fullySpecifiedName'] : "",
                  ],
                  "discharge" => $dischargeinfo,
                  "start" => $start,
                  "end" => $end,
                  "startexact" => $startexact,
                  "endexact" => $endexact,
                  "id" => $encid
                ];
                // var_dump($found);
                lognl (2, sprintf(
                  "......... Hospital stay from %s to %s (%s)",
                  $start,
                  $end,
                  $encid
                ));
                lognl (2, sprintf(
                  "............ admission reason: %s -> procedure performed: %s",
                  $reasondisplay,
                  $proceduredisplay
                ));
                lognl (2, sprintf(
                  "............ discharge information: %s / %s %s",
                  $dischargeinfo["text"],
                  $dischargeinfo["code"],
                  $dischargeinfo["display"]
                ));
              } else {
                lognlsev (2, WARNING, "......... +++ No discharge information found for reason $reasondisplay ($reason) period $start - $end\n");
              }
            }
          }
        }
        fclose($encounterhandle);
        // var_dump($found);exit;
  **/
}


