<?php

// PHP init sets
// available memory shall be sufficient
ini_set('memory_limit','2048M');
// don't use PCRE's Just-in-Time-Compiler
ini_set("pcre.jit", "0");

date_default_timezone_set("Europe/Brussels");

$starttimer = time();   // for emiting teh elapsed time register the start time

$THISSYNDERAIVERSION = "2.0.0";
$SYNTHETICDATAURL = "http://hl7.eu/fhir/syntheticdata";
$SUPPORTEDARTIFACTS = [
    [
        "short" => "LAB",
        "small" => "lab",
        "long" => "HL7 Europe Laboratory Report",
        "url" => "http://hl7.eu/fhir/laboratory/ImplementationGuide/hl7.fhir.eu.laboratory",
        "ig" => "https://build.fhir.org/ig/hl7-eu/laboratory/en/",
        "name" => "hl7.fhir.eu.laboratory"
    ],
    [
        "short" => "EPS",
        "small" => "eps",
        "long" => "HL7 Europe Patient Summary",
        "url" => "http://hl7.eu/fhir/eps/ImplementationGuide/hl7.fhir.eu.eps",
        "ig" => "https://build.fhir.org/ig/hl7-eu/eps/",
        "name" => "hl7.fhir.eu.eps"
    ],
    [
        "short" => "HDR",
        "small" => "hdr",
        "long" => "HL7 Europe Hospital Discharge Report",
        "url" => "http://hl7.eu/fhir/hdr/ImplementationGuide/hl7.fhir.eu.hdr",
        "ig" => "https://build.fhir.org/ig/hl7-eu/hdr/",
        "name" => "hl7.fhir.eu.hdr"
    ],
];

// read command line argument, must be one of the artifacts
if (isset($argv[1])) {
    $desired = $argv[1];
} else {
    die ("Must specifiy artifact directory to process.\n");
}
$toprocess = NULL;
foreach ($SUPPORTEDARTIFACTS as $saf) {
    if ($saf["short"] === $desired) {
        $toprocess = $saf;
    }
}

if ($toprocess === NULL) {
    echo ("Must specifiy artifact directory to process: ");
    foreach ($SUPPORTEDARTIFACTS as $saf) echo $saf["short"] . " ";
    die ("\n");
}

$desc = $toprocess["long"];
$short = $toprocess["short"];
$small = $toprocess["small"];

lognl("Creating FHIR package for $small - $desc ...");

// starter
$indexentries = array();

// go to the artifact folder
$theartifact = $toprocess["short"];
chdir ($theartifact);

// create package folders
if (is_dir("package")) {
    deleteDirectory("package");
    mkdir("package");
} else {
    mkdir("package");
}
mkdir("package/xml");


// first get all JSON Bundles
$alljson = glob("Bundle*.json");
$jsons = count($alljson);
foreach ($alljson as $jf) {
    $basename = basename($jf, ".json");
    $indexentries[] = [
        "filename" => "$jf",
        "resourceType" => "Bundle",
        "url" => "$SYNTHETICDATAURL/$small/$basename",
        "kind" => "instance",
        "type" => "document"
    ];
    copy($jf, "package/$jf");
}
lognl("... $jsons JSON files in package");
// now get all XML Bundles
$allxml = glob("Bundle*.xml");
$xmls = count($allxml);
foreach ($allxml as $jf) {
    /* we do not include file name in index
    $basename = basename($jf, ".xml");
    $indexentries[] = [
        "filename" => "xml/$jf",
        "resourceType" => "Bundle",
        "url" => "http://synderai.net/fhir/syntheticdata/$toprocess/$basename",
        "kind" => "instance",
        "type" => "Examples"
    ];
    */
    copy($jf, "package/xml/$jf");
}
lognl("... $xmls XML files in package");
$finalindex = [
    "index-version" => 2,
    "files" => $indexentries
];
// var_dump($finalindex);
$finalindex = json_encode($finalindex, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// create package.json, use file timestamp of $alljson[0]
$now = filemtime($alljson[0]);
$shortdate = date("YmdHis", $now);
$longdate = date("j F Y H:i", $now);
$signature = date("Ymd", $now);
$thisversion = "$THISSYNDERAIVERSION+$signature";
$pack = [
    "name" => "hl7.fhir.eu.syntheticdata",
    "date" => "$shortdate",
    "type" => "Examples",
    "license" => "AGPL-3.0",
    "canonical" => "$SYNTHETICDATAURL/$small",
    "url" => "$SYNTHETICDATAURL/$short/$thisversion",
    "version" => "$thisversion",
    "title" => "$desc Data Examples",
    "description" => "$desc Data Examples (built with synderai.net on $longdate)",
    "author" => "dr Kai Heitmann, HL7 Europe",
    "maintainers" => [
        [
            "name" => "HL7 Europe",
            "url" => "http://hl7.eu"
        ]
    ],
    "keywords" => [
        "eu",
        "Europe",
        "HL7 Europe",
        "Synthetic Data",
        "European Health Data Space",
        "EHDS"
    ],
    "fhirVersions" => ["4.0.1"],
    "dependencies" => [
        "hl7.fhir.r4.core" => "4.0.1"
    ],
    "directories" => [
        "lib" => "package",
        "example" => "example"
    ],
    "jurisdiction" => "http://unstats.un.org/unsd/methods/m49/m49.htm#150"
];
$pack = json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// now add files
file_put_contents("package/package.json", $pack);
file_put_contents("package/.index.json", $finalindex);

// finally targz it
exec("tar -czvf package.tar.gz package >/dev/null 2>/dev/null");
lognl("FHIR package for $theartifact - $desc created!");

exit;


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

function deleteDirectory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}
?>