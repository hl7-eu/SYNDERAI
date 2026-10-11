<?php

// lab observations including specimen for a proper LAB report for the patient

// emit set of patient's specimen
// ------------------------------
$pdat->specimenids = array();
$pdat->specimenfsh = array();
// var_dump($pdat->specimen);
// array_unique(array_column($pdat->specimen, 'code'))
foreach ($pdat->specimen as $ldate => $spp) {
  if ($ldate === $LABFILTERDATE) {
    // we are only working for the specified date
    // emit specimen unique by (SNOMED) code
    $tmpspecimen = array();
    $tmploincsystems = array();
    foreach ($spp as $lnsystem => $sp) {
      $FSHspm = "";
      if (!isset($tmpspecimen[$sp['code']])) {
        $specimeninstanceid = uuid();
        list($FSHspm) =
          twigit([
            "instanceid" => $specimeninstanceid,
            "specimen" => $sp
          ], "specimen-eu-lab");
        // register the instance id under this SNOMED specimen code
        $tmpspecimen[$sp['code']] = $specimeninstanceid;
      }
      // register FSH
      if (isset($pdat->specimenfsh[$ldate])) {
        $pdat->specimenfsh[$ldate] = $pdat->specimenfsh[$ldate] . $FSHspm;
      } else {
        $pdat->specimenfsh[$ldate] = $FSHspm;
      }
      // register this code for the loinc system
      $tmploincsystems[$lnsystem] = $sp['code'];
    }
    // var_dump($tmpspecimen);
    // var_dump($tmploincsystems);
    // assign the specimen ids for each loinc system
    foreach ($spp as $lnsystem => $sp) {
      $pdat->specimenids[$ldate][$lnsystem] = $tmpspecimen[$tmploincsystems[$lnsystem]];
    }
  }
}
// var_dump($pdat->specimenids);
// var_dump($pdat->specimenfsh);exit;

// emit patient's lab observations
// -------------------------------
$pdat->labresults = array();
$pdat->labconclusion = array();
// for an AI based conclusion and recommendation start with a nice table header
$aitableheading =  "| Analyte | Measurement | Normal Range   | Low/High Indiator |\n";
$aitableheading .= "| ------- | ----------- | -------------- | ----------------- |\n";
foreach ($pdat->labobservations as $ldate => $lbspd) {
  if ($ldate === $LABFILTERDATE) {
    // we are only working for the specified date
    // for AI conclusion we create a human :-) readable table
    $overalllabtesttable =  "";
    foreach ($lbspd as $labi) {
      // Normalise a code system alias: drop any leading '$' and map the
      // "snomed" short alias to "sct". Full URIs and URNs pass through
      // untouched. The templates add exactly one '$' where an alias is used,
      // so carrying the sigil in the data is what produced "$$sct" in run 1.
      $normsys = function ($s) {
        $s = (string) $s;
        if ($s === "" or strpos($s, ":") !== FALSE) return $s;  // URI / URN
        $s = ltrim($s, '$');
        return $s === "snomed" ? "sct" : $s;
      };

      $code = [
        "code" => $labi["code"]["code"],
        "display" => $labi["code"]["display"],
        "system" => $normsys($labi["code"]["system"])
      ];
      $lnsystem = $labi["lnsystem"];
      $value = [
        'type' => $labi["valuetype"],
        'value' => $labi["value"],
        'code' => $labi["valuecode"],
        'unit' => $labi["valueunit"],
        'human' => $labi["valuehuman"],
        'system' => $normsys($labi["valuesystem"]),
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
          $AI = getAIReferenceRange ($pdat->age, $pdat->gender, $labtestai,
            $labi["valueunit"], refRangeAgeBand($pdat->age));
          /*
           * AI should return either JSON with low, high, unit and display upon Quantities reference ranges
           * or "text" upon Qualitative reference ranges, such as "Pale yellow - Yellow to amber"
           * take that into account when post-processing it
           */
          if (isset($AI)) {
            $rr1 = json_decode($AI['rr'], TRUE);
            // var_dump($AI); exit;
            // echo "*****************************" . $AI['rr'] . "\n";
            toCACHE('referencerange', $md5, $AI['rr']);
          } else {
            $rr1 = NULL;
          }
        }
        // ---- normalise the reference range -----------------------------
        // RULE: the reference range never decides the value type. It used to:
        // $isnumeric started at TRUE, so a range that carried no numeric
        // bounds at all still fell into the else arm and retyped the
        // observation as "Quantity". That is how coded results reached the
        // valueQuantity branch of the template and broke the FSH parser.
        // A range is numeric only when BOTH bounds are present and numeric.
        if ($rr1 === NULL) {  // still no ref range, add the "placeholder reference range"
          $rr1 = [
            "low" => NULL,
            "high" => NULL,
            "unit" => $labi["valueunit"],
            "display" => NULL,
            "text" => NULL,
            "type" => NULL
          ];
        }
        $rawlow  = isset($rr1["low"])  ? $rr1["low"]  : NULL;
        $rawhigh = isset($rr1["high"]) ? $rr1["high"] : NULL;
        // record non-numeric bounds like "Negative" / "Trace" coming from the AI
        $thetext = array();
        foreach (array($rawlow, $rawhigh) as $bound)
          if (isset($bound) and !is_numeric($bound)) $thetext[] = (string) $bound;

        $isnumeric = (isset($rawlow) and is_numeric($rawlow)
                  and isset($rawhigh) and is_numeric($rawhigh));

        if ($isnumeric) {
          $rr1["low"]  = (0 + $rawlow);   // make it really an int/float
          $rr1["high"] = (0 + $rawhigh);
          if (!isset($rr1["unit"]) or $rr1["unit"] === "")
            $rr1["unit"] = $labi["valueunit"];
          $rr1["text"] = NULL;
        } else {
          // Qualitative or incomplete range -> carry it as text.
          $astext = NULL;
          if (isset($rr1["text"]) and $rr1["text"] !== "") {
            $astext = $rr1["text"];
          } else if (isset($rr1["display"]) and $rr1["display"] !== "") {
            $astext = $rr1["display"];
          } else if (count($thetext) === 2) {
            $astext = $thetext[0] . " - " . $thetext[1];  // was: [0] twice
          } else if (count($thetext) === 1) {
            $astext = $thetext[0];                        // was: [0] . [0]
          }
          $rr1 = [
            "low" => NULL,
            "high" => NULL,
            "unit" => $labi["valueunit"],
            "display" => isset($rr1["display"]) ? $rr1["display"] : NULL,
            "text" => $astext
          ];
        }
        $rr1["isNumeric"] = $isnumeric;

        /*
         * UNIT GUARD, same rule as in sections/recentlabobservationEPS.php.
         * A numeric range is only a range for this value when it carries the
         * same unit. The model chose the unit itself until 2026-10-11 and
         * chose a different one from the value in 206 of the 1053 numeric
         * ranges of release 5. The bounds move into .text with the unit named,
         * low and high are cleared, and nothing downstream can compare them.
         */
        if (isset($rr1["low"]) and isset($rr1["high"])
            and $rr1["low"] !== NULL and $rr1["high"] !== NULL) {
          $wantunit = (string) $labi["valueunit"];
          $gotunit  = isset($rr1["unit"]) ? (string) $rr1["unit"] : $wantunit;
          if ($gotunit !== $wantunit) {
            lognlsev(3, WARNING, "......... ~~~ Reference range of '"
              . $labi["code"]["display"] . "' came back in '$gotunit' while the "
              . "value is in '$wantunit', range kept as text, not compared\n");
            $rr1 = [
              "low" => NULL,
              "high" => NULL,
              "unit" => $wantunit,
              "display" => isset($rr1["display"]) ? $rr1["display"] : NULL,
              "text" => $rr1["low"] . " - " . $rr1["high"] . " " . $gotunit,
              "type" => isset($rr1["type"]) ? $rr1["type"] : NULL,
              "isNumeric" => FALSE
            ];
          }
        }

        /*
         * referenceRange.type, restricted to the six codes of the FHIR
         * referencerange-meaning code system. Anything else the model invents
         * is dropped rather than published.
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

        // A numeric range next to a non-quantitative value is a data smell
        // worth seeing, but it is not a reason to change the value type.
        if ($isnumeric and $value['type'] !== "Quantity")
          lognlsev(3, WARNING, "......... ~~~ Numeric reference range on a "
            . $value['type'] . " result '" . $labi["code"]["display"]
            . "', value type kept\n");
      } else {
        // no AI but add the "placeholder reference range"
        $rr1 = [
          "low" => NULL,
          "high" => NULL,
          "unit" => $labi["valueunit"],
          "display" => NULL,
          "text" => NULL,
          "type" => NULL,
          "isNumeric" => NULL
        ];
      }
    
      /*
      $rrlow = $rr1['low'];
      $rrhigh = $rr1['high'];
      $rrunit = $rr1['unit'];
      echo $labi["code"]["display"] . ": " . $rrlow . " - " . $rrhigh . " " . $rrunit . "\n";
      */
      $data = [
        "code" => [$code],  // code must be an array
        "subject" => $pdat->instanceid,
        "subjectname" => $pdat->name,
        "effective" => $ldate,
        "report" => strtoupper(date('d-M-Y', strtotime($ldate))), //  strtotime('+1 day', strtotime($ldate)))),
        "value" => $value,
        "reference" => $rr1,
        "specimenid" => $pdat->specimenids[$ldate][$lnsystem],
        "lnclass" => $labi["lnclass"],
        "lnsystem" => $labi["lnsystem"]
      ];
      // if ($value["code"] === "275778006") {echo "$md5\n";var_dump($rr1);exit;}
      // echo "*** " . $labi["code"]["display"] . " " . $labi["value"] . " " . $labi["valuesystem"] . " " . $data["lnclass"] . "\n";
      $data = json_decode(json_encode($data));
      // var_dump($data);
      // var_dump($data->code);
      
      $lorecomminstanceid = uuid();
      // if $thisartifact === "LAB" then use profile observation-resultslab-eu-lab, otherwise observation-medical-test-result-eu
      // also submit isVitaLSignCode to handle category.coding properly, see also https://github.com/hl7-eu/SYNDERAI/issues/100 #}
      list($FSHlab, $HTMLlab, $HEADlab) =
        twigit([
          "instanceid" => $lorecomminstanceid,
          "labresult" => $data,
          "isVitaLSignCode" => isVitaLSignCode($code)
        ], ($thisartifact === "LAB" ? "observation-resultslab-eu-lab" : "observation-medical-test-result-eu")
        );

      // build string for log
      $logtext = substr($data->effective, 0, 10) . ": " . $data->code[0]->display . " (" . $data->code[0]->code . ") ";
      if ($labi["valuetype"] === 'Quantity' and !is_numeric($labi["value"]))
        lognlsev(1, ERROR, "......... +++ Quantity result is not numeric: '"
          . $labi["value"] . "' for " . $labi["code"]["display"] . "\n");
      
      // build a table row with the results for AI conclusion
      $overalllabtesttable .=
        "| " . substr($ldate, 0, 10) .
        " | " . $labi["code"]["display"] .
        " | " . $labi["value"] . " " . $labi["valueunit"];
      $logtext .= $labi["value"] . " " . $labi["valueunit"] . " ";
      if ($labi["valuetype"] === 'Quantity')  {
        if(isset($rr1["low"]) and isset($rr1["high"])) {
          $overalllabtesttable .=  " | " . $rr1["low"] . " - " . $rr1["high"] . " " . $labi["valueunit"];
          $overalllabtesttable .=  " | ";
          $logtext .= "[" . $rr1["low"] . " - " . $rr1["high"] . " " . $labi["valueunit"] . "]";
          if ($labi["value"] < $rr1["low"]) {
            $overalllabtesttable .= "L";
            $logtext .= "L";
          } else if ($labi["value"] > $rr1["high"]) {
            $overalllabtesttable .= "H";
            $logtext .= "H";
          }
        } else if (isset($rr1["text"])) {
          $overalllabtesttable .= " | " . $rr1["text"] . " | ";
        }
      } else $overalllabtesttable .= " | | ";
      $overalllabtesttable .= " |\n";
      // one log line per result -- this used to sit outside the loop, so only
      // the last result of the day ever reached the log
      if (DEBUGLEVEL >= 4) lognl (4, "......... " . $logtext);
      // store all lab data of this date, the generated fsh and IDs + the AI table for this set of results
      $pdat->labresults[$ldate][] = [
        "instanceid" => $lorecomminstanceid,
        "data" => $data,
        "fsh" => $FSHlab
      ];
    }

    $pdat->labresultsaitable[$ldate] = $overalllabtesttable;

  }
}

// echo $overalllabtesttable;

// finally get conclusions / summaries from the AI lab doctor's perspective
// go through all results per date and use only lab results from that date or before
if (USE_AI) {
  foreach ($pdat->labresults as $ldate1 => $data1) {
    if ($ldate1 !== $LABFILTERDATE) continue; // we are only working for the specified date
    // build the big table first, only results of current date of before
    $bigaitable = "";
    foreach ($pdat->labresults as $ldate2 => $data2) {
      if ($ldate2 <= $ldate1) $bigaitable .= $pdat->labresultsaitable[$ldate2];
    }
    // now $bigaitable has all table rows with result as of the current date of before, use it for conclusions
    // echo "\n\n\n\n"  . $aitableheading . $bigaitable;
    $AI = getAILabConclusion ($pdat->age, $pdat->gender, $aitableheading . $bigaitable);
    // var_dump($AI);
    $pdat->labconclusion[$ldate1] = isset($AI["text"]) ? htmlspecialchars($AI["text"], ENT_QUOTES, 'UTF-8') : NULL;
    // var_dump($pdat->labconclusion);
  }
  
}

?>