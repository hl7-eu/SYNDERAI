<?php
/**
 * rxnorm-names.php
 *
 * Fetches the authoritative RxNorm Name and term type for a list of RXCUI values
 * from RxNav, and checks a list of rxcui/name pairs against it.
 *
 * Why this exists: a mapping run reported RXCUI values whose accompanying drug
 * names did not belong to them. Of 47 codes checked, 13 were unknown to RxNorm,
 * 8 named an ingredient rather than a finished product, and 12 named a different
 * drug. rxnorm_verify() catches exactly that class of defect.
 *
 * Endpoints used, all verified against rxnav.nlm.nih.gov:
 *   GET /REST/rxcui/{rxcui}/property.json?propName=RxNorm%20Name
 *   GET /REST/rxcui/{rxcui}/property.json?propName=TTY
 *   GET /REST/rxcui.json?name={name}&search=2          (normalised name lookup)
 *   GET /REST/approximateTerm.json?term={name}&maxEntries={n}
 * An unknown RXCUI answers 200 with an empty JSON object, not 404.
 * RxNav has no status endpoint under /REST/rxcui/{rxcui}/status.json — that path
 * answers 404 even for a current RXCUI — so "unknown" and "obsolete" cannot be
 * told apart here.
 *
 * Requires PHP 8.0 with ext-curl and ext-json; ext-dom only for
 * rxnorm_patch_conceptmap().
 *
 * RxNorm is a product of the US National Library of Medicine. Be polite to the
 * public endpoint: the throttle below defaults to 20 requests per second.
 */

declare(strict_types=1);

const RXNAV_BASE = 'https://rxnav.nlm.nih.gov/REST';

/**
 * One GET against RxNav. Returns [httpStatus, decodedJson|null, transportError].
 */
function rxnav_get(string $path, array $opt = []): array
{
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => $opt['userAgent'] ?? 'rxnorm-names.php',
        ]);
    }
    curl_setopt($ch, CURLOPT_URL, RXNAV_BASE . $path);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $opt['connectTimeout'] ?? 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $opt['timeout'] ?? 30);

    $attempts = max(1, (int) ($opt['retries'] ?? 3));
    $delayUs  = (int) (($opt['throttle'] ?? 0.05) * 1_000_000);

    for ($i = 1; $i <= $attempts; $i++) {
        if ($delayUs > 0) {
            usleep($delayUs);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            if ($i === $attempts) {
                return [0, null, curl_error($ch)];
            }
            sleep($i);
            continue;
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($code >= 500 && $i < $attempts) {
            sleep($i);
            continue;
        }

        return [$code, json_decode($body, true), ''];
    }

    return [0, null, 'exhausted'];
}

/** Pull the first propValue out of a /property.json answer. */
function rxnav_prop_value(?array $json): ?string
{
    $p = $json['propConceptGroup']['propConcept'][0]['propValue'] ?? null;

    return is_string($p) && $p !== '' ? $p : null;
}

/**
 * Look up the RxNorm Name and term type for each RXCUI.
 *
 * @param  string[] $rxcuis
 * @param  array{cacheFile?:string,throttle?:float,retries?:int,withTty?:bool} $opt
 * @return array<string,array{rxcui:string,name:?string,tty:?string,found:bool,http:int,error:?string}>
 *         keyed by rxcui, in the order given
 */
function rxnorm_names(array $rxcuis, array $opt = []): array
{
    $withTty = $opt['withTty'] ?? true;
    $cacheFile = $opt['cacheFile'] ?? null;

    $cache = [];
    if ($cacheFile !== null && is_file($cacheFile)) {
        $cache = json_decode((string) file_get_contents($cacheFile), true) ?: [];
    }

    $out = [];
    $dirty = false;
    foreach ($rxcuis as $raw) {
        $rxcui = trim((string) $raw);
        if ($rxcui === '' || isset($out[$rxcui])) {
            continue;
        }
        if (isset($cache[$rxcui])) {
            $out[$rxcui] = $cache[$rxcui];
            continue;
        }
        if (!ctype_digit($rxcui)) {
            $out[$rxcui] = ['rxcui' => $rxcui, 'name' => null, 'tty' => null,
                            'found' => false, 'http' => 0,
                            'error' => 'not a numeric RXCUI'];
            continue;
        }

        [$code, $json, $err] = rxnav_get("/rxcui/$rxcui/property.json?propName=" . rawurlencode('RxNorm Name'), $opt);
        $rec = ['rxcui' => $rxcui, 'name' => null, 'tty' => null,
                'found' => false, 'http' => $code, 'error' => $err ?: null];

        if ($code === 200) {
            $rec['name'] = rxnav_prop_value($json);
            $rec['found'] = $rec['name'] !== null;
            if ($rec['found'] && $withTty) {
                [$c2, $j2] = rxnav_get("/rxcui/$rxcui/property.json?propName=TTY", $opt);
                if ($c2 === 200) {
                    $rec['tty'] = rxnav_prop_value($j2);
                }
            }
            if (!$rec['found'] && $rec['error'] === null) {
                $rec['error'] = 'RXCUI unknown to RxNorm';
            }
        } elseif ($rec['error'] === null) {
            $rec['error'] = "HTTP $code";
        }

        $out[$rxcui] = $rec;
        $cache[$rxcui] = $rec;
        $dirty = true;
    }

    if ($cacheFile !== null && $dirty) {
        file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $out;
}

/**
 * Check rxcui => expected name pairs against RxNorm.
 *
 * verdict is one of:
 *   ok        the expected name equals the RxNorm Name
 *   variant   same after normalisation (case, spacing, punctuation, US/INN spelling)
 *   mismatch  RxNorm names a different concept
 *   unknown   RxNorm does not know this RXCUI
 *   error     the lookup failed
 *
 * @param  array<string,string> $pairs rxcui => name as used locally
 * @return array<int,array{rxcui:string,expected:string,actual:?string,tty:?string,verdict:string}>
 */
function rxnorm_verify(array $pairs, array $opt = []): array
{
    $names = rxnorm_names(array_keys($pairs), $opt);

    $normalise = static function (string $s): string {
        $s = mb_strtolower($s);
        $s = strtr($s, [
            'paracetamol' => 'acetaminophen',
            'salbutamol'  => 'albuterol',
            'adrenaline'  => 'epinephrine',
            'rifampicin'  => 'rifampin',
        ]);
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    };

    $rows = [];
    foreach ($pairs as $rxcui => $expected) {
        $rxcui = (string) $rxcui;
        $rec = $names[$rxcui] ?? null;
        $actual = $rec['name'] ?? null;

        if ($rec === null || ($rec['error'] !== null && !$rec['found'] && $rec['http'] !== 200)) {
            $verdict = 'error';
        } elseif (!$rec['found']) {
            $verdict = 'unknown';
        } elseif ($actual === $expected) {
            $verdict = 'ok';
        } elseif ($normalise($actual) === $normalise($expected)) {
            $verdict = 'variant';
        } else {
            $verdict = 'mismatch';
        }

        $rows[] = ['rxcui' => $rxcui, 'expected' => $expected, 'actual' => $actual,
                   'tty' => $rec['tty'] ?? null, 'verdict' => $verdict];
    }

    return $rows;
}

/**
 * Resolve a drug name to an RXCUI. Tries the normalised name lookup first, then
 * the approximate matcher, and returns only candidates whose RxNorm Name reads
 * back — an approximate hit alone is not evidence.
 *
 * @return array<int,array{rxcui:string,name:string,tty:?string,how:string}>
 */
function rxnorm_find(string $name, int $max = 3, array $opt = []): array
{
    [$code, $json] = rxnav_get('/rxcui.json?search=2&name=' . rawurlencode($name), $opt);
    $ids = $code === 200 ? ($json['idGroup']['rxnormId'] ?? []) : [];
    $how = 'normalised';

    if (!$ids) {
        [$code, $json] = rxnav_get('/approximateTerm.json?maxEntries=' . max(1, $max * 3)
                                   . '&term=' . rawurlencode($name), $opt);
        $ids = array_column($json['approximateGroup']['candidate'] ?? [], 'rxcui');
        $how = 'approximate';
    }

    $out = [];
    foreach (array_unique(array_map('strval', $ids)) as $id) {
        $rec = rxnorm_names([$id], $opt)[$id] ?? null;
        if ($rec === null || !$rec['found']) {
            continue;   // obsolete or non-normal-form concept
        }
        $out[] = ['rxcui' => $id, 'name' => $rec['name'], 'tty' => $rec['tty'], 'how' => $how];
        if (count($out) >= $max) {
            break;
        }
    }

    return $out;
}

/**
 * Rewrite element/@displayName in a DECOR conceptMap from RxNorm, for the group
 * whose source codeSystem is RxNorm. Returns a report; writes only when $outFile
 * is given. Elements whose RXCUI is unknown are left untouched and reported.
 *
 * @return array{changed:int,unknown:string[],rows:array<int,array{rxcui:string,old:string,new:?string,verdict:string}>}
 */
function rxnorm_patch_conceptmap(string $inFile, ?string $outFile = null,
                                 string $rxnormOid = '2.16.840.1.113883.6.88',
                                 array $opt = []): array
{
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    $doc->formatOutput = false;
    if (!$doc->load($inFile)) {
        throw new RuntimeException("cannot parse $inFile");
    }
    $xpath = new DOMXPath($doc);

    $pairs = [];
    $elements = [];
    foreach ($xpath->query('//*[local-name()="group"]') as $group) {
        $src = $xpath->query('*[local-name()="source"]', $group)->item(0);
        if (!$src instanceof DOMElement || $src->getAttribute('codeSystem') !== $rxnormOid) {
            continue;
        }
        foreach ($xpath->query('*[local-name()="element"]', $group) as $el) {
            /** @var DOMElement $el */
            $rxcui = $el->getAttribute('code');
            $pairs[$rxcui] = $el->getAttribute('displayName');
            $elements[$rxcui] = $el;
        }
    }

    $rows = [];
    $unknown = [];
    $changed = 0;
    foreach (rxnorm_verify($pairs, $opt) as $r) {
        $rows[] = ['rxcui' => $r['rxcui'], 'old' => $r['expected'],
                   'new' => $r['actual'], 'verdict' => $r['verdict']];
        if ($r['verdict'] === 'ok') {
            continue;
        }
        if ($r['actual'] === null) {
            $unknown[] = $r['rxcui'];
            continue;
        }
        $elements[$r['rxcui']]->setAttribute('displayName', $r['actual']);
        $changed++;
    }

    if ($outFile !== null) {
        file_put_contents($outFile, $doc->saveXML());
    }

    return ['changed' => $changed, 'unknown' => $unknown, 'rows' => $rows];
}

// ---------------------------------------------------------------- CLI demo
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $rxcuis = array_slice($argv, 1);
    if (!$rxcuis) {
        fwrite(STDERR, "Usage: php rxnorm-names.php <rxcui> [<rxcui> ...]\n");
        exit(2);
    }
    foreach (rxnorm_names($rxcuis) as $r) {
        printf("%-10s %-8s %s\n", $r['rxcui'], $r['tty'] ?? '-',
               $r['name'] ?? ('- (' . ($r['error'] ?? 'no name') . ')'));
    }
}
