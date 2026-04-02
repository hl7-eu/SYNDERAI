<?php

// prepare vital signs

$FSHvitals = "";
$HTMLvitals = "";
$HEADvitals = "";
$amalghtml = array();
$pdat->vitalsignentries = array();
if ($pdat->vitalsigns !== NULL) {
  // add heading for first round
  $HTMLvitals = "<tr><th>Vital Signs</th><th>$pdat->vitalsignslastdate</th><th>$pdat->vitalsignslastbutonedate</th></tr>";
  foreach ($pdat->vitalsigns as $key => $gdata) {
    // var_dump($gdata);
    if ($key === $pdat->vitalsignslastdate) {
      foreach($gdata as $sdata) {
        $vitalinstanceid = uuid();
        // var_dump($sdata);
        list($tmpfsh, $tmphtml, $HEADvitals, $vitainstance) = 
          twigit([
            "instanceid" => $vitalinstanceid,
            "patient" => $pdat,
            "vital" => $sdata
          ], "vitalsigns");
        // var_dump($tmpfsh);
        // split this tr td td tr into first td content as array key and the rest as array value
        $FSHvitals .= $tmpfsh;
        $firstpart = before("</td>", $tmphtml) . "</td>";  // is like <tr><td>Body Height</td>
        $tmp = after("</td>", $tmphtml);                   // is like <td>22-Jul-2025</td><td>177.2 cm</td></tr>
        $secondpart = before("</tr>", $tmp);               // is like <td>22-Jul-2025</td><td>177.2 cm</td>
        $thirdpart =  after("</td>", $secondpart);         // is like <td>177.2 cm</td>
        // lognl (1,"1: $firstpart");
        // lognl (1,"2: $secondpart");
        // lognl (1,"3: $thirdpart");
        $amalghtml[$firstpart] = $thirdpart . "<td>-</td></tr>";  // stores like <td>177.2 cm</td> under key "<tr><td>Body Height</td>"
        $pdat->vitalsignentries[] = [
          'id' => $vitalinstanceid,
          'instance' => $vitainstance,
          "bundleentryslicename" => "observation-vital-signs",
          "sectionentryslicename" => "vitalSign"
        ];
      }
    }
  }
  // add second round
  foreach ($pdat->vitalsigns as $key => $gdata) {
    // var_dump($sdata);
    if ($key === $pdat->vitalsignslastbutonedate) {
      foreach($gdata as $sdata) {
        $vitalinstanceid = uuid();
        // var_dump($sdata);
        list($tmpfsh, $tmphtml, $HEADvitals, $vitainstance) =
          twigit([
            "instanceid" => $vitalinstanceid,
            "patient" => $pdat,
            "vital" => $sdata
          ], "vitalsigns");
        // var_dump($tmpfsh);
        // split this tr td td tr into first td content, check the tmphtml array if key is present and add the second td as an extra column
        $FSHvitals .= $tmpfsh;
        $firstpart = before("</td>", $tmphtml) . "</td>";
        $tmp = after("</td>", $tmphtml);
        $secondpart = before("</tr>", $tmp);
        $thirdpart =  after("</td>", $secondpart);
        // lognl (1,"1: $firstpart");
        // lognl (1,"2: $secondpart");
        // lognl (1,"3: $thirdpart");
        if (isset($amalghtml[$firstpart])) {
          // already there, add column directly to existing part
          $amalghtml[$firstpart] = str_replace("<td>-</td></tr>", "", $amalghtml[$firstpart]);  // delete second td empty part and complete it
          $amalghtml[$firstpart] .= $thirdpart . "</tr>";
        } else {
          // not in first date, so create an extra row only for the second with no first content
          $amalghtml[$firstpart] = "<td>-</td>" . $thirdpart . "</tr>";
        }
        $pdat->vitalsignentries[] = [
          'id' => $vitalinstanceid,
          'instance' => $vitainstance,
          "bundleentryslicename" => "observation-vital-signs",
          "sectionentryslicename" => "vitalSign"
        ];
      }
    }
  }
  foreach ($amalghtml as $key => $value) {
    $HTMLvitals .= $key . $value;
  }
  // var_dump ($HTMLvitals);exit;
}

// non-mandatory section
if ($pdat->vitalsigns !== NULL) {
  $sections['sectionVitalSigns'] = [
    'title' => 'Vital Signs',
    'code' => '$loinc#8716-3',
    'display' => "Vital signs note",
    'text' => "<table class='hl7__ips'>$HEADvitals $HTMLvitals</table>",
    'entries' => $pdat->vitalsignentries,
    'fsh' => $FSHvitals
  ];
}

?>