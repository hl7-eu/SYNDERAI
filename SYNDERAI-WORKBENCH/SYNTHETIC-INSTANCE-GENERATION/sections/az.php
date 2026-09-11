<?php


// === recordTarget first, with patient data ===
list($OUTrt) =
  twigit(
    [
      "patient" => $pdat
    ],
    "az-recordTarget"
  );

// === inhoudsverantwoordelijke, with provider data ===
list($OUTp1) =
  twigit(
    [
      "provider" => $pdat->provider
    ],
    "az-participant-resp"
  );


// === conditions as component->act->observation ===
/**
 * Condition summarisation for the patient summary.
 *
 * @see lib/filter-and-adapt-conditions.php
 * @see https://synderai.net/index.php?menu=epsca
 */
$OUToverdrachtconcern = NULL;
$ccount = 0;
if ($pdat->conditions !== NULL) {
  $ccount = count($pdat->conditions);
  $OUTcond = "";
  foreach ($pdat->conditions as $sdata) {
    $episodeid = uuid();
    $conditioninstanceid = uuid();
    $icpcnl = map_concept($sdata["code"]["code"], "cm-snomed-to-icpc1nl");  // find ICPC-1-NL
    list($tmp) =
      twigit(
        [
          "episodeid" => $episodeid,
          "conditionid" => $conditioninstanceid,
          "episode" => NULL,
          "episodetitel" => NULL,
          "condition" => $sdata,
          "icpcnl" => $icpcnl
        ],
        "az-concern"
      );
    $OUTcond .= $tmp . "\n";
    if (isset($icpcnl["code"]) && isset($icpcnl["display"])) {
      lognl(3, "............ ICPC1-nl: " . $icpcnl["code"] . " " . $icpcnl["display"]);
    }
    // var_dump($icpcnl);
    // echo "$tmp\n\n";
  }
  // === overdracht concern, az-REPC_IN990111NL-overdrachtconcern with conditions ===
  list($OUToverdrachtconcern) =
    twigit(
      [
        // metas
        "reportdate" => $maxfoundix,
        "transmissionQuantity" => $ccount,
        // structures
        "provider" => $pdat->provider,
        // CDA XML fragements
        "recordTarget" => $OUTrt,
        "participantresp" => $OUTp1,
        "episodes" => $OUTcond
      ],
      "az-REPC_IN990111NL-overdrachtconcern"
    );
}


// === allergiesintolerances as component->act->observation ===
// also memorize allergies with SEVERE reaction for later processing
$OUTallergiesintolerances = NULL;
$severeAllergies = array();
$aicount = 0;
if ($pdat->allergies !== NULL) {
  $aicount = count($pdat->allergies);
  $OUTaint = "";
  foreach ($pdat->allergies as $sdata) {
    $allergyintoleranceid = uuid();
    list($tmp) =
      twigit(
        [
          "allergyintoleranceid" => $allergyintoleranceid,
          "allergyintolerance" => $sdata,
          "patientname" => $pdat->name
        ],
        "az-allergyintolerance"
      );
    $OUTaint .= $tmp . "\n";
    // echo "$tmp\n\n";

    if (isset($sdata["reaction1"]["severity"])) 
      if ($sdata["reaction1"]["severity"] === "SEVERE")
        $severeAllergies[] = $sdata;
  }
  list($OUTallergiesintolerances) =
    twigit(
      [
        // metas
        "reportdate" => $maxfoundix,
        "transmissionQuantity" => $aicount,
        // structures
        "provider" => $pdat->provider,
        // CDA XML fragements
        "recordTarget" => $OUTrt,
        "participantresp" => $OUTp1,
        "allergyintolerance" => $OUTaint
      ],
      "az-REPC_IN990131NL-allergieintolerantie"
    );
}


// === alerts as component->act->observation ===
// any allergy with SEVERE reaction is an alert
// additionally: randomly chosen for 50% of patients, one of
// 82576008 Retained foreign body in eye
// 312457003 Irregular blood group antibody detected
// 427958009 History of difficult intubation
// 10839421000119104 History of anaphylaxis
// 7831000175106 History of blood transfusion reaction
// 304253006 Not for resuscitation
// 425392003 Active advance directive
// 300648001 Does ride a bicycle
// 221000146108 Driver/rider of vehicle on public road
// 708129006 Transfusion of blood product declined for religious reason
// and if person is age > 60 also possible:
// 129839007 At increased risk for falls
$OUTalerts = NULL;
$alertcodes = array();
$acount = 0;
if (rand(1,100) > 50) {
  $alertcodes[] = ["82576008", "312457003", "427958009", "10839421000119104", "7831000175106", "304253006", "425392003", "300648001", "221000146108", "708129006"][rand(0, 9)];
  if ($pdat->age > 60) $alertcodes[] = "129839007";
}
// any severe allergies?
if (count($severeAllergies) > 0) {
  foreach ($severeAllergies as $sdata) {
    if (isset($sdata["code"])) $alertcodes[] = $sdata["code"];
  }
} 
// process alert codes
if ($alertcodes) {
  $acount = count($alertcodes);
  $OUTal = "";
  foreach ($alertcodes as $ac) {
    $ad = get_SNOMED_properties($ac);  // find preferred display name
    lognl(3, "............ Alert: " . $ac . " " . $ad["preferredTerm"]);
    $alertid = uuid();
    list($tmp) =
      twigit(
        [
          "alertid" => $alertid,
          "alertcode" => $ac,
          "alertdisplay" => $ad["preferredTerm"],
          "patientname" => $pdat->name,
          "provider" => $pdat->provider
        ],
        "az-alert"
      );
    $OUTal .= $tmp . "\n";
    // echo "$tmp\n\n";
  }
  list($OUTalerts) =
    twigit(
      [
        // metas
        "reportdate" => $maxfoundix,
        "transmissionQuantity" => $acount,
        // structures
        "provider" => $pdat->provider,
        // CDA XML fragements
        "recordTarget" => $OUTrt,
        "participantresp" => $OUTp1,
        "alerts" => $OUTal
      ],
      "az-REPC_IN990121NL-alert"
    );
} else {
  lognlsev(3, WARNING, "............ +++ No alerts found");
}


// === medication as component->act->observation ===
$OUTmedications = NULL;
$mcount = 0;
if ($pdat->medications !== NULL) {
  $mcount = count($pdat->medications);
  $OUTmeds = "";
  foreach ($pdat->medications as $sdata) {
    $medicationid = uuid();
    list($tmp) =
      twigit(
        [
          "medicationid" => $medicationid,
          "medication" => $sdata,
          "patientname" => $pdat->name
        ],
        "az-medication"
      );
    $OUTmeds .= $tmp . "\n";
    // echo "$tmp\n\n";
  }
  list($OUTmedications) =
    twigit(
      [
        // metas
        "reportdate" => $maxfoundix,
        "transmissionQuantity" => $mcount,
        // structures
        "provider" => $pdat->provider,
        // CDA XML fragements
        "recordTarget" => $OUTrt,
        "participantresp" => $OUTp1,
        "medicatieafspraken" => $OUTmeds
      ],
      "az-QUMA_IN991203-medication"
    );
}

// === MCCI wrapper with CDA XML fragements inside ===
$wrapperid = uuid();
list($OUTCDA) =
  twigit(
    [
      // metas
      "wrapperid" => $wrapperid,
      // counts (as comments)
      "ccount" => $ccount,
      "aicont" => $aicount,
      "acount" => $acount,
      "mcount" => $mcount,
      // CDA XML fragements
      "overdrachtconcerns" => $OUToverdrachtconcern,
      "allergieintoleranties" => $OUTallergiesintolerances,
      "alerts" => $OUTalerts,
      "medications" => $OUTmedications
    ],
    "az-mcci-wrapper"
  );
// echo "\n$OUTCDA\n";

?>