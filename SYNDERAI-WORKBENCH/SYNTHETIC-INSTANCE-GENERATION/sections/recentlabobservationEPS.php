<?php

// NO patient's specimen are emited for EPS
// ----------------------------------------

// emit patient's recent lab observations
// --------------------------------------

if (!function_exists('xmltext')) {
  /**
   * Escape a value for insertion into a FHIR XHTML narrative.
   *
   * Resource.text.div is parsed as XML, so any "<", ">" or "&" that is part of
   * the DATA rather than of the markup makes the whole div invalid and SUSHI
   * rejects the assignment. LOINC 11579-0 carries a literal "<" in its display
   * ("... by Detection limit <= 0.05 mIU/L") and is the case that surfaced this.
   * Apply it to every value entering the narrative; never to the markup itself.
   */
  function xmltext($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
  }
}

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
        /*
         * What goes to the model and what identifies the answer.
         *
         * $labtestai used to be "<display> <value> <unit>", so the model saw
         * the measured result while being asked for the interval that result
         * is judged against, while the cache key did NOT contain the value.
         * The range of the first patient of a given age and sex was therefore
         * reused for everyone else - a reference interval derived from one
         * arbitrary result. The value is gone from the prompt; the unit is
         * stated instead of being left to the model, which used to answer in a
         * different unit than the value in 206 of 1053 numeric ranges of
         * release 5.
         *
         * The key comes from refRangeCacheKey() in lib/cache.php now. It is
         * shared with sections/labobservation.php so that the LAB and the EPS
         * branch hit the same entry, it carries the LOINC code (the old key
         * had only the display text, so two codes sharing a display collided),
         * it bands the age instead of keying on the exact year, and it carries
         * a generation number, REFRANGE_CACHE_VERSION, so that a change to
         * this prompt actually retires the old answers rather than leaving
         * 4066 cached files answering the previous question.
         */
        $labtestai = $labi["code"]["display"];
        $md5 = refRangeCacheKey(
          $labi["code"]["code"], $labi["code"]["display"], $labi["valueunit"],
          $pdat->age, $pdat->gender
        );
        $fai = inCACHE('referencerange', $md5);
        if ($fai !== FALSE) {
          // reference range is in cache
          lognl(5, "......... MD5: $md5\n");
          $rr1 = json_decode($fai, TRUE);  // use json reference range from cache
        } else {
          // not in cache, ask AI
          $AI = getAIReferenceRange($pdat->age, $pdat->gender, $labtestai,
            $labi["valueunit"], refRangeAgeBand($pdat->age));
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
            "text" => NULL,
            "type" => NULL
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
              // Both arms used to print $thetext[0] twice: a range that came
              // back as low "100000" / high "1000000" was published as
              // "100000 - 100000" and a single bound "Negative" as
              // "NegativeNegative". sections/labobservation.php was corrected
              // for this; this branch had been left behind.
              if (count($thetext) == 2) {
                $rr1 = [
                  "text" => $thetext[0] . " - " . $thetext[1]
                ];
              } else if (count($thetext) == 1) {
                $rr1 = [
                  "text" => $thetext[0]
                ];
              } else {
                $rr1 = [];  // no reference range at all
              }
            }
          }

          /*
           * UNIT GUARD.
           *
           * A numeric reference range is only a reference range for THIS value
           * if it is expressed in the same unit. Release 5 carries 206 ranges
           * in a unit other than the value's: leukocytes in urine sediment
           * measured in /[HPF] against a range in {#/area}, {#/HPF} or
           * {[#/area]} (the last one is not valid UCUM), and [iU]/L against
           * iU/L. 72 of the 449 bold H/L flags in the narratives sit on such a
           * range, so the document compares two numbers in different units and
           * prints the verdict in bold - "23.5 H" against "0 - 5" where the
           * two figures are not commensurable.
           *
           * Converting here would mean carrying a UCUM conversion table and
           * guessing what the model meant. The honest treatment is to keep the
           * information and drop the arithmetic: the bounds move into .text
           * with the unit spelled out, low and high are cleared, and
           * downstream nothing can compare or flag them. A range in the right
           * unit is untouched.
           */
          if (isset($rr1["low"]) and isset($rr1["high"])
              and $rr1["low"] !== NULL and $rr1["high"] !== NULL) {
            $wantunit = (string) $labi["valueunit"];
            $gotunit  = isset($rr1["unit"]) ? (string) $rr1["unit"] : $wantunit;
            if ($gotunit !== $wantunit) {
              lognlsev(3, WARNING, "......... ~~~ Reference range of '"
                . $labi["code"]["display"] . "' came back in '$gotunit' while "
                . "the value is in '$wantunit', range kept as text, not compared\n");
              $rr1 = [
                "low" => NULL,
                "high" => NULL,
                "unit" => $wantunit,
                "display" => isset($rr1["display"]) ? $rr1["display"] : NULL,
                "text" => $rr1["low"] . " - " . $rr1["high"] . " " . $gotunit,
                "type" => isset($rr1["type"]) ? $rr1["type"] : NULL
              ];
            }
          }

          /*
           * referenceRange.type. Release 5 sets it on none of its 1643
           * reference ranges, so every range reads as a healthy-population
           * interval even where it is not one. Only the six codes of the FHIR
           * referencerange-meaning code system are allowed through; anything
           * else the model invents is dropped rather than published.
           */
          if (isset($rr1["type"])) {
            $RRTYPES = ["normal", "recommended", "treatment", "therapeutic", "pre", "post"];
            $t = strtolower(trim((string) $rr1["type"]));
            if (in_array($t, $RRTYPES)) {
              $rr1["type"] = $t;
            } else {
              if ($t !== "")
                lognlsev(3, WARNING, "......... ~~~ Reference range type '$t' is not in "
                  . "referencerange-meaning, dropped\n");
              $rr1["type"] = NULL;
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
          "text" => NULL,
          "type" => NULL
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

      // presets for local HTML -- raw values, escaped once further down
      $rvl = "";
      $rvu = "";
      $refr = "";
      $flag = "";                      // "" | " L" | " H", set when out of range
      $rdp = $code["display"];
      if ($value["type"] === 'Quantity') {
        $rvl = $value["value"];
        $rvu = $value["unit"];
        if (isset($rr1["low"]) and isset($rr1["high"])
            and $rr1["low"] !== NULL and $rr1["high"] !== NULL) {
          $refr = $rr1["low"] . " - " . $rr1["high"];
          /*
           * Mark the value when it falls outside the reference range - but
           * only when the range is a population interval. "Outside the
           * reference range" and "abnormal" are the same statement only for a
           * normal or recommended range. On a treatment or therapeutic range
           * the reverse holds: an INR of 2.5 under a vitamin K antagonist is
           * on target and lies outside 0.9 to 1.2, and a bold H there asserts
           * the opposite of the truth. pre and post ranges are collection-time
           * qualifiers and say nothing about this value either.
           *
           * The unit guard above has already cleared low and high for any
           * range whose unit differs from the value's, so the comparison below
           * can no longer run across units.
           */
          $rrtype = isset($rr1["type"]) ? $rr1["type"] : NULL;
          $comparable = ($rrtype === NULL or $rrtype === "normal" or $rrtype === "recommended");
          if ($comparable) {
            if ($rvl < $rr1["low"])       $flag = " L";
            else if ($rvl > $rr1["high"]) $flag = " H";
          }
        }
      }
      if ($value["type"] === 'CodeableConcept') {
        $rvl = $value["display"];
      }

      // Build the row. Everything that is DATA is escaped here, at the single
      // point where it enters XHTML; <strong> is the only markup this row adds
      // and is therefore written outside the escaped text. Before 2026-09-11
      // the values were interpolated raw, and the "<" in the display of LOINC
      // 11579-0 made the narrative invalid XML.
      $cell = $flag === ""
        ? xmltext($rvl)
        : "<strong>" . xmltext($rvl . $flag) . "</strong>";
      $HTMLlab1 .= "<tr><td>" . xmltext($rdp) . "</td><td>" . $cell
                 . "</td><td>" . xmltext($refr) . "</td><td>" . xmltext($rvu) . "</td></tr>";
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