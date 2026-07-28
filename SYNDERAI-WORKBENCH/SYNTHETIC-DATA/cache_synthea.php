<?php

/* SETTINGS*/
ini_set('memory_limit', '2G');

// $SYNTHEADIR = "synthea_sample_data_generated202507";
$SYNTHEADIR = "synthea_sample_data_generated202607";

echo "CACHING $SYNTHEADIR\n";

// #############
// do encounter, make a list of inpatient encounters
$DIR="$SYNTHEADIR/inpatientencounters";
if (is_dir($DIR)) {
    rrmdir($DIR);
}
mkdir($DIR);
$count=0;
$lines=0;
$found=array();
$pah = fopen("$SYNTHEADIR/encounters.csv", 'r');
while (($data = fgetcsv($pah, 10000, ",", "\"", "\\")) !== FALSE) {
    $lines++;
    if (trim($data[7]) === "inpatient") {
        $found[$data[3]] = $data[3];
        $count++;
    }
}
fclose($pah);
$thisfile = "$DIR/inpatientencounters.csv";
$content = implode("\n", $found);
file_put_contents($thisfile, $content . "\n");
echo "COUNT inpatientencounters items=$count lines=$lines\n";

// #############
// do procedures
$DIR="$SYNTHEADIR/procedures";
if (is_dir($DIR)) {
    rrmdir($DIR);
}
mkdir($DIR);
$count=0;
$lines=0;
$pah = fopen("$SYNTHEADIR/procedures.csv", 'r');
while (($data = fgetcsv($pah, 10000, ",", "\"", "\\")) !== FALSE) {
    $id = $data[2];
    $line = implode(",", $data);
    $thisfile = "$DIR/$id";
    $lines += is_file($thisfile) ? 0 : 1;
    file_put_contents($thisfile, $line . "\n", is_file($thisfile) ? FILE_APPEND : 0);
    $count++;
}
fclose($pah);
echo "COUNT procedures items=$count lines=$lines\n";

// #############
// do observations
$DIR="$SYNTHEADIR/observations";
if (is_dir($DIR)) {
    rrmdir($DIR);
}
mkdir($DIR);
$count=0;
$lines=0;
$pah = fopen("$SYNTHEADIR/observations.csv", 'r');
while (($data = fgetcsv($pah, 10000, ",", "\"", "\\")) !== FALSE) {
    $id = $data[1];
    $line = implode(",", $data);
    $thisfile = "$DIR/$id";
    $lines += is_file($thisfile) ? 0 : 1;
    file_put_contents($thisfile, $line . "\n", is_file($thisfile) ? FILE_APPEND : 0);
    $count++;
}
fclose($pah);
echo "COUNT observations items=$count lines=$lines\n";

// ##############

# HELPER function: recursively remove a directory
function rrmdir($dir) {
    foreach(glob($dir . '/*') as $file) {
        if(is_dir($file))
            rrmdir($file);
        else
            unlink($file);
    }
    rmdir($dir);
}
?>