<?php

/**
 * SYNDERAI Web Interface — index.php
 *
 * SYNDERAI © dr Kai Heitmann, HL7 Europe | AGPL-3.0 license
 * https://github.com/hl7-eu/SYNDERAI/
 *
 * Single-entry-point web application that renders the SYNDERAI public website.
 * All page navigation is handled through the ?menu= URL parameter; there are
 * no separate PHP files per page. HTML output is assembled by loading a base
 * template (tmpl/index.html) and replacing placeholder tokens (%%TOKEN%%)
 * with dynamically generated content.
 *
 * ============================================================================
 * DIRECTORY STRUCTURE
 * ============================================================================
 *
 *   bin/lib/          Parsedown Markdown renderer (http://parsedown.org)
 *   assets/           CSS and JS files for the SYNDERAI UI
 *   img/              Website images, including country flag PNGs (img/flags/)
 *   tmpl/             HTML template files with %%PLACEHOLDER%% markers:
 *                       index.html    — outer page shell (nav, head, foot)
 *                       header.html   — landing-page hero header
 *                       features.html — landing-page feature cards
 *                       examples.html — artifact category overview
 *                       listing.html  — per-artifact example file listing
 *                       info.html     — generic Markdown info page
 *                       footer.html   — site footer
 *   examples/         One subdirectory per artifact type (e.g. EPS, LAB, HDR).
 *                     Each artifact directory contains versioned sub-folders
 *                     following SemVer (e.g. 1.0.0+20251023) with Bundle*.json
 *                     and Bundle*.xml example files inside.
 *                     Each artifact also has a companion info-<ARTIFACT>.txt
 *                     metadata file (pipe-delimited, see below).
 *   config.php        Application configuration: defines $MENU, $VI7ETIDEEPLINK,
 *                     $SELFURL, and other runtime settings.
 *
 * ============================================================================
 * TEMPLATE PLACEHOLDER TOKENS
 * ============================================================================
 *
 *   %%TITLE%%              Page title (SYNDERAITITLE constant)
 *   %%NAVULLI%%            Navigation <ul><li> block
 *   %%BACKGROUNDIMG%%      CSS class for the page background image
 *   %%HEADER%%             Hero header block (landing page only)
 *   %%MAIN%%               Primary page content
 *   %%FOOTER%%             Footer block
 *   %%EXAMPLECATEGORIES%%  Artifact category card grid (examples overview)
 *   %%EXAMPLELISTING%%     Per-patient example file table (artifact listing)
 *   %%TEASER%%             Full application teaser string
 *   %%RENDITION%%          Rendered Markdown HTML (info pages)
 *   %%PACKAGE%%            SemVer folder name of the displayed package
 *
 * ============================================================================
 * INFO FILE FORMAT  (examples/info-<ARTIFACT>.txt)
 * ============================================================================
 *
 * Lines beginning with "#" are comments and are ignored.
 * All other lines are pipe-delimited with six fields:
 *   title | short | description | vi7eti | icon | color
 *
 *   title       Long human-readable name of the artifact type.
 *   short       Short label shown in the per-file listing.
 *   description Sentence fragment used in the category card ("N examples").
 *   vi7eti      Deep-link prefix for the Vi7eti FHIR viewer.
 *   icon        Material Design Icon class (e.g. "mdi-file-document-multiple").
 *   color       CSS class for icon colour (e.g. "vi7eti_regular").
 *
 * ============================================================================
 * PAGE ROUTING  (?menu=<value>)
 * ============================================================================
 *
 *   index                  Landing page (hero + feature cards)
 *   examples               Artifact category overview grid
 *   examples/<ARTIFACT>    Per-artifact example file listing
 *   <any other value>      Generic Markdown info page (e.g. "story", "about")
 *
 * ============================================================================
 * NAVIGATION BEHAVIOUR
 * ============================================================================
 *
 * The currently active menu item is rendered with reduced opacity and a
 * "cursor: not-allowed" style to signal it is the current page and
 * cannot be clicked again.
 *
 * ============================================================================
 * EXAMPLE FILE LISTING DETAILS
 * ============================================================================
 *
 * For each artifact the script:
 *   1. Identifies the most recent SemVer sub-folder via getLatestSemverFolder().
 *   2. Enumerates all Bundle*.json / Bundle*.xml files in that folder.
 *   3. Extracts patient name, age, nationality, resource type, and composition
 *      date from each JSON file via getPatientNameFromJSON().
 *   4. Groups all examples belonging to the same patient by an MD5 hash of
 *      their name string, so a patient with multiple lab reports gets a single
 *      card with all their reports listed chronologically (newest first).
 *   5. Shows up to $maxrowstoshowinitially (4) rows immediately; additional
 *      rows are hidden and revealed via a CSS toggle ("showondemand").
 *   6. Builds a Vi7eti FHIR viewer deep-link for each JSON/XML file.
 *
 * ============================================================================
 * CONSTANTS
 * ============================================================================
 *
 *   SYNDERAINAME      "SYNDERAI"
 *   SYNDERAIVERSION   "2.0"
 *   SYNDERAITITLE     Full title string
 *   SYNDERAITEASER    Teaser string with IPA pronunciation
 *   SYNDERAICONTACT   Contact e-mail address (obfuscated in output)
 *   FATAL             Severity level -1 — triggers die() after error display
 *   WARNING           Severity level  1
 *   ERROR             Severity level  2
 *   INERROR           TRUE — reserved for internal error-state signalling
 *
 * ============================================================================
 * FUNCTION INDEX
 * ============================================================================
 *
 *   getPatientNameFromJSON()   Extract patient metadata from a FHIR Bundle JSON
 *   getLatestSemverFolder()    Find the highest SemVer sub-folder in a directory
 *   handleError()              Display an error message and optionally halt
 *   antispambot()              Obfuscate an e-mail address against scrapers
 *   zeroise()                  Zero-pad a number to a minimum string width
 *   pretty_json()              Re-encode an object as compact pretty-printed JSON
 *   markup_json()              Wrap JSON tokens in colour-coded HTML <span> tags
 *   startsWith()               Test whether a string has a given prefix
 *   endsWith()                 Test whether a string has a given suffix
 *   test_pretty_json_object()  Unit test for pretty_json() with an object
 *   test_pretty_json_str()     Unit test for pretty_json() with a scalar string
 *   test_markup_json()         Unit test for markup_json()
 */


// ============================================================================
// PHP RUNTIME CONFIGURATION
// ============================================================================

/** Allow up to 2 GB of RAM — needed when parsing large FHIR Bundle JSON files. */
ini_set('memory_limit', '2048M');

/**
 * Disable PCRE's Just-in-Time compiler.
 * Required to avoid crashes on some hosting environments when running complex
 * regular expressions against large JSON strings in markup_json().
 */
ini_set("pcre.jit", "0");


// ============================================================================
// APPLICATION IDENTITY CONSTANTS
// ============================================================================

define("SYNDERAINAME",    "SYNDERAI");
define("SYNDERAIVERSION", "2.0");
define("SYNDERAITITLE",   "Synthetic Data Examples – Realistic – using AI (SYNDERAI)");
define("SYNDERAITEASER",  "Synthetic Data Examples – Realistic – using AI (SYNDERAI), pronounced /ˈsɪn.də.raɪ/");
define("SYNDERAICONTACT", "kai.heitmann@hl7europe.org");

/** Severity constants used by handleError(). */
define("FATAL",   -1);   // display error and die()
define("WARNING",  1);   // display warning, continue
define("ERROR",    2);   // display error, continue

/** Reserved flag for internal error-state tracking. */
define("INERROR", TRUE);


// ============================================================================
// MARKDOWN SUPPORT
// Parsedown (http://parsedown.org) converts Markdown source files to HTML
// for rendering on generic info pages.
// ============================================================================

include('bin/Parsedown.php');
$parsedown = new Parsedown();


// ============================================================================
// BOOTSTRAP
// ============================================================================

/** The script always routes requests back to itself via the ?menu= parameter. */
$SELFSCRIPT = "index.php";

/** Load application configuration: $MENU, $VI7ETIDEEPLINK, $SELFURL, etc. */
require "config.php";

if (!isset($MENU)) {
    handleError(FATAL, "+++ config file does not contain proper app menu definitions (MENU)");
}


// ============================================================================
// MENU / ROUTE RESOLUTION
// Determine which page to render based on the ?menu= GET parameter.
// The default is the first entry in $MENU (the landing page).
// Special case: any ?menu= value matching "examples/*" is treated as an
// artifact listing page even if it is not explicitly declared in $MENU.
// ============================================================================

$CURRENTMENU = $MENU[0];  // default to the landing (index) page

foreach ($MENU as $ix => $m) {
    $tm = $m['menu'];
    
    // Vulerability patch, see https://github.com/hl7-eu/SYNDERAI/issues/101
    // $sp = (isset($_GET['menu']) > 0) ? htmlspecialchars($_GET['menu']) : "";
    // htmlspecialchars() only escapes HTML entities. It does nothing to block filesystem path-traversal sequences
    // such as ?menu=examples/../../../../etc/passwd
    // fixed KH 2026-03-19

    // Step 1: Basic character whitelist — reject anything containing
    // traversal sequences, null bytes, or characters with no place in a
    // URL menu parameter before any further processing.
    $sp = (isset($_GET['menu']) > 0) ? htmlspecialchars($_GET['menu']) : ""; // ... but overwritten here on every pass

    if (!preg_match('/^[a-zA-Z0-9_\-\/]*$/', $sp)) {
        // Contains illegal characters — reject immediately
        $sp = "";
    }

    if ($sp !== "") {

      // Build the set of explicitly declared menu keys from config.php
      $declaredMenuKeys = array_column($MENU, 'menu');
      $isDeclaredMenu   = in_array($sp, $declaredMenuKeys, TRUE);
      $isExamplesPath   = (bool) preg_match('/^examples\/[a-zA-Z0-9_\-]+$/', $sp);

      if ($isDeclaredMenu) {
          // Explicitly declared in $MENU — unconditionally safe, no further checks

      } elseif ($isExamplesPath) {
          // Dynamic artifact listing — verify it stays inside examples/
          $allowedRoot   = realpath("examples");
          $requestedPath = realpath($sp);

          if ($allowedRoot === FALSE) {
              handleError(FATAL, "Server configuration error: SYNDERAI: examples/ root directory not found");
          }

          if ($requestedPath === FALSE
              || $requestedPath === $allowedRoot
              || strpos($requestedPath, $allowedRoot . DIRECTORY_SEPARATOR) !== 0
          ) {
              handleError(FATAL, "Invalid example path requested: path traversal attempt blocked!");
              $sp = "";
          }

      } else {
          // Neither a declared menu key nor a valid examples/* path — fall back to index
          $sp = "";
      }
  }

  // $sp is now validated — use it read-only in the routing loop below
  $CURRENTMENU = $MENU[0];  // default to landing page

  foreach ($MENU as $ix => $m) {
      $tm = $m['menu'];
      // NOTE: $sp is NOT re-assigned here — it comes from the validated block above

      if ($sp === $tm) {
          $CURRENTMENU = $MENU[$ix];
      } elseif (preg_match('/^examples\/*/', $sp)) {
          $CURRENTMENU = [
              "title" => "Examples",
              "menu"  => $sp,
              "file"  => "-"
          ];
      }
  }
}


// ============================================================================
// BASE TEMPLATE & NAVIGATION
// Load the outer page shell and substitute the title and navigation bar.
// The active menu item is visually disabled (opacity + cursor) to prevent
// the user from re-clicking the current page.
// ============================================================================

$content = file_get_contents("tmpl/index.html");
$content = str_replace("%%TITLE%%", SYNDERAITITLE, $content);

// Build the navigation bar: logo + <ul><li> links
$nav  = "<div class=\"logo\"><img src=\"img/HL7_Europe_RGB_2.png\" alt=\"logo\"></img></div>";
$nav .= "<ul class='nav-links' id='navLinks'>";

foreach ($MENU as $m) {
    $title = $m['title'];
    $url   = $SELFSCRIPT . "?menu=" . $m['menu'];
    $nav  .= "<li>";
    if ($m['menu'] === $CURRENTMENU['menu']) {
        // Active item: visually disabled to indicate current location
        $nav .= "<a href=\"$url\" style=\"cursor: not-allowed; opacity: 0.5; text-decoration: none;\">$title</a>";
    } else {
        $nav .= "<a href=\"$url\">$title</a>";
    }
    $nav .= "</li>";
}

$nav    .= "</ul>";
$content = str_replace("%%NAVULLI%%", $nav, $content);


// ============================================================================
// PAGE RENDERING — ROUTE DISPATCH
// ============================================================================

if ($CURRENTMENU['menu'] === 'index') {

    // -------------------------------------------------------------------------
    // LANDING PAGE
    // Renders the hero header and feature-card grid.
    // Uses the "index-image" background CSS class.
    // -------------------------------------------------------------------------
    $content = str_replace("%%BACKGROUNDIMG%%", "index-image", $content);

    $tmp     = file_get_contents("tmpl/header.html");
    $content = str_replace("%%HEADER%%", $tmp, $content);

    $tmp = file_get_contents("tmpl/features.html");
    $OUT = str_replace("%%MAIN%%", $tmp, $content);

} elseif ($CURRENTMENU['menu'] === 'examples') {

    // -------------------------------------------------------------------------
    // EXAMPLES OVERVIEW PAGE
    // Renders a card grid with one card per artifact category found under
    // the examples/ directory. Each card shows:
    //   - The artifact icon and title (from the info-<ARTIFACT>.txt file)
    //   - The number of distinct synthetic patients
    //   - The total number of distinct Bundle files (JSON + XML counted once)
    //   - The currently active SemVer package version
    //   - A "VISIT" link to the artifact listing page
    //
    // Patient count uses MD5-keyed deduplication so a patient with multiple
    // lab reports is counted once.
    // -------------------------------------------------------------------------
    $content = str_replace("%%BACKGROUNDIMG%%", "page-image", $content);

    $allexamplecategories = glob("examples/*");
    $eclist = "";

    foreach ($allexamplecategories as $ec) {
        if (is_dir($ec)) {

            // Find the most recent SemVer sub-folder
            try {
              $latestexamplesfolder = getLatestSemverFolder($ec);
            } catch (InvalidArgumentException $e) {
                handleError("ERROR", "SYNDERAI: " . $e->getMessage());
            }

            // Count distinct Bundle files and distinct patients in that folder
            $filesincat    = array();
            $patientsincat = array();
            foreach (glob($ec . "/" . $latestexamplesfolder . "/Bundle*") as $f) {
                $fp = pathinfo(basename($f), PATHINFO_FILENAME);
                $filesincat[$fp] = 1;
                if (endsWith($f, ".json")) {
                    list($resourcetype, $patientname, $patientmd5) = getPatientNameFromJSON($f);
                    $patientsincat[$patientmd5] = 1;  // deduplicate by name MD5
                }
            }
            $catcount = count($filesincat);
            $patcount = count($patientsincat);

            // Read display metadata from the companion info-<ARTIFACT>.txt file.
            // Falls back to safe defaults if the file is absent.
            $baseec = basename($ec);
            if (is_file("examples/info-$baseec.txt")) {
                $infocontent = file_get_contents("examples/info-$baseec.txt");
                $infolines   = explode("\n", $infocontent);
                foreach ($infolines as $il) {
                    if (substr(trim($il), 0, 1) !== "#") {  // skip comment lines
                        $info     = explode("|", $il);
                        $ectitle  = trim($info[0]);   // long artifact title
                        $ecshort  = trim($info[1]);   // short label
                        $ecdesc   = trim($info[2]);   // description fragment
                        $ecvi7eti = trim($info[3]);   // Vi7eti deep-link prefix
                        $ecicon   = trim($info[4]);   // MDI icon class
                        $ecicolor = trim($info[5]);   // CSS colour class
                    }
                }
            } else {
                // Default values when no info file is present
                $ectitle  = $ec;
                $ecshort  = "-";
                $ecdesc   = "-";
                $ecvi7eti = "";
                $ecicon   = "mdi-file-document-multiple";
                $ecicolor = "vi7eti_regular";
            }

            // Build the artifact category card
            $eclist .= "<div class=\"feature-card\" data-animate>";
            $eclist .= "<i class=\"mdi $ecicon $ecicolor\"></i>";
            $eclist .= "<h3>$ectitle</h3>";
            $eclist .= "<p><strong>$patcount synthetic patients</strong> with<br/><strong>$catcount</strong> $ecdesc";
            $eclist .= "<br><small>(Package $latestexamplesfolder)</small></p>";
            $eclist .= "<a href=\"index.php?menu=$ec\" class=\"learn-more-btn\">VISIT</a>";
            $eclist .= "</div>";
        }
    }

    $tmp = file_get_contents('tmpl/examples.html');
    $tmp = str_replace("%%EXAMPLECATEGORIES%%", $eclist,        $tmp);
    $tmp = str_replace("%%TEASER%%",            SYNDERAITEASER, $tmp);

    $OUT = str_replace("%%MAIN%%",   $tmp, $content);
    $OUT = str_replace("%%HEADER%%", "",   $OUT);

} elseif (preg_match('/^examples\/*/', $CURRENTMENU['menu'])) {

    // -------------------------------------------------------------------------
    // ARTIFACT LISTING PAGE  (?menu=examples/<ARTIFACT>)
    // Renders a per-patient card grid for one artifact category.
    //
    // Each patient card contains:
    //   - Patient name, age, and country flag (if a matching PNG exists)
    //   - A table of all their example files for this artifact type, sorted
    //     newest-first by composition date
    //   - "View" links (opening the Vi7eti FHIR viewer in a new tab) and
    //     direct JSON / XML download links for each file
    //   - Up to $maxrowstoshowinitially (4) rows visible by default; additional
    //     rows are hidden and revealed by a CSS/JS toggle
    // -------------------------------------------------------------------------
    $baseme  = basename($CURRENTMENU['menu']);
    $content = str_replace("%%BACKGROUNDIMG%%", "page-image", $content);

    $tmp = file_get_contents('tmpl/listing.html');
    $tmp = str_replace("%%TEASER%%", SYNDERAITEASER, $tmp);

    // Read display metadata from the companion info file (same format as above)
    if (is_file("examples/info-$baseme.txt")) {
        $infocontent = file_get_contents("examples/info-$baseme.txt");
        $infolines   = explode("\n", $infocontent);
        foreach ($infolines as $il) {
            if (substr(trim($il), 0, 1) !== "#") {
                $info     = explode("|", $il);
                $ectitle  = trim($info[0]);
                $ecshort  = trim($info[1]);
                $ecdesc   = trim($info[2]);
                $ecvi7eti = trim($info[3]);
            }
        }
    } else {
        $ectitle  = $baseme;
        $ecshort  = "-";
        $ecdesc   = "-";
        $ecvi7eti = "";
    }

    // Enumerate all Bundle files in the latest SemVer sub-folder
    try {
      $latestexamplesfolder = getLatestSemverFolder($CURRENTMENU['menu']);
    } catch (InvalidArgumentException $e) {
        handleError("ERROR", "SYNDERAI: " . $e->getMessage());
    }
    $allexamples          = glob($CURRENTMENU['menu'] . "/" . $latestexamplesfolder . "/Bundle*");

    // Pass 1: build the $exary index — one entry per unique filename stem,
    // recording the JSON/XML availability, patient metadata, and composition date.
    $exary   = [];
    $patdata = [];
    foreach ($allexamples as $ex) {
        $finfo  = pathinfo($ex);
        $fname  = $finfo['filename'];
        $fdir   = $finfo['dirname'];

        list($resourcetype, $patientname, $patientmd5, $patientcountrycode, $compositiondate)
            = getPatientNameFromJSON("$fdir/$fname.json");

        $exary[$fname] = [
            "json"            => is_file("$fdir/$fname.json") ? "$fdir/$fname.json" : FALSE,
            "xml"             => is_file("$fdir/$fname.xml")  ? "$fdir/$fname.xml"  : FALSE,
            "focus"           => basename($CURRENTMENU['menu']),
            "vi7eti"          => $ecvi7eti,
            "patientname"     => $patientname,
            "patientmd5"      => $patientmd5,
            "resourcetype"    => $resourcetype,
            "compositiondate" => $compositiondate
        ];

        // Per-patient display data keyed by name MD5 for deduplication
        $patdata[$patientmd5] = [
            "patientname"  => $patientname,
            "patientmd5"   => $patientmd5,
            "resourcetype" => $resourcetype,
            "ecshort"      => $ecshort,
            "countrycode"  => $patientcountrycode
        ];
    }

    // Pass 2: group all table rows by patient MD5 — each patient gets a bucket
    // containing one HTML <tr> per example file, using %%TR%% and %%CT%%
    // placeholders that are resolved in Pass 3.
    $exlist = array();
    foreach ($exary as $exk => $exf) {
        
        // Determine file format and path once, cleanly
        $format  = $exf['json'] ? "json" : ($exf['xml'] ? "xml" : "");
        $fileurl = $exf['json'] ?: $exf['xml'];

        if ($format !== '') {
            // Correct: VI7ETI deep-link is the full URL base
            // ?json=<vi7eti>&url=<self_url>/<file>
            // also replace any "+" in semver, eg "1.0.0+20260316", with %2B
            $showlink = $VI7ETIDEEPLINK
                      . $format . "="
                      . $exf['vi7eti']
                      . "&url=" . $SELFURL . "/"
                      . str_replace("+", "%2B", $fileurl);

            $jsonl = $exf['json']
                ? "<a target=\"_blank\" rel=\"noopener noreferrer\" href=\"" . $exf['json'] . "\">JSON </a>"
                : "";
            $xmll  = $exf['xml']
                ? "<a target=\"_blank\" rel=\"noopener noreferrer\" href=\"" . $exf['xml'] . "\">XML </a>"
                : "";

            $thedate     = strlen($exf["compositiondate"]) > 0
                ? date('d-M-Y', strtotime($exf["compositiondate"]))
                : "##CT##";
            $thesortdate = strlen($exf["compositiondate"]) > 0
                ? date('Ymd', strtotime($exf["compositiondate"]))
                : "missingdate";

            $row = "<tr class=\"$thesortdate {$exf['patientmd5']} ##TR##\">"
                . "<td class=\"smaller\">$thedate</td>"
                . "<td><a target=\"_blank\" rel=\"noopener noreferrer\" href=\"$showlink\">View</a></td>"
                . "<td>$jsonl</td><td>$xmll</td>"
                . "</tr>\n";

            if (isset($exlist[$exf["patientmd5"]])) {
                $exlist[$exf["patientmd5"]] .= $row;
            } else {
                $exlist[$exf["patientmd5"]]  = $row;
            }
        }
    }

    // Pass 3: compile the final HTML card for each patient.
    // Rows are sorted newest-first (rsort on date-prefixed class string).
    // Rows beyond $maxrowstoshowinitially receive the "showondemand" CSS class.
    $maxrowstoshowinitially = 4;
    $allexampleitemsout     = "";

    foreach ($exlist as $pkey => $prow) {

        // Patient card wrapper
        $allexampleitemsout .= "<div class=\"example-item\">";

        // Card header: icon + artifact short label + resource type
        $allexampleitemsout .= "<div>";
        $allexampleitemsout .= "<i class=\"mdi mdi-file-outline\"> </i> "
                            .  $patdata[$pkey]["ecshort"] . " "
                            .  $patdata[$pkey]["resourcetype"];
        $allexampleitemsout .= "</div>";

        // Patient identifier: name + country flag (if available)
        $allexampleitemsout .= "<div class=\"identifier\">";
        $allexampleitemsout .= $patdata[$pkey]["patientname"];
        $flagfile = "img/flags/" . strtolower($patdata[$pkey]["countrycode"]) . ".png";
        if (is_file($flagfile)) {
            $allexampleitemsout .= " <img width='24px' src=\"$flagfile\"> </img>";
        }
        $allexampleitemsout .= "</div>";

        // Example links table
        $allexampleitemsout .= "<div class=\"links\">";
        $allexampleitemsout .= "<table class=\"toggle-table\"><tbody>";
        // Static column-header row (always visible)
        $allexampleitemsout .= "<tr class=\"showalways\">"
                            .  "<td> </td>"
                            .  "<td><i class=\"mdi mdi-eye\"> </i></td>"
                            .  "<td colspan=\"2\"><i class=\"mdi mdi-download\"> </i></td>"
                            .  "<tr>";

        // Sort rows newest-first, then resolve %%TR%% and %%CT%% placeholders
        $prowary = explode("\n", $prow);
        rsort($prowary);
        $count = 0;
        foreach ($prowary as $p) {
            $count++;
            $TRclass = $count <= $maxrowstoshowinitially ? "showalways" : "showondemand";
            $p = str_replace("##TR##", $TRclass, $p);
            $allexampleitemsout .= str_replace("##CT##", "$count", $p);
        }

        $allexampleitemsout .= "</tbody></table>";
        $allexampleitemsout .= "</div>";  // .links
        $allexampleitemsout .= "</div>";  // .example-item
    }

    $tmp = str_replace("%%EXAMPLELISTING%%", $allexampleitemsout,    $tmp);
    $tmp = str_replace("%%TITLE%%",          $ectitle,               $tmp);
    $tmp = str_replace("%%PACKAGE%%",        $latestexamplesfolder,  $tmp);

    $OUT = str_replace("%%MAIN%%",   $tmp, $content);
    $OUT = str_replace("%%HEADER%%", "",   $OUT);

} elseif ($CURRENTMENU['menu'] === 'downloads') {
    // -------------------------------------------------------------------------
    // DOWNLOADS PAGE
    // Renders all download packages under examples/{artifact}/package.tar.tgz
    // and offer them in a hierarchical list for download
    // -------------------------------------------------------------------------
    $content = str_replace("%%BACKGROUNDIMG%%", "page-image", $content);

    $tmp = file_get_contents('tmpl/downloads.html');
    $tmp = str_replace("%%TEASER%%", SYNDERAITEASER, $tmp);

    // get all semver subfolders from the example directory
    $allpackages = glob("examples/*/*.*.*");

    $strlist = "<ul>";
    foreach ($allpackages as $pf) {
      $items = explode("/", $pf); 
      $artifact = $items[1];
      // Read display metadata from the companion info file (same format as above)
      if (is_file("examples/info-$artifact.txt")) {
          $infocontent = file_get_contents("examples/info-$artifact.txt");
          $infolines   = explode("\n", $infocontent);
          foreach ($infolines as $il) {
              if (substr(trim($il), 0, 1) !== "#") {
                  $info     = explode("|", $il);
                  $ectitle  = trim($info[0]);
                  $ecshort  = trim($info[1]);
                  $ecdesc   = trim($info[2]);
                  $ecvi7eti = trim($info[3]);
              }
          }
      } else {
          $ectitle  = $artifact;
          $ecshort  = "-";
          $ecdesc   = "-";
          $ecvi7eti = "";
      }
      $ppath = is_file("$pf/package.tar.gz") ? "$pf/package.tar.gz" : NULL;
      $package = $items[2];
      $packagedate = after("+", $package);
      $plist[$artifact][] = [
        "artifact" => $artifact,
        "title" => $ectitle,
        "desc" => $ecdesc,
        "package" => $package,
        "date" => $packagedate,
        "path" => str_replace("+", "%2B", $ppath)
      ]; 
    }
    foreach ($plist as $af => $pinfo) {
      // $pinfo has now all packages per this artifact $af
      // prepare "headline"
      $strlist .= "<h3>" . $pinfo[0]["title"] . " (" . $af . ")</h3><ul>";
      // sort entries by package date
      $dates = array_column($pinfo, 'date');
      array_multisort($dates, SORT_DESC, $pinfo);
      foreach ($pinfo as $pk) {
        $strlist .= "<li><i class=\"mdi mdi-treasure-chest green\"></i> " . 
          " Package " .
          $pk["package"] . 
          "<a href=\"" . $pk["path"]  . "\" download=\"package.tar.gz\">" .
          " <i class=\"mdi mdi-download\"></i>" . "</a></li>";
      }
      $strlist .= "</ul>";
      // var_dump($pinfo);
    }
    $content = str_replace("%%MAIN%%",         $tmp,  $content);
    $content = str_replace("%%DOWNLOADLIST%%", $strlist, $content);
    $OUT = str_replace("%%HEADER%%",           "",    $content);

} else {

    // -------------------------------------------------------------------------
    // GENERIC INFO PAGE
    // Renders any Markdown file declared in $MENU under its 'file' key.
    // The Markdown content is stripped up to the first "# " heading so that
    // front-matter or metadata above the first heading is not displayed.
    // -------------------------------------------------------------------------
    $content = str_replace("%%BACKGROUNDIMG%%", "page-image", $content);

    $file   = $CURRENTMENU['file'];
    $tmp    = file_get_contents('tmpl/info.html');
    $tmp    = str_replace("%%TEASER%%", SYNDERAITEASER, $tmp);

    // Load the Markdown source and trim everything before the first heading
    $render = file_get_contents($file);
    $render = substr($render, strpos($render, '# '));
    $render = $parsedown->text($render);

    $tmp = str_replace("%%RENDITION%%", $render, $tmp);
    $OUT = str_replace("%%MAIN%%",   $tmp, $content);
    $OUT = str_replace("%%HEADER%%", "",   $OUT);
}


// ============================================================================
// FOOTER ASSEMBLY
// Substitutes footer tokens and appends the rendered footer to $OUT.
// The contact e-mail is obfuscated via antispambot() before output.
// ============================================================================

$content = file_get_contents('tmpl/footer.html');
$content = str_replace("%%NAME%%",         SYNDERAINAME,                          $content);
$content = str_replace("%%VERSION%%",      SYNDERAIVERSION,                       $content);
$content = str_replace("%%CONTACTEMAIL%%", "mailto:" . antispambot(SYNDERAICONTACT), $content);
$content = str_replace("%%CURRENTYEAR%%",  date('Y'),                             $content);

$OUT = str_replace("%%FOOTER%%", $content, $OUT);

header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
    . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; "
    . "script-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data:; "
    . "connect-src 'self'; "
    . "frame-ancestors 'none';");
echo $OUT;
exit;

/*
 * END — konec
 */


// ============================================================================
// FUNCTIONS
// ============================================================================


/**
 * Extract patient metadata from a FHIR Bundle JSON file.
 *
 * Parses the JSON file and iterates over its entry array to locate the
 * Composition resource (for the report date) and the Patient resource
 * (for the patient name, age, and nationality).
 *
 * Patient name resolution attempts the following fallbacks in order:
 *   1. name[0].text
 *   2. name[2].text
 *   3. Concatenation of name[2].given (array or string) + name[0].family
 * If a birthDate is present, the patient's current age in years is appended
 * in parentheses: "Jane Doe (42)".
 *
 * Nationality is extracted from the patient-nationality FHIR extension:
 *   http://hl7.org/fhir/StructureDefinition/patient-nationality
 *
 * @param  string $jsonfilename  Absolute or relative path to a FHIR Bundle JSON file.
 *
 * @return array  Five-element indexed array:
 *                  [0] string  Resource type in parentheses, e.g. "(Bundle)",
 *                              or "" if not determinable.
 *                  [1] string  Patient display name with age, e.g. "Jane Doe (42)",
 *                              or "example" if not found.
 *                  [2] string  MD5 hash of the patient name string (used as a
 *                              deduplication key across multiple example files).
 *                  [3] string  ISO 3166-1 alpha-2 country code from the
 *                              nationality extension, or "".
 *                  [4] string  Composition date in Y-m-d format, or "".
 */
function getPatientNameFromJSON($jsonfilename) {
    $resourcetype       = "";
    $patientname        = "example";
    $patientcountrycode = "";
    $compositiondate    = "";

    if (is_file($jsonfilename)) {
        $maxFileSize = 10 * 1024 * 1024;  // 10 MB limit
        if (filesize($jsonfilename) > $maxFileSize) {
            handleError("ERROR", "oversized JSON file skipped: $jsonfilename");
            return [$resourcetype, "example", md5("example"), "", ""];
        }    
        $pnfc = file_get_contents($jsonfilename);
        if ($pnfc !== FALSE) {
            $jsonData = json_decode($pnfc, FALSE);

            if (isset($jsonData->resourceType))
                $resourcetype = "(" . $jsonData->resourceType . ")";

            if (!isset($jsonData->entry)) {
                handleError("ERROR", "SYNDERAI: missing entry array in $jsonfilename");
                return [$resourcetype, $patientname, md5($patientname), $patientcountrycode, $compositiondate];
            }

            foreach ($jsonData->entry as $e) {

                // Extract composition date from the Composition resource
                if ($e->resource->resourceType === 'Composition') {
                    if (isset($e->resource->date))
                        $compositiondate = date('Y-m-d', strtotime($e->resource->date));
                }

                // Extract patient display data from the Patient resource
                if ($e->resource->resourceType === 'Patient') {

                    // Attempt name resolution in priority order
                    if (isset($e->resource->name[0]->text)) {
                        $patientname = (string) $e->resource->name[0]->text;
                    } elseif (isset($e->resource->name[2]->text)) {
                        $patientname = (string) $e->resource->name[2]->text;
                    } elseif (isset($e->resource->name[0]->family)) {
                        $patientname = "";
                        if (isset($e->resource->name[2]->given)) {
                            $patientname = is_array($e->resource->name[2]->given)
                                ? (string) implode(" ", $e->resource->name[2]->given)
                                : (string) $e->resource->name[2]->given;
                        }
                        $patientname .= (string) $e->resource->name[0]->family;
                    }

                    // Append current age in years if birthDate is present
                    if (isset($e->resource->birthDate)) {
                        $age          = date('Y') - date('Y', strtotime($e->resource->birthDate));
                        $patientname .= " ($age)";
                    }

                    // Extract ISO country code from the patient-nationality extension
                    if (isset($e->resource->extension)) {
                        foreach ($e->resource->extension as $e) {
                            if ($e->url === "http://hl7.org/fhir/StructureDefinition/patient-nationality")
                                if (isset($e->extension[0]->valueCodeableConcept->coding[0]->code))
                                    $patientcountrycode = (string) $e->extension[0]->valueCodeableConcept->coding[0]->code;
                        }
                    }

                    break;  // Patient found — no need to continue iterating entries
                }
            }
        }
    }

    return [
        $resourcetype,
        $patientname,
        md5($patientname),   // stable deduplication key across multiple files
        $patientcountrycode,
        $compositiondate
    ];
}


/**
 * Display an error message in the browser and optionally halt execution.
 *
 * Wraps the message in a <div class='severitymessage'> and closes the <body>
 * tag before echoing. When $severity is FATAL (-1), die() is called
 * immediately after output so no further processing occurs.
 *
 * @param  int    $severity  One of the FATAL, WARNING, or ERROR constants.
 *                           Only FATAL causes the script to halt.
 * @param  string $text      Error message. Newline characters are converted
 *                           to <br/> for HTML display.
 *
 * @return void  Does not return when $severity === FATAL.
 */
function handleError($severity, $text) {
    $text = str_replace("\n", "<br/>", $text);
    $OUT  = "<div class='severitymessage'>" . $text . "</div>";
    $OUT .= "</body>";
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
    echo $OUT;
    if ($severity == FATAL) die;
}


/**
 * Obfuscate an e-mail address to hinder spam-harvesting bots.
 *
 * Randomly encodes each character of $email_address as one of:
 *   - An HTML decimal entity  (&#NNN;)
 *   - A plain ASCII character
 *   - A URL percent-encoded byte (%XX) — only when $hex_encoding > 0
 * The "@" character is always encoded as &#64; regardless of the random
 * selection to ensure it is never present as a literal in the output.
 *
 * @param  string $email_address   The e-mail address to obfuscate.
 * @param  int    $hex_encoding    0 = use only entity and plain-text encodings.
 *                                 1 = also allow percent-encoding (%XX).
 *                                 Default: 0.
 *
 * @return string  The obfuscated e-mail address, safe for HTML output.
 */
function antispambot($email_address, $hex_encoding = 0) {
    $email_no_spam_address = '';
    for ($i = 0, $len = strlen($email_address); $i < $len; $i++) {
        $j = rand(0, 1 + $hex_encoding);
        if (0 == $j) {
            $email_no_spam_address .= '&#' . ord($email_address[$i]) . ';';
        } elseif (1 == $j) {
            $email_no_spam_address .= $email_address[$i];
        } elseif (2 == $j) {
            $email_no_spam_address .= '%' . zeroise(dechex(ord($email_address[$i])), 2);
        }
    }
    return str_replace('@', '&#64;', $email_no_spam_address);
}


/**
 * Left-pad a value with zeroes to a minimum string length.
 *
 * A thin wrapper around sprintf() used by antispambot() to produce
 * two-character hex escape sequences (e.g. "0a" instead of "a").
 *
 * @param  int|string $number     The value to pad.
 * @param  int        $threshold  Minimum output width in characters.
 *
 * @return string  The zero-padded string representation of $number.
 */
function zeroise($number, $threshold) {
    return sprintf('%0' . $threshold . 's', $number);
}


/**
 * Re-encode a value as compact pretty-printed JSON (2-space indentation).
 *
 * PHP's json_encode() uses 4-space indentation by default. This function
 * halves that by post-processing the output with a regex that replaces each
 * run of leading spaces with half as many. JSON_HEX_APOS is also applied so
 * that single quotes are escaped as \u0027, making the output safe for
 * embedding in HTML attributes.
 *
 * @param  mixed  $in  Any JSON-serialisable value (object, array, scalar).
 *
 * @return string  Pretty-printed JSON string with 2-space indentation.
 */
function pretty_json($in): string {
    return preg_replace_callback(
        '/^ +/m',
        function (array $matches): string {
            return str_repeat(' ', strlen($matches[0]) / 2);
        },
        json_encode($in, JSON_PRETTY_PRINT | JSON_HEX_APOS)
    );
}


/**
 * Wrap JSON tokens in colour-coded HTML <span> elements for browser display.
 *
 * Applies a single regex pass over a JSON string and assigns a colour to each
 * recognised token type:
 *
 *   Keys (strings followed by ":")   → red
 *   String values                    → green
 *   Numeric values                   → darkorange
 *   null                             → magenta
 *   true / false                     → darkorange (treated as numbers)
 *
 * HTML special characters (<, >, &) are escaped before the colour pass so
 * that the source JSON cannot inject markup. If the regex replacement fails
 * for any reason, the original (escaped) input is returned unaltered.
 *
 * Intended to be chained with pretty_json() for formatted viewer output:
 *   echo markup_json(pretty_json(json_decode($raw)));
 *
 * @param  string $in  A raw JSON string (not yet HTML-escaped).
 *
 * @return string  HTML string with colour <span> wrappers, safe for echo.
 */
function markup_json(string $in): string {
    $string  = 'green';
    $number  = 'darkorange';
    $null    = 'magenta';
    $key     = 'red';
    $pattern = '/("(\\\\u[a-zA-Z0-9]{4}|\\\\[^u]|[^\\\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/';

    return preg_replace_callback(
        $pattern,
        function (array $matches) use ($string, $number, $null, $key): string {
            $match  = $matches[0];
            $colour = $number;
            if (preg_match('/^"/', $match)) {
                // Strings: key if followed by ":", value otherwise
                $colour = preg_match('/:$/', $match) ? $key : $string;
            } elseif ($match === 'null') {
                $colour = $null;
            }
            return "<span style='color:{$colour}'>{$match}</span>   ";
        },
        str_replace(['<', '>', '&'], ['&lt;', '&gt;', '&amp;'], $in)
    ) ?? $in;
}


// ============================================================================
// UNIT TESTS
// These functions are test cases for pretty_json() and markup_json().
// They use $this->assertEquals() and are intended to be run inside a
// PHPUnit test class — they cannot be called standalone from this script.
// ============================================================================

/**
 * Unit test: pretty_json() with a stdClass object input.
 * Verifies that a single-property object is serialised with 2-space indentation.
 */
function test_pretty_json_object() {
    $ob       = new \stdClass();
    $ob->test = 'unit-tester';
    $json     = pretty_json($ob);
    $expected = <<<JSON
{
  "test": "unit-tester"
}
JSON;
    $this->assertEquals($expected, $json);
}

/**
 * Unit test: pretty_json() with a plain scalar string input.
 * Verifies that a bare string is returned as a quoted JSON string.
 */
function test_pretty_json_str() {
    $ob   = 'unit-tester';
    $json = pretty_json($ob);
    $this->assertEquals("\"$ob\"", $json);
}

/**
 * Unit test: markup_json() chained with pretty_json() and json_decode().
 * Verifies that a compact JSON array is expanded and colour-coded correctly,
 * with each token wrapped in the expected <span style='color:...'> element.
 */
function test_markup_json() {
    $json = <<<JSON
[{"name":"abc","id":123,"warnings":[],"errors":null},{"name":"abc"}]
JSON;
    $expected = <<<STR
[
  {
    <span style='color:red'>"name":</span> <span style='color:green'>"abc"</span>,
    <span style='color:red'>"id":</span> <span style='color:darkorange'>123</span>,
    <span style='color:red'>"warnings":</span> [],
    <span style='color:red'>"errors":</span> <span style='color:magenta'>null</span>
  },
  {
    <span style='color:red'>"name":</span> <span style='color:green'>"abc"</span>
  }
]
STR;
    $output = markup_json(pretty_json(json_decode($json)));
    $this->assertEquals($expected, $output);
}


// ============================================================================
// STRING HELPERS
// ============================================================================

/**
 * Test whether $haystack begins with $needle.
 *
 * @param  string $haystack  The string to test.
 * @param  string $needle    The expected prefix.
 *
 * @return bool  TRUE if $haystack starts with $needle, FALSE otherwise.
 */
function startsWith($haystack, $needle) {
    return substr_compare($haystack, $needle, 0, strlen($needle)) === 0;
}

/**
 * Test whether $haystack ends with $needle.
 *
 * @param  string $haystack  The string to test.
 * @param  string $needle    The expected suffix.
 *
 * @return bool  TRUE if $haystack ends with $needle, FALSE otherwise.
 */
function endsWith($haystack, $needle) {
    return substr_compare($haystack, $needle, -strlen($needle)) === 0;
}


// ============================================================================
// SEMVER HELPER
// ============================================================================

/**
 * Find the sub-folder with the highest Semantic Version in a directory.
 *
 * Scans $directory for immediate child folders whose names match the SemVer
 * pattern:
 *   MAJOR.MINOR.PATCH           e.g. "1.5.0"
 *   MAJOR.MINOR.PATCH+BUILD     e.g. "1.5.0+20260316"
 *
 * Folders are compared in this order of precedence:
 *   1. MAJOR (numeric, ascending)
 *   2. MINOR (numeric, ascending)
 *   3. PATCH (numeric, ascending)
 *   4. Build metadata (the part after "+"):
 *        - A folder without metadata is considered lower than one with metadata.
 *        - If both have metadata, they are compared numerically when both are
 *          purely numeric, or lexicographically otherwise.
 *
 * This means "1.5.0+20260316" > "1.5.0+20251023" > "1.5.0" > "1.4.9".
 *
 * @param  string $directory  Path to the directory to scan (e.g. "examples/LAB").
 *
 * @return string|null  The name of the highest-versioned sub-folder (e.g.
 *                      "2.0.0+20260318"), or NULL if no matching folders exist.
 *
 * @throws \InvalidArgumentException  If $directory does not exist.
 */
function getLatestSemverFolder(string $directory): ?string {
    if (!is_dir($directory)) {
        throw new InvalidArgumentException("Directory does not exist: $directory");
    }

    // Match folders like "1.5.0" or "1.5.0+20260316"
    $semverPattern = '/^(\d+)\.(\d+)\.(\d+)(?:\+(\w+))?$/';

    $folders = array_filter(
        scandir($directory),
        fn($entry) =>
            $entry !== '.' &&
            $entry !== '..' &&
            is_dir($directory . DIRECTORY_SEPARATOR . $entry) &&
            preg_match($semverPattern, $entry)
    );

    if (empty($folders)) {
        return null;
    }

    usort($folders, function (string $a, string $b) use ($semverPattern): int {
        preg_match($semverPattern, $a, $matchesA);
        preg_match($semverPattern, $b, $matchesB);

        // Compare MAJOR, MINOR, PATCH numerically
        foreach ([1, 2, 3] as $i) {
            $cmp = (int)$matchesA[$i] <=> (int)$matchesB[$i];
            if ($cmp !== 0) return $cmp;
        }

        // Core versions are equal — compare build metadata
        $buildA = $matchesA[4] ?? '';
        $buildB = $matchesB[4] ?? '';

        if ($buildA === '' && $buildB === '') return 0;
        if ($buildA === '') return -1;   // no metadata < any metadata
        if ($buildB === '') return 1;

        // Numeric build metadata (e.g. dates as YYYYMMDD) compared as integers;
        // non-numeric metadata compared lexicographically
        return is_numeric($buildA) && is_numeric($buildB)
            ? (int)$buildA <=> (int)$buildB
            : strcmp($buildA, $buildB);
    });

    return end($folders);  // highest version is last after ascending sort
}

/**
 * Return the substring of $inthat that follows the first occurrence of $needle.
 *
 * @param  string      $needle  The delimiter to search for.
 * @param  string      $inthat  The string to search within.
 *
 * @return string|void  The substring after the first occurrence of $needle,
 *                      or void (implicit NULL) if $needle is not found.
 */
function after($needle, $inthat) {
    if (!is_bool(strpos($inthat, $needle))) {
        return substr($inthat, strpos($inthat, $needle) + strlen($needle));
    }
}