<?php
/*

SYNDERAI © dr Kai Heitmann, HL7 Europe | Privacy Policy • AGPL-3.0 license 
For Background Information and Contributors on Visualize HL7 Example and Test Instances (SYNDERAI)
see "The SYNDERAI Story" on GitHub https://github.com/hl7-eu/SYNDERAI/

Directory setup

+- bin/lib
        contains the markup support (http://parsedown.org)
+- assets
        contains css and js subdirectories and repsective files in there that are used for the SYNDERAI environment
+- img
        contains images for the website
+- tmpl
        contains the HTML template files with placeholder markers that are replaced by index.php
        
+- config.php
        what it says

+- examples
        contains 1 folder per artifact, e.g. EPS
        and 1 info file named info-{artifact}.txt per per artifact
        see README.md there

*/

// PHP init sets
// available memory shall be sufficient
ini_set('memory_limit','2048M');
// don't use PCRE's Just-in-Time-Compiler
ini_set("pcre.jit", "0");

// script short and long name and version number
define("SYNDERAINAME", "SYNDERAI");
define("SYNDERAIVERSION", "2.0");
define("SYNDERAITITLE", "Synthetic Data Examples – Realistic – using AI (SYNDERAI)");
define("SYNDERAITEASER", "Synthetic Data Examples – Realistic – using AI (SYNDERAI), pronounced /ˈsɪn.də.raɪ/");
define("SYNDERAICONTACT", "kai.heitmann@hl7europe.org");

// markdown support
include('bin/Parsedown.php');
$parsedown = new Parsedown();
// $t = file_get_contents('STORY.md');
// echo $parsedown->text($t);

define("FATAL", -1);
define("WARNING", 1);
define("ERROR", 2);

define("INERROR", TRUE);

// preset variables
$SELFSCRIPT = "index.php"; // $_SERVER['PHP_SELF'];

// include the focus config settings FOCUSCONFIG
require "config.php";

if (!isset($MENU)) {
    handleError(FATAL, "+++ config file does not contain proper app menu definitions (MENU)");
}

$CURRENTMENU = $MENU[0]; // assume for now we show the index page

// get URL parameter and process it
foreach ($MENU as $ix => $m) {
    $tm = $m['menu'];
    // echo "$CURRENTMENU--$tm.";
    // var_dump($_GET['menu']);
    $sp = (isset($_GET['menu']) > 0) ? htmlspecialchars($_GET['menu']) : "";
    if ($sp === $tm) {
        $CURRENTMENU = $MENU[$ix];  // exact match of the menu items
    } else if (preg_match('/^examples\/*/', $sp)) {
      $CURRENTMENU = [
        "title" => "Examples",
        "menu" => $sp,
        "file" => "-"
      ]; 
    }
}

// var_dump($CURRENTMENU);exit;

$content = file_get_contents("tmpl/index.html");
$content = str_replace("%%TITLE%%", SYNDERAITITLE, $content);

// make navigation ul li
$nav = "<div class=\"logo\"><img src=\"img/HL7_Europe_RGB_2.png\" alt=\"logo\"></img></div>";
$nav .= "<ul class='nav-links' id='navLinks'>";
if (FALSE) {
    $nav .= "<li>";
    $nav .= "<a href='?menu=index'>Home</a>";
    $nav .= "</li>";
} else {
    foreach ($MENU as $m) {
        $title = $m['title'];
        $url = $SELFSCRIPT . "?menu=" . $m['menu'];
        $nav .= "<li>";
        if ($m['menu'] === $CURRENTMENU['menu']) {
          $nav .= "<a href=\"$url\" style=\"cursor: not-allowed; opacity: 0.5; text-decoration: none;\">$title</a>";
        } else {
          $nav .= "<a href=\"$url\">$title</a>";
        }
        $nav .= "</li>";
      }
}
$nav .= "</ul>";

$content = str_replace("%%NAVULLI%%", $nav, $content);

// var_dump($CURRENTMENU);

if ($CURRENTMENU['menu'] === 'index') {

    // set body background image
    $content = str_replace("%%BACKGROUNDIMG%%", "index-image", $content);

    // this is the landing page with main header and features

    $tmp = file_get_contents("tmpl/header.html");
    $content = str_replace("%%HEADER%%", $tmp, $content);
    
    $tmp = file_get_contents("tmpl/features.html");
    $OUT = str_replace("%%MAIN%%", $tmp, $content);

} else if ($CURRENTMENU['menu'] === 'examples') {

  // set body background image
  $content = str_replace("%%BACKGROUNDIMG%%", "page-image", $content);

  // create example category list
  $allexamplecategories = glob("examples/*");
  $eclist = "";
  foreach ($allexamplecategories as $ec) {
    if (is_dir($ec)) { // only look at the artifact folders
      /*
      * look into the artifact folder, it look like this
      * +-- LAB
      *     +-- 1.0.0+20251023
      *     +-- 2.0.0+20260318
      * 1. Identify the most recent version based on the semver of the packages
      * 2. For that: count files in the category directory, look at basename as we might have 
      *    .json and .xml for the same artifact and also count number of distinct
      *    patients (e.g. lab may have 1 to many lab reports per patient)
      */
      $filesincat = array();
      $patientsincat = array();
      $latestexamplesfolder = getLatestSemverFolder($ec);
      foreach (glob($ec . "/" . $latestexamplesfolder . "/Bundle*") as $f) {
        $fp = pathinfo(basename($f), PATHINFO_FILENAME);
        $filesincat[$fp] = 1;
        if (endsWith($f, ".json")) {  // this is a JSON Bundle
          list ($resourcetype, $patientname, $patientmd5) = getPatientNameFromJSON ($f);
          // echo "$f - $patientname - " . $patientmd5 . "<br/>";
          $patientsincat[$patientmd5] = 1;
        }
      }
      // var_dump($filesincat);var_dump($patientsincat);exit;
      $catcount = count($filesincat);
      $patcount = count($patientsincat);
      // get info file for the respective directory
      $baseec = basename($ec);
      if (is_file("examples/info-$baseec.txt")) {
        $infocontent = file_get_contents("examples/info-$baseec.txt");
        $infolines = explode("\n", $infocontent);
        foreach ($infolines as $il) {
          if (substr(trim($il), 0, 1) !== "#") {
            $info = explode("|", $il);
            $ectitle = trim($info[0]);
            $ecshort = trim($info[1]);
            $ecdesc = trim($info[2]);
            $ecvi7eti = trim($info[3]);
            $ecicon = trim($info[4]);
            $ecicolor = trim($info[5]);
            // var_dump($info);
            // echo "($ecicon)($ecicolor)---";
          }
        }
      } else {
        $ectitle = $ec;
        $ecshort = "-";
        $ecdesc = "-";
        $ecvi7eti = "";
        $ecicon = "mdi-file-document-multiple";
        $ecicolor = "vi7eti_regular";
        
      }
      $eclist .= "<div class=\"feature-card\" data-animate>";
      $eclist .= "<i class=\"mdi $ecicon $ecicolor\"></i>";
      $eclist .= "<h3>$ectitle</h3>";
      $eclist .= "<p><strong>$patcount synthetic patients</strong> with<br/><strong>$catcount</strong> $ecdesc";
      $eclist .= "<br><small>(Package $latestexamplesfolder)</small></p>";
      $eclist .= "<a href=\"index.php?menu=$ec\" class=\"learn-more-btn\">VISIT</a>";
      $eclist .= "</div>";
    }
  }

  // this is example overview page, render it using the examples template
  $tmp = file_get_contents('tmpl/examples.html');
  $tmp = str_replace("%%EXAMPLECATEGORIES%%", $eclist, $tmp);
  $tmp = str_replace("%%TEASER%%", SYNDERAITEASER, $tmp);
  
  $OUT = str_replace("%%MAIN%%", $tmp, $content);
  $OUT = str_replace("%%HEADER%%", "", $OUT);

} else if (preg_match('/^examples\/*/', $CURRENTMENU['menu'])) {
  
  $baseme = basename($CURRENTMENU['menu']);

  // set body background image
  $content = str_replace("%%BACKGROUNDIMG%%", "page-image", $content);

  $tmp = file_get_contents('tmpl/listing.html');
  $tmp = str_replace("%%TEASER%%", SYNDERAITEASER, $tmp);

  // get info file from the respective directory
  if (is_file("examples/info-$baseme.txt")) {
    $infocontent = file_get_contents("examples/info-$baseme.txt");
    $infolines = explode("\n", $infocontent);
    foreach ($infolines as $il) {
      if (substr(trim($il), 0, 1) !== "#") {
        $info = explode("|", $il);
        $ectitle = trim($info[0]);
        $ecshort = trim($info[1]);
        $ecdesc = trim($info[2]);
        $ecvi7eti = trim($info[3]);
        // var_dump($info);exit;
      }
    }
  } else {
    $ectitle = $baseme;
    $ecshort = "-";
    $ecdesc = "-";
    $ecvi7eti = "";
  }
  // build the list of example files
  $latestexamplesfolder = getLatestSemverFolder($CURRENTMENU['menu']);
  // var_dump(glob($CURRENTMENU['menu'] . "/" . $latestexamplesfolder . "/*"));exit;
  $allexamples = glob($CURRENTMENU['menu'] . "/" . $latestexamplesfolder . "/Bundle*");
  // var_dump($allexamples);
  $exary = [];
  foreach ($allexamples as $ex) {
    $base = basename($ex);
    $finfo = pathinfo($ex);
    // get filename and extension
    $fname = $finfo['filename'];
    $fext = $finfo['extension'];
    $fdir = $finfo['dirname'];
    // get name of synthetic patient, i.e. name and its md5 value, country code
    // also get the composition date and the resource type
    list ($resourcetype, $patientname, $patientmd5, $patientcountrycode, $compositiondate) = getPatientNameFromJSON ("$fdir/$fname.json");

    // add array entry with filename and information about existence of filename.json and filename.xml
    // store the $patientname string as MD5 and create / add items to an array of all of his examples
    $exary[$fname] = [
      "json" => is_file("$fdir/$fname.json") ? "$fdir/$fname.json" : FALSE,
      "xml" => is_file("$fdir/$fname.xml") ? "$fdir/$fname.xml" : FALSE,
      "focus" => basename($CURRENTMENU['menu']),
      "vi7eti" => $ecvi7eti,
      "patientname" => $patientname,
      "patientmd5" => $patientmd5,
      "resourcetype" => $resourcetype,
      "compositiondate" => $compositiondate
    ];
    $patdata[$patientmd5] = [
      "patientname" => $patientname,
      "patientmd5" => $patientmd5,
      "resourcetype" => $resourcetype,
      "ecshort" => $ecshort,
      "countrycode" => $patientcountrycode
    ];
    $exlist[$patientmd5] = "";  // init output for this patient
    
  }
  // var_dump($exary);exit;

  $exlist = array();
  foreach ($exary as $exk => $exf) {
    // 1. hush through all examples and collect single table rows of all examples for the same patient in "his" md5 key bucket
    $showlink = ($exf['json'] ? "json=" : ($exf['xml'] ? "xml=" : ""));
    if ($showlink !== '') {
      $showlink = $VI7ETIDEEPLINK . $showlink;
      $showlink .= $exf['vi7eti'] . "&url=" . "$SELFURL/";
      $showlink .= $exf['json'] ? $exf['json'] : $exf['xml'];
      $jsonl = $exf['json'] ? "<a target=\"_blank\" rel=\"noopener noreferrer\" href=\"" . $exf['json'] . "\">JSON </a>" : "";
      $xmll  = $exf['xml']  ? "<a target=\"_blank\" rel=\"noopener noreferrer\" href=\"" . $exf['xml'] . "\">XML </a>" : "";
      $thedate = strlen($exf["compositiondate"]) > 0 ? date('d-M-Y', strtotime($exf["compositiondate"])) : "##CT##";
      $thesortdate = strlen($exf["compositiondate"]) > 0 ? date('Ymd', strtotime($exf["compositiondate"])) : "missingdate";
      if (isset($exlist[$exf["patientmd5"]])) {
        $exlist[$exf["patientmd5"]] .= "<tr class=\"" . $thesortdate . " " . $exf["patientmd5"] . " ##TR##\"><td class=\"smaller\">$thedate</td><td><a href=\"$showlink\">View</a></td><td>$jsonl</td><td>$xmll</td><tr>\n";
      } else {
        $exlist[$exf["patientmd5"]]  = "<tr class=\"" . $thesortdate . " " . $exf["patientmd5"] . " ##TR##\"><td class=\"smaller\">$thedate</td><td><a href=\"$showlink\">View</a></td><td>$jsonl</td><td>$xmll</td><tr>\n";
      }
    }
  }

  $maxrowstoshowinitially = 4;
  $allexampleitemsout = "";
  foreach ($exlist as $pkey => $prow) {    
    // 2. now we have per patient all table rows with "his" exmamples, compile "his" example item card

    $allexampleitemsout .= "<div class=\"example-item\">";
    $allexampleitemsout .= "<div>";
    $allexampleitemsout .= "<i class=\"mdi mdi-file-outline\"> </i> " . $patdata[$pkey]["ecshort"] . " ". $patdata[$pkey]["resourcetype"];
    $allexampleitemsout .= "</div>";
    $allexampleitemsout .= "<div class=\"identifier\">";
    $allexampleitemsout .= $patdata[$pkey]["patientname"];
    // process country code for flag
    if (is_file("img/flags/" . strtolower($patdata[$pkey]["countrycode"]) . ".png")) {
      $allexampleitemsout .= " <img width='24px' src=\"img/flags/" . strtolower($patdata[$pkey]["countrycode"]) . ".png\"> </img>";
    }
    $allexampleitemsout .= "</div>";

    // create the links class for this patient
    $allexampleitemsout .= "<div class=\"links\">";

    $allexampleitemsout .= "<table class=\"toggle-table\"><tbody>";
    $allexampleitemsout .= "<tr class=\"showalways\"><td> </td><td><i class=\"mdi mdi-eye\"> </i></td><td colspan=\"2\"><i class=\"mdi mdi-download\"> </i></td><tr>";

    $prowary = explode("\n", $prow);
    rsort($prowary);
    $count = 0;
    foreach ($prowary as $p) {
      // hush through all examples and emit the example items for the patient using his table rows in "his" md5 key bucket
      $count++;
      $TRclass = $count <= $maxrowstoshowinitially ? "showalways" : "showondemand";
      $p = str_replace("##TR##", $TRclass, $p);
      $allexampleitemsout .= str_replace("##CT##", "$count", $p);
    }

    $allexampleitemsout .= "</tbody></table>";

    $allexampleitemsout .= "</div>"; // links

    $allexampleitemsout .= "</div>"; // example-item

  }
  
  $tmp = str_replace("%%EXAMPLELISTING%%", $allexampleitemsout, $tmp);
  $tmp = str_replace("%%TITLE%%", $ectitle, $tmp);
  $tmp = str_replace("%%PACKAGE%%", $latestexamplesfolder, $tmp);

  $OUT = str_replace("%%MAIN%%", $tmp, $content);
  $OUT = str_replace("%%HEADER%%", "", $OUT);

} else {

    // set body background image
    $content = str_replace("%%BACKGROUNDIMG%%", "page-image", $content);

    // this is an simple info page, render it using the info template
    $file = $CURRENTMENU['file'];
    $tmp = file_get_contents('tmpl/info.html');
    $tmp = str_replace("%%TEASER%%", SYNDERAITEASER, $tmp);
    $render = file_get_contents($file);
    $render = substr($render, strpos($render, '# '));
    $render = $parsedown->text($render);
    $tmp = str_replace("%%RENDITION%%", $render, $tmp);
    $OUT = str_replace("%%MAIN%%", $tmp, $content);
    $OUT = str_replace("%%HEADER%%", "", $OUT);

}

// finally the footer
$content = file_get_contents('tmpl/footer.html');
$content = str_replace("%%NAME%%", SYNDERAINAME, $content);
$content = str_replace("%%VERSION%%", SYNDERAIVERSION, $content);
$content = str_replace("%%CONTACTEMAIL%%", "mailto:" . antispambot(SYNDERAICONTACT), $content);
$content = str_replace("%%CURRENTYEAR%%", date('Y'), $content);

$OUT = str_replace("%%FOOTER%%", $content, $OUT);

echo $OUT;

exit;

/*
 *  END KONEC
 */


/**
* @param string $jsonfilename
* @return array [ $resourcetype, $patientname, $$patientmd5, $patientcountrycode, $compositiondate ]
*/
function getPatientNameFromJSON ($jsonfilename) {
  $resourcetype = "";
  $patientname = "example";
  $patientcountrycode = "";
  $compositiondate = "";
  if (is_file($jsonfilename)) {
    $pnfc = file_get_contents($jsonfilename);
    if ($pnfc !== FALSE) {
      $jsonData = json_decode($pnfc, FALSE);
      if (isset($jsonData->resourceType)) $resourcetype = "(" . $jsonData->resourceType . ")";
      if (!isset($jsonData->entry)) die ("+++" . "$fdir/$fname.json" . " has no entry???");
      foreach ($jsonData->entry as $e) {
        if ($e->resource->resourceType === 'Composition') {
          if (isset($e->resource->date)) $compositiondate = date('Y-m-d', strtotime($e->resource->date));
          // var_dump($compositiondate);exit;
        }
        if ($e->resource->resourceType === 'Patient') {
          // var_dump($e->resource->name[0]->text);
          // var_dump(isset($e->resource->name[0]->text));exit;
          if (isset($e->resource->name[0]->text)) {
            $patientname = (string) $e->resource->name[0]->text;
          } else if (isset($e->resource->name[2]->text)) {
            $patientname = (string) $e->resource->name[2]->text;
          } else if (isset($e->resource->name[0]->family)) {
            $patientname = "";
            // var_dump($e->resource->name);exit;
            if (isset($e->resource->name[2]->given)) {
              if (is_array($e->resource->name[2]->given)) {
                $patientname = (string) implode(" ", $e->resource->name[2]->given);
              } else {
                $patientname = (string) $e->resource->name[2]->given;
              }
            }
            $patientname .= (string) $e->resource->name[0]->family;
          }
          if (isset($e->resource->birthDate)) {
            $age = (date('Y') - date('Y', strtotime($e->resource->birthDate)));
            $patientname .= " ($age)";
          }
          if (isset($e->resource->extension)) {
            foreach ($e->resource->extension as $e) {
              if ($e->url === "http://hl7.org/fhir/StructureDefinition/patient-nationality")
                if (isset($e->extension[0]->valueCodeableConcept->coding[0]->code)) 
                  $patientcountrycode = (string) $e->extension[0]->valueCodeableConcept->coding[0]->code;
            }
          }
          break;
        }
      }
    }
  }

  $retval = array();
  $retval[] = $resourcetype;
  $retval[] = $patientname;
  $retval[] = md5($patientname);
  $retval[] = $patientcountrycode;
  $retval[] = $compositiondate;
  // var_dump($retval);exit;

  return $retval;

}

function handleError ($severity, $text) {
    $text = str_replace("\n", "<br/>", $text);
    $OUT = "";
    $OUT .= "<div class='severitymessage'>" . $text . "</div>";
    $OUT .= "</body>";
    echo $OUT;
    if ($severity == FATAL) die;
}



/**
* @param string $email
* @return string
*/

function antispambot( $email_address, $hex_encoding = 0 ) {
    $email_no_spam_address = '';
    for ( $i = 0, $len = strlen( $email_address ); $i < $len; $i++ ) {
        $j = rand( 0, 1 + $hex_encoding );
        if ( 0 == $j ) {
            $email_no_spam_address .= '&#' . ord( $email_address[ $i ] ) . ';';
        } elseif ( 1 == $j ) {
            $email_no_spam_address .= $email_address[ $i ];
        } elseif ( 2 == $j ) {
            $email_no_spam_address .= '%' . zeroise( dechex( ord( $email_address[ $i ] ) ), 2 );
        }
    }

    return str_replace( '@', '&#64;', $email_no_spam_address );
}

function zeroise( $number, $threshold ) {
    return sprintf( '%0' . $threshold . 's', $number );
}

/**
 * takes an object parameter and returns the pretty json format.
 * this is a space saving version that uses 2 spaces instead of the regular 4
 *
 * @param $in
 *
 * @return string
 */
function pretty_json ($in): string
{
  return preg_replace_callback('/^ +/m',
    function (array $matches): string
    {
      return str_repeat(' ', strlen($matches[0]) / 2);
    }, json_encode($in, JSON_PRETTY_PRINT | JSON_HEX_APOS)
  );
}

/**
 * takes a JSON string an adds colours to the keys/values
 * if the string is not JSON then it is returned unaltered.
 *
 * @param string $in
 *
 * @return string
 */

function markup_json (string $in): string
{
  $string  = 'green';
  $number  = 'darkorange';
  $null    = 'magenta';
  $key     = 'red';
  $pattern = '/("(\\\\u[a-zA-Z0-9]{4}|\\\\[^u]|[^\\\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/';
  return preg_replace_callback($pattern,
      function (array $matches) use ($string, $number, $null, $key): string
      {
        $match  = $matches[0];
        $colour = $number;
        if (preg_match('/^"/', $match))
        {
          $colour = preg_match('/:$/', $match)
            ? $key
            : $string;
        }
        elseif ($match === 'null')
        {
          $colour = $null;
        }
        return "<span style='color:{$colour}'>{$match}</span>   ";
      }, str_replace(['<', '>', '&'], ['&lt;', '&gt;', '&amp;'], $in)
   ) ?? $in;
}

function test_pretty_json_object ()
{
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

function test_pretty_json_str ()
{
  $ob   = 'unit-tester';
  $json = pretty_json($ob);
  $this->assertEquals("\"$ob\"", $json);
}

function test_markup_json ()
{
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
  $this->assertEquals($expected,$output);
}

/*****
 * 
 * some string helpers
 * 
 */

function startsWith($haystack, $needle) {
    return substr_compare($haystack, $needle, 0, strlen($needle)) === 0;
}
function endsWith($haystack, $needle) {
    return substr_compare($haystack, $needle, -strlen($needle)) === 0;
}

/*****
 * semver helper
 */
function getLatestSemverFolder(string $directory): ?string
{
    if (!is_dir($directory)) {
        throw new InvalidArgumentException("Directory does not exist: $directory");
    }

    // Match folders like 1.5.0 or 1.5.0+20260316
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

        // Compare major, minor, patch numerically
        foreach ([1, 2, 3] as $i) {
            $cmp = (int)$matchesA[$i] <=> (int)$matchesB[$i];
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        // If core version is equal, compare build metadata
        // No metadata is treated as lower than any metadata
        $buildA = $matchesA[4] ?? '';
        $buildB = $matchesB[4] ?? '';

        if ($buildA === '' && $buildB === '') return 0;
        if ($buildA === '') return -1;
        if ($buildB === '') return 1;

        // Compare build metadata: numeric if both are numeric, string otherwise
        return is_numeric($buildA) && is_numeric($buildB)
            ? (int)$buildA <=> (int)$buildB
            : strcmp($buildA, $buildB);
    });

    return end($folders);
}