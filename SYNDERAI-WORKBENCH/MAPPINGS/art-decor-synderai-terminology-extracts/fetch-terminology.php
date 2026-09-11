<?php
/**
 * fetch-terminology.php
 *
 * Downloads value sets, code systems and concept maps from an ART-DECOR
 * project and stores each artefact under its own name, one subdirectory per
 * artefact type.
 *
 * The artefact list and all defaults live in terminology-config.php.
 *
 * Endpoint shape, verified against art-decor.org:
 *   <base>/<type>/<oid>[/<effectiveDate>]/$extract?language=&format=&project=
 *   format=xml returns DECOR XML, format=json the JSON serialisation.
 *
 * Version selection is per artefact, through the effectiveDate field of its
 * config entry:
 *   'effectiveDate' => 'dynamic'              always the newest version
 *   'effectiveDate' => '2026-09-09T22:00:00'  exactly that version, pinned
 * An empty field is read as dynamic.
 *
 * A dateless request does not answer with the newest version alone: the API
 * returns every version of the artefact in one document, newest first. That
 * order is not guaranteed anywhere, so unwrapXml() picks the element with the
 * highest effectiveDate rather than the first one it finds.
 *
 * $extract resolves includes that point at a local code system. An include
 * with op="descendent-of" against SNOMED CT comes back unresolved, because the
 * endpoint has no SNOMED terminology server behind it.
 *
 * Requires PHP 8.0 with ext-dom and ext-json. Uses ext-curl when available and
 * falls back to stream wrappers.
 *
 * Exit status is the number of artefacts that could not be downloaded,
 * capped at 254.
 */

declare(strict_types=1);

/** Set timezone explicitly to avoid ambiguous date/time offsets in output. */
date_default_timezone_set('Europe/Berlin');

include_once("../../CONSTANTS/constants.php");
include_once("../../SYNTHETIC-INSTANCE-GENERATION/lib/common-utils.php");
include_once("../../SYNTHETIC-INSTANCE-GENERATION/config.php");

/** Record script start time for elapsed-time logging via logmeterinit(). */
$STARTTIMER = time();

lognlsev(1, INFO, "*** Fetching / caching SYNDERAI Terminology");

const ARTEFACT_TYPES = ['valueset', 'codesystem', 'conceptmap'];

/** Value of effectiveDate that asks for the newest version. */
const EFFECTIVE_DATE_DYNAMIC = 'dynamic';

/** Shape a pinned effectiveDate has to have, so a typo is caught before the request. */
const EFFECTIVE_DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?)?$/';

// ---------------------------------------------------------------- helpers
function usage(): void
{
    echo <<<'TXT'
Usage: php fetch-terminology.php [options]

  -c FILE    config file (default: terminology-config.php next to this script)
  -d DIR     output directory; artefacts land in the per-type subdirectories
  -f FORMAT  xml or json
  -l LANG    language for the API call
  -b URL     API base URL
  -p PREFIX  ART-DECOR project prefix
  -n         do not unwrap, keep the collection element the API sends
  -q         only report failures
  -h         this help

Which version of an artefact is fetched is decided in the config, per artefact:
set effectiveDate to 'dynamic' for the newest version, or to a concrete
effectiveDate to pin it.

Exit status is the number of artefacts that could not be downloaded.

TXT;
}

/**
 * One HTTP GET. Returns [status, body, transportError].
 * status is 0 when the request never produced a response.
 */
function httpGet(string $url, int $connectTimeout, int $timeout): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'fetch-terminology.php',
        ]);
        $body = curl_exec($ch);
        $err  = $body === false ? curl_error($ch) : '';
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$code, $body === false ? '' : $body, $err];
    }

    $context = stream_context_create(['http' => [
        'method'          => 'GET',
        'timeout'         => $timeout,
        'ignore_errors'   => true,
        'follow_location' => 1,
        'max_redirects'   => 5,
        'header'          => "User-Agent: fetch-terminology.php\r\nAccept-Encoding: identity\r\n",
    ]]);

    $body = @file_get_contents($url, false, $context);
    $code = 0;
    $rh = http_get_last_response_headers();
    if (isset($rh)) {
        foreach ($rh as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    if ($body === false) {
        $e = error_get_last();

        return [$code, '', $e['message'] ?? 'request failed'];
    }

    return [$code, $body, ''];
}

/**
 * Strip the API's collection wrapper so the artefact element becomes the
 * document root. Returns the new XML, or null when no artefact element was
 * found. $error carries the parse error when the body is not well-formed XML.
 *
 * A dateless request answers with every version of the artefact. The element
 * with the highest effectiveDate wins; $picked reports which one that was and
 * how many the response held, so the caller can log it.
 */
function unwrapXml(
    string $xml,
    array $rootNames,
    array $stripAttributes,
    ?string &$error,
    ?array &$picked = null
): ?string {
    $error = null;
    $picked = null;
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;   // never reflow mixed content in <desc>
    $doc->formatOutput = false;
    $loaded = $doc->loadXML($xml);

    if (!$loaded) {
        $errors = array_map(
            static fn (LibXMLError $e): string => trim($e->message) . ' (line ' . $e->line . ')',
            libxml_get_errors()
        );
        $error = $errors[0] ?? 'not well-formed';
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return null;
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $wanted = array_map('strtolower', $rootNames);

    $node = null;
    $versions = 1;
    if ($doc->documentElement !== null
        && in_array(strtolower($doc->documentElement->localName), $wanted, true)) {
        $node = $doc->documentElement;                 // already unwrapped
    } else {
        $xpath = new DOMXPath($doc);
        foreach ($rootNames as $name) {
            $found = $xpath->query('//*[local-name()="' . $name . '"]');
            if ($found === false || $found->length === 0) {
                continue;
            }
            // The API lists versions newest first, but nothing guarantees that
            // order, so compare effectiveDate. It is ISO 8601, which sorts
            // lexicographically.
            $versions = $found->length;
            foreach ($found as $candidate) {
                if (!$candidate instanceof DOMElement) {
                    continue;
                }
                if ($node === null
                    || strcmp($candidate->getAttribute('effectiveDate'),
                              $node->getAttribute('effectiveDate')) > 0) {
                    $node = $candidate;
                }
            }
            break;
        }
    }

    if (!$node instanceof DOMElement) {
        return null;
    }

    $picked = [
        'effectiveDate' => $node->getAttribute('effectiveDate'),
        'versions'      => $versions,
    ];

    foreach ($stripAttributes as $attribute) {
        if ($node->hasAttribute($attribute)) {
            $node->removeAttribute($attribute);
        }
    }

    $out = new DOMDocument('1.0', 'UTF-8');
    $out->preserveWhiteSpace = true;
    $out->formatOutput = false;
    $out->appendChild($out->importNode($node, true));

    return $out->saveXML();
}

/** Well-formedness check without unwrapping. Returns an error string or null. */
function checkXml(string $xml): ?string
{
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($ok) {
        return null;
    }

    return isset($errors[0]) ? trim($errors[0]->message) . ' (line ' . $errors[0]->line . ')' : 'not well-formed';
}

// ---------------------------------------------------------------- options

$options = getopt('c:d:f:l:b:p:nqh');
if ($options === false) {
    usage();
    exit(2);
}
if (isset($options['h'])) {
    usage();
    exit(0);
}

$configPath = $options['c'] ?? __DIR__ . DIRECTORY_SEPARATOR . 'terminology-config.php';
if (!is_file($configPath) || !is_readable($configPath)) {
    lognlsev(1, FATAL, "config file not readable: $configPath");
}

$config = require $configPath;
if (!is_array($config)) {
    lognlsev(1, FATAL, "config file did not return an array: $configPath");
}

$defaults = [
    'base'            => 'https://art-decor.org/exist/apps/api',
    'project'         => '',
    'language'        => 'en-US',
    'format'          => 'xml',
    'outdir'          => '.',
    'directories'     => [],
    'unwrap'          => true,
    'rootElements'    => ['valueSet', 'codeSystem', 'conceptMap'],
    'stripAttributes' => [],
    'connectTimeout'  => 15,
    'timeout'         => 600,
    'retries'         => 3,
    'retryDelay'      => 2,
    'artefacts'       => [],
];
$config += $defaults;

if (isset($options['d'])) { $config['outdir']   = (string) $options['d']; }
if (isset($options['f'])) { $config['format']   = (string) $options['f']; }
if (isset($options['l'])) { $config['language'] = (string) $options['l']; }
if (isset($options['b'])) { $config['base']     = (string) $options['b']; }
if (isset($options['p'])) { $config['project']  = (string) $options['p']; }
if (isset($options['n'])) { $config['unwrap']   = false; }
$quiet = isset($options['q']);

if (!in_array($config['format'], ['xml', 'json'], true)) {
    lognlsev(1, FATAL, "unknown format: {$config['format']} (expected xml or json)");
}
if (!is_array($config['artefacts']) || $config['artefacts'] === []) {
    lognlsev(1, FATAL, "no artefacts configured in $configPath");
}
if ($config['format'] === 'json' && $config['unwrap'] && !$quiet) {
    lognlsev(1, ERROR, "note: unwrapping applies to XML only, the JSON serialisation has no wrapper\n");
}

// ---------------------------------------------------------------- download

$format = $config['format'];
$ok = 0;
$failures = [];

foreach ($config['artefacts'] as $index => $artefact) {
    foreach (['type', 'oid', 'name'] as $key) {
        if (!isset($artefact[$key]) || $artefact[$key] === '') {
            lognlsev(1, FATAL, "artefact #$index in $configPath is missing '$key'");
        }
    }
    $type = strtolower((string) $artefact['type']);
    $oid  = (string) $artefact['oid'];
    $name = (string) $artefact['name'];

    if (!in_array($type, ARTEFACT_TYPES, true)) {
        lognlsev(1, FATAL, "artefact '$name' has unknown type '$type' (expected " . implode(', ', ARTEFACT_TYPES) . ')');
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
        lognlsev(1, FATAL, "artefact name '$name' is not usable as a file name");
    }

    // version selection, per artefact
    $requested = trim((string) ($artefact['effectiveDate'] ?? ''));
    $pinned = null;
    if ($requested !== '' && strcasecmp($requested, EFFECTIVE_DATE_DYNAMIC) !== 0) {
        if (!preg_match(EFFECTIVE_DATE_PATTERN, $requested)) {
            lognlsev(1, FATAL, "artefact '$name' has effectiveDate '$requested', expected '"
                . EFFECTIVE_DATE_DYNAMIC . "' or a date such as 2026-09-09T22:00:00");
        }
        $pinned = $requested;
    }

    $segments = [rtrim($config['base'], '/'), $type, $oid];
    if ($pinned !== null) {
        $segments[] = rawurlencode($pinned);
    }
    $url = implode('/', $segments) . '/$extract?' . http_build_query([
        'language' => $config['language'],
        'format'   => $format,
        'project'  => $config['project'],
    ]);

    $subdir = $config['directories'][$type] ?? $type;
    $dir = rtrim($config['outdir'], '/\\');
    if ($subdir !== '') {
        $dir .= DIRECTORY_SEPARATOR . $subdir;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        lognlsev(1, FATAL, "cannot create directory: $dir");
    }
    $dest = $dir . DIRECTORY_SEPARATOR . $name . '.' . $format;

    if (!$quiet) {
        lognl(1, "Artefact: " . $name . ($pinned !== null ? " (pinned to $pinned)" : " (dynamic)"));
        lognl(1, "... url: ". $url);
    }

    // transfer, with retries on transport errors and 5xx
    $status = 0;
    $body = '';
    $error = '';
    for ($attempt = 1; $attempt <= max(1, (int) $config['retries']); $attempt++) {
        [$status, $body, $error] = httpGet($url, (int) $config['connectTimeout'], (int) $config['timeout']);
        if ($status === 200) {
            break;
        }
        if ($status !== 0 && $status < 500) {
            break;                       // 4xx will not get better on a retry
        }
        if ($attempt < max(1, (int) $config['retries'])) {
            sleep((int) $config['retryDelay']);
        }
    }

    if ($status !== 200) {
        $reason = $status === 0
            ? 'no response' . ($error !== '' ? " ($error)" : '')
            : "HTTP $status";
        $failures[] = "$name  $reason";
        lognlsev(1, ERROR, "  FAILED  $name  $reason\n");
        continue;
    }
    if (trim($body) === '') {
        $failures[] = "$name  empty response";
        lognlsev(1, ERROR, "  FAILED  $name  empty response\n");
        continue;
    }

    // validate, and unwrap when asked
    $picked = null;
    if ($format === 'xml') {
        if ($config['unwrap']) {
            $parseError = null;
            $out = unwrapXml($body, $config['rootElements'], $config['stripAttributes'], $parseError, $picked);
            if ($parseError !== null) {
                $failures[] = "$name  malformed xml: $parseError";
                lognlsev(1, ERROR, "  FAILED  $name  response is not well-formed xml: $parseError\n");
                continue;
            }
            if ($out === null) {
                $raw = $dir . DIRECTORY_SEPARATOR . $name . '.raw.xml';
                file_put_contents($raw, $body);
                $wanted = implode(', ', $config['rootElements']);
                $failures[] = "$name  no artefact element ($wanted) found";
                lognlsev(1, ERROR, "  FAILED  $name  no artefact element ($wanted) in the response, raw body kept as $raw\n");
                continue;
            }
            // A pinned request must not come back as a different version.
            if ($pinned !== null && $picked !== null
                && $picked['effectiveDate'] !== '' && $picked['effectiveDate'] !== $pinned) {
                $failures[] = "$name  asked for $pinned, got {$picked['effectiveDate']}";
                lognlsev(1, ERROR, "  FAILED  $name  asked for effectiveDate $pinned, response carries {$picked['effectiveDate']}\n");
                continue;
            }
            $body = $out;
        } else {
            $parseError = checkXml($body);
            if ($parseError !== null) {
                $failures[] = "$name  malformed xml: $parseError";
                lognlsev(1, ERROR, "  FAILED  $name  response is not well-formed xml: $parseError\n");
                continue;
            }
        }
    } else {
        $decoded = json_decode($body, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $failures[] = "$name  malformed json: " . json_last_error_msg();
            lognlsev(1, ERROR, "  FAILED  $name  response is not valid json: " . json_last_error_msg() . "\n");
            continue;
        }
    }

    // write via a temp file in the target directory, so a half-written file
    // never replaces a good one
    $tmp = @tempnam($dir, '.artdecor');
    if ($tmp === false || @file_put_contents($tmp, $body) === false || !@rename($tmp, $dest)) {
        if ($tmp !== false) { @unlink($tmp); }
        $failures[] = "$name  cannot write $dest";
        lognlsev(1, ERROR, "  FAILED  $name  cannot write $dest\n");
        continue;
    }
    @chmod($dest, 0644);

    $ok++;
    if (!$quiet) {
        $note = '';
        if ($picked !== null && $picked['effectiveDate'] !== '') {
            $note = ", effectiveDate " . $picked['effectiveDate'];
            if ($picked['versions'] > 1) {
                $note .= " (newest of {$picked['versions']} versions in the response)";
            }
        }
        lognl(1, "... saved to " . $dir . "/" . $name . " with " . strlen($body) . " bytes" . $note);
    }
}

lognlsev(1, SUCCESS, $ok . " downloaded, " . count($failures) . " failed, output in ". $config['outdir']);
if ($failures !== []) {
    lognlsev(1, ERROR, "failures:\n  " . implode("\n  ", $failures) . "\n");
}

exit(min(count($failures), 254));
