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
      $code = array();
      $code = [
        "code" => $labi["codecode"],
        "display" => $labi["codedisplay"],
        // must tweak system snomed short alias "snomed" to "sct"
        "system" => $labi["codesystem"] === "snomed" ? "sct" : $labi["codesystem"]
      ];
      $lnsystem = $labi["lnsystem"];
      $value = array();
      $value = [
        'type' => $labi["valuetype"],
        'value' => $labi["value"],
        'code' => $labi["valuecode"],
        'unit' => $labi["valueunit"],
        // must tweak system snomed short alias "snomed" to "sct"
        'system' => $labi["valuesystem"] === "snomed" ? "sct" :$labi["valuesystem"],
        'display' => $labi["valuedisplay"]
      ];
      if (USE_AI) {
        $labtestai = $labi["codedisplay"] . " " . $labi["value"] . " " . $labi["valueunit"];
        $labtestmd5 = $pdat->age . $pdat->gender . $labi["codedisplay"] . $labi["valueunit"];
        // reference range in cache?
        $md5 = md5($pdat->age . $pdat->gender . $labtestmd5);
        $fai = inCACHE('referencerange', $md5);
        if ($fai !== FALSE) {
          // reference range is in cache
          lognl(5, "......... MD5: $md5\n");
          $rr1 = json_decode($fai, TRUE);  // use json reference range from cache
        } else {
          // not in cache, ask AI
          $AI = getAIReferenceRange ($pdat->age, $pdat->gender, $labtestai);
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
          $isok = TRUE;  // assume all ik ok
          if (isset($rr1["low"])) {
            if (!is_numeric($rr1["low"])) $isok = FALSE; 
          }
          if (isset($rr1["high"])) {
            if (!is_numeric($rr1["high"])) $isok = FALSE; 
          }
          if (!$isok) {
            // the low or hugh field contains "non-numeric" values, reset $rr1 reference range to be used as text
            if (isset($rr1["display"])) {
              $rr1 = [
               "text" => $rr1["display"]
              ];
            } else $rr1 = [];  // no reference range at all
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
    
      /*
      $rrlow = $rr1['low'];
      $rrhigh = $rr1['high'];
      $rrunit = $rr1['unit'];
      echo $labi["codedisplay"] . ": " . $rrlow . " - " . $rrhigh . " " . $rrunit . "\n";
      */
      $data = [
        "code" => $code,
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
      // echo "*** " . $labi["codedisplay"] . " " . $labi["value"] . " " . $labi["valuesystem"] . " " . $data["lnclass"] . "\n";
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
      $logtext = substr($data->effective, 0, 10) . ": " . $data->code->display . " (" . $data->code->code . ") ";
      if ($labi["valuetype"] === 'Quantity')
        if ( ! ( ((float) $labi["value"]) || (float) $labi["value"] > 0 || $labi["value"] === '0.0' ) ) echo "+++ Cannot cast as float/decimal: " . $labi["value"] . "\n";
      
      // build a table row with the results for AI conclusion
      $overalllabtesttable .=
        "| " . substr($ldate, 0, 10) .
        " | " . $labi["codedisplay"] .
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
      // store all lab data of this date, the generated fsh and IDs + the AI table for this set of results
      $pdat->labresults[$ldate][] = [
        "instanceid" => $lorecomminstanceid,
        "data" => $data,
        "fsh" => $FSHlab
      ];
    }

    $pdat->labresultsaitable[$ldate] = $overalllabtesttable;

    if (DEBUGLEVEL >= 4) lognl ("......... " . $logtext);

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