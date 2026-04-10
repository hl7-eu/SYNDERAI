<?php

define('RESOLVEDDIR', 'solve');
define('MISSINGS', '__missings.txt');
define('SOLVED', '__solved.txt');

// --- Input ---
$all = file_get_contents(MISSINGS);
$lines = [
    '310627002 is outdated, finding a replacement was not successful, terms: ||Physical examination procedure|=>5880005 Physical examination procedure (procedure)',
    '51061000 is outdated, finding a replacement was not successful, terms: ||Drug detoxification (procedure)|'
    // Add more lines here as needed
];
$lines = explode("\n", $all);

echo "*** Trying to resolve automatically\n";
foreach ($lines as $line) {
    // Extract original SNOMED code at the start of the line
    if (!preg_match('/^(\d+)/', $line, $codeMatch)) {
        fwrite(STDERR, "+++ Could not parse original code from line: $line\n");
        continue;
    }
    $originalCode = $codeMatch[1];

    // already done?
    if (is_file(RESOLVEDDIR . "/$originalCode")) continue;

    // Extract term between || and the next |
    if (!preg_match('/\|\|([^|]+)\|/', $line, $termMatch)) {
        fwrite(STDERR, "+++ Could not parse term from line: $line\n");
        continue;
    }
    $term = strtolower($termMatch[1]);

    // Build the URL
    $url = 'http://localhost:8080/exist/apps/api/terminology/codesystem'
         . '?string=' . rawurlencode($term)
         . '&context=external%2Fsnomed'
         . '&loincContext=all'
         . '&ancestor=71388002'
         . '&isa='
         . '&refset=';

    // Fetch results
    $json = @file_get_contents($url);
    if ($json === false) {
        fwrite(STDERR, "+++ HTTP request failed for term: $term\n");
        continue;
    }

    $data = json_decode($json, true);
    if (!isset($data['designation']) || !is_array($data['designation'])) {
        fwrite(STDERR, "+++ No designations found for term: $term ($originalCode)\n");
        continue;
    }

    // Output header once per input line
    echo ">>>" . $termMatch[1] . PHP_EOL;
    echo str_pad('original-snomed-code', 22) . ' | '
       . str_pad('new-code',  12) . ' | '
       . 'new-snomed-text' . PHP_EOL;
    echo str_repeat('-', 80) . PHP_EOL;

    foreach ($data['designation'] as $designation) {
        $newCode = $designation['code']   ?? '';
        $newText = $designation['#text']  ?? '';

        // do we have a match?
        $hasfsn = str_contains($termMatch[1], "(procedure)") or str_contains($termMatch[1], "(regime/therapy)");
        $withnofsn = trim(str_replace("(procedure)", "", $termMatch[1]));
        $withnofsn = trim(str_replace("(regime/therapy)", "", $withnofsn));
        $match = 
            ((strtolower($newText) === strtolower($termMatch[1])) or
             (strtolower($newText) === strtolower($withnofsn))) ? 1 : 0;
        // echo "##1" . (strtolower($newText) === strtolower($termMatch[1])) . "\n";
        // echo "##2" . (strtolower($newText) === strtolower($withnofsn)) . "\n";
        // echo "##3" . $hasfsn . "\n";
        // echo "##4 (" . strtolower($newText) . ") == (" . strtolower($termMatch[1]) . ") / (" . strtolower($withnofsn) . ")" . "\n";
        if ($match && $hasfsn) {
            $newwithfsn = $newText . (str_contains($termMatch[1], "(procedure)") ? " (procedure)" : " (regime/therapy)") ;
        } else {
            $newwithfsn = "";
        } 
        // echo "##5" . $match . "\n";
        // echo "##6" . $newText . "\n";

        echo str_pad($originalCode, 22) . ' | '
           . str_pad($newCode . ($match ? "*" : ""), 12) . ' | '
           . $newText . PHP_EOL;

        if ($match) {
            // write to file
            $out = "$newCode|$newText|$newwithfsn||||" . PHP_EOL;
            file_put_contents(RESOLVEDDIR . "/$originalCode", $out);
        }
    }

    echo PHP_EOL;
}

echo "*** Resolving manually assignments\n";
$solved = file_get_contents(SOLVED);
$cnt = 0;
foreach (explode("\n", $solved) as $line) {
    $cnt++;
    $item = explode("|", $line);
    $oldcode = trim($item[0]);
    if (strlen($oldcode) === 0) continue;
    // already done?
    if (is_file(RESOLVEDDIR . "/$oldcode")) continue;
    $newcode = trim($item[1]);
    if (!isset($newcode) || $newcode === NULL || $newcode === "") {
        echo "+++ Code missing? Line $cnt\n";
    } else {
        $newdisplay = trim($item[2]);
        echo "... adding $oldcode => $newcode '$newdisplay'\n";
        $out = "$newCode|$newdisplay|$newdisplay||||" . PHP_EOL;
        file_put_contents(RESOLVEDDIR . "/$oldcode", $out);
    }
}