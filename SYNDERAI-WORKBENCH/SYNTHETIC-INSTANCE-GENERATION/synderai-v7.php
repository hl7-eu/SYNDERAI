<?php

/**
 * SynderAI v7 — Synthetic Patient Data Generator
 *
 * This is the main entry-point script for the SynderAI platform. It generates
 * synthetic, standards-compliant healthcare example files (FHIR Shorthand/FSH)
 * for one or more European health document artifact types from a pool of
 * pre-generated synthetic patients and clinical stories.
 *
 * ============================================================================
 * OVERVIEW
 * ============================================================================
 *
 * The script runs in three sequential phases:
 *
 *   Phase 0 — Setup & pre-compilation
 *     - Parse CLI options and apply run-time overrides
 *     - Load the patient demographics list (up to 25k+ entries)
 *     - Randomly downsample to $SELCOUNT patients (default 50)
 *     - Load mapping tables, encounter filters, and reference data
 *     - Optionally import manually crafted ISH files as additional HDR patients
 *     - Initialise the statistics output file
 *
 *   Phase I — Per-patient FSH generation
 *     For each patient in the list:
 *     a. Match a stratified clinical story (by age ±4 yr and gender)
 *        from the 25k+ clinical-candidates pool, or replay a stored match
 *        when --rerun is used.
 *     b. Conditionally load clinical data components (conditions, medications,
 *        immunisations, procedures, allergies, care plans, vital signs,
 *        pregnancies, lab observations, devices, inpatient encounters).
 *     c. Amalgamate consecutive inpatient encounters into hospital stays and
 *        synthesise ISH discharge definitions for HDR artifacts.
 *     d. Locate the geographically nearest primary-care facility and hospital.
 *     e. Call emitFSH() for every requested artifact type and write FSH files
 *        to the output directory.
 *     f. Append a statistics record to the CSV stats file.
 *
 *   Phase II — Post-processing
 *     For each artifact type with a corresponding synderai-make-fhir.sh <ARTIFACT> 
 *     shell script, convert the emitted FSH files to FHIR JSON/XML by running that
 *     script via liveExecuteCommand().
 *
 * ============================================================================
 * SUPPORTED ARTIFACT TYPES
 * ============================================================================
 *
 *   EPS  — European Patient Summary (IPS-compliant)
 *   LAB  — EU Laboratory Report (one FSH file per lab date)
 *   HDR  — Hospital Discharge Report
 *
 * ============================================================================
 * CLI OPTIONS
 * ============================================================================
 *
 *   --patient  <eci>         Process only the patient with this ECI.
 *                             May be repeated for multiple patients.
 *   --countries <cc>[,<cc>]  Comma-separated list of ISO 3166-1 alpha-2
 *                             country codes; restricts input to those countries.
 *   --count    <n>            Number of patients to process (default: 50).
 *   --artifacts <a>[,<a>]    Comma-separated artifact types to generate
 *                             (e.g. EPS,LAB,HDR). Required; script exits
 *                             without this option.
 *   --ish                    Also process manually crafted *.hdr.ish files
 *                             from the ish-input/ directory (HDR only).
 *   --rerun    <file>        Re-run using patient ECIs and story IDs from a
 *                             previously generated statistics CSV file.
 *                             Looks in the current directory first, then in
 *                             STATSDIR if not found.
 *   --help                   Shows this page
 *
 * ============================================================================
 * KEY INPUT FILES (defined via constants in constants.php)
 * ============================================================================
 *
 *   SYNTHETICDATA/25_tipster_202509_25k.csv
 *     Tab-separated synthetic patient demographics (up to 25 000 rows).
 *     Columns: language, given, family, gender, birthdate, age, eci,
 *              countrycode, street1, street2, street3, city, postcode,
 *              country, phone, latitude, longitude
 *
 *   SYNTHETICDATA/25_tipster_clinicalcandidates25k.csv
 *     Comma-separated clinical story candidates used for stratified matching.
 *     Col 0: candidate ID, Col 1: age, Col 2: gender (M/F)
 *
 *   SYNTHEADIR/<resource>.csv
 *     Synthea-generated clinical CSV files:
 *     encounters, conditions, procedures, immunizations, medications,
 *     allergies, supplies, careplans, imaging_studies, devices
 *
 *   ish-input/*.hdr.ish  (optional, requires --ish flag)
 *     Manually crafted HDR ISH definition files.
 *
 * ============================================================================
 * KEY OUTPUT FILES
 * ============================================================================
 *
 *   FSHOUTPUTDIR/<ARTIFACT>/__<eci>-<type>-example[-n-date].fsh
 *     FHIR Shorthand source files, one per patient per artifact (LAB: one per
 *     lab result date).
 *
 *   STATSDIR/STATSFILE-<YYYYMMDD-HHmm>.csv
 *     Per-run statistics file. Columns:
 *     gender;age;country;eci;storyid;lat;long;name;<ARTIFACT1>;<ARTIFACT2>;...
 *
 * ============================================================================
 * COMPONENT MAP  ($COMPONENTS)
 * ============================================================================
 *
 * Controls which data getters are loaded per artifact. Populated before
 * Phase I and consumed by includeConditionally() to avoid loading unused data.
 *
 *   EPS: encounters, rxnormsct, cvxsct, conditions, medications, immunizations,
 *        procedures, allergiesintolerances, careplans, vitalsigns, pregnancies,
 *        devices, recentlabresults, lnsctspecimen, inpatientencounters
 *
 *   LAB: labresults, lnsctspecimen, annotations, conditions
 *
 *   HDR: conditions, procedures, medications, rxnormsct, recentlabresults,
 *        lnsctspecimen, encounters, inpatientencounters
 *
 * ============================================================================
 * GLOBAL VARIABLES
 * ============================================================================
 *
 *   $STARTTIMER                int     Unix timestamp at script start (for elapsed-time logging)
 *   $SELPATIENT                array   ECIs to process; empty = no filter
 *   $SELCOUNT                  int     Max patients to process (default: 50)
 *   $SELCOUNTRIES              array|null  Country codes to filter by; NULL = all
 *   $ARTIFACTS                 array|null  Artifact types requested on this run
 *   $PROCESSISH                bool    TRUE if --ish was passed
 *   $SELRERUN                  string  Path to stats file for --rerun
 *   $ISARERUN                  bool    TRUE when operating in re-run mode
 *   $STORYFOR                  array   Maps ECI => storyid for re-runs
 *   $COMPONENTS                array   Artifact → required component names map
 *   $PATIENTS                  array   Working patient list
 *   $SYNTHETICPROVIDERS        array   Loaded by getters/providers.php
 *   $CLINICALPROCEDUREENCOUNTERS array Loaded by getters/encounters.php
 *   $INPATIENTENCOUNTERS       array   Loaded by getters/encounters.php
 *   $THESTATSFILE              string  Path to the current run's stats CSV
 *   $TOTALEXAMPLEFILES         int     Running total of FSH files emitted
 *
 * ============================================================================
 * FUNCTION INDEX
 * ============================================================================
 *
 *   emitFSH($pdat, $thisartifact)      — generate FSH for one patient/artifact
 *   liveExecuteCommand($cmd)           — run a shell command with live output
 *   parseCLiOptions($shortOpts, $longOpts) — parse & validate CLI arguments
 *
 *
 * ============================================================================
 * EXTERNAL DEPENDENCIES
 * ============================================================================
 *
 *   constants.php          — all path, OID, and configuration constants
 *   lib/common-utils.php   — string helpers, logging, UUID, country normalisation
 *   lib/ai-utils.php       — OpenAI/Claude AI generation functions
 *   lib/proximity-utils.php — nearest-provider geospatial search
 *   lib/eci.php            — European Citizen Identifier generation
 *   lib/cache.php          — file-based key/value cache
 *   lib/snomed.php         — SNOMED CT concept property lookup
 *   lib/loinc.php          — LOINC concept property lookup
 *   lib/ish-parser.php     — ISH file parser
 *   lib/clinical-story-matcher.php — clinical story pre-selection helpers
 *   twig/mstwiggy.php      — Twig template engine interface (twigit())
 *   config.php             — run-time configuration (AI keys, paths, flags)
 *   getters/*.php          — per-component data loaders (conditions, meds, etc.)
 *   sections/*.php         — per-section FSH/HTML assemblers
 */


// ============================================================================
// INITIALISATION
// ============================================================================

/** Raise PHP memory limit to accommodate large CSV datasets and Twig caches. */
ini_set('memory_limit', '10G');

/** Set timezone explicitly to avoid ambiguous date/time offsets in output. */
date_default_timezone_set('Europe/Berlin');

/* CONSTANTS */
include_once("../CONSTANTS/constants.php");

/* GENERAL SYNDERAI INCLUDES */
include_once("lib/common-utils.php");
include_once("lib/ai-utils.php");
include_once("lib/proximity-utils.php");
include_once("lib/eci.php");
include_once("lib/cache.php");
include_once("lib/snomed.php");
include_once("lib/loinc.php");
include_once("lib/atc.php");
include_once("lib/ish-parser.php");
include_once("lib/clinical-story-matcher.php");

/* TWIG parts ( new style FSH and HTML Generation ) */
include_once("twig/mstwiggy.php");

/** Record script start time for elapsed-time logging via logmeterinit(). */
$STARTTIMER = time();

// ============================================================================
// CLI OPTION DEFAULTS
// ============================================================================

/** @var array $SELPATIENT  ECIs to restrict processing to. Empty = no filter. */
$SELPATIENT = array();

/** @var int $SELCOUNT  Maximum number of patients to process in this run. */
$SELCOUNT = 50;

/** @var array|null $SELCOUNTRIES  ISO 3166-1 alpha-2 country codes to filter
 *  by, or NULL meaning all countries are accepted. */
$SELCOUNTRIES = NULL;

/** @var array|null $ARTIFACTS  Artifact type codes requested for this run
 *  (e.g. ['EPS','LAB']). NULL until populated by --artifacts; script exits
 *  immediately if it remains NULL after CLI parsing. */
$ARTIFACTS = NULL;

/** @var bool $PROCESSISH  When TRUE, manually crafted *.hdr.ish files in
 *  ish-input/ are parsed and their patients prepended to the patient list.
 *  Only effective when HDR is in $ARTIFACTS. */
$PROCESSISH = FALSE;

/** @var string $SELRERUN  Resolved file path of the statistics file to replay,
 *  or an empty string when --rerun was not supplied. */
$SELRERUN = "";

/** @var bool $ISARERUN  TRUE when operating in re-run mode (--rerun used and
 *  the statistics file was successfully loaded). */
$ISARERUN = FALSE;

/** @var array $STORYFOR  Maps ECI (string) => storyid (string) for re-runs,
 *  populated from the statistics CSV when $ISARERUN is TRUE. */
$STORYFOR = array();

/* Announce the script version */
lognl(1, "SYNDERAI 7.0 as of 2026-03");


// ============================================================================
// CLI PARSING
// Supported long options:
//   --patient   <eci>          ECI of a specific patient to process
//   --countries <cc>[,<cc>]    Comma-separated country codes
//   --count     <n>            Number of patients (integer)
//   --artifacts <a>[,<a>]      Comma-separated artifact types (EPS, LAB, HDR)
//   --ish                      Enable ISH file import (flag, no value)
//   --rerun     <file>         Path to statistics file for a re-run
//   --help                     Help page
// ============================================================================

$longOpts = array(
    "patient:",     // required value: ECI of a specific patient
    "countries:",   // required value: comma-separated country codes
    "count:",       // required value: integer patient count
    "artifacts:",   // required value: comma-separated artifact type codes
    "ish",          // flag: also process manually crafted HDR ISH files
    "rerun:",       // required value: path to statistics file for a re-run
    "help",         // flag: show help page and exit
);

try {
    $options = parseCLiOptions("", $longOpts);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} catch (\RuntimeException $e) {
    fwrite(STDERR, 'Fatal: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

foreach ($options as $opt => $val) {

    if ($opt === 'patient') {
        // Accept one or more --patient options; each adds one ECI to the filter list
        if (isset($val) && !empty($val))
            $SELPATIENT[] = $val;

    } elseif ($opt === 'count') {
        // Override the default patient count; value must be a valid integer
        // and must be ge zero
        if (isset($val) && is_int(0 + $val))
            $SELCOUNT = $val < 0 ? 0 : $val;

    } elseif ($opt === 'countries') {
        // Parse a comma-separated list of country codes; normalise to upper case
        if (isset($val) && !empty($val)) {
            $clist = explode(',', $val);
            if (is_array($clist)) {
                $SELCOUNTRIES = array();
                foreach ($clist as $c)
                    $SELCOUNTRIES[] = strtoupper(trim($c));
            }
        }

    } elseif ($opt === 'artifacts') {
        // Parse a comma-separated list of artifact types; normalise to upper case
        if (isset($val) && !empty($val)) {
            $alist = explode(',', $val);
            if (is_array($alist)) {
                $ARTIFACTS = array();
                foreach ($alist as $a)
                    $ARTIFACTS[] = strtoupper(trim($a));
            }
        }

    } elseif ($opt === 'ish') {
        $PROCESSISH = TRUE;

    } elseif ($opt === 'rerun') {
        // Resolve the statistics file path: check current directory first,
        // then STATSDIR. Load all ECI/storyid pairs if the file is found.
        if (isset($val) && !empty($val)) {
            $SELRERUN = $val;
            $SELRERUN = (is_file($SELRERUN))
                ? $SELRERUN
                : ((is_file(STATSDIR . $SELRERUN)) ? (STATSDIR . $SELRERUN) : $SELRERUN);

            if (is_file($SELRERUN)) {
                $ISARERUN = TRUE;
                $patienthandle = fopen($SELRERUN, "r");
                while (($csvline = fgetcsv($patienthandle, NULL, ";", '"', '\\')) !== FALSE) {
                    if (count($csvline) === 0 or startsWith($csvline[0], "#")) continue;
                    if (count($csvline) < 9)
                        lognlsev (2, WARNING, "... re-run file $SELRERUN is malformed.");
                    // var_dump($csvline);
                    $ceci = $csvline[3];  // ECI is in column 3
                    $stid = $csvline[4];  // story ID is in column 4
                    // Only import rows whose ECI matches the expected format (XXXX-XXXXXX-D)
                    if (preg_match('/^\d{4}-\d{6}-\d$/', $ceci)) {
                        $SELPATIENT[]    = $ceci;
                        $STORYFOR[$ceci] = $stid;
                    }
                }
                if (count($STORYFOR) === 0) {
                    lognlsev(1, ERROR, "... +++ statistics file $SELRERUN seems to be empty, running with defaults!\n");
                    $ISARERUN = FALSE;
                }
            } else {
                lognlsev(1, ERROR, "... +++ statistics file $SELRERUN not found, running with defaults!\n");
                $ISARERUN = FALSE;
            }
        }
    } elseif ($opt === 'help') {
        echo <<<HELP
SYNDERAI command line options
    --patient  <eci>         Process only the patient with this ECI.
                              May be repeated for multiple patients.
    --countries <cc>[,<cc>]  Comma-separated list of ISO 3166-1 alpha-2
                              country codes; restricts input to those countries.
    --count    <n>            Number of patients to process (default: 50).
    --artifacts <a>[,<a>]    Comma-separated artifact types to generate
                              (e.g. EPS,LAB,HDR). Required; script exits
                              without this option.
    --ish                    Also process manually crafted *.hdr.ish files
                              from the ish-input/ directory (HDR only).
    --rerun    <file>        Re-run using patient ECIs and story IDs from a
                              previously generated statistics CSV file.
                              Looks in the current directory first, then in
                              STATSDIR if not found.
    --help                   Shows this page

HELP;
        exit;
    }
}

/* --artifacts is mandatory — exit immediately if not provided */
if ($ARTIFACTS === NULL) {
    lognlsev(1, ERROR, "... +++ No artifacts to generate specified (I'm ready!)\n");
    lognlsev(1, ERROR, "... +++ Use CLI --artifacts to specify one or more artifacts to process...\n");
    exit;
}

// ============================================================================
// COMPONENT MAP
// Maps each artifact type to the list of data components it requires.
// Used by includeConditionally() to load only the getters that are needed.
// ============================================================================

/** @var array $COMPONENTS  Artifact type => list of required component names. */
$COMPONENTS["EPS"] = [
    'encounters', 'rxnormsct', 'cvxsct', 'conditions', 'medications',
    'immunizations', 'procedures', 'allergiesintolerances', 'careplans',
    'vitalsigns', 'pregnancies', 'devices', 'recentlabresults',
    'lnsctspecimen', 'inpatientencounters'
];
$COMPONENTS["LAB"] = [
    'labresults', 'lnsctspecimen', 'annotations', 'conditions'
];
$COMPONENTS["HDR"] = [
    'conditions', 'procedures', 'medications', 'rxnormsct', 'vitalsigns',
    'recentlabresults', 'lnsctspecimen', 'encounters', 'inpatientencounters'
];

$targetpats = count($SELPATIENT) > 0 ? count($SELPATIENT) : $SELCOUNT;
lognl(1, "*** This run is for " . implode(", ", $ARTIFACTS) . " targeting $targetpats patient(s).");

if (USE_AI) {
    lognl(1, "*** This run uses AI for selected areas.");
}


// ============================================================================
// PHASE 0 — SETUP, TESTING AND PRE-COMPILATION
// ============================================================================

lognl(1, "*** Phase 0: Testing, collecting and pre-compiling meta data");

/*
 * Load runtime configuration (API keys, directory paths, feature flags).
 * Also performs additional internal checks.
 */
include_once("config.php");

/*
 * Verify that the OpenAI API is reachable if AI enrichment is enabled.
 * If unavailable, generation continues without AI calls.
 */
if (!testAIavailability() and USE_AI) {
    lognlsev(1, WARNING, "... +++ AI desired but not available, skipping all AI calls\n");
}

/*
 * Validate that all required Synthea CSV source files are present.
 * The script halts with an error if any file is missing.
 */
lognl(1, "... check base data\n");
$DESIREDFILES = [
    'encounters', 'conditions', 'procedures', 'immunizations',
    'medications', 'allergies', 'supplies', 'careplans',
    'imaging_studies', 'devices'
];
foreach ($DESIREDFILES as $f) {
    if (!is_file(SYNTHEADIR . "/$f.csv")) {
        lognlsev(1, ERROR, "+++ No $f.csv in " . SYNTHEADIR . "\n");
        exit;
    }
}

/*
 * ============================================================
 * TEST AREA — guarded by if(FALSE) so it never runs in production.
 * Change to if(TRUE) to invoke a live AI hospital course test.
 * WARNING: contains var_dump/exit — will halt the script.
 * ============================================================
 */
if (FALSE) {
    $x = [
        "encounters" => [
            [
                "reason"    => ["code" => "68496003", "display" => "Polyp of colon"],
                "discharge" => ["text" => "Colonic polyp, removed endoscopically", "code" => "K63.5", "display" => "Polyp of colon"]
            ]
        ]
    ];
    var_dump(getAIHospitalCourse(46, "male", $x, TRUE));
    exit;
} // END TEST AREA


// ============================================================================
// PATIENT LIST LOADING
// Read the 25k synthetic patient demographics CSV and apply any pre-selection
// filters (--patient, --countries) to build the working patient list.
//
// CSV columns (tab-separated):
//   language, given, family, gender, birthdate, age, eci, countrycode,
//   street1, street2, street3, city, postcode, country, phone, latitude, longitude
// ============================================================================

$patienthandle = fopen(SYNTHETICDATA . "/25_tipster_202509_25k.csv", "r");
$row           = 0;
$PATIENTS      = array();
$arnames       = array();
$preselectionineffect = count($SELPATIENT) > 0 or $SELCOUNTRIES !== NULL;

while (($csvline = fgetcsv($patienthandle, NULL, "\t", '"', '\\')) !== FALSE) {
    $row++;
    $candiate = NULL;

    if ($row === 1) {
        // First row: capture column names to build associative arrays later
        for ($c = 0; $c < count($csvline); $c++)
            $arnames[] = $csvline[$c];
    } else {
        // Apply pre-selection filters when active
        if ($preselectionineffect) {
            if (count($SELPATIENT) > 0) {
                // Filter by specific ECI(s) from --patient or --rerun
                $thiseci = $csvline[6];
                if (in_array($thiseci, $SELPATIENT))
                    $candiate = array_combine($arnames, $csvline);
            } elseif ($SELCOUNTRIES) {
                // Filter by country code(s) from --countries
                $thiscountry = $csvline[7];
                if (in_array($thiscountry, $SELCOUNTRIES))
                    $candiate = array_combine($arnames, $csvline);
            } else {
                $candiate = array_combine($arnames, $csvline);
            }
        } else {
            // No pre-selection: accept all rows
            $candiate = array_combine($arnames, $csvline);
        }
    }

    if ($candiate) {
        $candiate["preselected"]["inpatient"] = FALSE;  // default: not required to be an inpatient
        $candiate["given"] = [$candiate["given"]];      // correct given to be an array
        $PATIENTS[] = $candiate;
    }
}
fclose($patienthandle);
lognlsev(1, SUCCESS, "... patient list" . ($preselectionineffect ? " (pre-selections in effect): " : ": ") . count($PATIENTS) . " found");


// ============================================================================
// RANDOM DOWNSAMPLING
// Randomly delete patients from the list until exactly $SELCOUNT remain.
// Skipped when specific patients were requested (--patient / --rerun).
// ============================================================================

if ($SELCOUNT == 0) {
    // no patients (maybe only ish processing)
    $PATIENTS = array();
} else {
    $todelete = count($PATIENTS) - $SELCOUNT;
    while ($todelete > 0) {
        $rix = rand(0, count($PATIENTS) - 1);
        if (isset($PATIENTS[$rix])) {
            array_splice($PATIENTS, $rix, 1);
            $todelete--;
        }
    }
}

lognlsev(1, SUCCESS, "... # of selected patients for this run: " . count($PATIENTS));


// ============================================================================
// PRE-REQUISITE DATA LOADING
// Unconditional and conditional getter includes.
// Conditional includes use includeConditionally() to check whether the
// component is needed by at least one of the requested $ARTIFACTS.
// ============================================================================

/** Loads $SYNTHETICPROVIDERS array — always required for provider lookups. */
include("getters/providers.php");

/** Loads vital sign codes in order to place correct * category[VSCat].coding = $observation-category#vital-signs in LAB */
include("getters/vitalsignscodes.php");

/** RXNORM → SNOMED CT mapping table (needed for EPS and HDR). */
if (includeConditionally("rxnormsct")) include("getters/rxnormsct.php");

/** CVX → SNOMED CT mapping table (needed for EPS immunizations). */
if (includeConditionally("cvxsct")) include("getters/cvxsct.php");

/** LOINC → SNOMED CT specimen mapping table (needed for LAB, EPS, HDR). */
if (includeConditionally("lnsctspecimen")) include("getters/lnsctspecimen.php");

/**
 * Encounter class filters (needed by EPS and HDR).
 * Populates:
 *   $CLINICALPROCEDUREENCOUNTERS — clinical encounters used to filter procedures
 *   $INPATIENTENCOUNTERS         — inpatient encounters with valid admission/discharge info
 */
$CLINICALPROCEDUREENCOUNTERS = array();
$INPATIENTENCOUNTERS         = array();
if (includeConditionally("encounters")) include("getters/encounters.php");


// ============================================================================
// STATISTICS FILE INITIALISATION
// Creates the output statistics CSV with a timestamped filename.
// Header format: gender;age;country;eci;storyid;lat;long;name;<ART1>;<ART2>;...
// ============================================================================

if (!(is_dir(STATSDIR))) mkdir(STATSDIR);
$THESTATSFILE = STATSDIR . STATSFILE . "-" . date("Ymd-Hi") . ".csv";
$firststat    = "gender;age;country;eci;storyid;lat;long;name;";
foreach ($ARTIFACTS as $a) $firststat .= "$a;";
$firststat    = before_last(";", $firststat) . "\n";
file_put_contents($THESTATSFILE, $firststat);
lognlsev(1, SUCCESS, "... recordings/statistics to file $THESTATSFILE");


// ============================================================================
// ISH FILE IMPORT (optional — requires --ish and HDR in $ARTIFACTS)
// Parses all *.hdr.ish files in ish-input/ and adds their patients to the
// end of the patient list. Each ISH patient receives a freshly generated ECI.
// ============================================================================

if (in_array("HDR", $ARTIFACTS) && $PROCESSISH) {

    lognl(1, "... Reading/importing and parsing *.hdr.ish files");

    $count = 0;
    foreach (glob("ish-input/*.hdr.ish") as $hdrfile) {
        lognl(1, "...... file $hdrfile");
        $ish = parse_ish_file($hdrfile);
        // var_dump($ish);exit;

        /*
         * check ISH data completeness
         * Patient must have gender (male|female), given, family, birthdate, longitude and latitude
         */
        // var_dump($ish["section"]);exit;
        $isherror = FALSE;  // assume all is ok for now
        if (!(
            isset($ish["patient"]["given"]) &&
            is_array($ish["patient"]["given"]) && 
            strlen(implode(" ", $ish["patient"]["given"])) > 0
        )) $isherror = "no given";
        if (!(
            isset($ish["patient"]["family"]) &&
            strlen($ish["patient"]["family"]) > 0
        )) $isherror = "no family";
        if (!(
            isset($ish["patient"]["gender"])
        )) $isherror = "no gender";
        else {
            if (!(
                $ish["patient"]["gender"] === "male" or $ish["patient"]["gender"] === "female"
            )) $isherror = "no good gender code";
        }
        if (!(
            isset($ish["patient"]["country"]) && 
            strlen($ish["patient"]["country"]) > 0
        )) $isherror = "no country";
        if (!(
            isset($ish["patient"]["birthdate"]) && 
            validateYmd($ish["patient"]["birthdate"])
        )) $isherror = "no or incorrect birthdate";
        if (!(
            isset($ish["patient"]["latitude"]) &&
            preg_match('/^\d+\.\d+$/', $ish["patient"]["latitude"])
        )) $isherror = "no or incorrect latitude";
        if (!(
            isset($ish["patient"]["longitude"]) &&
            preg_match('/^\d+\.\d+$/', $ish["patient"]["longitude"])
        )) $isherror = "no or incorrect longitude";
        if (!(
            isset($ish["encounter"]["procedure"]) && 
            is_array($ish["encounter"]["procedure"]) && 
            count($ish["encounter"]["procedure"]) > 0
        )) $isherror = "no encounter procedure";
        if (!(
            isset($ish["encounter"]["reason"]) && 
            is_array($ish["encounter"]["reason"]) && 
            count($ish["encounter"]["reason"]) > 0
        )) $isherror = "no encounter reason";
        // get all conditions as preselection criteria
        $hdrconditions = NULL;
        if (isset($ish["section"])) {
            foreach ($ish["section"] as $s) {
                if (!(
                    isset($s["type"]) && 
                    strlen($s["type"]) > 0
                )) $isherror = "section with no type";
                if (isset($s["entry"])) {
                    foreach ($s["entry"] as $e) {
                        if (!(
                            isset($e["type"]) && 
                            strlen($e["type"]) > 0
                        )) $isherror = "entry with no type";
                        if (isset($e["type"]) && $e["type"] === "condition") {
                            // var_dump($e["code"]);
                            $hdrconditions[] = $e["code"][0]["code"];  // store SNOMED code of condition form later preselect
                        }
                    }
                }
            }
        }
        
        if ($isherror === FALSE) {
            // store ISH patient and data
            $hdrpatient                    = $ish["patient"]; // assign read-only shortcut again, for simpler expressions
            // complete name
            $ish["patient"]["name"]        = implode(" ", $hdrpatient["given"]) . " " . $hdrpatient["family"];
            // calculate current age from birthdate (31 556 926 seconds per Julian year)
            $ish["patient"]["age"]         = floor((time() - strtotime($hdrpatient["birthdate"])) / 31556926);
            $ish["patient"]["instanceid"]  = uuid();
            // ISH patients do not have a pre-existing ECI, assign one based on md5 of some data
            $md5 = md5($ish["patient"]["name"] . $ish["patient"]["birthdate"]);
            $ish["patient"]["eci"]         = generateECImd5($md5); 
            // set preselected array
            $ish["patient"]["preselected"] = [
                "inpatient" => TRUE,
                "diagnosis" => $hdrconditions
            ];
            $hdrpatient                    = $ish["patient"];  // reassign shortcut again
            // is there already a hospital in ISH? If not, find an appropriate one
            if (!isset($ish["hospital"])) {
                lognl(2, "......... Finding a close-by provider (hospital) for " . 
                    $hdrpatient["name"] . ", " . $ish["patient"]["country"] . ", " .
                    " latitude: " . $ish["patient"]["latitude"] .
                    " longitude: " . $ish["patient"]["longitude"] . "...");
                $hdrhospital = getClosestProvider(
                    $ish["patient"]["country"],
                    $ish["patient"]["latitude"],
                    $ish["patient"]["longitude"], 
                    "hospitals");
                lognl(2, "............ closest hospital: " . $hdrhospital['name'] . " in " .
                    $hdrhospital['postcode'] . " " . $hdrhospital['city'] .
                    " (" . round($hdrhospital['distance']) . " km away)");
                if (!(isset($ish["practitioner"]) or isset($ish["provider"]))) {
                    // for the newly found hospital there is obviously no practitioner/provider in ISH, 
                    // use the one found in hospital which is already present in $hdrhospital
                } else {
                    // otherwise ISH specified a practitioner/provider, use it
                    $hdrhospital["practitioner"] = 
                        isset($ish["provider"]) ? $ish["provider"] : 
                            (isset($ish["practitioner"]) ? $ish["practitioner"] : $ish["practitioner"]);
                }
                lognl(2, "............ practitioner/provider: " . 
                    implode(" ", $hdrhospital["practitioner"]["given"]) . " " .
                    $hdrhospital["practitioner"]["family"]);
                $ish["hospital"] = $hdrhospital;
            }
            // create the whole set and assign it to $PATIENTS, patient primary info is on root level
            $newpatient = $ish["patient"];
            foreach ($ish as $ikey => $iary) {
                if ($ikey !== "patient") $newpatient = array_merge($newpatient, [ $ikey => $iary ]);
            }
            $PATIENTS[] = $newpatient;
            $count++;
        } else {
            lognlsev(1, ERROR, "... Processing ish file $hdrfile throws errors ($isherror), skipping...");
        }

    }

    lognl(1, "...... $count ish file(s) are added to the set of patients of this run.");

}


// ============================================================================
// CONVERT PATIENT LIST TO OBJECT ARRAY
// json_encode → json_decode converts the plain associative arrays produced by
// fgetcsv() into stdClass objects, enabling property-style access ($pdat->eci)
// throughout the rest of the script.
// ============================================================================

$PATIENTS = json_decode(json_encode($PATIENTS));
if (json_last_error() !== JSON_ERROR_NONE) {
    lognlsev(2, ERROR, "............ JSON encode+decode patient failed: " . json_last_error_msg());
}
// var_dump($PATIENTS[0]);exit;


// ============================================================================
// OPEN CLINICAL CANDIDATES FILE
// Remains open for the entire Phase I loop; rewound at the start of each
// candidate-search iteration.
// ============================================================================

$clinicalcandidateshandle = fopen(SYNTHETICDATA . "/25_tipster_clinicalcandidates25k.csv", "r");


// ============================================================================
// EMPTY ARTIFACT OUTPUT DIRECTORIES
// Clear previously generated FSH files before the new run so stale examples
// are not mixed with freshly generated ones.
// ============================================================================

lognl(1, "... Emptying artifact result directories");
foreach ($ARTIFACTS as $a) {
    $fn = FSHOUTPUTDIR . "/$a/__*";
    foreach (glob($fn) as $f) unlink($f);
    lognl(1, "...... $a now empty");
}


// ============================================================================
// PHASE I — PER-PATIENT FSH GENERATION
// Iterate over every patient in the working list, find a matching clinical
// story, collect clinical data, and emit FSH example files.
// ============================================================================

if ($PROCESSISH) {
    lognl(1, "*** Phase I: Processing synthetic patient`s clinical story based on ISH, emitting FSH");
} else {
    lognl(1, "*** Phase I: Pairing clinical stories and demographics data using stratification,");
    lognl(1, "             Processing synthetic patient`s clinical stories, emitting FSH");
}

$round             = 0;
$TOTALEXAMPLEFILES = 0;

foreach ($PATIENTS as $pdat) {

    $age    = $pdat->age;
    $gender = $pdat->gender;

    /* Normalise country field to consistent [code, name] pair */
    list($pdat->country, $pdat->countryname) = unifyCountryCodeName($pdat->country);

    lognlsev(1, INFO, "... Processing example #" . ++$round . " '" .
        implode(" ", $pdat->given) . " " . $pdat->family . "' age $age yr, $pdat->gender, from " . $pdat->countryname .
        " (ECI: " . $pdat->eci . ")...\n");

    /*
     * HDR always requires an inpatient encounter.
     * Mark the patient's preselection accordingly so the clinical story
     * matcher will only return candidates that have inpatient encounters.
     */
    if (in_array("HDR", $ARTIFACTS)) {
        $pdat->preselected->inpatient = TRUE;
    }

    $findacandidaterounds = 10;  // maximum attempts to find a matching clinical story
    $matchcount           = 0;
    $pdat->match          = NULL;

    /*
     * For non-ISH patients: If patient has preselection criteria (e.g. required diagnoses,
     * medications, or inpatient status), first narrow the candidate pool.
     */
    if (!$PROCESSISH and isset($pdat->preselected)) {
        lognl(2, "...... Collecting candidates that matches pre-selection criteria\n");
        $matchingpreselectioncriteriacandidates = getClinicalStoryCandidatesWithMatchingPreselections($pdat->preselected);
    } else {
        $matchingpreselectioncriteriacandidates = NULL;  // no constraints; all candidates are eligible
    }

    // -------------------------------------------------------------------------
    // CLINICAL STORY MATCHING
    // Re-run mode: use the storyid stored in the statistics file.
    // ISH mode: the patient has all definition already defined by ISH
    // Normal mode: scan the candidates CSV for gender + age-band matches,
    //   optionally filtered by preselection criteria, and pick one at random.
    //
    // Matching criteria (normal mode):
    //   - Gender must match exactly.
    //   - Candidate age must be between (patient age + 1) and (patient age + 4).
    //   - If preselection criteria were specified, the candidate must satisfy all.
    // -------------------------------------------------------------------------
    if ($PROCESSISH) {
        lognl(2, "...... Using clinical story of ISH patient $pdat->name\n");
    } else if ($ISARERUN) {
        $eci           = $pdat->eci;
        $candid        = $STORYFOR[$eci];
        lognl(2, "...... Using stored clinical story candidate for this patient: $candid\n");
        $pdat->match   = $candid;
    } else {
        lognl(2, "...... Finding clinical story candidates that matches stratification criteria\n");
        while ($findacandidaterounds-- > 0) {
            $matches = array();
            rewind($clinicalcandidateshandle);
            while (($mdat = fgetcsv($clinicalcandidateshandle, 10000, ",", '"', '\\')) !== FALSE) {
                $mgender   = $mdat[2] === "M" ? "male" : "female";
                $agediff   = (int) $mdat[1] - (int) $age;
                // Accept candidates that are 1 to 4 years older than the patient
                $matchingagerange   = ($agediff > 0 and $agediff <= 4);
                $matchinggender     = ($mgender === $gender);
                $matchingpreselectioncriteria =
                    $matchingpreselectioncriteriacandidates === NULL
                    ? TRUE
                    : in_array($mdat[0], $matchingpreselectioncriteriacandidates);

                if ($matchingagerange and $matchinggender and $matchingpreselectioncriteria)
                    $matches[] = $mdat;
            }
            $matchcount = count($matches);
            if ($matchcount > 0) {
                $cand          = rand(0, $matchcount - 1);
                $candid        = $matches[$cand][0];
                $pdat->match   = $candid;

                if ($candid === NULL) { 
                    var_dump($matches); exit; 
                }  // should never happen

                $candidateage = $matches[$cand][1];
                lognl(1, "......... clinical story match #$cand out of $matchcount stories chosen: $candid ($candidateage yr)\n");
                $findacandidaterounds = -1;  // signal success; exit the retry loop
            }
        }
    }

    // -------------------------------------------------------------------------
    // CLINICAL DATA LOADING
    // get clinical data, from patient's clinical story candidate
    // or – if patient has already ISH defined items – from the ISH items.
    // Each getter is included only when required by the active artifact set.
    // Getters either read from the Synthea CSV files or from ISH data
    // and populate properties on $pdat.
    // -------------------------------------------------------------------------

    if (includeConditionally("conditions"))            include("getters/conditions.php");
    if (includeConditionally("procedures"))            include("getters/procedures.php");
    if (includeConditionally("inpatientencounters"))   include("getters/inpatientencounters.php");
    if (!$PROCESSISH) {
        // non-ISH patients must get these information from their clinical story candidate
        if (includeConditionally("medications"))           include("getters/medications.php");
        if (includeConditionally("immunizations"))         include("getters/immunizations.php");
        if (includeConditionally("allergiesintolerances")) include("getters/allergiesintolerances.php");
        if (includeConditionally("careplans"))             include("getters/careplans.php");
        if (includeConditionally("vitalsigns"))            include("getters/vitalsigns.php");
        if (includeConditionally("pregnancies"))           include("getters/pregnancies.php");
    }
    // var_dump($pdat->conditions);var_dump($pdat->procedures);exit;

    // -------------------------------------------------------------------------
    // HOSPITAL STAY ASSEMBLY (HDR prerequisite)
    // When the patient has inpatient encounters, merge consecutive encounter
    // periods into unified hospital stays.
    //
    // Two encounters are considered consecutive when the start of the second
    // equals the end of the first (e.g. a patient transferred between wards
    // on the same day produces two linked encounter records).
    //
    // Result: $pdat->hospitalstays — array of stay records, each containing:
    //   episodestart  — ISO date of first encounter in the cluster
    //   episodeend    — ISO date of last encounter in the cluster
    //   encounters    — array of individual encounter records in the cluster
    //   hospitalCourse — AI-generated narrative
    //
    // -------------------------------------------------------------------------
    if (isset($pdat->inpatientencounters) && ($pdat->inpatientencounters !== NULL)) {

        $maxix           = count($pdat->inpatientencounters) - 1;
        $stays           = array();
        $staycount       = 0;
        $thisencounterset = array();

        lognl(2, "......... Detecting consecutive encounters, creating stay period(s)");
        if ($maxix >= 0) {
            // start of the very first encounter
            $hxstart           = $pdat->inpatientencounters[0]["start"];
            $stays[$staycount] = ["episodestart" => $hxstart];

            for ($ii = 0; $ii <= $maxix; $ii++) {
                $hxend             = $pdat->inpatientencounters[$ii]["end"];
                $thisencounterset[] = $pdat->inpatientencounters[$ii];

                if ($ii < $maxix) {
                    // there is still a next encounter
                    if ($pdat->inpatientencounters[$ii + 1]["start"] === $hxend) {
                        // Consecutive encounter: continue building this stay cluster
                        // $hxnextstart = $pdat->inpatientencounters[$ii + 1]["start"];
                        // $hxnextend   = $pdat->inpatientencounters[$ii + 1]["end"];
                    } else {
                        // Gap detected: close the current stay and start a new one
                        $stays[$staycount] = array_merge(
                            $stays[$staycount],
                            ["episodeend" => $hxend, "encounters" => $thisencounterset]
                        );
                        $staycount++;
                        $stays[$staycount] = ["episodestart" => $pdat->inpatientencounters[$ii + 1]["start"]];
                        $thisencounterset  = array();
                    }
                }
            }
            // Close the final stay
            $stays[$staycount] = array_merge(
                $stays[$staycount],
                ["episodeend" => $hxend, "encounters" => $thisencounterset]
            );
        }

        // var_dump($stays);exit;/*üüüüü*/

        // Attach a short hospital course narrative to each stay - if not ish
        if (!$PROCESSISH) {
            lognl(2, "......... Getting hospital course and invented procedures per stay");
            for ($ii = 0; $ii <= count($stays) - 1; $ii++) {
                $md5 = md5(
                    $pdat->eci . 
                    $pdat->inpatientencounters[$ii]["procedure"]["code"] . 
                    $stays[$ii]["episodestart"] . 
                    $stays[$ii]["episodeend"]);
                $hcipai = inCACHE("hospitalcourse", "$md5.json");  // look for hospital course and invented procedures in cache first
                if ($hcipai !== FALSE) {
                    // hooray, in cache
                    $thishcipai = json_decode($hcipai, TRUE);
                    $stays[$ii] = array_merge($stays[$ii], $thishcipai);
                    // echo "-----$md5\n";var_dump($stays[$ii]);exit;
                } else {
                    $tmp = getAIHospitalCourse($pdat->age, $pdat->gender, $stays[$ii], TRUE);
                    // includeProcedures = TRUE|FALSE
                    if (200 === $tmp["code"]) {
                        /*
                        * now we have the hospital course text between %%TEXT%% tags and
                        * optional procedures between %%PROCEDURES%% - split them up
                        * example
                        * %%TEXT%%
                        * Dear Colleague, I am writing to inform you regarding the discharge of your patient, an 84-year-old male, ...
                        * %%TEXT%%
                        * %%PROCEDURES%%
                        * diagnostic|2026-03-16|Clinical knee examination with valgus stress testing|5880005|Physical examination procedure
                        * therapeutic|2026-03-16|Postoperative analgesia administration|18629005|Administration of drug or medicament
                        * %%PROCEDURES%%
                        */
                        $theText = trim(after("%%TEXT%%", before_last("%%TEXT%%", $tmp["text"])));
                        $theProcedureLines = trim(after("%%PROCEDURES%%", before_last("%%PROCEDURES%%", $tmp["text"])));
                        $theProcedures = array();
                        foreach (explode("\n", $theProcedureLines) as $line) {
                            $items = explode("|", $line);
                            // correct the codes if needed
                            $snomed = trim($items[3]);
                            $snomedproperties = get_SNOMED_properties($snomed, trim($items[4]));
                            if ($snomedproperties["code"] !== $snomed) $snomed = $snomedproperties["code"]; // this is a replacement
                            $snomeddisplay = strlen($snomedproperties["fullySpecifiedName"]) > 0 ? $snomedproperties["fullySpecifiedName"] : $snomeddisplay;
                            if (strlen($snomed) > 0)
                                $theProcedures[] = [
                                    "type" => trim($items[0]),
                                    "date" => trim($items[1]),
                                    "text" => trim($items[2]),
                                    "code" => [
                                        "code" => $snomed,
                                        "system" => "\$sct",
                                        "display" => $snomeddisplay,
                                        "preferredTerm" => $snomedproperties["preferredTerm"],
                                    ]
                                ];
                        }
                        $stays[$ii] = array_merge($stays[$ii], [
                            "hospitalCourse" => $theText,
                            "inventedProcedures" => $theProcedures
                        ]);
                        // var_dump($stays[$ii]);exit;
                        toCACHE("hospitalcourse", "$md5.json", json_encode([
                            "hospitalCourse" => $theText,
                            "inventedProcedures" => $theProcedures
                        ]));
                    } else {
                        $stays[$ii] = array_merge($stays[$ii], [
                            "hospitalCourse" => NULL,
                            "inventedProcedures" => NULL
                        ]);
                    }
                }
            }
        }

        // echo "------------------\n";var_dump($stays);/*üüüüü*/

        $pdat->hospitalstays = $stays;
    }

    // -------------------------------------------------------------------------
    // LAB OBSERVATION and DEVICES LOADING - if not an ISH patient
    // Uses ALLLABS mode to retrieve every lab result for EPS, LAB, and HDR.
    // -------------------------------------------------------------------------
    if (!$PROCESSISH) {
        $LABRESULTTYPE = "ALLLABS";
        if (includeConditionally("labresults") or includeConditionally("recentlabresults")) {
            include("getters/validlabcodes.php");
            include("getters/labobservations.php");
        }
        if (includeConditionally("devices")) include("getters/devices.php");
    }

    // -------------------------------------------------------------------------
    // MINIMUM DATA VALIDATION
    // Warn (but do not abort) when a patient lacks the minimum clinical data
    // required for a complete artifact of the requested type.
    // -------------------------------------------------------------------------
    if (in_array("EPS", $ARTIFACTS)) {
        if ($pdat->conditions === NULL)
                lognlsev(2, WARNING, "...... +++ Patient's clinical story candidate has missing conditions for a proper EPS, continuing anyway\n");
        if ($pdat->medications === NULL)
                lognlsev(2, WARNING, "...... +++ Patient's clinical story candidate has missing medications for a proper EPS, continuing anyway\n");
        if ($pdat->immunizations === NULL)
                lognlsev(2, WARNING, "...... +++ Patient's clinical story candidate has missing immunizations for a proper EPS, continuing anyway\n");
        if ($pdat->procedures === NULL)
                lognlsev(2, WARNING, "...... +++ Patient's clinical story candidate has missing procedures for a proper EPS, continuing anyway\n");
    }
    if (in_array("LAB", $ARTIFACTS)) {
        if ($pdat->labobservations === NULL)
                lognlsev(2, FATAL, "...... +++ Patient's clinical story candidate has missing lab observations for a proper LAB, refusing to continue\n");
    }
    if (in_array("HDR", $ARTIFACTS)) {
        if (!isset($pdat->conditions) or $pdat->conditions === NULL)
                lognlsev(2, WARNING, "...... +++ Patient's clinical story candidate has missing conditions for a proper HDR, continuing anyway\n");
        if (!isset($pdat->procedures) or $pdat->procedures === NULL)
                lognlsev(2, WARNING, "...... +++ Patient's clinical story candidate has missing procedures for a proper HDR, continuing anyway\n");
        if (!isset($pdat->inpatientencounters) or $pdat->inpatientencounters === NULL)
                lognlsev(2, FATAL, "...... +++ Patient's clinical story candidate has missing inpatient encounters for a proper HDR, refusing to continue\n");
    }
    
    if ($pdat->match === NULL)
        lognl(1, "+++ No match found or stored for age=$age and gender=$gender\n");

    // -------------------------------------------------------------------------
    // NEAREST PRIMARY-CARE PROVIDER LOOKUP
    // Find the geographically closest primary-care facility to the patient.
    // Result stored in $pdat->provider; may be NULL if none is found.
    // -------------------------------------------------------------------------
    $pdat->provider = NULL;
    if (strlen($pdat->country) > 0) {
        lognl(2, "...... Finding a close-by provider (primary care facility) for " . 
            implode(" ", $pdat->given) . " " . $pdat->family . " in " . $pdat->city . 
            " (" . $pdat->countryname . "), latitude: $pdat->latitude longitude: $pdat->longitude ...");
        $pdat->provider = getClosestProvider(
            $pdat->country, $pdat->latitude, $pdat->longitude, "primary_care_facilities"
        );
        if ($pdat->provider !== NULL)
            lognl(2, "......... closest primary care facility: " . $pdat->provider['name'] . " in " .
                $pdat->provider['postcode'] . " " . $pdat->provider['city'] .
                " (" . round($pdat->provider['distance']) . " km away)");
    }

    // -------------------------------------------------------------------------
    // HDR ISH GENERATION (from clinical data, not from pre-existing ISH files)
    // When generating HDR and the patient does not already have ISH definitions
    // (i.e. was not imported from a *.hdr.ish file), synthesise ISH definitions
    // from the patient's clinical story data by:
    //   1. Extracting medications, conditions and procedures per hospital stay.
    //   2. Finding the nearest hospital via getClosestProvider().
    //   3. Rendering a generic-hdr Twig template to produce an ISH string.
    //   4. Parsing the ISH string via parse_ish() and storing on $pdat->ish[].
    // If this is an ISH patient, simpy, assign his data so far to $pdat->ish[]
    // -------------------------------------------------------------------------
    if (in_array("HDR", $ARTIFACTS) and !$PROCESSISH) {

        lognl(2, "...... Inventing HDR by creating and parsing ISH information " .
            "based on the hospital stay(s) clinical story for " . implode(" ", $pdat->given) . " " . $pdat->family);

        for ($i = 0; $i < count($pdat->hospitalstays); $i++) {
            $thisstay  = $pdat->hospitalstays[$i];
            $start     = $thisstay["episodestart"];
            $end       = $thisstay["episodeend"];
            // Admission reason comes from the first encounter in the cluster
            $reason    = $thisstay["encounters"][0]["reason"];
            // Discharge information comes from the first encounter (searching in order) that has it
            $discharge = NULL;
            foreach ($thisstay["encounters"] as $eeii) {
                if (isset($eeii["discharge"]["text"])) {
                    $discharge = $eeii["discharge"];
                    break;
                }
            }

            lognl(2, sprintf(
                "......... Hospital stay from %s to %s (encounter phases: %s), admission reason: %s",
                $start, $end, count($thisstay["encounters"]), $reason["display"]
            ));
            lognl(2, sprintf("............ discharge information: %s", $discharge["display"]));

            // Collect medications, conditions and procedures linked to this stay's encounters
            $thisencountermedications = array();
            if ($pdat->medications !== NULL)
                foreach ($pdat->medications as $m)
                    if (isset($m["encounter"]))
                        foreach ($thisstay["encounters"] as $eeii)
                            if ($m["encounter"] === $eeii["encounterid"])
                                $thisencountermedications[] = $m;

            $thisencounterconditions = array();
            if ($pdat->conditions !== NULL)
                foreach ($pdat->conditions as $m)
                    if (isset($m["encounter"]))
                        foreach ($thisstay["encounters"] as $eeii)
                            if ($m["encounter"] === $eeii["encounterid"])
                                $thisencounterconditions[] = $m;

            $thisencounterprocedures = array();
            if ($pdat->procedures !== NULL)
                foreach ($pdat->procedures as $m)
                    if (isset($m["encounter"]))
                        foreach ($thisstay["encounters"] as $eeii)
                            if ($m["encounter"] === $eeii["encounterid"])
                                $thisencounterprocedures[] = $m;
            // if we did not find any generated procedures, out the procedures invented by AI on the list
            if (count($thisencounterprocedures) === 0 && isset($thisstay["inventedProcedures"])) {
                foreach($thisstay["inventedProcedures"] as $sp) {
                    $thisencounterprocedures[] = [
                        "type" => $sp["type"],
                        "date" => isset($sp["date"]) ? $sp["date"] : $end,
                        "code" => [
                            "code" => $sp["code"]["code"],
                            "system" => "\$sct",
                            "display" => $sp["text"],
                            "preferredTerm" => $sp["code"]["display"]
                        ]
                    ];    
                }
            }
            $thisencountervitalsigns = array();
            // get the recent vitals from within the stay
            $lastdayofstay = NULL;  // not yet known
            $overallvitals = 0;
            if ($pdat->vitalsigns !== NULL)
                // echo "*ÜÜÜÜ PDAT\n";var_dump($pdat->vitalsigns);
                foreach ($pdat->vitalsigns as $dkey => $vpd) {
                    // $m is now a set of vitals of the $dkey day, hush through them
                    foreach ($vpd as $lkey => $n) {
                        // $n is now a single vital of that $dkey day
                        if (isset($n["encounter"])) {
                            foreach ($thisstay["encounters"] as $eeii) {
                                // hush through all enounters of this stay and see if you find corresponding vitals 
                                // echo "*ÜÜÜÜ* " . $n["encounter"] . " - " . $eeii["encounterid"] . " DD " . $lastdayofstay . "\n";
                                if ($n["encounter"] === $eeii["encounterid"]) {
                                    // $n is an vital sign of $dkey day associated with the $eeii encounter
                                    // remember it with that day and remember the day as $lastdayofstay
                                    if ($dkey >= $lastdayofstay) $lastdayofstay = $dkey;
                                    $thisencountervitalsigns[$dkey][] = $n;
                                    $overallvitals++;
                                }
                            }
                        }    
                    }
                }
            // var_dump($lastdayofstay);var_dump($thisencountervitalsigns);exit;
            // if $lastdayofstay is set and we have more than 15 vital signs ($overallvitals) 
            // use only the vitals of $lastdayofstay
            if ($lastdayofstay !== NULL and $overallvitals > 15) {
                // foreach ($thisencountervitalsigns as $k => $v) { echo "üüüü $k - " . $v["code"]["display"] . "\n";}
                $thisencountervitalsigns = [$thisencountervitalsigns[$lastdayofstay]];
            } else {
                $thisencountervitalsigns = NULL;
            }
            // var_dump($lastdayofstay);var_dump($thisencountervitalsigns);exit;

            // Find the nearest hospital for this stay
            lognl(2, "......... Finding a close-by provider (hospital) for " . 
                implode(" ", $pdat->given) . " " . $pdat->family .
                " in " . $pdat->city . " " . $pdat->country .
                " latitude: $pdat->latitude longitude: $pdat->longitude ...");
            $hdrhospital = getClosestProvider($pdat->country, $pdat->latitude, $pdat->longitude, "hospitals");
            lognl(2, "............ closest hospital: " . $hdrhospital['name'] . " in " .
                $hdrhospital['postcode'] . " " . $hdrhospital['city'] .
                " (" . round($hdrhospital['distance']) . " km away)");
            if ($hdrhospital["practitioner"] !== NULL)
                lognl(2, "............ practitioner: " . 
                    implode(" ", $hdrhospital["practitioner"]["given"]) . " " .
                    $hdrhospital["practitioner"]["family"]);

            // Render the generic-hdr ISH template and parse the result
            $ISHHDR = twigit([
                "patient"   => $pdat,
                "encounter" => $thisstay,
                "hospital"  => $hdrhospital,
                "reason"    => $reason,
                "discharge" => $discharge,
                "medication" => $thisencountermedications,
                "condition"  => $thisencounterconditions,
                "procedure"  => $thisencounterprocedures,
                "vitalsigns" => $thisencountervitalsigns,
            ], "generic-hdr");

            // var_dump($thisencountervitalsigns);//var_dump($ISHHDR);exit;
            // var_dump($ISHHDR);

            $thisish       = parse_ish($ISHHDR);

            $pdat->ish[]   = json_decode(json_encode($thisish));
            if (json_last_error() !== JSON_ERROR_NONE) {
                lognlsev(2, ERROR, "............ JSON encode+decode ish for patient failed: " . json_last_error_msg());
            }
            // echo "---------------\n";var_dump($ISHHDR);echo "---------------\n";var_dump($pdat->ish);
        }
    }

    // -------------------------------------------------------------------------
    // FSH EMISSION
    // Call emitFSH() for each requested artifact type. Track per-artifact
    // counts for the statistics file and running totals.
    // -------------------------------------------------------------------------
    lognl(2, "...... emitting FSH\n");
    $allcount = 0;
    $stat     = array();
    foreach ($ARTIFACTS as $a) {
        lognl(2, "......... for $a\n");
        $count      = emitFSH($pdat, $a);
        $allcount  += $count;
        lognl(3, "............ # of examples emitted: $count\n");
        $stat[$a]   = $count;
    }

    // -------------------------------------------------------------------------
    // STATISTICS RECORD
    // Append one row per patient to the run's statistics CSV.
    // Format: gender;age;country;eci;storyid;lat;long;name;<ART1_count>;...
    // NOTE: $pdat->match appears twice in the current format — this is a known
    // bug; the second occurrence should be a separate storyid-related field.
    // -------------------------------------------------------------------------
    $statline = "$pdat->gender;$pdat->age;$pdat->country;$pdat->eci;$pdat->match;$pdat->match;$pdat->latitude;$pdat->longitude;$pdat->name;";
    foreach ($ARTIFACTS as $a) {
        if (isset($stat[$a])) $statline .= $stat[$a];
        $statline .= ";";
    }
    $statline = before_last(";", $statline) . "\n";
    file_put_contents($THESTATSFILE, $statline, FILE_APPEND);

    /* Log per-patient success or failure */
    if ($allcount > 0) {
        lognlsev(1, SUCCESS, "... *** round #$round: $allcount example file" .
            ($allcount === 1 ? "" : "s") .
            " for " . implode(" ", $pdat->given) . " " . $pdat->family .
            " (" . $pdat->age . " yr) with ECI " . $pdat->eci . " emitted.");
        $TOTALEXAMPLEFILES += $allcount;
    } else {
        lognlsev(1, ERROR, "... +++ round #$round: NO example file" .
            ($allcount === 1 ? "" : "s") .
            " for " . implode(" ", $pdat->given) . " " . $pdat->family .
            " (" . $pdat->age . " yr) with ECI " . $pdat->eci . " emitted.");
    }
}

/* Close the clinical candidates file after the patient loop */
fclose($clinicalcandidateshandle);


// ============================================================================
// PHASE II — POST-PROCESSING
// For each artifact type, run the corresponding synderai-make-fhir.sh <ARTIFACT>
// shell script (if it exists) to compile FSH → FHIR JSON/XML.
// Only executed when at least one FSH file was emitted in Phase I.
// ============================================================================

if ($TOTALEXAMPLEFILES > 0) {
    lognl(1, "*** Phase II: post-processing by creating FHIR from FSH and copy results");
    foreach ($ARTIFACTS as $a) {
        if (is_file("synderai-make-fhir.sh")) {
            $result = liveExecuteCommand("sh synderai-make-fhir.sh $a");
            if ($result['exit_status'] === 0) {
                lognlsev(1, SUCCESS, "... post-processing for $a succeeded");
            } else {
                lognlsev(1, ERROR, "... post-processing for $a failed");
            }
        } else {
            lognlsev(1, WARNING, "... no post-processing instructions for $a yet (missing synderai-make-fhir.sh)");
        }
    }
    lognlsev(1, SUCCESS, "*** konec");
} else {
    lognl(1, "+++ Phase II: post-processing omitted as there were no example files emitted for any candidate");
    lognlsev(1, ERROR, "*** konec (with errors)");
}

exit;


// ============================================================================
// FUNCTIONS
// ============================================================================


/**
 * Generate FSH example file(s) for a single patient and one artifact type.
 *
 * This function is the core FSH assembly engine. It is called once per patient
 * per artifact type and handles the three supported artifact types (HDR, EPS,
 * LAB) through separate code paths. Each path:
 *   1. Assembles section data by including section/*.php assemblers.
 *   2. Calls twigit() to render Twig templates into FSH strings.
 *   3. Concatenates all FSH fragments into a single output string.
 *   4. Writes the output to FSHOUTPUTDIR/<ARTIFACT>/__<eci>-<type>-example.fsh.
 *
 * ---- HDR path ---------------------------------------------------------------
 * Iterates over $pdat->ish (one entry per hospital stay). For each stay:
 *   - Initialises encounter, hospital, author, and requester instance IDs.
 *   - Includes sections/hdr.php to populate $sections[].
 *   - Renders patient, encounter, hospital, composition, provenance, and
 *     device Twig templates.
 *   - Writes one .fsh file per stay.
 *
 * ---- EPS path ---------------------------------------------------------------
 * Single pass per patient:
 *   - Finds the nearest individual practitioner from $SYNTHETICPROVIDERS and
 *     adds their name to $pdat->provider.
 *   - Includes all EPS section assemblers (condition, medication, immunization,
 *     procedure, allergyintolerance, careplan, vitalsign, pregnancy,
 *     recentlabobservationEPS, device).
 *   - Renders composition, bundle, provenance, patient, and PCP Twig templates.
 *   - Uses the most recent lab observation date as the composition date.
 *   - Writes one .fsh file.
 *
 * ---- LAB path ---------------------------------------------------------------
 * One FSH file per distinct lab result date:
 *   - For each date in $pdat->labobservations:
 *     - Emits patient, requester, service request, and lab observation FSH.
 *     - Optionally appends an AI-generated lab conclusion as an annotation
 *       section (when $pdat->labconclusion[$date] is set).
 *     - Creates a laboratory organisation FSH fragment.
 *     - Renders diagnostic report, composition, provenance, device, and
 *       bundle Twig templates.
 *     - Writes one .fsh file per date, named with a sequential counter and
 *       the date suffix.
 *
 * @global array $COMPONENTS          Artifact → required component names map.
 * @global array $SYNTHETICPROVIDERS  Synthetic practitioner records.
 *
 * @param  object $pdat          Patient data object (stdClass). Must have at
 *                                minimum: eci, given, family, age, gender,
 *                                country, countryname, latitude, longitude,
 *                                instanceid (set inside this function).
 *                                Additional properties are set by getter includes.
 * @param  string $thisartifact  Artifact type code: "HDR", "EPS", or "LAB".
 *
 * @return int  Number of FSH files written for this patient/artifact combination.
 */
function emitFSH($pdat, $thisartifact) {

    global $COMPONENTS;
    global $SYNTHETICPROVIDERS;
    global $PROCESSISH;

    $sections    = array();

    // Build composite patient name for convenient use in templates
    $pdat->name = (is_array($pdat->given) ? implode(" ", $pdat->given) : $pdat->given) . " " . $pdat->family;

    // =========================================================================
    // HDR ARTIFACT PATH
    // Process each ISH definition stored in $pdat->ish (one per hospital stay).
    // =========================================================================
    if ($thisartifact === "HDR") {

        // emit all HDRs
        $outputcount = 0;

        if ($PROCESSISH) {
            // all the pdat collected so far is "the ISH"
            $allishdat = json_decode(json_encode( [ $pdat ] ));
            if (json_last_error() !== JSON_ERROR_NONE) {
                lognlsev(2, ERROR, "............ JSON encode+decode ish for patient failed: " . json_last_error_msg());
            }
        } else {
            $allishdat = $pdat->ish;
        }

        foreach ($allishdat as $thisStayISH) {

            // Assign fresh UUIDs to all FHIR resource instances for this stay
            $pdat->instanceid = uuid();

            $hdrencounter                          = $thisStayISH->encounter;
            $hdrencounter->instanceid              = uuid();
            $hdrencounter->instancerole            = uuid();

            $hdrhospital                           = $thisStayISH->hospital;
            $hdrhospital->instanceid               = uuid();
            $hdrhospital->instancerole             = uuid();
            $hdrhospital->instancepractitioner     = uuid();
            $hdrhospital->instanceorganization     = uuid();
            // var_dump($hdrhospital);

            // Build HDR sections from the ISH definition
            lognl(2, "............ " . "Hospital Dicharge Report " . 
                $hdrencounter->start . " to " . $hdrencounter->end . "\n");
            $sections = array();
            include("sections/hdr.php");

            // Render core FHIR resource FSH strings
            list($FSHPAT) = twigit([
                "patient" => $pdat
            ], "patient-eu-core");
            list($FSHENC) = twigit([
                "patient" => $pdat,
                "encounter" => $hdrencounter,
                "hospital" => $hdrhospital
            ], "encounter-eu-hdr");
            list($FSHHOS) = twigit([
                "patient" => $pdat,
                "encounter" => $hdrencounter,
                "hospital" => $hdrhospital
            ], "provider-as-hospital");

            // Provenance: covers patient, hospital role, practitioner, organisation
            $targets   = [];
            $targets[] = $pdat->instanceid;
            $targets[] = $hdrhospital->instancerole;
            $targets[] = $hdrhospital->instancepractitioner;
            $targets[] = $hdrhospital->instanceorganization;
            $provenance = [
                "deviceid"     => uuid(),
                "provenanceid" => uuid(),
                "date"         => date('Y-m-d\TH:i:s\Z'),
                "targets"      => $targets
            ];
            list($FSHPROVDEV) = twigit(["provenance" => $provenance], "device-and-provenance");

            // var_dump($hdrencounter);

            $composition = [
                "instanceid" => uuid(),
                "identifier" => uuid(),
                "date"       => $hdrencounter->end
            ];
            // NOTE: composition-eu-hdr template is used here — should be a HDR-specific template
            list($FSHCMP) = twigit([
                "patient"     => $pdat,
                "provider"    => $hdrhospital,
                "sections"    => $sections,
                "composition" => $composition
            ], "composition-eu-hdr");

            // Prepare Bundle metadata
            $bundle = [
                "instanceid" => uuid(),
                "identifier" => uuid()
            ];

            // Render the HDR FHIR Bundle
            list($FSHBNDL) = twigit([
                "patient"     => $pdat,
                "bundle"      => $bundle,
                "composition" => $composition,
                "hospital"    => $hdrhospital,
                "encounter"   => $hdrencounter,
                "sections"    => $sections,
                "provenance"  => $provenance
            ], "bundle-eu-hdr");
            //var_dump($sections["sectionSignificantResults"]["entries"]);exit;
            //var_dump($FSHBNDL);//exit;

            $OUTFSH = $FSHBNDL . $FSHCMP . $FSHPAT . $FSHENC . $FSHHOS . $FSHPROVDEV;
            $OUTFSH = applyCorrectionsOnAIflawsInFSH($OUTFSH);
            if (!is_dir(FSHOUTPUTDIR . "/$thisartifact")) mkdir(FSHOUTPUTDIR . "/$thisartifact");
            $outputcount++;
            $fn = FSHOUTPUTDIR . "/$thisartifact/__" . $pdat->eci . 
                "-hdr-example-$outputcount-" . substr($hdrencounter->end, 0, 10) . ".fsh";
            file_put_contents($fn, $OUTFSH);
        }
    }

    // =========================================================================
    // EPS ARTIFACT PATH
    // Single FSH file per patient covering all EPS sections.
    // =========================================================================
    if ($thisartifact === "EPS") {

        // emit all EPSs
        $outputcount = 0;

        // Assign a fresh resource instance ID for this EPS
        $pdat->instanceid = uuid();
        list($FSHPAT) = twigit(["patient" => $pdat], "patient-eps");

        // Locate and enrich the primary-care provider record with instance IDs
        if ($pdat->provider !== NULL) {
            $pdat->provider["instancerole"]     = uuid();
            $pdat->provider["instanceroleorg"]  = uuid();
            $pdat->provider["orgidentifier"]    = uuid();
            $pdat->provider["providerorgname"]  = $pdat->provider["name"];
            $pdat->provider["instanceprovider"] = uuid();

            // Find the nearest individual practitioner from the global provider list
            $providerdistkm        = 384400;  // initialise to lunar distance
            $closestproviderperson = NULL;
            foreach ($SYNTHETICPROVIDERS as $sp) {
                $dist = distance($pdat->latitude, $pdat->longitude, $sp["lat"], $sp["long"]);
                if ($dist < $providerdistkm) {
                    $providerdistkm        = $dist;
                    $closestproviderperson = $sp;
                }
            }
            $hprovider = NULL;
            if ($closestproviderperson !== NULL) {
                $hprovider = [
                    "prefix"   => $closestproviderperson["prefix"],
                    "given"    => $closestproviderperson["given"],
                    "family"   => $closestproviderperson["family"],
                    "distance" => round($providerdistkm),
                ];
            }
            $pdat->provider["providernameprefix"] = $hprovider["prefix"];
            $pdat->provider["providernamegiven"]  = $hprovider["given"];
            $pdat->provider["providernamefamily"] = $hprovider["family"];

            list($FSHPCP) = twigit(["provider" => $pdat->provider], "provider-as-primarycarephysician-eps");
        } else {
            $FSHPCP = "";
            lognlsev(3, WARNING, "......... +++ No provider as primary care physician found for " . $pdat->name . "\n");
        }

        // Include all EPS section assemblers
        include("sections/condition.php");
        $thispatientid = $pdat->instanceid;
        include("sections/medication.php");
        include("sections/immunization.php");
        include("sections/procedure.php");
        include("sections/allergyintolerance.php");
        include("sections/careplan.php");
        include("sections/vitalsign.php");
        include("sections/pregnancy.php");
        include("sections/recentlabobservationEPS.php");
        include("sections/device.php");

        // Use the most recent lab observation date as the composition date
        $maxfoundix  = max(array_keys($pdat->labobservations));
        $composition = [
            "instanceid" => uuid(),
            "identifier" => uuid(),
            "date"       => $maxfoundix
        ];
        list($FSHCMP) = twigit([
            "patient"     => $pdat,
            "provider"    => $pdat->provider,
            "sections"    => $sections,
            "composition" => $composition
        ], "composition-eu-eps");

        // Concatenate all section FSH
        $FSHSEC = "";
        foreach ($sections as $section) $FSHSEC .= $section["fsh"];

        // Provenance covers patient, composition, and provider roles
        $targets   = [];
        $targets[] = $pdat->instanceid;
        $targets[] = $composition["instanceid"];
        $targets[] = $pdat->provider["instancerole"];
        $targets[] = $pdat->provider["instanceroleorg"];
        $targets[] = $pdat->provider["instanceprovider"];
        $provenance = [
            "deviceid"     => uuid(),
            "provenanceid" => uuid(),
            "date"         => $composition["date"],
            "targets"      => $targets
        ];
        list($FSHPROVDEV) = twigit(["provenance" => $provenance], "device-and-provenance");

        // Prepare Bundle metadata
        $bundle = ["instanceid" => uuid(), "identifier" => uuid()];

        // Render the EPS FHIR Bundle
        list($FSHBNDL) = twigit([
            "patient"     => $pdat,
            "bundle"      => $bundle,
            "composition" => $composition,
            "provider"    => $pdat->provider,
            "sections"    => $sections,
            "provenance"  => $provenance
        ], "bundle-eu-eps");

        $OUTFSH = $FSHBNDL . $FSHCMP . $FSHPAT . $FSHPCP . $FSHSEC . $FSHPROVDEV;
        $OUTFSH = applyCorrectionsOnAIflawsInFSH($OUTFSH);
        if (!is_dir(FSHOUTPUTDIR . "/$thisartifact")) mkdir(FSHOUTPUTDIR . "/$thisartifact");
        $fn = FSHOUTPUTDIR . "/$thisartifact/__" . $pdat->eci . "-eps-example.fsh";
        file_put_contents($fn, $OUTFSH);
        $outputcount++;

        lognl(3, "............ generated...");
    }

    // =========================================================================
    // LAB ARTIFACT PATH
    // One FSH file per distinct lab result date in $pdat->labobservations.
    // =========================================================================
    if ($thisartifact === "LAB") {

        // emit all EPSs
        $outputcount = 0;

        $overallsetofdates = array_keys($pdat->labobservations);

        foreach ($overallsetofdates as $thisroundate) {

            // Each lab report date gets a fresh patient instance ID
            $pdat->instanceid = uuid();
            list($FSHPAT) = twigit(["patient" => $pdat], "patient-eu-core");

            // Include the lab observation section assembler for this date
            $LABFILTERDATE = $thisroundate;
            include("sections/labobservation.php");
            $thisroundlabresultcount = count($pdat->labresults[$thisroundate]);

            // Emit the primary-care facility as the service requester (if available)
            if ($pdat->provider !== NULL) {
                $requester = [
                    "instancerole"  => uuid(),
                    "instanceorg"   => uuid(),
                    "orgidentifier" => uuid(),
                    "orgname"       => $pdat->provider["name"]
                ];
                list($FSHrequester) = twigit(
                    [
                        "requester" => $requester, 
                        "provider" => $pdat->provider
                    ], "provider-as-requester-eu-lab"
                );
            } else {
                $requester    = NULL;
                $FSHrequester = "";
                lognlsev(3, WARNING, "......... +++ No provider as requester found for " . $pdat->name . "\n");
            }

            // Render the FHIR ServiceRequest for this lab round
            $servicerequest["instanceid"] = uuid();
            list($FSHsvrq) = twigit([
                "instanceid"   => $servicerequest["instanceid"],
                "srvqidentifier" => uuid(),
                "requester"    => $requester,
                "patient"      => $pdat,
                "specimenids"  => array_unique($pdat->specimenids[$thisroundate]),
                "conditions"   => $pdat->conditions
            ], "servicerequest-eu-lab");
            // var_dump($FSHsvrq);

            // Optionally include an AI-generated lab conclusion as an annotation section
            if (isset($pdat->labconclusion["$thisroundate"])) {
                $HTMLannotations  = "<tr><th>Conclusion and Recommendations based on this report and previous findings known to us</th></tr>";
                $HTMLannotations .= "<tr><td>" . $pdat->labconclusion["$thisroundate"] . "</td></tr>";
                $sections['annotations'] = [
                    'title'   => 'Annotation comment',
                    'code'    => '$loinc#48767-8',
                    'display' => "Annotation comment [Interpretation] Narrative",
                    'text'    => "<h3>Annotation</h3><table class='hl7__eu__lab__report'>$HTMLannotations</table>",
                    'entries' => array()
                ];
                lognl(4, "......... Annotation (" . substr($thisroundate, 0, 10) . "): " .
                    "\n------------" . $pdat->labconclusion["$thisroundate"] . "------------");
            }

            // Create a fake laboratory organisation for this report
            $laboratory = [
                "instancerole"         => uuid(),
                "instancepractitioner" => uuid(),
            ];
            list($FSHlaboratory) = twigit(["laboratory" => $laboratory], "provider-as-laboratory");

            // Render the FHIR Composition and DiagnosticReport
            $composition = [
                "instanceid" => uuid(),
                "identifier" => uuid(),
                "date"       => isset($thisroundate)
                    ? date('Y-m-d\TH:i:s\Z', strtotime($thisroundate))
                    : date('Y-m-d\TH:i:s\Z')
            ];
            $diagnosticreport = ["instanceid" => uuid(), "identifier" => uuid()];

            // make specimen IDs unique as they are associated with the LOINC class
            // where different classes can have the same specimen id and thus are not unique
            $uniqspecimenids = array_unique($pdat->specimenids[$thisroundate]);
            list($FSHdiagreport) = twigit([
                "composition"      => $composition,
                "patient"          => $pdat,
                "diagnosticreport" => $diagnosticreport,
                "specimenids"      => $uniqspecimenids,
                "results"          => $pdat->labresults[$thisroundate]
            ], "diagnostic-report-lab-eu");

            list($FSHcomposition) = twigit([
                "composition"      => $composition,
                "patient"          => $pdat,
                "results"          => $pdat->labresults[$thisroundate],
                "categories"       => SUPPORTED_LOINC_LABORATORY_CATEGORIES,
                "requester"        => $requester,
                "laboratory"       => $laboratory,
                "diagnosticreport" => $diagnosticreport,
                "additionalsections" => $sections,
                "servicerequest"   => $servicerequest,
            ], "composition-lab-report-eu");

            // Prepare the FHIR Bundle
            $bundle = ["instanceid" => uuid(), "identifier" => uuid()];

            // Provenance targets: patient, bundle, composition, laboratory, requester roles
            $targets   = [];
            $targets[] = $pdat->instanceid;
            $targets[] = $composition["instanceid"];
            $targets[] = $laboratory["instancerole"];
            $targets[] = $laboratory["instancepractitioner"];
            $targets[] = $requester["instancerole"];
            $targets[] = $requester["instanceorg"];
            $provenance = [
                "deviceid"     => uuid(),
                "provenanceid" => uuid(),
                "date"         => $composition["date"],
                "targets"      => $targets
            ];
            list($FSHPROVDEV) = twigit(["provenance" => $provenance], "device-and-provenance");

            // Render the FHIR Bundle
            list($FSHbundle) = twigit([
                "bundle"                   => $bundle,
                "patient"                  => $pdat,
                "composition"              => $composition,
                "diagnosticreport"         => $diagnosticreport,
                "servicerequest"           => $servicerequest,
                "requesterrole"            => $requester["instancerole"],
                "requesterorg"             => $requester["instanceorg"],
                "specimenids"              => $uniqspecimenids,
                "results"                  => $pdat->labresults[$thisroundate],
                "provenance"               => $provenance,
                "laboratory"               => $laboratory
            ], "bundle-lab-report-eu");

            // Assemble and write the final FSH output file for this date
            $OUTFSH = $FSHbundle . $FSHcomposition . $FSHdiagreport . $FSHrequester . $FSHsvrq . $FSHPAT . $FSHlaboratory . $FSHPROVDEV;
            $thislabresultdatafsh = "";
            foreach ($pdat->labresults[$thisroundate] as $t) $thislabresultdatafsh .= $t["fsh"];
            $OUTFSH .= "\n\n" . $thislabresultdatafsh . "\n\n" . $pdat->specimenfsh[$thisroundate];
            $OUTFSH = applyCorrectionsOnAIflawsInFSH($OUTFSH);

            if (!is_dir(FSHOUTPUTDIR . "/$thisartifact")) mkdir(FSHOUTPUTDIR . "/$thisartifact");
            $outputcount++;
            $fn = FSHOUTPUTDIR . "/$thisartifact/__" . $pdat->eci .
                  "-lab-example-$outputcount-" . substr($thisroundate, 0, 10) . ".fsh";
            file_put_contents($fn, $OUTFSH);

            lognl(3, "............ #$outputcount generated, $thisroundlabresultcount item" .
                ($thisroundlabresultcount === 1 ? "" : "s") . "...");
        }
    }

    return $outputcount;
}


/**
 * Execute a shell command and stream its output to the browser/console in real time.
 *
 * Opens the command via popen(), reads its combined stdout+stderr in 4 kB
 * chunks, echoes each chunk immediately, and collects the full output for
 * post-processing. The exit status is extracted from a sentinel "Exit status: N"
 * line appended to the command via shell parameter expansion.
 *
 * All existing output buffers are flushed before the command runs to ensure
 * live streaming is not blocked by PHP output buffering.
 *
 * @param  string $cmd  Shell command to execute (passed to popen via sh -c).
 *
 * @return array  Two-element associative array:
 *                  'exit_status' => int     Exit code of the executed command.
 *                  'output'      => string  Full command output with the
 *                                           "Exit status: N" sentinel removed.
 */
function liveExecuteCommand($cmd) {

    while (@ob_end_flush()); // flush all existing output buffers

    $proc = popen("$cmd 2>&1 ; echo Exit status: $?", 'r');

    $live_output     = "";
    $complete_output = "";

    while (!feof($proc)) {
        $live_output     = fread($proc, 4096);
        $complete_output = $complete_output . $live_output;
        echo "$live_output";
        @flush();
    }

    pclose($proc);

    // Extract the exit status appended as "Exit status: N"
    // var_dump($complete_output);
    preg_match('/[0-9]+$/', $complete_output, $matches);

    $matches0 = isset($matches[0]) ? intval($matches[0]) : 0;
    return array(
        'exit_status' => $matches0,
        'output'      => str_replace("Exit status: " . $matches0, '', $complete_output)
    );
}


/**
 * Parse CLI options and throw on any unrecognised flags.
 *
 * Wraps PHP's getopt() to add strict validation: any option present in $argv
 * that is not declared in $shortOpts or $longOpts causes an
 * InvalidArgumentException to be thrown, allowing the caller to display a
 * clean error message and exit rather than silently ignoring unknown flags.
 *
 * Short option syntax (same as getopt()):
 *   "v"   — flag, no value
 *   "o:"  — required value
 *   "f::" — optional value
 *
 * Long option syntax (same as getopt()):
 *   "verbose"   — flag, no value
 *   "output:"   — required value
 *   "format::"  — optional value
 *
 * @param  string   $shortOpts  Short option string passed directly to getopt().
 * @param  string[] $longOpts   Long option array passed directly to getopt().
 *
 * @return array  Parsed options in the same format as getopt(): keys are option
 *                names (without leading dashes), values are the option value or
 *                FALSE for flag options.
 *
 * @throws \InvalidArgumentException  If one or more unrecognised options are
 *                                    present in $argv.
 * @throws \RuntimeException          If getopt() itself fails (returns false).
 */
function parseCLiOptions($shortOpts, array $longOpts): array {

    $optind = 0;
    $parsed = getopt($shortOpts, $longOpts, $optind);

    if ($parsed === false) {
        throw new \RuntimeException('getopt() failed to parse arguments.');
    }

    // Build the set of allowed option strings by stripping ':' / '::' suffixes
    $allowedLong = array_map(
        fn(string $o): string => '--' . rtrim($o, ':'),
        $longOpts
    );

    $allowedShort = [];
    $len = strlen($shortOpts);
    for ($i = 0; $i < $len; $i++) {
        $char = $shortOpts[$i];
        if ($char === ':') continue;  // skip value markers
        $allowedShort[] = '-' . $char;
    }

    $allowed = array_merge($allowedLong, $allowedShort);

    // Scan $argv for what was actually passed, normalising to "--option" form
    $passedOptions = [];
    foreach (array_slice($GLOBALS['argv'], 1) as $arg) {
        if ($arg === '--') break;  // end-of-options marker

        if (str_starts_with($arg, '--')) {
            // "--option" or "--option=value" → extract "--option"
            $passedOptions[] = '--' . explode('=', substr($arg, 2), 2)[0];
        } elseif (str_starts_with($arg, '-') && strlen($arg) >= 2 && $arg[1] !== '-') {
            // "-v" or combined "-abc" short flags
            $flags = substr($arg, 1);
            foreach (str_split($flags) as $flag) {
                $passedOptions[] = '-' . $flag;
                // If this flag expects a value, the rest of the string is that value
                $pos = strpos($shortOpts, $flag);
                if ($pos !== false && isset($shortOpts[$pos + 1]) && $shortOpts[$pos + 1] === ':')
                    break;
            }
        }
    }

    $unknown = array_diff($passedOptions, $allowed);
    if (!empty($unknown)) {
        throw new \InvalidArgumentException(
            'Unknown option(s): ' . implode(', ', $unknown)
        );
    }

    return $parsed;
}