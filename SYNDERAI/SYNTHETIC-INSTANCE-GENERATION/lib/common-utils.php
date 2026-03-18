<?php

/**
 * Common Utilities — SynderAI
 *
 * A general-purpose utility library shared across the SynderAI synthetic
 * patient-data generation platform. Functions are grouped into five sections:
 *
 *   1. Component & Configuration    — conditional feature inclusion
 *   2. String Helpers               — substring extraction, prefix/suffix checks
 *   3. Identifier Generation        — UUID v4 and cryptographically secure strings
 *   4. Logging & Console Output     — levelled logging with ANSI colour support
 *   5. Data Utilities               — country normalisation, value/quantity parsing
 *                                     missing-map tracking
 */

/* GENERAL SYNDERAI INCLUDES */
include_once("config.php");


// =============================================================================
// SECTION 1 — Component & Configuration
// =============================================================================

/**
 * Determine whether a given component should be included for the active build.
 *
 * Iterates over the global $COMPONENTS map and checks whether the requested
 * $component belongs to any component group whose key is listed in the
 * global $ARTIFACTS array (i.e. the set of artifacts selected for the current
 * run). Returns TRUE on the first match found.
 *
 * @global array $COMPONENTS  Associative array mapping artifact keys to the
 *                            list of component names they require.
 *                            Example: ["ips" => ["allergies", "medications"], ...]
 * @global array $ARTIFACTS   Flat array of artifact keys that are active for
 *                            the current generation run.
 *                            Example: ["ips", "fhir_bundle"]
 *
 * @param  string $component  Name of the component to test (e.g. "allergies").
 *
 * @return bool               TRUE if the component is required by at least one
 *                            active artifact, FALSE otherwise.
 */
function includeConditionally($component) {
    global $COMPONENTS;
    global $ARTIFACTS;

    foreach ($COMPONENTS as $key => $value) {
        if (in_array($key, $ARTIFACTS)) {
            if (in_array($component, $COMPONENTS[$key])) {
                return TRUE;
            }
        }
    }
    return FALSE;
}


// =============================================================================
// SECTION 2 — String Helpers
// =============================================================================

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

/**
 * Return the substring of $inthat that follows the last occurrence of $needle.
 *
 * @param  string      $needle  The delimiter to search for.
 * @param  string      $inthat  The string to search within.
 *
 * @return string|void  The substring after the last occurrence of $needle,
 *                      or void (implicit NULL) if $needle is not found.
 */
function after_last($needle, $inthat) {
    if (!is_bool(strrevpos($inthat, $needle))) {
        return substr($inthat, strrevpos($inthat, $needle) + strlen($needle));
    }
}

/**
 * Return the substring of $inthat that precedes the first occurrence of $needle.
 *
 * @param  string $needle  The delimiter to search for.
 * @param  string $inthat  The string to search within.
 *
 * @return string  The substring before the first occurrence of $needle.
 *                 Returns an empty string if $needle is not found (strpos returns false).
 */
function before($needle, $inthat) {
    return substr($inthat, 0, strpos($inthat, $needle));
}

/**
 * Return the substring of $inthat that precedes the last occurrence of $needle.
 *
 * @param  string $needle  The delimiter to search for.
 * @param  string $inthat  The string to search within.
 *
 * @return string  The substring before the last occurrence of $needle.
 */
function before_last($needle, $inthat) {
    return substr($inthat, 0, strrevpos($inthat, $needle));
}

/**
 * Return the substring of $inthat that lies between $needle and $that.
 *
 * Extracts the content that appears after the first occurrence of $needle
 * and before the first occurrence of $that following it.
 *
 * @param  string $needle  Opening delimiter.
 * @param  string $that    Closing delimiter.
 * @param  string $inthat  The string to search within.
 *
 * @return string  The substring between the two delimiters.
 */
function between($needle, $that, $inthat) {
    return before($that, after($needle, $inthat));
}

/**
 * Return the substring of $inthat that lies between the last $needle and the last $that.
 *
 * Extracts the content that appears after the last occurrence of $needle
 * and before the last occurrence of $that.
 *
 * @param  string $needle  Opening delimiter (last occurrence used).
 * @param  string $that    Closing delimiter (last occurrence used).
 * @param  string $inthat  The string to search within.
 *
 * @return string  The substring between the two delimiters.
 */
function between_last($needle, $that, $inthat) {
    return after_last($needle, before_last($that, $inthat));
}

/**
 * Find the position of the last occurrence of $needle in $instr.
 *
 * PHP's built-in strrpos() only supports single-character needles in older
 * versions; this implementation handles multi-character needles by reversing
 * both strings and delegating to strpos().
 *
 * @param  string    $instr   The string to search within.
 * @param  string    $needle  The substring to locate.
 *
 * @return int|false  Zero-based character offset of the last occurrence of
 *                    $needle within $instr, or FALSE if not found.
 */
function strrevpos($instr, $needle) {
    $rev_pos = strpos(strrev($instr), strrev($needle));
    if ($rev_pos === false) {
        return false;
    }
    return strlen($instr) - $rev_pos - strlen($needle);
}

/**
 * Check whether $haystack begins with $needle.
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
 * Check whether $haystack ends with $needle.
 *
 * @param  string $haystack  The string to test.
 * @param  string $needle    The expected suffix.
 *
 * @return bool  TRUE if $haystack ends with $needle, FALSE otherwise.
 */
function endsWith($haystack, $needle) {
    return substr_compare($haystack, $needle, -strlen($needle)) === 0;
}

function validateYmd($date, $format = 'Y-m-d') { 
	$d = DateTime::createFromFormat($format, $date); 
	return $d && $d->format($format) === $date; 
}


// =============================================================================
// SECTION 3 — Identifier Generation
// =============================================================================

/**
 * Generate a random UUID (version 4).
 *
 * Produces a 128-bit identifier formatted as eight groups of hexadecimal
 * digits separated by hyphens: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx.
 *
 * The version nibble is fixed at 4 (random) and the variant bits are set to
 * the RFC 4122 "10xx" pattern. All other bits are populated with mt_rand(),
 * which is not cryptographically secure; use random_str() if security matters.
 *
 * @return string  A UUID v4 string, e.g. "550e8400-e29b-41d4-a716-446655440000".
 */
function uuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),  // time_low
        mt_rand(0, 0xffff),                        // time_mid
        mt_rand(0, 0x0fff) | 0x4000,               // time_hi_and_version (version 4)
        mt_rand(0, 0x3fff) | 0x8000,               // clk_seq (RFC 4122 variant)
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)  // node
    );
}

/**
 * Generate a cryptographically secure random string.
 *
 * Selects $length characters at random from $keyspace using random_int(),
 * which is backed by the OS CSPRNG (e.g. /dev/urandom on Linux).
 *
 * PHP compatibility:
 *   - PHP 7+: random_int() is a core built-in.
 *   - PHP 5.x: requires the paragonie/random_compat polyfill
 *              (https://github.com/paragonie/random_compat).
 *
 * @param  int    $length    Number of characters to generate. Must be ≥ 1.
 * @param  string $keyspace  Pool of characters to draw from. Defaults to
 *                           alphanumeric (digits + lower + upper case ASCII).
 *
 * @return string  A random string of exactly $length characters.
 *
 * @throws \RangeException  If $length is less than 1.
 */
function random_str(
    int $length = 64,
    string $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'
): string {
    if ($length < 1) {
        throw new \RangeException("Length must be a positive integer");
    }
    $pieces = [];
    $max = mb_strlen($keyspace, '8bit') - 1;
    for ($i = 0; $i < $length; ++$i) {
        $pieces[] = $keyspace[random_int(0, $max)];
    }
    return implode('', $pieces);
}


// =============================================================================
// SECTION 4 — Logging & Console Output
// =============================================================================

/**
 * Emit a timestamped log line if the current debug level permits it.
 *
 * Strips any trailing newline from $text before output so that the elapsed-
 * time prefix produced by {@see logmeterinit()} and the message always appear
 * on the same line with a single trailing newline.
 *
 * Output format:
 *   HH:MM:SS (H:MM:SS)  <text>
 *
 * @param  int    $level  Minimum DEBUGLEVEL required for this message to appear.
 *                        Lower values = more important (0 = always shown).
 * @param  string $text   The message to log. Embedded newlines are removed.
 *
 * @return void
 */
function lognl($level, $text) {
    if (DEBUGLEVEL >= $level) {
        $text = str_replace("\n", "", $text);
        logmeterinit();
        echo sprintf("%s\n", $text);
    }
}

/**
 * Emit a colour-coded, timestamped log line with an explicit severity label.
 *
 * Wraps {@see lognl()} with ANSI colour codes appropriate for the given
 * severity. The colour is reset automatically at the end of each line.
 *
 * Supported severity values and their colours:
 *   "ERROR"   → Red
 *   "WARN"    → Yellow
 *   "INFO"    → Blue
 *   "SUCCESS" → Green
 *
 * Messages whose severity is not one of the above are silently suppressed.
 *
 * @param  int    $level     Minimum DEBUGLEVEL required to emit this message.
 * @param  string $severity  One of: "ERROR", "WARN", "INFO", "SUCCESS".
 * @param  string $text      The message to log.
 *
 * @return void
 */
function lognlsev($level, $severity, $text) {
    if (DEBUGLEVEL >= $level) {
        if ($severity === "ERROR") {
            echo consoleColor("RED");
            lognl($level, $text . consoleColor("NC"));
        }
        if ($severity === "WARN") {
            echo consoleColor("YELLOW");
            lognl($level, $text . consoleColor("NC"));
        }
        if ($severity === "INFO") {
            echo consoleColor("BLUE");
            lognl($level, $text . consoleColor("NC"));
        }
        if ($severity === "SUCCESS") {
            echo consoleColor("GREEN");
            lognl($level, $text . consoleColor("NC"));
        }
    }
}

/**
 * Print a timestamp and elapsed-time prefix for a log entry.
 *
 * Reads the global $STARTTIMER (Unix timestamp set at application start)
 * and outputs the current wall-clock time together with the elapsed duration
 * since that baseline.
 *
 * Output format (8 characters wide + elapsed):
 *   " HH:MM:SS (H:MM:SS) "
 *
 * This function is called automatically by {@see lognl()} and should not
 * normally need to be invoked directly.
 *
 * @global int $STARTTIMER  Unix timestamp recorded when the application started.
 *
 * @return void
 */
function logmeterinit() {
    global $STARTTIMER;

    $time    = time();
    $elapsed = abs($STARTTIMER - $time);

    $h       = floor($elapsed / 3600);
    $elapsed -= $h * 3600;
    $m       = floor($elapsed / 60);
    $elapsed -= $m * 60;

    echo sprintf("%8s (%d:%02d:%02d) ", date('H:i:s'), $h, $m, $elapsed);
}

/**
 * Return the ANSI escape sequence for a named console colour.
 *
 * Supported colour names:
 *   Foreground: "RED", "GREEN", "YELLOW", "BLUE", "WHITE"
 *   Background: "REDBG", "GREENBG", "YELLOWBG", "BLUEBG"
 *   Reset:      "NC" (No Colour — resets all attributes)
 *
 * @param  string      $c  Colour name (case-sensitive).
 *
 * @return string|null  The ANSI escape sequence, or NULL for unrecognised names.
 */
function consoleColor($c) {
    if ($c === "RED")      return "\033[0;31m";
    if ($c === "GREEN")    return "\033[0;32m";
    if ($c === "YELLOW")   return "\033[0;33m";
    if ($c === "BLUE")     return "\033[0;34m";
    if ($c === "WHITE")    return "\033[0;37m";
    if ($c === "REDBG")    return "\033[41m";
    if ($c === "GREENBG")  return "\033[42m";
    if ($c === "YELLOWBG") return "\033[0;43m";
    if ($c === "BLUEBG")   return "\033[44m";
    if ($c === "NC")       return "\033[0m";
}


// =============================================================================
// SECTION 5 — Data Utilities
// =============================================================================

/**
 * Normalise a country identifier to a [code, name] pair.
 *
 * Accepts either an ISO 3166-1 alpha-2 country code (e.g. "it") or a full
 * country name (e.g. "Italy") and looks it up against the VALID_INTL_COUNTRIES
 * constant (an associative array of the form ["it" => "Italy", ...]).
 * Matching is case-insensitive.
 *
 * If the input is not found in VALID_INTL_COUNTRIES, the function silently
 * defaults to Germany ("de" / "Germany").
 *
 * @param  string $countrycodeorname  An ISO 3166-1 alpha-2 code or a full country name.
 *
 * @return array  Two-element indexed array: [string $code, string $name].
 *                Example: ["it", "Italy"]
 */
function unifyCountryCodeName($countrycodeorname) {
    $countrycodeorname = strtolower($countrycodeorname);

    if (array_key_exists($countrycodeorname, VALID_INTL_COUNTRIES)) {
        // Input is already a recognised country code
        $eucountrycode = $countrycodeorname;
        $eucountryname = VALID_INTL_COUNTRIES[$countrycodeorname];
    } elseif (in_array($countrycodeorname, array_map('strtolower', VALID_INTL_COUNTRIES))) {
        // Input is a country name — reverse-look up its code
        $eucountrycode = array_search($countrycodeorname, array_map('strtolower', VALID_INTL_COUNTRIES));
        $eucountryname = VALID_INTL_COUNTRIES[$eucountrycode];
    } else {
        // Unknown input — fall back to Germany
        $eucountrycode = "de";
        $eucountryname = "Germany";
    }

    return [$eucountrycode, $eucountryname];
}

/**
 * Parse a compound value/quantity string into its constituent parts.
 *
 * Accepts strings of the form "<value> #<unit>" as produced by the ISH
 * data-generation pipeline and splits them into a four-element tuple
 * compatible with FHIR Quantity resources.
 *
 * Example input:  "109 #kg"
 * Example output: ["109", "kg", "kg", "$ucum"]
 *
 * @param  string $v  A compound string in the format "<numeric_value> #<unit_code>".
 *
 * @return array  Four-element indexed array:
 *                [string $value, string $unit, string $code, string $system]
 *                where $system is always "$ucum" (UCUM unit system).
 */
function splitValueQuantityCompound($v) {
    $value  = before(" ", $v);   // numeric value, e.g. "109"
    $unit   = after("#", $v);    // display unit,  e.g. "kg"
    $code   = after("#", $v);    // UCUM code (same as unit in this context)
    $system = "\$ucum";          // FHIR UCUM system URI token

    return [$value, $unit, $code, $system];
}

/**
 * Append a missing-mapping record to the shared missing-map log file.
 *
 * When a code or concept cannot be mapped during data generation, callers
 * use this function to register the problematic line so that it can be
 * reviewed and mapped later. Entries are appended to the file defined by
 * the MISSINGMAPFILE constant.
 *
 * @param  string $line  A descriptive line of text identifying the missing
 *                       mapping (e.g. the original CSV row or code string).
 *
 * @return void
 */
function registerMapMissing($line) {
    file_put_contents(MISSINGMAPFILE, $line . "\n", FILE_APPEND);
}