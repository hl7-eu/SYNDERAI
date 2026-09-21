<?php

require_once __DIR__ . "/../lib/vital-guards.php";


// prepare all sections and entries of the HDR parsed from ish (if ish is set) or as prepared sections

// for later correct section slice names following HDR spec
//                     -- used in ISH --     -- correct section slice in HDR --
$CORRECTSECTIONSLICES["admissionevaluation"] = "sectionAdmissionEvaluation";
$CORRECTSECTIONSLICES["socialhistory"]       = "";
$CORRECTSECTIONSLICES["tobaccouse"]          = "";
$CORRECTSECTIONSLICES["synthesis"]           = "sectionSynthesis";
$CORRECTSECTIONSLICES["hospitalcourse"]      = "sectionHospitalCourse";
$CORRECTSECTIONSLICES["procedures"]          = "sectionSignificantProcedures";
$CORRECTSECTIONSLICES["results"]             = "sectionSignificantResults";
$CORRECTSECTIONSLICES["vitalsigns"]          = "sectionVitalSigns";
$CORRECTSECTIONSLICES["medication"]          = "sectionPharmacotherapy";
$CORRECTSECTIONSLICES["pharmacotherapy"]     = "sectionPharmacotherapy";
$CORRECTSECTIONSLICES["careplan"]            = "sectionPlanOfCare";
$CORRECTSECTIONSLICES["dischargediagnosis"]  = "sectionDiagnosticSummary";
$CORRECTSECTIONSLICES["dischargemedication"] = "sectionDischargeMedications";
$CORRECTSECTIONSLICES["pharmacotherapy"]     = "sectionPharmacotherapy";
$CORRECTSECTIONSLICES["familyhistory"]       = "";

foreach($thisStayISH->section as $section) {

  // var_dump($section); exit;
  // var_dump($section);

  $FSHsec = "";
  $HTMLsec = "";
  $HEADsec = "";
  // catch all entries for this section
  $thissectionentries = array();
  // Preset
  $thisectionentryslicename = "";
  if (isset($CORRECTSECTIONSLICES[$section->type])) {
    $thisectionentryslicename = $CORRECTSECTIONSLICES[$section->type];
  } else {
    $thisectionentryslicename = "UNKNOWN-SECTION-TYPE-OR-NOT-SET-$section->type";
    lognlsev(ERROR, 2, "............... " . "Unknown section type '" . $section->type . "', please check\n");
  } 

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
      // Clear the three per-entry bags HERE, not once before the loop.
      //
      // Not every branch below assigns them: the "result" case is guarded by
      // an inner if with no else, and an unknown entry type reaches the bottom
      // without calling twigit() at all. Whatever the previous entry left in
      // $tmpfsh/$tmphtml/$tmphead would then be appended a second time - a
      // duplicated narrative row, and in the FSH a second Instance carrying an
      // id that is already in the file.
      $tmpfsh = "";
      $tmphtml = "";
      $tmphead = "";
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
        // Observation.code is 1..1. Without a code the template renders
        // "* code = # \"\"", which the FSH parser rejects and which drags the
        // whole Observation down with it. Skip the entry before it is rendered
        // so that no bundle entry and no section reference is created either.
        if (!vitalHasCode($ent)) {
          lognlsev(2, ERROR, "............... +++ Vital sign without an observation code, skipped\n");
          registerMapMissing("+++ Vital sign without an observation code");
          continue;
        }
        list($tmpfsh, $tmphtml, $tmphead, $entinstance) = 
          twigit([
            "instanceid" => $entinstanceid,
            "patient" => $pdat,
            "vital" => $ent
          ], "vitalsigns");
        // set entry meta data
        // var_dump($tmpfsh);
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
      if ($entrytype === "tobaccouse") {
      /* ------------------------------------ */
        list($tmpfsh, $tmphtml, $tmphead, $entinstance) =
          twigit([
            "instanceid" => $entinstanceid,
            "patient" => $pdat,
            "tobaccouse" => $ent
          ], "observation-tobaccouse");
        // set entry meta data
        $ent->bundleentryslicenameentries = "";
      } else 
      /* ------------------------------------ */
      if ($entrytype === "result" or $entrytype === "addrecentlabresults") {
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
          // var_dump($ent);//exit;
          if ($entrytype === "result") {
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
        // echo "ÜÜÜÜÜÜÜÜÜÜ\n";var_dump($ent);var_dump($tmpfsh);//exit;
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
        //echo "ÜÜÜÜÜÜÜÜÜÜ\n";var_dump($tmpfsh);
        // set entry meta data
        $ent->bundleentryslicenameentries = "";
      } else 
      /* ------------------------------------ */
      if ($entrytype === "condition") {
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
      //
      // All three bags belong to ONE section and are reset per section a few
      // lines above ($FSHsec/$HTMLsec/$HEADsec = "" before the entry loop), so
      // nothing accumulated here crosses a section boundary.
      //
      // $HTMLsec used to be an assignment while $FSHsec was an append. The
      // entry templates emit one <tr>...</tr> per entry and the surrounding
      // <table> is added further below, so the assignment threw away every row
      // but the last: a section with five entries produced five Observations
      // and a narrative naming one of them. Counted over the last HDR run,
      // 15 of 15 multi-entry sections showed a single row and 128 entries were
      // absent from the narrative they belong to. Nothing in the FSH was wrong,
      // which is why no validator ever said so.
      $FSHsec  .= $tmpfsh;
      $HTMLsec .= $tmphtml;
      // The head bag is the column header of that one table and is identical
      // for every entry of a section. It stays an assignment on purpose;
      // appending would repeat the header once per entry.
      $HEADsec  = $tmphead;
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

// var_dump( $sections["sectionSignificantProcedures"] );//var_dump($pdat->hdrentries);exit;
// var_dump( $sections["sectionPlanOfCare"] );

?>