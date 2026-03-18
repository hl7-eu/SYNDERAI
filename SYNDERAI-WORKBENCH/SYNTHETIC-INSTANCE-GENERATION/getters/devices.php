<?php

// ***
      // *** get all devices in use for this candidate
      // ***
      // open care plan
      $pdat->devices = NULL;
      $devicehandle = @fopen(SYNTHEADIR . "/devices.csv", "r");
      $found = array();
      lognl(1, "...... List of device use items for this patient\n");
      rewind($devicehandle);
      while (!feof($devicehandle)) {
        $buffer = fgets($devicehandle);
        if (strpos($buffer, $candid) !== FALSE) {
          $item = explode(",", $buffer);
          // var_dump($item);exit;
          // take devices only still in  use
          if (strlen(trim($item[1])) === 0) {
            $start = substr(trim($item[0]), 0, 10);
            $snomed = trim($item[4]);
            $display = trim($item[5]);

            $snomedproperties = get_SNOMED_properties($snomed);
            if ($snomedproperties["code"] !== $snomed) $snomed = $snomedproperties["code"]; // this is a replacement
            $uid = trim($item[6]);

            if (strlen($snomedproperties['fullySpecifiedName']) > 0) $display = $snomedproperties['fullySpecifiedName'];

            $found[] = [
              "start" => $start,
              "code" => $snomed,
              "system" => "\$sct",
              "display" => $display,
              "uid" => $uid
            ];
            lognl(3, sprintf(
              "......... %-10s %-10s %s",
              $snomed,
              $start,
              $display
            ));
          } 
        }
      }
      fclose($devicehandle);
      if (count($found) === 0) {
        lognl(3, "......... +++ No devices in use found\n");
        $pdat->devices = NULL;
      } else {
        // store / handle result
        array_multisort(array_column($found, 'start'), SORT_DESC, $found); // sort by date and report
        // var_dump($found);
        $pdat->devices = $found;
      }

?>