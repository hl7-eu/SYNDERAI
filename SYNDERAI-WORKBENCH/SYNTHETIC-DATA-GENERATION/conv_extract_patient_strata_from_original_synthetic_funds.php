<?php
/*
 * Pre-process all patients from Synthea's generated clinical stories
 * and store their uid, calculated age and sex in 25_tipster_clinicalcandidates_25k
 * so that this file contains a reference to all original patients with their strata.
 * Filter out all patients that are dead.
 * This allows to select a generated clinical story from Synthea based on the stratum and
 * associate it with a European citizen record to be used for demographics instead
 * the Synthea based one.
 * 
 * The reuslting file shall be copied to the SYNTHETIC-DATA directory
 *
 * KH 202509
 */

/* SETTINGS*/
ini_set('memory_limit', '3G');

/* GENERAL SYNDERAI INCLUDES */
include_once("../CONSTANTS/constants.php");

echo "Analyzing " . SYNTHEADIR . "/patients.csv\n";

$pah = fopen(SYNTHEADIR . "/patients.csv", 'r');

$DELIMITER = ",";

$today = date('Y-m-d');

$clinicalpatients = array();

echo "Clinical Patients: ";

$count = 0;

while (($data = fgetcsv($pah, 10000, ",", "\"", "\\")) !== FALSE) {

    // var_dump($data);
    $uid = $data[0];
    $birthdate = $data[1];
    $eth1 = $data[13];
    $eth2 = $data[14];
    $gender = $data[15];
    $deathdate = $data[2];

    $age = floor((time() - strtotime($birthdate)) / 31556926);  // 31556926 is the number of seconds in a year

    // echo "PAT# $count - $uid $birthdate $age $gender $eth1 $eth2\n";
    if ($eth1 == "white" && $eth2 == "nonhispanic" && strlen($deathdate) === 0) {
        $count++;
        $clinicalpatients[] = $uid . $DELIMITER . $age . $DELIMITER . $gender;
    }

}
fclose($pah);

$count = count($clinicalpatients);
echo "count=$count\n";
$kcount = floor($count / 1024);
$cdate = date('Ym');

$lines = "uuid" . $DELIMITER . "age" . $DELIMITER . "gender" . "\n";
foreach ($clinicalpatients as $l) {
    $lines .= $l . "\n";
}
file_put_contents(SYNTHETICDATA . "/25_tipster_clinicalcandidates_" . $kcount  . "k_" . $cdate . ".csv", $lines);

?>