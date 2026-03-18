<?php

	// FHIR JSON 2 XML
	$st = "wrap-fhir-json2xml.xsl";

	ini_set('memory_limit','2048M');

	$javacmd="java -Xmx4096m -jar";
	$javajar="bin/saxon-he-11.5.jar";

	$all = glob('*.json');
	// var_dump($all);exit;

	foreach ($all as $jf) {
		$xf = basename($jf, '.json') . ".xml";
		echo("converting $jf to $xf\n");
	    // Build command
		$command =  $javacmd . " " . $javajar . " -u -xsl:" . $st . " -o:" . $xf . " -it " . "json-file=" . $jf;
		// echo $command . "\n";
		exec($command, $ol, $rv);
	}
