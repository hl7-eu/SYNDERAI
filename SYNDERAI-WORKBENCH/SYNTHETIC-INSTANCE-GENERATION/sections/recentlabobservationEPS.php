<?php

// NO patient's specimen are emited for EPS
// ----------------------------------------

// emit patient's recent lab observations
// --------------------------------------

$FSHlab1 = "";
$HTMLlab1 = "";
$pdat->labentries = array();
$pdat->labresults = array();

if ($pdat->labobservations === NULL) {
  // no recent relevant lab observations, be silent on this
} else {

  $maxfoundix = max(array_keys($pdat->labobservations));
  // 
  // dump($maxfoundix);
  foreach ($pdat->labobservations as $ldate => $lbspd) {
    // echo "$ldate - $maxfoundix[0]\n";
    if ($ldate !== $maxfoundix)
      continue;  // only the recent one, all other skip
    foreach ($lbspd as $labi) {
      $code = array();
      $code = [
        "code" => $labi["code"]["code"],
        "display" => $labi["code"]["display"],
        // must tweak system snomed short alias "snomed" to "sct"
        "system" => $labi["code"]["system"] === "snomed" ? "\$sct" : $labi["code"]["system"]
      ];
      $lnsystem = $labi["lnsystem"];
      $value = array();
      $value = [
        'type' => $labi["valuetype"],
        'value' => $labi["value"],
        'code' => $labi["valuecode"],
        'unit' => $labi["valueunit"],
        // must tweak system snomed short alias "snomed" to "sct"
        'system' => $labi["valuesystem"] === "snomed" ? "\$sct" : $labi["valuesystem"],
        'display' => $labi["valuedisplay"]
      ];
      if (USE_AI) {
        $labtestai = $labi["code"]["display"] . " " . $labi["value"] . " " . $labi["valueunit"];
        $labtestmd5 = $pdat->age . $pdat->gender . $labi["code"]["display"] . $labi["valueunit"];
        // reference range in cache?
        $md5 = md5($pdat->age . $pdat->gender . $labtestmd5);
        $fai = inCACHE('referencerange', $md5);
        if ($fai !== FALSE) {
          // reference range is in cache
          lognl(5, "......... MD5: $md5\n");
          $rr1 = json_decode($fai, TRUE);  // use json reference range from cache
        } else {
          // not in cache, ask AI
          $AI = getAIReferenceRange($pdat->age, $pdat->gender, $labtestai);
          /*
           * AI should return either JSON with low, high, unit and display upon Quantities reference ranges
           * or "text" upon Qualitative reference ranges, such as "Pale yellow - Yellow to amber"
           * take that into account when post-processing it
           */
          if (isset($AI)) {
            $rr1 = json_decode($AI['rr'], TRUE);
            // var_dump($AI); exit;
            // echo $AI['rr'] . "\n";
            toCACHE('referencerange', $md5, $AI['rr']);
          } else {
            $rr1 = NULL;
          }
        }
        // if ($value["code"] = "275778006") var_dump($rr1);
        if ($rr1 === NULL) {  // still no ref range, add the "placeholder reference range"
          $rr1 = [
            "low" => NULL,
            "high" => NULL,
            "unit" => $labi["valueunit"],
            "display" => NULL,
            "text" => NULL
          ];
        } else {
          // if set check whether low and high are real numbers (hich is ok)
          // or characters like "Negative" coming from faulty AI. 
          // if the latter correct it.
          $isnumeric = TRUE;  // assume all is numeric
          $thetext = array(); // to record non-numeric ranges like "Negative-Trace".
          if (isset($rr1["low"])) {
            if (is_numeric($rr1["low"])) {
              $rr1["low"] = (0 + $rr1["low"]);   // make it really a int/float
            } else {
              $isnumeric = FALSE;
              $thetext[] = $rr1["low"];
            }
          }
          if (isset($rr1["high"])) {
            if (is_numeric($rr1["high"])) {
              $rr1["high"] = (0 + $rr1["high"]);  // make it really a int/float
            } else {
              $isnumeric = FALSE;
              $thetext[] = $rr1["high"];
            }
          }
          if (!$isnumeric) {
            // the low or high field contains "non-numeric" values, reset $rr1 reference range to be used as text
            if (isset($rr1["display"])) {
              // if display is already set use it...
              $rr1 = [
                "text" => $rr1["display"]
              ];
            } else {
              // ... otherwise try to compile something usefull out of the text(s)
              if (count($thetext) == 2) {
                $rr1 = [
                  "text" => $thetext[0] . " - " . $thetext[0]
                ];
              } else if (count($thetext) == 1) {
                $rr1 = [
                  "text" => $thetext[0] . $thetext[0]
                ];
              } else {
                $rr1 = [];  // no reference range at all
              }
            }
          }
        }
      } else {
        // no AI but add the "placeholder reference range"
        $rr1 = [
          "low" => NULL,
          "high" => NULL,
          "unit" => $labi["valueunit"],
          "display" => NULL,
          "text" => NULL
        ];
      }

      $data = [
        "code" => [$code],  // code must be an array
        "subject" => $pdat->instanceid,
        "subjectname" => $pdat->name,
        "effective" => $ldate,
        "report" => strtoupper(date('d-M-Y', strtotime($ldate))), //  strtotime('+1 day', strtotime($ldate)))),
        "value" => $value,
        "reference" => $rr1,
        "lnclass" => $labi["lnclass"],
        "lnsystem" => $labi["lnsystem"]
      ];

      // presets for local HTML
      $rvl = "";
      $rvu = "";
      $refr = "";
      $rdp = $code["display"];
      if ($value["type"] === 'Quantity') {
        $rvl = $value["value"];
        $rvu = $value["unit"];
        if (isset($rr1["low"]) and isset($rr1["high"])) {
          if ($rr1["low"] !== NULL and $rr1["high"] !== NULL) {
            $refl = $rr1["low"];
            $refh = $rr1["high"];
            $refr = $refl . " - " . $refh;
            // correct display of value if out of range
            if ($rvl < $refl) {
              $rvl = "<strong>$rvl L</strong>";
            } else if ($rvl > $refh) {
              $rvl = "<strong>$rvl H</strong>";
            }
          }
        }

      }
      if ($value["type"] === 'CodeableConcept') {
        $rvl = $value["display"];
      }

      // invent HTML for lab
      $HTMLlab1 .= "<tr><td>$rdp</td><td>$rvl</td><td>$refr</td><td>$rvu</td></tr>";
      // var_dump($data);
      $data = json_decode(json_encode($data));
      // var_dump($data);
      // var_dump($data->code);
      $lorecomminstanceid = uuid();
      list($tmpfsh, $tmphtml, $tmphead, $lorecomminstance) =
        twigit([
          "instanceid" => $lorecomminstanceid,
          "labresult" => $data
        ], "observation-medical-test-result-eu");
      // store all lab data of this date, the generated fsh and IDs
      // var_dump($tmpfsh);var_dump($lorecomminstance);exit;
      $FSHlab1 .= $tmpfsh;
      $HTMLlab1 .= "";
      $pdat->labresults[$ldate][] = [
        "id" => $lorecomminstanceid,
        "instance" => $lorecomminstance,
        "data" => $data,
        "fsh" => $tmpfsh
      ];
      $pdat->labentries[] = [
        "id" => $lorecomminstanceid,
        "instance" => $lorecomminstance,
        "bundleentryslicename" => "",
        "sectionentryslicename" => "results-medicalTestResult"
      ];
    }
  }

  $HTMLlab1 = "<thead><tr><th>Recent Lab Observations</th><th>" .
    strtoupper(date('d-M-Y', strtotime($ldate))) .
    "</th><th>Reference&#xA0;Range</th><th>Unit</th></tr></thead><tbody>" .
    $HTMLlab1 . "</tbody>";

  // non-mandatory section// non-mandatory section
  if ($pdat->labresults !== NULL) {
    $sections['sectionResults'] = [
      'title' => 'Relevant diagnostic tests/laboratory data',
      'code' => '$loinc#30954-2',
      'display' => "Relevant diagnostic tests/laboratory data note",
      'text' => "<table class='hl7__ips'>$HTMLlab1</table>",
      'entries' => $pdat->labentries,
      'fsh' => $FSHlab1 . "\n\n"
    ];
  }
}
?>