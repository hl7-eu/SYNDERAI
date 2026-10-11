<?php

$EXTRADISCHARGE["History of artificial joint (situation)"] = [ "text" => "Artificial joint complication assessed, revision surgery performed if indicated|Z96.6|Presence of orthopedic joint implants"];
$EXTRADISCHARGE["Injury of anterior cruciate ligament"] = [ "text" => "ACL reconstruction performed, post-operative recovery uneventful|S83.5|Sprain and strain of anterior cruciate ligament of knee"];
$EXTRADISCHARGE["Injury of medial collateral ligament of knee"] = [ "text" => "MCL repair performed, post-operative recovery uneventful|S83.4|Sprain and strain of medial collateral ligament of knee"];
$EXTRADISCHARGE["Injury of tendon of the rotator cuff of shoulder"] = [ "text" => "Rotator cuff repair performed, post-operative recovery uneventful|M75.1|Rotator cuff syndrome"];
$EXTRADISCHARGE["Rupture of patellar tendon"] = [ "text" => "Patellar tendon repair performed, post-operative recovery uneventful|S76.1|Injury of quadriceps muscle and tendon"];
$EXTRADISCHARGE["Malignant neoplasm of breast (disorder)"] = [ "text" => "Breast cancer, surgical treatment performed and oncology plan established|C50.9|Malignant neoplasm of breast, unspecified"];
$EXTRADISCHARGE["Neuropathy due to type 2 diabetes mellitus (disorder)"] = [ "text" => "Diabetic peripheral neuropathy, assessed and pain management optimised|E11.40|Type 2 diabetes mellitus with diabetic neuropathy, unspecified"];
$EXTRADISCHARGE["Non-small cell carcinoma of lung TNM stage 1 (disorder)"] = [ "text" => "NSCLC stage 1, surgical resection performed and oncology plan established|C34.10|Malignant neoplasm of upper lobe, bronchus or lung, unspecified"];
$EXTRADISCHARGE["Non-small cell carcinoma of lung  TNM stage 1 (disorder)"] = [ "text" => "NSCLC stage 1, surgical resection performed and oncology plan established|C34.10|Malignant neoplasm of upper lobe, bronchus or lung, unspecified"];
$EXTRADISCHARGE["Overlapping malignant neoplasm of colon"] = [ "text" => "Overlapping colon malignancy, surgical resection performed and oncology plan established|C18.8|Malignant neoplasm of overlapping lesion of colon"];
$EXTRADISCHARGE["Primary small cell malignant neoplasm of lung TNM stage 1 (disorder)"] = [ "text" => "Small cell lung cancer stage 1, chemotherapy initiated and oncology plan established|C34.10|Malignant neoplasm of upper lobe, bronchus or lung, unspecified"];
$EXTRADISCHARGE["Primary small cell malignant neoplasm of lung  TNM stage 1 (disorder)"] = [ "text" => "Small cell lung cancer stage 1, chemotherapy initiated and oncology plan established|C34.10|Malignant neoplasm of upper lobe, bronchus or lung, unspecified"];
$EXTRADISCHARGE["Sleep disorder (disorder)"] = [ "text" => "Sleep disorder, investigated and managed|G47.9|Sleep disorder, unspecified"];
$EXTRADISCHARGE["History of aortic valve repair (situation)"] = [ "text" => "Post aortic valve repair follow-up, cardiac status and anticoagulation reviewed|Z95.4|Presence of other heart-valve replacement"];
$EXTRADISCHARGE["History of aortic valve replacement (situation)"] = [ "text" => "Post aortic valve replacement follow-up, anticoagulation and cardiac status reviewed|Z95.2|Presence of prosthetic heart valve"];
$EXTRADISCHARGE["History of coronary artery bypass grafting (situation)"] = [ "text" => "Post-CABG follow-up, cardiac status reviewed and stable|Z95.1|Presence of aortocoronary bypass graft"];
$EXTRADISCHARGE["Sterilization requested (situation)"] = [ "text" => "Voluntary surgical sterilization, procedure completed|Z30.2|Sterilization admitted"];
$EXTRADISCHARGE["Awaiting transplantation of kidney (situation)"] = [ "text" => "Pre-renal transplant workup completed, patient listed|Z49.0|Preparatory care for dialysis"];
$EXTRADISCHARGE["Abnormal findings diagnostic imaging heart+coronary circulation (finding)"] =  [ "text" => "Coronary artery disease confirmed on imaging, management plan established|R93.1|Abnormal findings on diagnostic imaging of heart and coronary circulation"];
$EXTRADISCHARGE["Abnormal findings diagnostic imaging heart+coronary circulat (finding)"] =  [ "text" => "Coronary artery disease confirmed on imaging, management plan established|R93.1|Abnormal findings on diagnostic imaging of heart and coronary circulation"];
$EXTRADISCHARGE["Meningomyelocele (disorder)"] =  [ "text" => "Meningomyelocele, surgical repair performed and neurological status assessed|Q05.9|Spina bifida, unspecified"];
$EXTRADISCHARGE["Preinfarction syndrome (disorder)"] = ["text" => "Unstable angina, medically stabilised and coronary intervention performed|I20.0|Unstable angina"];
$EXTRADISCHARGE["Leukemia  disease (disorder)"] = ["text" => "Leukemia disease, chemotherapy planned|C95|Leukemia of unspecified cell type"];

/*
 * first get all typical-inpatient-adm+discharge-diagnoses (in MAPPINGS) 
 * for later filtering
 * example line with snomed code+display for admission reason, discharge diagnosis text and icd code+display
 * reason-code|reason-display|discharge-diagnosis|icd10-code|icd10-display
 * 431857002;Chronic kidney disease stage 4 (disorder);Chronic kidney disease, stage 4;N18.4;Chronic kidney disease, stage 4
 */
lognl(1, "... load typical inpatient admission and discharge diagnoses\n");
$handle = fopen(MAPPINGS . "/typical-inpatient-adm+discharge-diagnoses.csv","r");
$APPROPRIATEREASONS = [];
while (($buffer = fgetcsv($handle, 10000, ";", '"', '\\')) !== FALSE) {
  $reasoncode = trim($buffer[0]);
  $synthesistext = trim($buffer[2]);
  if (strlen($reasoncode) > 0 and strlen($synthesistext) > 0) {
    $APPROPRIATEREASONS[$reasoncode] = [
      "reason" => [
        "code" => $reasoncode,          // snomed code
        "display" => trim($buffer[1])   // snomed display
      ],
      "discharge" => [
        "text" => $synthesistext,       // short synthesis text
        "code" => trim($buffer[3]),     // icd10 code
        "display" => trim($buffer[4]),  // icd10 display
      ]
    ];
  } else {
    if (strlen($reasoncode) > 0)
      lognlsev(3, WARN, "Admission/Discharge code $reasoncode has no synthesis.");
  }
}

/* 
  PRE-REQUISITES
  all Encounter classes, some filtered (for procedure filtering to prevent wellness procedures)
  Structure
  Id	START	STOP	PATIENT	ORGANIZATION	PROVIDER	PAYER	ENCOUNTERCLASS	CODE	DESCRIPTION	BASE_ENCOUNTER_COST	TOTAL_CLAIM_COST	PAYER_COVERAGE	REASONCODE	REASONDESCRIPTION
*/

lognl(1, "... load encounter ids with usefull classes / reason codes\n");

// look-up cache
$cachedin = inCACHE('encounters', "inpatient-encounters.json");
/*
 * CACHE VERSION. The procedure-encounter list is cached as a flat JSON array
 * and carries no record of the rule that produced it. Changing the rule above
 * without changing this file name leaves every existing run using the old list
 * - the fix would be inert and look like it had not worked. Bump the suffix
 * whenever $NOPROCEDUREENCOUNTERCLASSES or the inpatient condition changes.
 * v2: 2026-10-11, negative class list, "ambulatory" admitted.
 */
$cachedcp = inCACHE('encounters', "clinical-procedure-encounters-v2.json");

$ok = FALSE;
if ($cachedin !== FALSE and $cachedcp !== FALSE) {
  $INPATIENTENCOUNTERS = json_decode($cachedin, TRUE);
  $CLINICALPROCEDUREENCOUNTERS = json_decode($cachedcp, TRUE);
  $ok = count($INPATIENTENCOUNTERS) > 0 and count($CLINICALPROCEDUREENCOUNTERS) > 0;
}

if (!$ok) {

  $handle = fopen(SYNTHEADIR . "/encounters.csv","r");
  while (($buffer = fgetcsv($handle, 10000, ",", '"', '\\')) !== FALSE) {
    
    $eid = trim($buffer[0]);  // get the encounter id
    
    $encclass = trim($buffer[7]);  // ... and the encounter class
    // echo ("ENCOUNTER $eid $encclass\n");
    
    $useableencounter = FALSE;  // filter all out for now

    /*
    * inpatient class = inpatient? then check whether the reason code exists
    * and is in out list of "appropriate" admission reasons. If
    * so then add this encounter information to $INPATIENTENCOUNTERCLASSES
    */
    if ($encclass === "inpatient" and trim($buffer[13]) !== '183801001') { // never use 183801001 Inpatient stay 3 days
      $useableencounter = TRUE;   // set true also for later filtering CLINPROCEDUREENCOUNTERCLASSES
      // find out whether the reason code is in our list of "appropriate" admission reasons
      $reasoncode = trim($buffer[13]);
      $reasondisplay = trim($buffer[14]);

      // if the appropriate reason code is empty for this reason display
      // then try to use a matching discharge info from the extra in $EXTRADISCHARGE
      $extraappropriatereason = NULL;
      if (!isset($APPROPRIATEREASONS[$reasoncode])) {
        // echo "**R**" . $reasoncode . " - " . $reasondisplay . "\n";
        if (isset($EXTRADISCHARGE[$reasondisplay])) {
          $tmp = explode('|', $EXTRADISCHARGE[$reasondisplay]["text"]);
          $extraappropriatereason = [
            "reason" => [
              "code" => $reasoncode,
              "display" => $reasondisplay
            ],
            "discharge" => [
              "text" => trim($tmp[0]),
              "code" => trim($tmp[1]),   // icd10 code
              "display" => trim($tmp[2]),
            ]
          ];
          // var_dump($EXTRADISCHARGE[$reasondisplay]);
        }
      }

      $dischargeextrainfo = NULL;
      if (isset($APPROPRIATEREASONS[$reasoncode]["discharge"])) {
        $dischargeextrainfo = $APPROPRIATEREASONS[$reasoncode]["discharge"];
        if (isset($extraappropriatereason["discharge"])) {
          $dischargeextrainfo = $extraappropriatereason["discharge"];
        }
      }
      $INPATIENTENCOUNTERS[] = array_merge([
        "encounterid" => $eid,
        "candid" => trim($buffer[3]),
        "procedure" => [
          "code" => trim($buffer[8]),
          "system" => "\$sct",
          "display" => trim($buffer[9])
        ],
        "reason" => [
          "code" => $reasoncode,
          "system" => "\$sct",
          "display" => $reasondisplay
        ],
        "start" => substr(trim($buffer[1]), 0, 10),
        "end" => substr(trim($buffer[2]), 0, 10),
        "startexact" => trim($buffer[1]),
        "endexact" => trim($buffer[2]),
        "discharge" => $dischargeextrainfo
      ]);
    }

    /*
     * Which encounter classes may carry a procedure.
     *
     * The stated intent, in the comment at getters/procedures.php:124, is to
     * keep wellness procedures out. The implementation was a POSITIVE list:
     * inpatient (set above) plus emergency, hospice, snf and urgentcare. Every
     * other class fell through with $useableencounter still FALSE, and the
     * class that Synthea uses for the whole of outpatient medicine -
     * "ambulatory" - was among them. So was "outpatient", "virtual" and "home".
     *
     * What that cost, measured against the release 5 EPS package (313 bundles):
     * 1641 Procedure resources are present, but SNOMED 65575008 Biopsy of
     * prostate appears zero times although prostate_cancer_europe.json reaches
     * its Prostate_Biopsy state unconditionally after the diagnosis, and three
     * patients carry 126906006 Neoplasm of prostate. 26294005 Radical
     * prostatectomy likewise zero. All three Encounter states of that module
     * are encounter_class = ambulatory. The Observation states inside the very
     * same encounters are NOT filtered this way, which is why six PSA results
     * (LOINC 2857-1) did reach the package while not a single procedure from
     * those encounters did. A patient with an active prostate neoplasm, a PSA
     * value and an empty procedure list is the visible symptom.
     *
     * The list is therefore negative now: a class is useable unless it is named
     * here. "wellness" is the class the original comment was aiming at. Add a
     * class to this array rather than removing one from a positive list, so
     * that a class Synthea introduces later is admitted by default instead of
     * silently dropping the procedures attached to it.
     */
    $NOPROCEDUREENCOUNTERCLASSES = ["wellness"];
    if (!in_array($encclass, $NOPROCEDUREENCOUNTERCLASSES)) $useableencounter = TRUE;
    if ($useableencounter) $CLINICALPROCEDUREENCOUNTERS[] = $eid;

  }
  fclose($handle);

  toCACHE('encounters', "inpatient-encounters.json", json_encode($INPATIENTENCOUNTERS));
  toCACHE('encounters', "clinical-procedure-encounters-v2.json", json_encode($CLINICALPROCEDUREENCOUNTERS));

}

?>