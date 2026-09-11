<?php
/*
 * Pre-process all patients from Synthea's generated clinical stories
 * and store their uid, calculated age and sex in 25_tipster_clinicalcandidates_*k
 * so that this file contains a reference to all original patients with their strata.
 * For this purpose here filter out all patients that are dead.
 * This allows to select a generated clinical story from Synthea based on the stratum and
 * associate it with a European citizen record to be used for demographics instead
 * the Synthea based one.
 * 
 * The reuslting file shall be copied to the SYNTHETIC-DATA directory
 *
 * KH 202509, 202609
 */

/* SETTINGS*/
ini_set('memory_limit', '3G');

/** Set timezone explicitly to avoid ambiguous date/time offsets in output. */
date_default_timezone_set('Europe/Berlin');

/* GENERAL SYNDERAI INCLUDES */
include_once("../CONSTANTS/constants.php");
include_once("../SYNTHETIC-INSTANCE-GENERATION/lib/common-utils.php");
include_once("../SYNTHETIC-INSTANCE-GENERATION/config.php");

/** Record script start time for elapsed-time logging via logmeterinit(). */
$STARTTIMER = time();


lognlsev(1, INFO, "*** Analyzing " . SYNTHEADIR . "/patients.csv");
lognl(1, "    --------:-----total-+-----alive-+---skipped-+----in set-");
lognl(1, "    matching");

$pah = fopen(SYNTHEADIR . "/patients.csv", 'r');

$DELIMITER = ",";

$today = date('Y-m-d');

$clinicalpatients = array();
$patientidentifiers = array();

$count = 0;
$alive = 0;
$dead = 0;

while (($data = fgetcsv($pah, 10000, ",", "\"", "\\")) !== FALSE) {

    $count++;

    // var_dump($data);
    $uid = $data[0];
    $birthdate = $data[1];
    $eth1 = $data[13];
    $eth2 = $data[14];
    $gender = $data[15];
    $deathdate = $data[2];

    $age = floor((time() - strtotime($birthdate)) / 31556926);  // 31556926 is the number of seconds in a year

    // echo "PAT# $count - $uid $birthdate $age $gender $eth1 $eth2\n";
    // ONLY register patients that are ethnicity white or non-hispanic and alive. 
    if (strlen($deathdate) === 0) { // only alive in our set
        $alive++;
        if ($eth1 == "white" && $eth2 == "nonhispanic") {  // only white and nonhispanic in our set
            $clinicalpatients[] = $uid . $DELIMITER . $age . $DELIMITER . $gender;
            $patientidentifiers[$uid] = TRUE;
        }
    } else {
        $dead++;
    }

}
fclose($pah);

$inset = count($clinicalpatients);

lognl(1, sprintf("    registered  %7d %11d %11d %11d", $count, $alive, $dead, $inset));

$kcount = floor($count / 1024);
$cdate = date('Ym');

$lines = "uuid" . $DELIMITER . "age" . $DELIMITER . "gender" . "\n"; // headline
// build file contents
foreach ($clinicalpatients as $l) {
    $lines .= $l . "\n";
}
// write stratum file
$STRATUMFILENAME = SYNTHETICDATA . "/25_tipster_clinicalcandidates_" . $kcount  . "k_" . $cdate . ".csv";
file_put_contents($STRATUMFILENAME, $lines);

lognlsev(1, SUCCESS, "*** written to $STRATUMFILENAME");

lognlsev(1, INFO, "*** Caching " . SYNTHEADIR);

lognl(1, "    --------:-pool----------------+------items-+------lines-");

// #############
// do encounter, make a list of inpatient encounters
lognl(1, "    starting: inpatientencounters");
$DIR = SYNTHEADIR . "/inpatientencounters";
if (is_dir($DIR)) {
    rrmdir($DIR);
}
mkdir($DIR);
$count = 0;  // registered records count
$lines = 0;  // lines read from source
$found = array();
$syntheafile = SYNTHEADIR . "/encounters.csv";
$totalLines = intval(exec("wc -l '$syntheafile'"));
$pah = fopen($syntheafile, 'r');
lognonl(1, "      caching...");
while (($data = fgetcsv($pah, 10000, ",", "\"", "\\")) !== FALSE) {
    $lines++;
    $id = $data[3];
    if (trim($data[7]) === "inpatient" && isset($patientidentifiers[$id])) {  // only if is inpatient and is in set) {
        if (strlen($id) < 35) {  // must be something like a57c0587-63ff-96dc-c3fc-1bc6ae38e4e3
            lognl(1, "... a problem occured");
            lognlsev(1, FATAL, "+++ misconfigured pool config, check position index of patient id...");
        }
        $found[$id] = $id;  // patient ix
        $count++;
    }
    if ($lines % floor($totalLines / 5) === 0 && $lines > 0) {
        echo " " . number_format(($lines / $totalLines * 100), 0) . "%";
    }
}
echo "\n";
fclose($pah);
$thisfile = "$DIR/inpatientencounters.csv";
$content = implode("\n", $found);
file_put_contents($thisfile, $content . "\n");
lognl(
    1,
    sprintf(
        "    finished: %-17s %12d %12d",
        "inpatientencounters",
        $count,
        $lines
    )
);

// #############
// do conditions, procedures, observations, immunizations
$tobecached = [
    [
        "pool" => "conditions",
        "patix" => 2
    ],
    [
        "pool" => "procedures",
        "patix" => 2
    ],
    [
        "pool" => "observations",
        "patix" => 1
    ],
    [
        "pool" => "immunizations",
        "patix" => 1
    ],
    [
        "pool" => "medications",
        "patix" => 2
    ]
];
foreach ($tobecached as $cacheitem) {
    $tbcx = $cacheitem["pool"];
    $cpix = $cacheitem["patix"];
    lognl(1, "    starting: $tbcx");
    $DIR = SYNTHEADIR . "/$tbcx";
    if (strlen($DIR) > 10 && is_dir($DIR)) {
        rrmdir($DIR);
    }
    mkdir($DIR);
    // first create empty stubs for every patient here
    lognl(1, "      creating stubs...");
    foreach ($patientidentifiers as $l => $m) {
        if (isset($patientidentifiers[$l]))
            file_put_contents("$DIR/$l", "");
    }
    lognonl(1, "      caching...");
    $syntheafile = SYNTHEADIR . "/$tbcx.csv";
    $totalLines = intval(exec("wc -l '$syntheafile'"));
    $pah = fopen($syntheafile, 'r');
    $count = array();  // registered records
    $lines = 0;  // lines read from source
    while (($data = fgetcsv($pah, 10000, ",", "\"", "\\")) !== FALSE) {
        $lines++;
        if ($lines === 1)
            continue;        // skip headline
        $id = $data[$cpix];   // patient ix
        if (isset($patientidentifiers[$id])) {  // only if it is in set
            $thisfile = "$DIR/$id";
            $count[$id] = 1;  // only count once when there are items
            if (strlen($id) < 35) {  // must be something like a57c0587-63ff-96dc-c3fc-1bc6ae38e4e3
                lognl(1, "... a problem occured");
                lognlsev(1, FATAL, "+++ misconfigured pool config, check position index of patient id...");
            }
            $line = implode(",", $data);
            file_put_contents($thisfile, $line . "\n", is_file($thisfile) ? FILE_APPEND : 0);
        }
        if ($lines % floor($totalLines / 5) === 0 && $lines > 0) {
            echo " " . number_format(($lines / $totalLines * 100), 0) . "%";
        }
    }
    echo "\n";
    fclose($pah);
    lognl(
        1,
        sprintf(
            "    finished: %-17s %14d %12d",
            $tbcx,
            count($count),
            $totalLines
        )
    );
}

// ##############

# HELPER function: recursively remove a directory
function rrmdir($dir)
{
    foreach (glob($dir . '/*') as $file) {
        if (is_dir($file))
            rrmdir($file);
        else
            unlink($file);
    }
    rmdir($dir);
}
?>