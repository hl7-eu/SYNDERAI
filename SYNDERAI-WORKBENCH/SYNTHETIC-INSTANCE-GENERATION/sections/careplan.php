<?php

// prepare care plan including immunization recommendation

$FSHcareplan = "";
$HTMLcareplan = "";
$HEADcareplan = "";
$FSHvaccrecomm = "";
$HTMLvaccrecomm = "";
$HEADvaccrecomm = "";

$pdat->careplanentries = array();
$pdat->goalsentries = array();

if ($pdat->careplans !== NULL) {
  // add heading
  foreach ($pdat->careplans as $sdata) {

    // prepare optional goal definitions first as they are part of the careplan
    $goals = array();
    if (USE_AI) {
      if (strlen($sdata["activity"]["code"]) > 0 and strlen($sdata["activity"]["display"]) > 0 and strlen ($sdata["reason"]["display"]) > 0) {
        $conditions4ai = "";
        if ($pdat->conditions !== NULL) {
          foreach ($pdat->conditions as $c) {
            if (substr($c["active"], 0, 1) == '1' /* active condition */) $conditions4ai .= " " . $c["code"]["display"] . ",";
          }
        }
        $aig = getAIGoals ($pdat->age, $pdat->gender, $sdata["activity"]["display"], $sdata["reason"]["display"], $conditions4ai);
        /* this gives in $aig["text"] something like
         *
         *  Instance: GoalBPControl  
            InstanceOf: Goal  
            Title: "Achieve target blood pressure for hypertension management"  
            * description.text = "Maintain blood pressure below 130/80 mmHg through lifestyle modifications"  
            * target[0].measure = http://loinc.org#85354-9 "Blood pressure panel--recommended patient position: sitting, left arm"  
            * target[0].detailQuantity.value = 130  
            * target[0].detailQuantity.unit = "mm[Hg]"  
            * target[0].detailQuantity.system = "http://unitsofmeasure.org"
            * target[0].detailQuantity.code = #mm[Hg]  
            * target[1].measure = http://loinc.org#8462-4 "Diastolic blood pressure--sitting, left arm"  
            * target[1].detailQuantity.value = 80  
            * target[1].detailQuantity.unit = "mm[Hg]"  
            * target[1].detailQuantity.system = "http://unitsofmeasure.org"
            * target[1].detailQuantity.code = #mm[Hg]"
        */
        
        /* 
          now we have one to many instances of goal

            Instance: GoalBPControl  
            InstanceOf: Goal  
            Title: "Achieve target blood pressure for hypertension management"  
            * description.text
            ...
            Instance: GoalBPControl  
            InstanceOf: Goal  
            Title: "Achieve target blood pressure for hypertension management"  
            * description.text

            Now create a set of fsh parts for the instances,
            re-writing Instance and InstanceOf
        */
        
        $goalstitle = array();  // a list of all titles per goal
        $goaldesc = array();    // a list of all "* description.text" per goal
        $goalfsh = array();     // a list of all fsh per goal
        if ($aig["code"] === 200) {
          // only if we got it 
          $counter = -1;
          // desintegrate the AI generated set of goals
          foreach (explode("\n", $aig["text"]) as $line) {
            // echo "  A $line\n";
            if (startsWith($line, "Instance: ")) {
              $counter++;
              $goalfsh[$counter] = "";
              // skip that very line
            } else if (startsWith($line, "InstanceOf: ")) {
             // skip that very line
            } else if (startsWith($line, "Description: ")) {
              // skip that very line
            } else if (startsWith($line, "Title: ")) {
              $goalstitle[$counter] = trim(str_replace('"', "", after("Title: ", $line)));
            } else if (startsWith($line, "* description.text = ")) {
              $goalsdesc[$counter] = trim(str_replace('"', "", after("* description.text = ", $line)));
              // this line is added also by twig again as * desc... so don't copy it here
            } else if (str_contains($line, ".measure = http://loinc.org#")) {
              // skip that very line as it comes faulty from AI
            } else {
              // take over all other types of lines
              $goalfsh[$counter] .= $line . "\n";
            }
          }
          // echo "-----------------------\n";var_dump($goalfsh);echo "-----------------------\n";

          foreach ($goalfsh as $cnt => $gf) {
            //echo "ORIG-------------------\n";echo $gf;echo "ORIG-------------------\n";
            /*
              Add missing text to Goal resource
            */
            $gf .= "\n" . 
               "* text.status = #generated\n" .
               "* text.div = \"\"\"" .
               "<div xmlns=\"http://www.w3.org/1999/xhtml\">" .
               $goalsdesc[$cnt] .
               "</div>" .
              "\"\"\"";
            /*
              CAVEAT #1
              ... Goal.target.measure is required if Goal.target.detail is populated
             */
            if (str_contains($gf, ".detail") and !str_contains($gf, ".measure = http://loinc.org#")) {
              // measure missing while we have detail targets, let's AI try to fix it
              $tmp = fixMissingTargetMeasurewithAI($goalsdesc[$cnt], $gf);
              // echo "  ?? detail but no measure\n";
              if (200 === $tmp["code"]) {
                $gf = $tmp["text"];
              } else {
                lognlsev(2, WARNING, "......... in Careplan Goals, a proper target.measure could not be generated, skipping.");
                // invalidate the FSH part for now, as we cannot fix the AI shortcomings yet
                $gf = "";
              }
            }
            /*
              CAVEAT #2
              don't trust the display names for the LOINC code
              in * target[x].measure of * target.measure
              so re-write the LOINC with correct display name
             */
            if (strlen(trim($gf)) > 0 and str_contains($gf, ".measure = http://loinc.org#")) {
              $newlines = "";
              foreach (explode("\n", $gf) as $line) {
                // echo "  G $line\n";
                if ((startsWith(trim($line), "* target") and str_contains($line, ".measure = http://loinc.org#"))) {
                  // echo "  LC $line\n";
                  $tmp1 = after("http://loinc.org#", $line); // :: 8462-4 "Diastolic blood pressure--sitting, left arm"
                  $loinc = before(" ", $tmp1);               // :: 8462-4
                  $olddisplay = after(" ", $tmp1);           // :: "Diastolic blood pressure--sitting, left arm"
                  $tmp2 = before("#", $line);                // :: * target[0].measure = http://loinc.org
                  // echo "  L $loinc\n";
                  $tmp3 = get_LOINC_properties($loinc);
                  $newdisplay = $tmp3["display"];
                  // echo "  D $olddisplay -> $newdisplay\n";
                  if (strlen($newdisplay) === 0) {
                    $newdisplay = "?undef?";
                    lognlsev(2, WARNING, "......... in Careplan Goals, a LOINC code needed to be replaced but a new correct one could not be found, skipping.");
                    // invalidate the FSH part for now, as we cannot fix the AI shortcomings yet
                    $newlines = "";
                  } else {
                    $newlines .= $tmp2 . "#" . $loinc . " \"$newdisplay\"\n";
                  }
                  // echo "**X " . $tmp2 . "#" . $loinc . " \"$newdisplay\"\n";
                } else $newlines .= "$line\n";  // otherwise just add the line as is
              }
              // if all is done, re-construct the fsh
              $gf = $newlines;

            }
            // if even now details are present without a measure make the fsh invalid, so we emit minila text only.
            if (str_contains($gf, ".detail") and !str_contains($gf, ".measure = http://loinc.org#")) {
              $gf = "";   // invalidating the FSH remainder will prevent validation errors
              lognlsev(2, WARNING, "......... in Careplan Goals, even an attempt to add a target measure failed, skipping.");
            }
            // echo "\nFINALLY-vvvvv--------------------\n";echo $goalsdesc[$cnt] . "\n";echo $gf;echo "\nFINALLY-^^^^^------------------\n";
            $goalinstanceid = uuid();
            $goals[$cnt] = [
              "instanceid" =>$goalinstanceid,
              "fsh" => trim($gf),
              "title" => $goalstitle[$cnt],
              "description" => $goalsdesc[$cnt]
            ];
            $pdat->goalsentries[] = [
              'id' => $goalinstanceid,
              'instance' => "Instance-Goal-" . $goalinstanceid,
              'nosectionentry' => TRUE,
              "bundleentryslicename" => "" // none defined
            ];
          }
        }
      }
    }
    // var_dump($goals);

    $careplaninstanceid = uuid();
    list($tmpfsh, $tmphtml, $HEADcareplan, $cpinstance) = 
      twigit([
        "instanceid" => $careplaninstanceid,
        "patient" => $pdat,
        "careplan" => $sdata,
        "goals" => $goals
      ], "careplan");
    // echo("---------\n" . $tmpfsh);
    $pdat->careplanentries[] = [
      'id' => $careplaninstanceid,
      'instance' => $cpinstance,
      "bundleentryslicename" => "careplan",
      "sectionentryslicename" => "carePlan"
    ];
    $FSHcareplan .= $tmpfsh;
    $HTMLcareplan .= $tmphtml;

  }
}
// by default: add an immunization recommendation for the next influenza vaccination
// if patient is above 60 yr with optional reference to his last influenza vaccination
if ($pdat->age >= 60) {
  // first find out when there was the last influenza vaccinations, list is sorted by date
  $lastinfluenzavaccinationRef = "";
  $lastinfluenzavaccinationOn = "";
  foreach ($pdat->immunizations as $sdata) {
    if (isset($sdata["activity"]))
      if (strlen($lastinfluenzavaccinationRef) == 0 and $sdata["activity"]["code"] == '1181000221105' /* influenza vaccine */) {
        $lastinfluenzavaccinationRef = $immunizationinstanceid;
        $lastinfluenzavaccinationOn = $sdata["date"];
      }
  }
  // recommend a seasonal influenza vaccination at the beginning of next october
  $duedate = date('Y') . "-10-01"; // default: assume last influenza vaccination is older than this year recommend one for this october
  // if influenza vaccination was already this year recommend one for next year, otherwise for this october
  if (substr($lastinfluenzavaccinationOn, 0, 4) == date('Y')) $duedate = (date('Y') + 1) . "-10-01";
  $irdata = [
    "recommendationCreated" => date('Y-m-d'),
    "vaccineCode" => "1181000221105",
    "vaccineCodeDisplay" => "Influenza virus antigen only vaccine product",
    "targetDisease" => "6142004",
    "targetDiseaseDisplay" => "Influenza (disorder)",
    "dateDue" => $duedate,
    // reason:  830152006 Recommendation regarding vaccination
    "seriesText" => "Annual seasonal influenza vaccination",
    "supportingImmunization" => $lastinfluenzavaccinationRef === "" ? NULL : $lastinfluenzavaccinationRef
  ];
  $imrecomminstanceid = uuid();
  list($FSHvaccrecomm, $HTMLvaccrecomm, $HEADvaccrecomm, $irinstance) =
    twigit(["instanceid" => $imrecomminstanceid, "patient" => $pdat, "immunizationrecommendation" => $irdata], "immunizationrecommendation");
  $pdat->careplanentries[] = [
      'id' => $imrecomminstanceid ,
      'instance' => $irinstance,
      "bundleentryslicename" => "",
      "sectionentryslicename" => "immunizationRecommendation"
    ];
}

// non-mandatory section
if ($pdat->careplans !== NULL) {
  $sections['sectionPlanOfCare'] = [
    'title' => 'Care Plan',
    'code' => '$loinc#18776-5',
    'display' => "Plan of care note",
    'text' => "<table class='hl7__ips'>$HEADcareplan $HTMLcareplan $HEADvaccrecomm $HTMLvaccrecomm</table>",
    // has for sure careplanentries and maybe goalsentries
    'entries' => array_merge($pdat->careplanentries, $pdat->goalsentries),
    'fsh' => $FSHcareplan . $FSHvaccrecomm
  ];
}

?>