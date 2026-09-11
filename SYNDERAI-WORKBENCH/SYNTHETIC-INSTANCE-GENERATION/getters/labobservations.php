<?php

// ***
// *** get all random lab observations for this candidate, check if valid LOINC
// ***
$found = array();
$labobs = array();
$labcategories = array();
lognl(1, "...... List of lab observations for this patient\n");
// open observations
$pdat->labobservations = NULL;
$observationhandle = fopen(
  is_file(SYNTHEADIR . "/observations/$candid") ? SYNTHEADIR . "/observations/$candid" : SYNTHEADIR . "/observations.csv", "r");
rewind($observationhandle);
while (($item = fgetcsv($observationhandle, 10000, ",", '"', '\\')) !== FALSE) {
  if (strpos($item[1], $candid) !== FALSE) {
    // 0                    1                                    2                                    3          4      5                                        6    7     8
    // 2015-08-25T23:20:16Z,226e4d9b-24bd-15ff-b7d9-a867a55423ae,4eaf3de2-b539-52bf-11cb-370e46c2aadc,laboratory,2345-7,Glucose [Mass/volume] in Serum or Plasma,84.4,mg/dL,numeric
    $lnc = $item[4];
    // Guess LOINC, may be SNOMED however
    $lk = get_LOINC_properties($lnc);
    // f ($lnc === "5767-9") { echo "ÜÜÜ";var_dump($item);}
    // echo "$lnc\n";
    if (in_array($lnc, $labcodes)) {
      $ldate = $item[0];
      isset($found[$ldate]) ? ($found[$ldate] += 1) : ($found[$ldate] = 1);
      // CAVE: SNOMED (qualifier value) / (finding) vs UCUM measurement vs text
      $resultunit = $item[7];  // UCUM such as mL/min/{1.73_m2} or mmol/L or it is '{nominal}' or empty
      $resulttype = $item[8];  // numeric or text
      $resultvalue = trim($item[6]);
      $humanunit = "";
      // Contract for the fields below, identical in every branch. The emitting
      // template prefixes '$' to 'system' and '#' to 'unit', so neither sigil
      // belongs in the values here.
      //   type    Quantity | CodeableConcept | String  -- decides value[x]
      //   value   the raw result as Synthea delivered it
      //   code    Quantity: UCUM code   CodeableConcept: SNOMED code   String: ""
      //   unit    Quantity: UCUM code   otherwise ""
      //   human   Quantity: human-readable unit, otherwise ""
      //   system  Quantity: "ucum"      CodeableConcept: "sct"         String: ""
      //   display CodeableConcept: concept display, otherwise ""
      if ($resulttype !== 'text') {
        // ---- numeric result -> FHIR Quantity ----------------------------
        $thistype = "Quantity";
        // UCUM code, e.g. mIU/L. NOT $item[4] -- that column is the LOINC
        // code of the observation itself, which is not a unit.
        $thisunit = $resultunit;
        $thiscode = $resultunit;
        // human-readable synonym for Quantity.unit, falls back to the code
        $humanunit = valueset_canonical_code('vs-ucum-concepts', $thisunit);
        if (!$humanunit)
          $humanunit = $thisunit;
        $thissystem = "ucum";
        $thisdisplay = "";
      } else {
        // ---- non-numeric result -> try to code it, else keep the text ----
        // Look the answer up unconditionally. The "(finding)" / "(qualifier
        // value)" suffix is only a hint: culture results such as "Greater than
        // 100 000 colony forming units per mL Escherichia coli" carry no
        // suffix but are codeable all the same.
        $thiscode = valueset_canonical_code("vs-synthea-observation-value-snomed", $resultvalue);
        if ($thiscode !== NULL) {
          $thistype = "CodeableConcept";
          $thissystem = "sct";
          $thisunit = "";
          $thisdisplay = valueset_display("vs-synthea-observation-value-snomed", $thiscode);
        } else {
          // No concept found. Emit the text rather than an empty coding, and
          // record the miss so the value set can be extended from the log.
          if ((strpos($resultvalue, "(qualifier value)") !== FALSE)
              or (strpos($resultvalue, "(finding)") !== FALSE)) {
            lognlsev(1, ERROR, "......... +++ Not able to get answer code '$resultvalue'\n");
            registerMapMissing("+++ Unable to get LAB result answer code for '$resultvalue'");
          } else {
            lognlsev(3, WARNING, "......... ~~~ Uncoded text result '$resultvalue', emitted as valueString\n");
            registerMapMissing("~~~ Uncoded LAB text result '$resultvalue'");
          }
          $thistype = "String";
          $thiscode = "";
          $thisunit = "";
          $thissystem = "";
          $thisdisplay = "";
        }
      }
      // echo "***** $thistype $resultvalue $thiscode $thisdisplay\n";
      $hislabobs = [
        "code" => [
          "code" => $lnc,
          "display" => $lk['display'],
          "system" => "\$loinc",
        ],
        "lnclass" => $lk['class'],
        "lnsystem" => $lk['system'],
        "valuex" => [
          "type" => $thistype,
          "value" => $resultvalue,
          "code" => $thiscode,
          "unit" => $thisunit,
          "human" => $humanunit,
          "system" => $thissystem,
          "display" => $thisdisplay,
        ],
        "valuetype" => $thistype,
        "value" => $resultvalue,
        "valuecode" => $thiscode,
        "valueunit" => $thisunit,
        "valuehuman" => $humanunit,
        "valuesystem" => $thissystem,
        "valuedisplay" => $thisdisplay,
        "date" => $ldate
      ];
      // if ($lnc === "5767-9") { echo "ÜÜÜ";var_dump($hislabobs);exit;}
      // echo "$ldate  $lnc  " . $lk['display'] ."\n";
      // not here isset($labcategories[$lk['class']]) ? $labcategories[$lk['class']] += 1 : $labcategories[$lk['class']] = 1;
      $labobs[$ldate][] = $hislabobs;
    } 
  }
}

fclose($observationhandle);

// echo "Matching lab counts: " .  count($found) . "\n";exit;
$labobservationcount = 0;
if (count($found) === 0) {
   lognlsev(3, WARNING, "......... +++ No lab observations found\n");
   $pdat->labobservations = NULL;
} else {
  
  $pdat->labobservations = array();
  
  // get lab observations grouped by result day according to parameter:
  // MAXLABS a set of lab results of a day with the maximum of lab results (some time in the past)
  // RECENTLAB a set of lab results of the the most recent date
  // RECENT2LABS a set of lab results of the two most recent dates
  // RECENT3LABS a set of lab results of the three most recent dates
  // ALLLABS a set of all lab results grouped by day
  // SOMELABS a set of lab results of a few random days
  //
  // set(s) is/are stored in $pdat->labobservations[KEY] with KEY as the date
  
  // keys = dates of all sets found
  $allfound = array_keys($found);

  if ($LABRESULTTYPE === "MAXLABS") {
    // MAXLABS
    $maxfoundix = array_keys($found, max($found));
    $maxfoundix = max(array_keys($found));
    $pdat->labobservations[$maxfoundix] = $labobs[$maxfoundix];
  } else if ($LABRESULTTYPE === "RECENTLAB" or $LABRESULTTYPE === "RECENT2LAB" or $LABRESULTTYPE === "RECENT3LAB") {
    // RECENTLAB
    $latestix = count($allfound) - 1; // get lab results with latest date by using the last element in this key array
    if (isset($allfound[$latestix]))
      $pdat->labobservations[$allfound[$latestix]] = $labobs[$allfound[$latestix]];
    if ( $LABRESULTTYPE === "RECENT2LAB" or $LABRESULTTYPE === "RECENT3LAB") {
      // RECENT2LAB
      $lastbutoneix = count($allfound) - 2;
      if (isset($allfound[$lastbutoneix]))
        $pdat->labobservations[$allfound[$lastbutoneix]] = $labobs[$allfound[$lastbutoneix]];
      if ($LABRESULTTYPE === "RECENT3LAB") {
        // RECENT3LAB
        $lastbuttwoix = count($allfound) - 3;
        if (isset($allfound[$lastbuttwoix]))
          $pdat->labobservations[$allfound[$lastbuttwoix]] = $labobs[$allfound[$lastbuttwoix]];
      }
    }
  } else if ($LABRESULTTYPE === "ALLLABS") {
    $pdat->labobservations = $labobs;
  }
  // var_dump($pdat->labobservations);exit;

  // get lab categories
  $labcategories = array();
  foreach ($pdat->labobservations as $lbspd) // set per day
    foreach ($lbspd as $lbc) {  // results of a day
      $labobservationcount++;
      isset($labcategories[$lbc['lnclass']]) ? $labcategories[$lbc['lnclass']] += 1 : $labcategories[$lbc['lnclass']] = 1;
    }
  // var_dump($labcategories);exit;

  // get clinical observation data for patient
  lognl(3, "...... Candidate patient $candid out of total $matchcount patients chosen");
  lognl(3, "...... $labobservationcount matching observations from pool selected from " . count($pdat->labobservations) . " day(s), $LABRESULTTYPE");

  $pdat->laboratorycategories = $labcategories;
  lognl(3, "...... List of lab categories for this example:");
  foreach ($pdat->laboratorycategories as $lck => $lcv) {
    lognl(3, "........." . $lck);
  }

}

// show matching lab results
if (DEBUGLEVEL >= 2 && $pdat->labobservations !== NULL) {
  foreach ($pdat->labobservations as $ldate => $lbspd) {
      lognl(3, "........... @> " . substr($ldate, 0, 10));
      foreach ($lbspd as $lbc)
        lognl(3, 
          sprintf(".............. -> %-10s %-10s %-10s %20.20s %10s %20s",
          $lbc["code"]["code"],
          $lbc["lnclass"],
          $lbc["lnsystem"],
          $lbc["value"],
          $lbc["valueunit"],
          $lbc["code"]["display"]
          )
        );
  }
}

// get specimen date list
$tmpspecimenlist = array();
if ($pdat->labobservations !== NULL) {
  foreach ($pdat->labobservations as $ldate => $lbspd)
    foreach ($lbspd as $lbc) {
      $tmpspecimenlist[$ldate][$lbc["lnsystem"]] = $lbc["date"];
    }
}
// var_dump($tmpspecimenlist);exit;

// populate patient's specimen list, key is date
$pdat->specimen = array();
foreach ($tmpspecimenlist as $ldate => $tspm)
  foreach ($tspm as $lnsystem => $v) {
    // var_dump($v);
    $specimencollectiondate = substr($v, 0, 10);
    // date('Y-m-d', strtotime("-1 day"));  // SHALL BE $v as date collected the day before the lab result
    // var_dump($specimencollectiondate);exit;
    $specmitem = array();

    // get SNOMED code for Loinc system part code / name
    $specmitem = NULL;
    $speccode = codesystem_code_for_term('cs-specimen-label', $lnsystem);
    if ($speccode !== NULL) {
      $snomed = map_term($lnsystem, 'cs-specimen-label', 'cm-specimen-to-snomed-ct');
      if ($snomed !== NULL) {
        $specmitem = [
          'code' => $snomed['code'],
          'display' => $snomed['display'],
          'date' => $specimencollectiondate
        ];
      }
    } 
    if (!$specmitem) {
      // echo "'$lnsystem'\n";
      $specmitem = [
        'code' => "123038009",
        'display' => "Specimen (specimen)",
        'date' => $specimencollectiondate
      ];
      lognlsev(1, ERROR, "......... +++ Not able to derive specimen from LOINC system '" . $lnsystem . "', using default.");
    }
    $pdat->specimen[$ldate][$lnsystem] = $specmitem;
  }

if (DEBUGLEVEL >= 3) {
  // show all specimen
  foreach ($pdat->specimen as $ldate => $lspecm) {
      lognl(3, "...... @> " . substr($ldate, 0, 10));
      foreach ($lspecm as $lsys => $labi)
        lognl(3, sprintf("........ +> %-10s %-10s %20s",
          $labi["code"],
          $lsys,
          $labi["display"],
        ));
  }
}
// var_dump($pdat->specimen);exit;
$tmp = array();
foreach ($pdat->specimen as $s) foreach ($s as $m) $tmp[$m["code"]] = TRUE;
$overallspecimencount = count($tmp );
if (in_array("LAB", $ARTIFACTS)) // only if LAB is emitted say something about the findings
  lognl(3, "......... Set with $labobservationcount lab tests and overall $overallspecimencount types of specimen found...\n");

?>