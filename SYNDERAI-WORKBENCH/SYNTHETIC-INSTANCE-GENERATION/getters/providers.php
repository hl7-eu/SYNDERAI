<?php

// ***
// *** get all synthetic providers per country
// ***
lognl(1, "... load synthetic providers per country\n");
$handle = fopen(SYNTHEADIR . "/provider_synthetic.csv","r");
$SYNTHETICPROVIDERS = array();
while (($buffer = fgetcsv($handle, 10000, ",", '"', '\\')) !== FALSE) {
  $drtitle = in_array(trim($buffer[4]), ["de", "ch", "at", "lu"]) ? "Dr." : "dr";
  $dr = (rand(0, 100) <= 35) ? $drtitle : ""; // 35% as dr / Dr.
  $SYNTHETICPROVIDERS[] = [
    "prefix" => $dr,
    "given" => trim($buffer[0]),
    "family" => trim($buffer[1]),
    "gender" => trim($buffer[2]),
    "language" => trim($buffer[4]),
    "country" => trim($buffer[8]),
    "phone" => trim($buffer[11]),
    "lat" => trim($buffer[9]),
    "long" => trim($buffer[10])
  ];
}
fclose($handle);
if (count($SYNTHETICPROVIDERS) === 0) {
  lognl(3, "...... +++ No synthetic providers found\n");
}
// var_dump($SYNTHETICPROVIDERS);exit;
?>