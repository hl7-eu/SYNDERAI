<?php
/*
 * Shared discharge-information criterion.
 *
 * An inpatient encounter can only become a Hospital Discharge Report if a
 * discharge synthesis exists for its admission reason. Two sources provide one:
 *
 *   1. MAPPINGS/typical-inpatient-adm+discharge-diagnoses.csv, keyed by the
 *      encounter's SNOMED REASONCODE;
 *   2. $EXTRADISCHARGE below, keyed by the encounter's REASONDESCRIPTION, for
 *      reasons the CSV does not cover.
 *
 * This file exists so that the runtime getter and the pre-processing cache apply
 * the SAME criterion. They used to implement it separately, and drifted: the
 * cache registered every patient with any inpatient encounter, while the getter
 * additionally required discharge information, so 38% of the patients the cache
 * offered could not in fact produce an HDR.
 *
 * KH/AI 2026-09-30
 */

/* Discharge syntheses for admission reasons absent from the MAPPINGS CSV.
 * Format: "synthesis text|icd10 code|icd10 display" */
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

/**
 * Load the admission-reason -> discharge-synthesis table from MAPPINGS.
 *
 * @return array  reasoncode => ["reason" => [...], "discharge" => [...]]
 */
function loadAppropriateReasons() {
    $appropriate = [];
    $handle = fopen(MAPPINGS . "/typical-inpatient-adm+discharge-diagnoses.csv", "r");
    if ($handle === FALSE) return $appropriate;
    while (($buffer = fgetcsv($handle, 10000, ";", '"', '\\')) !== FALSE) {
        $reasoncode    = trim($buffer[0]);
        $synthesistext = trim($buffer[2]);
        if (strlen($reasoncode) > 0 and strlen($synthesistext) > 0) {
            $appropriate[$reasoncode] = [
                "reason" => [
                    "code"    => $reasoncode,
                    "display" => trim($buffer[1])
                ],
                "discharge" => [
                    "text"    => $synthesistext,
                    "code"    => trim($buffer[3]),
                    "display" => trim($buffer[4]),
                ]
            ];
        }
    }
    fclose($handle);
    return $appropriate;
}

/**
 * The discharge information for an admission reason, or NULL when none exists.
 * NULL means the encounter cannot carry a Hospital Discharge Report.
 *
 * @param string $reasoncode     SNOMED REASONCODE of the encounter
 * @param string $reasondisplay  REASONDESCRIPTION of the encounter
 * @return array|null
 */
function dischargeInfoFor($reasoncode, $reasondisplay) {
    global $APPROPRIATEREASONS, $EXTRADISCHARGE;

    /* 183801001 "Inpatient stay 3 days" is never a usable admission reason. */
    if ($reasoncode === '183801001') return NULL;

    if (isset($APPROPRIATEREASONS[$reasoncode]["discharge"]))
        return $APPROPRIATEREASONS[$reasoncode]["discharge"];

    if (isset($EXTRADISCHARGE[$reasondisplay]["text"])) {
        $parts = explode('|', $EXTRADISCHARGE[$reasondisplay]["text"]);
        return [
            "text"    => trim($parts[0]),
            "code"    => isset($parts[1]) ? trim($parts[1]) : "",
            "display" => isset($parts[2]) ? trim($parts[2]) : "",
        ];
    }
    return NULL;
}
