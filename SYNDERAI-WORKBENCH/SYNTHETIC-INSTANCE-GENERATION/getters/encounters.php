<?php

/* $EXTRADISCHARGE and the MAPPINGS loader now live in lib/discharge-criterion.php,
 * so that this getter and the pre-processing cache apply the same criterion. */
include_once("lib/discharge-criterion.php");

/*
 * first get all typical-inpatient-adm+discharge-diagnoses (in MAPPINGS) 
 * for later filtering
 * example line with snomed code+display for admission reason, discharge diagnosis text and icd code+display
 * reason-code|reason-display|discharge-diagnosis|icd10-code|icd10-display
 * 431857002;Chronic kidney disease stage 4 (disorder);Chronic kidney disease, stage 4;N18.4;Chronic kidney disease, stage 4
 */
lognl(1, "... load typical inpatient admission and discharge diagnoses\n");
$APPROPRIATEREASONS = loadAppropriateReasons();
lognl(2, "...... " . count($APPROPRIATEREASONS) . " admission reasons with a discharge synthesis, plus "
        . count($EXTRADISCHARGE) . " additional ones\n");

/* 
  PRE-REQUISITES
  all Encounter classes, some filtered (for procedure filtering to prevent wellness procedures)
  Structure
  Id	START	STOP	PATIENT	ORGANIZATION	PROVIDER	PAYER	ENCOUNTERCLASS	CODE	DESCRIPTION	BASE_ENCOUNTER_COST	TOTAL_CLAIM_COST	PAYER_COVERAGE	REASONCODE	REASONDESCRIPTION
*/

lognl(1, "... load encounter ids with usefull classes / reason codes\n");

// look-up cache
$cachedin = inCACHE('encounters', "inpatient-encounters.json");
$cachedcp = inCACHE('encounters', "clinical-procedure-encounters.json");

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

      /*
       * FIX 2026-09-30. This used to read:
       *     if (isset($APPROPRIATEREASONS[$reasoncode]["discharge"])) {
       *         $dischargeextrainfo = $APPROPRIATEREASONS[...];
       *         if (isset($extraappropriatereason["discharge"])) { ...override... }
       *     }
       * where $extraappropriatereason was built only when $APPROPRIATEREASONS had
       * NO entry for the reason code, so the inner branch could never run: all 23
       * $EXTRADISCHARGE entries were dead code. Measured against the 2026-09-13
       * population, that halved the HDR-capable pool - 12.9% of living patients
       * instead of 25.1%.
       */
      $dischargeextrainfo = dischargeInfoFor($reasoncode, $reasondisplay);
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

    // unset filter for the following classes too (for procedure filtering to prevent wellness procedures etc.)
    if ($encclass === "emergency") $useableencounter = TRUE;
    if ($encclass === "hospice") $useableencounter = TRUE;
    if ($encclass === "snf") $useableencounter = TRUE;
    if ($encclass === "urgentcare") $useableencounter = TRUE;
    if ($useableencounter) $CLINICALPROCEDUREENCOUNTERS[] = $eid;

  }
  fclose($handle);

  toCACHE('encounters', "inpatient-encounters.json", json_encode($INPATIENTENCOUNTERS));
  toCACHE('encounters', "clinical-procedure-encounters.json", json_encode($CLINICALPROCEDUREENCOUNTERS));

}

?>