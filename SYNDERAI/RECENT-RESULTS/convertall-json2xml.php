<?php

// PHP init sets
// available memory shall be sufficient
ini_set('memory_limit','2048M');
// don't use PCRE's Just-in-Time-Compiler
ini_set("pcre.jit", "0");

$starttimer = time();   // for emiting teh elapsed time register the start time

// JAVA must be available
$JAVACMD="java -Xmx4096m -jar";
// local XSLT processor using JAVA
$JAVAJAR="../bin/saxon-he-11.5.jar";
$stylesheet = "../fhir40/wrapped-fhir-json2xml.xsl";

// read command line argument, must be one of the artifacts
if (isset($argv[1])) {
    $desired = $argv[1];
} else {
    die ("Must specifiy artifact directory to process.\n");
}
$toprocess = "";
foreach (["LAB", "EPS", "HDR"] as $af) {
    if ($af === $desired) $toprocess = $af;
}

lognl("Processing FHIR JSON to XML conversion for $toprocess ...");

if (strlen($toprocess) > 0) {
    chdir ($toprocess);
    $alljson = glob("Bundle*.json");
    $all2process = count($alljson);
    $processed = 0;
    foreach ($alljson as $jf) {
        $processed++;
        lognl("... transforming $processed/$all2process $toprocess $jf");
        $command = "$JAVACMD $JAVAJAR -u -xsl:$stylesheet -it JSONfile='../$toprocess/$jf'";
        // var_dump($command);
        $OUT = "";
        $ol = "";
        $rv = 0;
        exec($command, $ol, $rv);
        // var_dump($rv);
        if ($rv !== 0) {
            die("A (json) error occurred. ");
        }
        $OUT = implode("\n", array_values($ol));
        $info = pathinfo($jf);
        $xf = $info['filename'] . ".xml";
        file_put_contents($xf, $OUT);
        // var_dump($OUT);exit;
        lognl("... --> $xf");
    }
    chdir ("..");
} else {
    die ("Unknow artifact directory $desired.\n");
}


function lognl ($text) {
  $text = str_replace("\n", "", $text);
  logmeterinit();
  $out = sprintf("%s\n", $text);
  echo $out;
}

function logmeterinit() {
  global $starttimer;
  $time = time();
  $elapsed = abs($starttimer - $time);
  $h = floor($elapsed / 3600);
  $elapsed -= $h * 3600;
  $m = floor($elapsed / 60);
  $elapsed -= $m * 60;
  $out = sprintf("%8s (%d:%02d:%02d) ", date('H:i:s'), $h, $m, $elapsed);
  echo $out;
}
?>