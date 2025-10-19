<?php

require __DIR__ . '/autoload.php';

/* INCLUDES */
include_once("../CONSTANTS/constants.php");

// Twig is a modern template engine for PHP

function twigit ($data, $with) {

    $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/templates');

    $twig = new \Twig\Environment($loader, [
        'cache' => __DIR__ . '/cache', // optional but recommended in production
        'auto_reload' => true,         // recompile when templates change
        //'debug' => true,             // enable if you need the debug extension
    ]);

    $bag = new ArrayObject();         // new per request!
    $twig->addGlobal('bag', $bag);    // register global twig html bag
    $bag['html'] = "";                // init html bag
    $bag['instance'] = array();       // init instance(s) bag, array of strings
    $bag['heading'] = "";             // init heading bag

    /*********
     * add and emit HTML functions
     */
    $twig->addFunction(new \Twig\TwigFunction('addHTML_tr', function () use($bag) {
        $bag['html'] .= "<tr>";        // add <tr>
        return "";
    }));

    $twig->addFunction(new \Twig\TwigFunction('addHTML_trend', function () use ($bag) {
        $bag['html'] .= "</tr>";      // add </tr>
        return "";
    }));

    $twig->addFunction(new \Twig\TwigFunction('addHTML_td', function ($value) use ($bag) {
        $bag['html'] .= "<td>$value</td>";  // embrace string value with <td></td>
    }));

    $twig->addFunction(new \Twig\TwigFunction('addHTML_tdgray', function ($value) use ($bag) {
        $bag['html'] .= "<td><span class='grayedout'>" . $value . "</span></td>";
    }));

    $twig->addFunction(new \Twig\TwigFunction('addHTML_tdnb', function ($value) use ($bag) {
        $bag['html'] .= "<td><span class='nb'>" . $value . "</span></td>";
    }));

    $twig->addFunction(new \Twig\TwigFunction('emitHTML', function () use ($bag) {
        return $bag['html'];          // return html bag, alternatively in twig {{ html }}
    }, ['is_safe' => ['html']]));


    /*********
     * add and emit HEAD for table functions
     */
    $twig->addFunction(new \Twig\TwigFunction('addHEAD_tr', function () use($bag) {
        $bag['heading'] .= "<tr>";        // add <tr>
        return "";
    }));

    $twig->addFunction(new \Twig\TwigFunction('addHEAD_trend', function () use ($bag) {
        $bag['heading'] .= "</tr>";      // add </tr>
        return "";
    }));

    $twig->addFunction(new \Twig\TwigFunction('addHEAD_th', function ($value) use ($bag) {
        $bag['heading'] .= "<th>$value</th>";  // for table heading embrace string value with <th></th>
    }));

    $twig->addFunction(new \Twig\TwigFunction('emitHEAD', function () use ($bag) {
        return $bag['heading'];          // return heading bag, alternatively in twig {{ heading }}
    }, ['is_safe' => ['html']]));
    
    
    /*********
     * helper functions
     */
    $twig->addFunction(new \Twig\TwigFunction('setInstance', function ($value) use ($bag) {
        $bag['instance'][] = $value;
        return $value;
    }));

    $twig->addFunction(new \Twig\TwigFunction('getUUID', function () use ($bag) {
        return uuid();
    }));

    /*********
     * synthetic data policy and provenance parts
     */
    $twig->addFunction(new \Twig\TwigFunction('syntheticDataPolicyMeta', function () use ($bag) {
        return implode("\n", SYNDERAI_SYNTHETIC_DATA_POLICY_META);
    }));
    


    // add some global data for rendition
    $data["HL7EUROPEEXAMPLESOID"] = HL7EUROPEEXAMPLESOID;
    // $rendition = $twig->render("ObservationPregnancyStatusUvIps.fsh.twig", $vars);
    $rendition = $twig->render("$with.fsh.twig", $data);

    if (!str_contains($rendition, '%%FSH%%'))  echo "+++Error: twig rendition does not contain required %%FSH%% tag!\n";
    if (!str_contains($rendition, '%%HEAD%%')) echo "+++Error: twig rendition does not contain required %%HEAD%% tag!\n";
    if (!str_contains($rendition, '%%HTML%%')) echo "+++Error: twig rendition does not contain required %%HTML%% tag!\n";

    $split1 = explode('%%HEAD%%', $rendition);  // get fsh section in [0] and the rest in [1]
    $tmpfsh = explode('%%FSH%%', $split1[0]);   // get fsh section
    $tmpfsh = trim($tmpfsh[1]);
    $tmphtml = explode('%%HTML%%', $split1[1]);   // get head section in [0] and html in [1]
    $head = trim($tmphtml[0]);
    $html = trim($tmphtml[1]);
    // clean up newlines in FSH: eliminate all extra nl
    $fsh = "";
    foreach (explode("\n", $tmpfsh) as $l) {
        $l = rtrim($l);
        if (startsWith($l, "Instance:")) $fsh .= "\n";
        if (strlen($l) > 0) $fsh .= $l . "\n";
    }
    $fsh .= "\n";

    $retval = array();
    $retval[] = $fsh;
    $retval[] = $html;
    $retval[] = $head;
    if (count($bag['instance']) === 0) {
        $retval[] = NULL;  // one default NULL instance
    } else foreach ($bag['instance'] as $i)
        $retval[] = $i;

    // if ($with === "pregnancy-outcome-ips") var_dump($retval);
    
    return $retval;  // return the list of variables

}