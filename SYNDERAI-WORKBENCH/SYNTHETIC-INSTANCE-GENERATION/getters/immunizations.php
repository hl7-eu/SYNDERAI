<?php

// ***
// *** get all random immunizations for this candidate
// ***
// open immunizations
$pdat->immunizations = NULL;
$immunizationshandle = @fopen(SYNTHEADIR . "/immunizations.csv", "r");
$found = array();
lognl(1, "...... List of immunizations for this patient\n");
rewind($immunizationshandle);
while (!feof($immunizationshandle)) {
  $buffer = fgets($immunizationshandle);
  if (strpos($buffer, $candid) !== FALSE) {
    $item = explode(",", $buffer);
    $cvxcode = trim($item[3]);
    $cvxdisplay = trim($item[4]);
    // invent a lot number
    $lotlen = rand(4, 9);  // .. is between 3 chars + 4 ... 9 char
    $lot = random_str(2, "ABCDEFGHIJKLMNOPQRSTUVWXYZ");
    $lot .= "-" . random_str($lotlen);
    $date = substr($item[0], 0, 10); // eg 2024-12-23
    // invent expiration date of vaccine
    $expires = strtotime($date);
    $randdays = rand(28, 372);
    $expires = date('Y-m-d', strtotime("+$randdays day", $expires));
    if (isset($MAP_CVX_2_SNOMED[$cvxcode])) {
      $themap = $MAP_CVX_2_SNOMED[$cvxcode];
      // get SNOMED right with code, display, route and site
      $snomedproperties = get_SNOMED_properties($themap["snomed"]["code"], $cvxdisplay);  // might do a replacement on retired codes as well
      if (strlen($snomedproperties["preferredTerm"]) === 0) {
        lognlsev(1, WARNING, "......... +++ vaccination: SNOMED code for CVX $cvxcode $cvxdisplay not found!");
        registerMapMissing("vaccination: SNOMED code for CVX $cvxcode $cvxdisplay not found");
      }
      // assume SNOMED for site and route are ok, maybe this needs to be verified later but the concept maps are all based on SNOMED
      // get ATC right
      $atcproperties = get_ATC_properties($themap["atc"]["code"]);
      if (strlen($atcproperties["display"]) === 0) {
        lognlsev(1, WARNING, "......... +++ vaccination: information for ATC " . $themap["atc"]["code"] . " not found!");
        registerMapMissing("vaccination: information for ATC " . $themap["atc"]["code"] . " not found");
      }
    } else {
      $info = isset($item[6]) ? trim($item[6]) : "";
      lognlsev(1, ERROR, "Cannot map CVX $cvxcode " . $info);
      registerMapMissing("Cannot map CVX $cvxcode " . $info);
      $themap = NULL;
    }
    if ($themap !== NULL) {        
      $found[$cvxcode] = [
        "date" => $date,
        "cvx" => [
          "code" => $cvxcode,
          "system" => "\$cvx",
          "display" => $cvxdisplay,
        ],
        "snomed" => [
          "code" => $snomedproperties["code"],
          "system" => "\$sct",
          "display" => $snomedproperties["preferredTerm"],
          "preferredTerm" => $snomedproperties["preferredTerm"],
        ],
        "atc" => [
          "code" => $atcproperties["code"],
          "system" => "\$atc",
          "display" => $atcproperties["display"],
        ],
        "lotNumber" => $lot,
        "expires" => $expires,
        "site" => [
          "code" => $themap["site"]["code"],
          "system" => "\$sct",
          "display" => $themap["site"]["display"],
        ],
        "route" => [
          "code" => $themap["route"]["code"],
          "system" => "\$sct",
          "display" => $themap["route"]["display"],
        ],
        "sectionentryslicename" => "immunization"
      ];
      lognl(3, sprintf(
        "......... %-10s %-10s %s",
        $snomedproperties["code"],
        $date,
        $snomedproperties["preferredTerm"]
      ));
      if (strlen($date)<7) {
        lognlsev(1, ERROR, "+++ DATE len<7 $date");
      }
    } else {
      lognlsev(1, ERROR, "Cannot map CVX $cvxcode $cvxdisplay");
      registerMapMissing("Cannot map CVX $cvxcode $cvxdisplay");
    }
  }
}
fclose($immunizationshandle);

if (count($found) === 0) {
  lognlsev(3, WARNING, "......... +++ No immunizations found\n");
  $pdat->immunizations = NULL;
} else {
  // store / handle result
  array_multisort(array_column($found, 'date'), SORT_DESC, $found); // sort by date and report
  $pdat->immunizations = $found;
}
// add an immunization recommendation later

?>