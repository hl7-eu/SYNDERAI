<?php

/* __ input files from synthea and check __ */
define("SYNTHETICDATA",            "../SYNTHETIC-DATA");
define("SYNTHEADIR",               SYNTHETICDATA . "/synthea_sample_data_generated202507");
define("SYNTHEAINTL",              SYNTHETICDATA . "/synthea-international-202507");
define("MAPPINGS",                 "../MAPPINGS");


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

/* Laboratory Categories */
define("SUPPORTED_LOINC_LABORATORY_CATEGORIES",
  [
    "HEM/BC" => ["Hematology", "18723-7", "Hematology studies (set)"],
    "CHEM" => ["Chemistry", "18719-5", "Chemistry studies (set)"],
    "COAG" => ["Coagulation", "18720-3", "Coagulation studies (set)"],
    "UA" => ["Urinalysis", "18729-4", "Urinalysis studies (set)"],
    "ALLERGY" => ["Allergens", "20662-3", "Allergens tested for in Serum"],
    "MICRO" => ["Microbiology", "18725-2", "Microbiology studies (set)"],
    "PANEL.MICRO" => ["Microbiology Panel", "18725-2", "Microbiology studies"],
    "PATH" => ["Pathology", "27898-6", "Pathology studies (set)"],
    "MOLPATH" => ["Molecular pathology", "26435-8", "Molecular pathology studies (set)"],
    "PATH.PROTOCOLS.BRST" => ["Cancer pathology panel - Breast cancer specimen", "85904-1", "Cancer pathology panel - Breast cancer specimen by CAP cancer protocols"],
    "PATH.PROTOCOLS.GENER" => ["Cancer pathology panel", "LP207911-1", "Cancer pathology panel"],
    "PANEL.DRUG/TOX" => ["Drugs of abuse panel", "72479-9", "Drugs of abuse panel - Blood by Screen method"],
    "SPEC" => ["Specimen information", "LP7846-1", "Specimen information"]    
  ]
);
/*

    CHEM MICRO PANEL.MICRO HEM/BC COAG PATH.PROTOCOLS.BRST PATH.PROTOCOLS.GENER PATH MOLPATH  

    LOINC Code (Name)

    18717-9 (BLOOD BANK STUDIES)
    18718-7 (CELL MARKER STUDIES)
    18719-5 (CHEMISTRY STUDIES)
    18720-3 (COAGULATION STUDIES)
    18721-1 (THERAPEUTIC DRUG MONITORING STUDIES)
    18722-9 (FERTILITY STUDIES)
    18723-7 (HEMATOLOGY STUDIES)
    18724-5 (HLA STUDIES)
    18725-2 (MICROBIOLOGY STUDIES)
    18727-8 (SEROLOGY STUDIES)
    18728-6 (TOXICOLOGY STUDIES)
    18729-4 (URINALYSIS STUDIES)
    18767-4 (BLOOD GAS STUDIES)
    18768-2 (CELL COUNTS+DIFFERENTIAL STUDIES)
    18769-0 (MICROBIAL SUSCEPTIBILITY TESTS)
    26435-8 (MOLECULAR PATHOLOGY STUDIES)
    26436-6 (LABORATORY STUDIES)
    26437-4 (CHEMISTRY CHALLENGE STUDIES)
    26438-2 (CYTOLOGY STUDIES)

    18767-4	Blood gas studies (set)			
    18719-5	Chemistry studies (set)			
    18723-7	Hematology studies (set)			
    18720-3	Coagulation studies (set)			
    18728-6	Toxicology studies (set)			
    18725-2	Microbiology studies (set)			
    56874-1	Serology and blood bank studies (set)			
    18729-4	Urinalysis studies (set)			
    56847-7	Calculated and derived values (set)			
    56846-9	Cardiac biomarkers (set)
    20662-3 Allergens tested for in Serum

    also
    MICRO PANEL.MICRO
    */

  /* Lipids, Acid base, Gastrointestinal function, Cardiac enzymes, Hormones, Vitamins, Tumor markers */

/*
  Negative (qualifier value) - 260385009:Negative (qualifier value)
  Detected (qualifier value) - 260373001:Detected (qualifier value)
  Not detected (qualifier value) - 260415000:Not detected (qualifier value)
  Positive (qualifier value) - 10828004:Positive (qualifier value)
  Worsening (qualifier value) - 230993007:Worsening (qualifier value)
  Improving (qualifier value) - 385633008:Improving (qualifier value)
  Translucent (qualifier value) - 300828005:Translucent (qualifier value)
  Brown color (qualifier value) - 371254008:Brown color (qualifier value)
  None (qualifier value) - 260413007:None (qualifier value)

  Cloudy urine (finding) 7766007
  Finding of bilirubin in urine (finding) 275778006
  Urine blood test = negative (finding) 167297006
  Urine ketone test = +++ (finding) 167287002
  Urine ketone test = trace (finding) 167288007
  Urine leukocyte test negative (finding) 394717006
  Urine nitrite negative (finding) 314138001
  Urine protein test = + (finding) 167275009
  Urine protein test = ++ (finding) 167276005
  Urine protein test = +++ (finding) 167277001
  Urine protein test negative (finding) 167273002
  Urine smell ammoniacal (finding) 167248002

  Stage 2 (qualifier value) - 258219007:Stage 2 (qualifier value)
  Stage 4 (qualifier value) - 258228008:Stage 4 (qualifier value)
  Stage 1 (qualifier value) - 258215001:Stage 1 (qualifier value)
  Stage 3 (qualifier value) - 258224005:Stage 3 (qualifier value)

*/
define("SUPPORTED_SNOMED_LABRESULT_CODES",
  [
    [ "snomed" => "Not detected (qualifier value)", "code" => "260415000", "display" => "Not detected"],
    [ "snomed" => "Detected (qualifier value)", "code" => "260373001", "display" => "Detected"],
    [ "snomed" => "Negative (qualifier value)", "code" => "260385009", "display" => "Negative"],
    [ "snomed" => "Positive (qualifier value)", "code" => "10828004", "display" => "Positive"],
    [ "snomed" => "Urine glucose test = ++ (finding)", "code" => "167265006", "display" => "Urine glucose test = ++"],
    [ "snomed" => "Worsening (qualifier value)", "code" => "230993007", "display" => "Worsening"],
    [ "snomed" => "Improving (qualifier value)", "code" => "385633008", "display" => "Improving"],
    [ "snomed" => "Translucent (qualifier value)", "code" => "300828005", "display" => "Translucent"],
    [ "snomed" => "Brown color (qualifier value)", "code" => "371254008", "display" => "Brown color"],
    [ "snomed" => "None (qualifier value)", "code" => "260413007", "display" => "None"],
    [ "snomed" => "Cloudy urine (finding)", "code" => "7766007", "display" => "Cloudy urine"],
    [ "snomed" => "Finding of bilirubin in urine (finding)", "code" => "275778006", "display" => "Finding of bilirubin in urine"],
    [ "snomed" => "Urine blood test = negative (finding)", "code" => "167297006", "display" => "Urine blood test = negative"],
    [ "snomed" => "Urine ketone test = +++ (finding)", "code" => "167287002", "display" => "Urine ketone test negative"],
    [ "snomed" => "Urine ketone test = trace (finding)", "code" => "167288007", "display" => "Urine ketone test = trace"],
    [ "snomed" => "Urine leukocyte test negative (finding)", "code" => "394717006", "display" => "Urine leukocyte test negative"],
    [ "snomed" => "Urine nitrite negative (finding)", "code" => "314138001", "display" => "Urine nitrite negative"],
    [ "snomed" => "Urine protein test = + (finding)", "code" => "167275009", "display" => "Urine protein test = +"],
    [ "snomed" => "Urine protein test = ++ (finding)", "code" => "167276005", "display" => "Urine protein test = ++"],
    [ "snomed" => "Urine protein test = +++ (finding)", "code" => "167277001", "display" => "Urine protein test = +++"],
    [ "snomed" => "Urine protein test negative (finding)", "code" => "167273002", "display" => "Urine protein test negative"],
    [ "snomed" => "Urine smell ammoniacal (finding)", "code" => "167248002", "display" => "Urine smell ammoniacal"],
    [ "snomed" => "Mucus in urine (finding)", "code" => "276409005", "display" => "Mucus in urine"],
    [ "snomed" => "Urine blood test = + (finding)", "code" => "167300001", "display" => "Urine blood test = +"],
    [ "snomed" => "Urine ketone test negative (finding)", "code" => "167287002", "display" => "Urine ketone test negative"],
    [ "snomed" => "Urine leukocyte test = + (finding)", "code" => "394712000", "display" => "Urine leukocyte test"],
    [ "snomed" => "Urine microscopy: no casts (finding)", "code" => "314137006", "display" => "Urine microscopy: no casts"],
    [ "snomed" => "Urine nitrite positive (finding)", "code" => "314137006", "display" => "Urine nitrite positive"],
    [ "snomed" => "Urine ketone test = + (finding)", "code" => "167289004", "display" => "Urine ketone test = +"],
    [ "snomed" => "Urine ketone test = ++ (finding)", "code" => "167290008", "display" => "Urine ketone test = ++"],
    [ "snomed" => "Urine ketone test = trace (finding)", "code" => "167288007", "display" => "Urine ketone test = ++"],
    [ "snomed" => "Finding of presence of bacteria", "code" => "365691004", "display" => "Finding of presence of bacteria"],
    [ "snomed" => "Finding of presence of bacteria (finding)", "code" => "365691004", "display" => "Finding of presence of bacteria"],
    /*[ "snomed" => "Greater than 100 000 colony forming units per mL Klebsiella pneumoniae", "code" => "????", "display" => "????"],*/
    [ "snomed" => "No growth (qualifier value)", "code" => "264868006", "display" => "No growth"],

    [ "snomed" => "Foul smelling urine (finding)", "code" => "690701000119101", "display" => "Foul smelling urine"],
    [ "snomed" => "Blood in urine (finding)", "code" => "34436003", "display" => "Blood in urine"],

    [ "snomed" => "xxxx", "code" => "xxx", "display" => "xxx"],
]);

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