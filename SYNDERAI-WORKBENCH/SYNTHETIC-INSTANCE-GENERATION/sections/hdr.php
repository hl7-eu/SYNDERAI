<?php

// prepare all sections and entries of the HDR parsed from ish (if ish is set) or as prepared sections

// for later correct section slice names following HDR spec
//                     -- used in ISH --        -- correct slice in HDR --
$CORRECTSECTIONSLICES["admissionevaluation"] = "sectionAdmissionEvaluation";
$CORRECTSECTIONSLICES["socialhistory"]       = "";
$CORRECTSECTIONSLICES["synthesis"]           = "sectionSynthesis";
$CORRECTSECTIONSLICES["hospitalcourse"]      = "sectionHospitalCourse";
$CORRECTSECTIONSLICES["procedure"]           = "sectionSignificantProcedures";
$CORRECTSECTIONSLICES["results"]             = "sectionSignificantResults";
$CORRECTSECTIONSLICES["vitalsign"]           = "sectionVitalSigns";
$CORRECTSECTIONSLICES["medication"]          = "sectionPharmacotherapy";
$CORRECTSECTIONSLICES["pharmacotherapy"]     = "sectionPharmacotherapy";
$CORRECTSECTIONSLICES["careplan"]            = "sectionPlanOfCare";
$CORRECTSECTIONSLICES["dischargediagnosis"]  = "sectionDiagnosticSummary";
$CORRECTSECTIONSLICES["dischargemedication"] = "sectionDischargeMedications";
$CORRECTSECTIONSLICES["pharmacotherapy"]     = "sectionPharmacotherapy";

foreach($thisStayISH->section as $section) {

  // var_dump($section); exit;
  // var_dump($section);

  $FSHsec = "";
  $HTMLsec = "";
  $HEADsec = "";
  // catch all entries for this section
  $thissectionentries = array();
  // Preset
  $thisectionentryslicename = 
    isset($CORRECTSECTIONSLICES[$section->type]) ? $CORRECTSECTIONSLICES[$section->type] : "UNKNOWN-SECTION-TYPE-NOT-SET";

  // process section entries first if any
  if (isset($section->entry)) {
    $tmpfsh = "";
    $tmphtml = "";
    $tmphead = "";
    $thissectionentries = array();
    foreach ($section->entry as $ent) {
      $ent->bundleentryslicenameentries = "";

      $entrytype = $ent->type;
      $entinstanceid = uuid();
      $entinstance = "";
      // split up by type of entry

      /* ------------------------------------ */
      if ($entrytype === "familyhistory") {
      /* ------------------------------------ */
        list($tmpfsh, $tmphtml, $tmphead, $entinstance) = 
          twigit(["instanceid" => $entinstanceid, "patient" => $pdat, "familyhistory" => $ent], "familyhistory");
        // set entry meta data
        $ent->bundleentryslicenameentries = "";
      } else 
      /* ------------------------------------ */
      if ($entrytype === "vitalsign") {
      /* ------------------------------------ */
        // var_dump($ent);
        // add some data for twig template
        /*
        if (isset($ent->valueQuantity)) {
          $ent->scale = "numeric";
          list($ev, $eu, $es) = splitValueQuantityCompound($ent->valueQuantity);
          // determine unit code
          $theunitcode = $eu;
          if ($unit === "{score}") $theunitcode = "1";
          if ($unit === "{nominal}") $theunitcode = "1";
          $ent->value = $ev;
          $ent->unit = $eu;
          $ent->code = $theunitcode;
        }
        if (isset($ent->component)) {
          foreach($ent->component as $ix => $e) {
            if (isset($e->valueQuantity)) {
              $ent->component[$ix]->scale = "numeric";
              list($ev, $eu, $ec, $es) = splitValueQuantityCompound($e->valueQuantity);   
              $ent->component[$ix]->slice = $e->slice;
              $ent->component[$ix]->value = $ev;
              $ent->component[$ix]->unit = $eu;
              $ent->component[$ix]->code = $eu === "{score}" ? "1" : $eu;
              $ent->component[$ix]->system = $es;
            };
          }
        }
          */
        list($tmpfsh, $tmphtml, $tmphead, $entinstance) = 
          twigit([
            "instanceid" => $entinstanceid,
            "patient" => $pdat,
            "vital" => $ent
          ], "vitalsigns");
        // set entry meta data
        $ent->bundleentryslicenameentries = "";
      } else 
      /* ------------------------------------ */
      if ($entrytype === "socialhistory") {
      /* ------------------------------------ */
        list($tmpfsh, $tmphtml, $tmphead, $entinstance) =
          twigit([
            "instanceid" => $entinstanceid,
            "patient" => $pdat,
            "socialhistory" => $ent
          ], "socialhistory");
        // set entry meta data
        $ent->bundleentryslicenameentries = "";
      } else 
      /* ------------------------------------ */
      if ($entrytype === "results" or $entrytype === "addrecentlabresults") {
      /* ------------------------------------ */
        if ($entrytype === "addrecentlabresults") {
          // add recent lab value for this patient from his labobservations - of any
          $maxfoundix = array();
          if (isset($pdat->labobservations)) $maxfoundix = max(array_keys($pdat->labobservations));
          // TODO: do something with these results
        } else {
          // some additions
          $ent->subject = $pdat->instanceid;
          $ent->subjectname = $pdat->name;
          // var_dump($ent);exit;
          if ($entrytype === "results") {
            list($tmpfsh, $tmphtml, $tmphead, $entinstance) =
              twigit([
                "instanceid" => $entinstanceid,
                "labresult" => $ent,
                "isImagingCode" => TRUE,
              ], "observation-medical-test-result-eu");
          }
          // var_dump($tmpfsh);
          // set entry meta data
          $ent->bundleentryslicenameentries = "";
        }
        
      } else 
      /* ------------------------------------ */
      if ($entrytype === "procedure") {
      /* ------------------------------------ */
        // reset array of array procedure.code from array (ish artifact) to simple code array
        if (is_array($ent->code)) $ent->code = $ent->code[0];
        list($tmpfsh, $tmphtml, $tmphead, $entinstance) =
          twigit(["instanceid" => $entinstanceid, "patient" => $pdat, "procedure" => $ent], "procedure-eu");
        // set entry meta data
        // echo "ÜÜÜÜÜÜÜÜÜÜ\n";var_dump($ent);// exit;
        $ent->bundleentryslicenameentries = "";
      } else 
      /* ------------------------------------ */
      if ($entrytype === "medication") {
      /* ------------------------------------ */
        // var_dump($ent);
        $druginstanceid = uuid();
        list($tmpfsh, $tmphtml, $tmphead, $druginstance, $entinstance) =
          twigit([
            "instanceid" => $entinstanceid,
            "patient" => $pdat,
            "medication" => $ent,
            "druginstanceid" => $druginstanceid
          ], "medicationstatement-eu-core");
          // var_dump($section);var_dump($tmpfsh);exit;
        // set extra entry for drug medication
        $pdat->hdrentries[] = [
          'id' => $druginstanceid,
          'instance' => $druginstance,
          'nosectionentry' => TRUE,
          "bundleentryslicename" => "medication",
        ];
        // set entry meta data
        $ent->bundleentryslicenameentries = "";
      } else 
      /* ------------------------------------ */
      if ($entrytype === "careplan") {
      /* ------------------------------------ */
        $ent->status = "#not-started";
        list($tmpfsh, $tmphtml, $tmphead, $entinstance) = 
          twigit([
            "instanceid" => $entinstanceid,
            "patient" => $pdat,
            "careplan" => $ent
          ], "careplan");
        $tmpfsh = str_replace("= =", "=", $tmpfsh);  // fix possible AI flaw
        // set entry meta data
        $ent->bundleentryslicenameentries = "";
      } else 
      /* ------------------------------------ */
      if ($entrytype === "dischargediagnosis") {
      /* ------------------------------------ */
        // var_dump($ent);
        list($tmpfsh, $tmphtml, $tmphead, $entinstance) = 
          twigit(["instanceid" => $entinstanceid, "patient" => $pdat, "condition" => $ent], "condition-eu");
        // set entry meta data
        // echo "ÜÜÜÜÜÜÜÜÜÜ\n";var_dump($tmpfsh);
        $ent->bundleentryslicenameentries = "";
      }
      else {
        /* ------------------------------------ */
        /*          UNKNOW entry type           */
        /* ------------------------------------ */
        $ent->bundleentryslicenameentries = "UNKNOWN-$entrytype";
      }

      // store tmp fsh, html and header
      $FSHsec .= $tmpfsh;
      $HTMLsec = $tmphtml;
      $HEADsec = $tmphead;
      // prepare entry meta data
      $thisentrymeta = [
        "id" => $entinstanceid,
        "instance" => $entinstance,
        "bundleentryslicename" => $ent->bundleentryslicenameentries,
        "sectionentryslicename" => $thisectionentryslicename
      ];
      $pdat->hdrentries[] = $thisentrymeta;
      $thissectionentries[] = $thisentrymeta;
    }
  }

  // now the section
  $sectitle = isset($section->title) ? $section->title : $section->code->display;
  lognl(2, "............... " . "Section: " . $sectitle . "\n");

  $HTMLsec = isset($section->text) ? str_replace("\"\"\"", "", $section->text) : "<table class='hl7__hdr'>$HTMLsec</table>";

  $sections[$thisectionentryslicename] = [
    'title' => $sectitle,
    'code' => $section->code->system . "#" . $section->code->code,
    'display' => $section->code->display,
    'text' => $HTMLsec,
    'entries' => $thissectionentries,
    'fsh' => $FSHsec
  ];

}

//var_dump( $sections );var_dump($pdat->hdrentries);exit;

?>