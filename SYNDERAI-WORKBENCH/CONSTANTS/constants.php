<?php

/* __ input files from synthea and check __ */
define("SYNTHETICDATA",            "../SYNTHETIC-DATA");
define("SYNTHEADIR",               SYNTHETICDATA . "/synthea_sample_data_generated202609");
define("SYNTHEAINTL",              SYNTHETICDATA . "/synthea-international-202509");
define("MAPPINGS",                 "../MAPPINGS");

define("EUROPEDEMOGRAPHICS",       SYNTHETICDATA . "/25_tipster_eu_demographics_nuts2_v12.tsv");

define("SYNTHEAPATIENTSTRATA",     SYNTHETICDATA . "/25_tipster_clinicalcandidates_40k_202609.csv");

/** Severity constants used by lognlsev(). */
define("FATAL",   -1);   // display error and die()
define("SUCCESS",  0);   // display warning, continue
define("WARNING",  1);   // display warning, continue
define("ERROR",    2);   // display error, continue
define("INFO",     3);   // display error, continue

/* DIR NAMES, relative to INSTANCE GENERATION directory */
define("CACHEDIR",                 "cache");
define("CACHEITEMSEPARATOR",       "|");
define("FSHOUTPUTDIR",             "pex");
define("STATSDIR",                 "statistics/");  // name of the folder with all stat files
define("STATSFILE",                "statistics");  // gets date + suffix .csv later
define("MISSINGMAPFILE",           "__missing_maps.txt");


/* GLOBALs, eg HL7 Europe OID for the examples, branch .999 is used */
define("HL7EUROPEOID",             "2.16.840.1.113883.2.51");
define("HL7EUROPEEXAMPLESOID",     HL7EUROPEOID . ".999");


/* Valid international (European) country codes in stock where we have hospitals or primary care providers for */
define("VALID_INTL_COUNTRIES",
  [
    "at" => "Austria",
    "be" => "Belgium",
    "bg" => "Bulgaria",
    "cz" => "Czech Republic",
    "cz" => "Czechia",
    "de" => "Germany",
    "dk" => "Denmark",
    "ee" => "Estonia",
    "es" => "Spain",
    "fi" => "Finland",
    "fr" => "France",
    "gb" => "United Kingdom",
    "gr" => "Greece",
    "hr" => "Croatia",
    "hu" => "Hungary",
    "ie" => "Ireland",
    "it" => "Italy",
    "lt" => "Lithuania",
    "lu" => "Luxembourg",
    "no" => "Norway",
    "nl" => "Netherlands",
    "pl" => "Poland",
    "pt" => "Portugal",
    "ro" => "Romania",
    "se" => "Sweden",
    "si" => "Slovenia",
    "sk" => "Slovakia"
  ]
);

define("SYNDERAI_SYNTHETIC_DATA_POLICY_META",
  [
    "* meta.security[+].system = \$v3-ActReason",
    "* meta.security[=].code = #HTEST",
    "* meta.security[+].system = \$v3-ActReason",
    "* meta.security[=].code = #TRAIN",
    "* meta.tag[+].system = \"https://synderai.net/fhir/CodeSystem/tags\"",
    "* meta.tag[=].code = #synthetic",
    "* meta.tag[=].display = \"SYNDERAI Synthetic data\""
  ]);

?>