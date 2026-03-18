<?php

/**
 * Twig Template Engine Interface — SynderAI
 *
 * Provides a single entry-point function, twigit(), that initialises a Twig
 * environment, registers all SynderAI-specific custom functions, and renders
 * a named template against a data array.
 *
 * Twig (https://twig.symfony.com) is a modern, sandboxed PHP template engine.
 * Templates live under the ./templates/ directory relative to this file and
 * are compiled to the ./cache/ directory for performance.
 *
 * Two template conventions are supported, distinguished by file extension:
 *
 *   *.fsh.twig   — Full rendering mode. The template emits three delimited
 *                  sections that are split apart and returned separately:
 *                    %%FSH%%  ... %%HEAD%%  ... %%HTML%%
 *                  Used for FHIR Shorthand (FSH) resources that also carry
 *                  an HTML table representation.
 *
 *   *.ish.twig   — Lightweight rendering mode. The template emits a single
 *                  delimited section:
 *                    %%ISH%%
 *                  Used for ISH (Instance Shorthand) content that requires
 *                  no FSH or HTML output.
 *
 * Custom Twig functions registered by twigit():
 *
 *   HTML body accumulator (writes into $bag['html']):
 *     addHTML_tr()        — appends <tr>
 *     addHTML_trend()     — appends </tr>
 *     addHTML_td($v)      — appends <td>$v</td>
 *     addHTML_tdgray($v)  — appends <td><span class="grayedout">$v</span></td>
 *     addHTML_tdnb($v)    — appends <td><span class="nb">$v</span></td>
 *     emitHTML()          — returns (and renders) the full accumulated HTML
 *
 *   HTML table heading accumulator (writes into $bag['heading']):
 *     addHEAD_tr()        — appends <tr>
 *     addHEAD_trend()     — appends </tr>
 *     addHEAD_th($v)      — appends <th>$v</th>
 *     emitHEAD()          — returns (and renders) the full accumulated heading HTML
 *
 *   Helpers:
 *     setInstance($v)           — pushes $v onto the $bag['instance'] array and
 *                                 returns it (used to register FSH instance names)
 *     getUUID()                 — generates and returns a UUID v4 string
 *     syntheticDataPolicyMeta() — returns the synthetic-data policy metadata
 *                                 block as a newline-joined string
 *
 * Global Twig variable injected automatically:
 *   HL7EUROPEEXAMPLESOID — OID string from the HL7EUROPEEXAMPLESOID constant
 *
 * External dependencies:
 *   autoload.php          — Composer autoloader (loads the Twig library)
 *   constants.php         — defines HL7EUROPEEXAMPLESOID,
 *                           SYNDERAI_SYNTHETIC_DATA_POLICY_META
 *   common-utils.php      — provides uuid() and startsWith()
 */

require __DIR__ . '/autoload.php';

/* INCLUDES */
include_once("../CONSTANTS/constants.php");


/**
 * Initialise Twig, register custom functions, render a template, and return
 * the parsed output sections.
 *
 * The function configures a fresh Twig environment on every call (a new
 * ArrayObject bag is created per request to avoid state leaking between
 * calls). It then selects the correct template file based on the $with name
 * and the available file extension (.fsh.twig or .ish.twig), renders it, and
 * splits the output on the appropriate delimiters.
 *
 * ----- FSH template return value (.fsh.twig) --------------------------------
 * An indexed array with at least three elements:
 *
 *   [0]  string   Cleaned FHIR Shorthand (FSH) block. Leading/trailing blank
 *                 lines are removed; a blank line is inserted before each
 *                 "Instance:" declaration; HTML entity &#039; is corrected to '.
 *   [1]  string   HTML table body rows (the content between %%HEAD%% and %%HTML%%).
 *   [2]  string   HTML table heading row(s) (the content between %%FSH%% and %%HEAD%%).
 *   [3+] string|null  One entry per FSH instance name collected via setInstance()
 *                     during template rendering, or a single NULL element if
 *                     no instances were registered.
 *
 * ----- ISH template return value (.ish.twig) --------------------------------
 *   string  The raw ISH content following the %%ISH%% delimiter.
 *
 * ----- Error return value ---------------------------------------------------
 *   array   An empty array [] when no matching template file is found.
 *   Validation errors (missing required delimiter tags) are echoed to stdout
 *   but do not prevent the function from continuing.
 *
 * @param  array  $data  Associative array of variables to expose inside the
 *                       Twig template. Keys become Twig variable names.
 *                       HL7EUROPEEXAMPLESOID is injected automatically and
 *                       does not need to be included by the caller.
 * @param  string $with  Base name of the template to render, without extension
 *                       and without the trailing ".fsh.twig" / ".ish.twig"
 *                       suffix. Example: "ObservationVitalSigns" will look for
 *                       "ObservationVitalSigns.fsh.twig" first, then
 *                       "ObservationVitalSigns.ish.twig".
 *
 * @return array|string  See above — the return type depends on which template
 *                       convention is matched.
 */
function twigit($data, $with) {

    // -------------------------------------------------------------------------
    // Initialise the Twig environment
    // Templates are loaded from ./templates/; compiled cache written to ./cache/
    // -------------------------------------------------------------------------
    $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/templates');

    $twig = new \Twig\Environment($loader, [
        'cache'       => __DIR__ . '/cache', // compiled template cache (recommended in production)
        'auto_reload' => true,               // recompile automatically when a template changes
        //'debug' => true,                   // uncomment to enable the Twig debug extension
    ]);

    // -------------------------------------------------------------------------
    // Per-request state bag (ArrayObject so closures can modify it by reference)
    // Three slots are initialised:
    //   'html'     — accumulates HTML table body markup via addHTML_* functions
    //   'instance' — collects FSH instance names via setInstance()
    //   'heading'  — accumulates HTML table heading markup via addHEAD_* functions
    // -------------------------------------------------------------------------
    $bag = new ArrayObject();
    $twig->addGlobal('bag', $bag);
    $bag['html']     = "";
    $bag['instance'] = [];
    $bag['heading']  = "";


    // =========================================================================
    // HTML BODY ACCUMULATOR FUNCTIONS
    // These Twig functions build up table rows in $bag['html'].
    // Templates call them in sequence; emitHTML() flushes the buffer to output.
    // =========================================================================

    /** Opens a table row: appends <tr> to $bag['html']. */
    $twig->addFunction(new \Twig\TwigFunction('addHTML_tr', function () use ($bag) {
        $bag['html'] .= "<tr>";
        return "";
    }));

    /** Closes a table row: appends </tr> to $bag['html']. */
    $twig->addFunction(new \Twig\TwigFunction('addHTML_trend', function () use ($bag) {
        $bag['html'] .= "</tr>";
        return "";
    }));

    /** Appends a standard table cell: <td>$value</td> to $bag['html']. */
    $twig->addFunction(new \Twig\TwigFunction('addHTML_td', function ($value) use ($bag) {
        $bag['html'] .= "<td>$value</td>";
    }));

    /**
     * Appends a de-emphasised (grayed-out) table cell to $bag['html'].
     * Produces: <td><span class='grayedout'>$value</span></td>
     */
    $twig->addFunction(new \Twig\TwigFunction('addHTML_tdgray', function ($value) use ($bag) {
        $bag['html'] .= "<td><span class='grayedout'>" . $value . "</span></td>";
    }));

    /**
     * Appends a non-breaking table cell to $bag['html'].
     * Produces: <td><span class='nb'>$value</span></td>
     * The 'nb' CSS class prevents the cell content from wrapping across lines.
     */
    $twig->addFunction(new \Twig\TwigFunction('addHTML_tdnb', function ($value) use ($bag) {
        $bag['html'] .= "<td><span class='nb'>" . $value . "</span></td>";
    }));

    /**
     * Flushes the HTML body buffer to Twig output.
     * Returns the full accumulated content of $bag['html'] as a safe HTML string.
     * Equivalent to using {{ bag.html|raw }} directly in the template.
     * The 'is_safe' => ['html'] option prevents Twig from auto-escaping the output.
     */
    $twig->addFunction(new \Twig\TwigFunction('emitHTML', function () use ($bag) {
        return $bag['html'];
    }, ['is_safe' => ['html']]));


    // =========================================================================
    // HTML TABLE HEADING ACCUMULATOR FUNCTIONS
    // Mirror of the HTML body functions, but write to $bag['heading'].
    // Used to build <thead> rows separately from <tbody> rows.
    // =========================================================================

    /** Opens a heading row: appends <tr> to $bag['heading']. */
    $twig->addFunction(new \Twig\TwigFunction('addHEAD_tr', function () use ($bag) {
        $bag['heading'] .= "<tr>";
        return "";
    }));

    /** Closes a heading row: appends </tr> to $bag['heading']. */
    $twig->addFunction(new \Twig\TwigFunction('addHEAD_trend', function () use ($bag) {
        $bag['heading'] .= "</tr>";
        return "";
    }));

    /** Appends a table header cell: <th>$value</th> to $bag['heading']. */
    $twig->addFunction(new \Twig\TwigFunction('addHEAD_th', function ($value) use ($bag) {
        $bag['heading'] .= "<th>$value</th>";
    }));

    /**
     * Flushes the heading buffer to Twig output.
     * Returns the full accumulated content of $bag['heading'] as a safe HTML string.
     * The 'is_safe' => ['html'] option prevents Twig from auto-escaping the output.
     */
    $twig->addFunction(new \Twig\TwigFunction('emitHEAD', function () use ($bag) {
        return $bag['heading'];
    }, ['is_safe' => ['html']]));


    // =========================================================================
    // HELPER FUNCTIONS
    // =========================================================================

    /**
     * Registers a FHIR FSH instance name and returns it for inline use.
     * Pushes $value onto $bag['instance'] so the caller can retrieve all
     * instance names from the return value of twigit() after rendering.
     */
    $twig->addFunction(new \Twig\TwigFunction('setInstance', function ($value) use ($bag) {
        $bag['instance'][] = $value;
        return $value;
    }));

    /**
     * Generates and returns a UUID v4 string.
     * Delegates to the global uuid() helper from common-utils.php.
     * Callable from within any Twig template as {{ getUUID() }}.
     */
    $twig->addFunction(new \Twig\TwigFunction('getUUID', function () use ($bag) {
        return uuid();
    }));


    // =========================================================================
    // SYNTHETIC DATA POLICY & PROVENANCE
    // =========================================================================

    /**
     * Returns the SynderAI synthetic-data policy metadata block as a
     * newline-joined string, suitable for embedding in FHIR resource comments
     * or document headers to declare the synthetic origin of the data.
     * The content is sourced from the SYNDERAI_SYNTHETIC_DATA_POLICY_META
     * constant array defined in constants.php.
     */
    $twig->addFunction(new \Twig\TwigFunction('syntheticDataPolicyMeta', function () use ($bag) {
        return implode("\n", SYNDERAI_SYNTHETIC_DATA_POLICY_META);
    }));


    // -------------------------------------------------------------------------
    // Inject global constants into the Twig data array
    // HL7EUROPEEXAMPLESOID is needed by most FSH templates to build OID-based
    // identifiers and is added automatically so callers don't need to pass it.
    // -------------------------------------------------------------------------
    $data["HL7EUROPEEXAMPLESOID"] = HL7EUROPEEXAMPLESOID;


    // =========================================================================
    // TEMPLATE SELECTION AND RENDERING
    //
    // Priority: .fsh.twig (full FSH+HTML+HEAD output) beats .ish.twig (ISH only).
    // If neither file exists, an error is echoed and an empty array is returned.
    // =========================================================================
    $templatedir = __DIR__ . "/templates";

    if (is_file("$templatedir/$with.fsh.twig")) {

        // ---------------------------------------------------------------------
        // FSH template path
        // The rendered string must contain all three delimiters in order:
        //   %%FSH%%  — marks the start of the FHIR Shorthand block
        //   %%HEAD%% — marks the start of the HTML table heading block
        //   %%HTML%% — marks the start of the HTML table body block
        //
        // Layout inside the rendered string:
        //   <preamble> %%FSH%% <fsh content> %%HEAD%% <head content> %%HTML%% <html content>
        // ---------------------------------------------------------------------
        $rendition = $twig->render("$with.fsh.twig", $data);

        // Validate that all three required delimiters are present
        if (!str_contains($rendition, '%%FSH%%'))  echo "+++Error: twig rendition does not contain required %%FSH%% tag!\n";
        if (!str_contains($rendition, '%%HEAD%%')) echo "+++Error: twig rendition does not contain required %%HEAD%% tag!\n";
        if (!str_contains($rendition, '%%HTML%%')) echo "+++Error: twig rendition does not contain required %%HTML%% tag!\n";

        // Split on %%HEAD%%: everything before it contains the FSH block;
        // everything after contains the HEAD and HTML sections.
        $split1  = explode('%%HEAD%%', $rendition);
        $tmpfsh  = explode('%%FSH%%', $split1[0]);  // isolate the FSH content
        $tmpfsh  = trim($tmpfsh[1]);

        // Correct HTML entity encoding that Twig may introduce for apostrophes
        $tmpfsh  = str_replace("&#039;", "'", $tmpfsh);

        // Split the second half on %%HTML%% to separate HEAD from HTML body
        $tmphtml = explode('%%HTML%%', $split1[1]);
        $head    = trim($tmphtml[0]);   // HTML table heading rows
        $html    = trim($tmphtml[1]);   // HTML table body rows

        // Clean up the FSH block: strip trailing whitespace from each line,
        // collapse consecutive blank lines, and insert a blank line before
        // each "Instance:" declaration to improve readability.
        $fsh = "";
        foreach (explode("\n", $tmpfsh) as $l) {
            $l = rtrim($l);
            if (startsWith($l, "Instance:")) $fsh .= "\n";  // blank line before each instance
            if (strlen($l) > 0) $fsh .= $l . "\n";          // skip empty lines
        }
        $fsh .= "\n";   // ensure a trailing newline

        // Build the return array:
        //   [0] FSH string
        //   [1] HTML body string
        //   [2] HTML heading string
        //   [3+] FSH instance names (or a single NULL if none were registered)
        $retval   = [];
        $retval[] = $fsh;
        $retval[] = $html;
        $retval[] = $head;

        if (count($bag['instance']) === 0) {
            $retval[] = NULL;   // placeholder so callers can always expect index 3
        } else {
            foreach ($bag['instance'] as $i) {
                $retval[] = $i;
            }
        }

        return $retval;

    } elseif (is_file("$templatedir/$with.ish.twig")) {

        // ---------------------------------------------------------------------
        // ISH template path
        // The rendered string must contain the %%ISH%% delimiter.
        // Only the content after %%ISH%% is returned.
        // ---------------------------------------------------------------------
        $rendition = $twig->render("$with.ish.twig", $data);

        if (!str_contains($rendition, '%%ISH%%')) echo "+++Error: twig rendition does not contain required %%ISH%% tag!\n";

        $split = explode('%%ISH%%', $rendition);
        return $split[1];   // return only the ISH content section

    } else {
        // No matching template file found for either convention
        echo "+++Error: twig template $with not found!\n";
        return [];
    }
}