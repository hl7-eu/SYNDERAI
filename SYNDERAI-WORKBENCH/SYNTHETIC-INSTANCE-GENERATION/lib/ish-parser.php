<?php

// ---- helpers ----

/**
 * BUG 1 FIX: before() and after() were called in transform_code() but never defined.
 * These are not PHP built-ins. Defined here as simple string utilities.
 */
/*
function before(string $needle, string $haystack): string {
    $pos = strpos($haystack, $needle);
    return $pos === false ? $haystack : substr($haystack, 0, $pos);
}
function after(string $needle, string $haystack): string {
    $pos = strpos($haystack, $needle);
    return $pos === false ? '' : substr($haystack, $pos + strlen($needle));
}
*/

function is_block_start(string $line): ?string {
    return preg_match('/^(\S+)\s*$/u', $line, $m) ? $m[1] : null;
}
function split_key_value(string $line): ?array {
    return preg_match('/^\s+(\S+)\s+(.*)$/u', $line, $m) ? [$m[1], rtrim($m[2])] : null;
}
function base_and_repeatable(string $name): array {
    return (substr($name, -1) === '*') ? [substr($name, 0, -1), true] : [$name, false];
}
function attach_child(array &$parent, string $base, bool $repeat, array $data): void {
    if ($repeat) {
        if (!isset($parent[$base]) || !is_array($parent[$base])) $parent[$base] = [];
        $parent[$base][] = $data;
    } else {
        $parent[$base] = $data; // last one wins
    }
}

/**
 * Transform code per rule:
 * If value contains BOTH '$' and '#', e.g. "$sct#65147003 display",
 * split into ['code'=>..., 'system'=>..., 'display'=>...]
 * If value is constructed as numeric #unit or numeric $system#unit
 * e.g. 400 #ng/l or 145 $ucum#mm[Hg]
 * split into ['value'=>..., 'system'=>..., 'unit'=>...]
 *
 * Otherwise return original value unchanged.
 *
 * BUG 5 FIX: parameter type changed from string to mixed.
 * The original string type hint caused a TypeError when an already-transformed
 * array value was passed in (possible if a value is processed more than once).
 * 
 * examples  (if system is omittedm ucum is assumed...)
 * 
 * "400 #ng/l"         → ['value' => 400,   'system' => '$ucum', 'unit' => 'ng/l']
 * "3.5 #mmol/l"       → ['value' => 3.5,   'system' => '$ucum', 'unit' => 'mmol/l']
 * "145 $ucum#mm[Hg]"  → ['value' => 145,   'system' => 'ucum',  'unit' => 'mm[Hg]']
 * "$sct#65147003 Foo" → ['code'  => '65147003', 'system' => '$sct', 'display' => 'Foo']
 * "plain string"      → ['text'  => 'plain string']
 */
function transform_code(mixed $value): mixed {
    if (!is_string($value)) return $value;

    // Branch 1 — coded concept: "$system#code [display]"
    if (strpos($value, '$') !== false && strpos($value, '#') !== false) {
        $pos = strpos($value, ' ');
        if ($pos === false) {
            // No display part — token is $system#code only
            $code   = after('#', $value);
            $system = before('#', $value);
            return ['code' => $code, 'system' => $system, 'display' => ''];
        }
        $cosy    = substr($value, 0, $pos);   // e.g. "$sct#65147003"
        $code    = after('#', $cosy);
        $system  = before('#', $cosy);
        $display = ltrim(substr($value, $pos + 1));
        return [
            'code' => $code,
            'system' => $system,
            'display' => $display
        ];
    }

    // Branch 2 — quantity: "numeric #unit" or "numeric $system#unit"
    // Matches an integer or decimal, whitespace, an optional $system, then #unit.
    if (preg_match('/^(\d+(?:\.\d+)?)\s+(?:\$([^#]*))?#(.+)$/', $value, $m)) {
        $numeric = strpos($m[1], '.') !== false ? (float) $m[1] : (int) $m[1];
        $system = strlen($m[2]) == 0 ? "ucum" : $m[2];  // empty string when no system was present, assume $ucum
        return [
            'value'  => $numeric,
            'system' => $system,   
            'unit'   => $m[3],
            'code'   => $m[3],
            'scale'  => 'numeric'
        ];
    }

    return $value;
}

function parse_ish_file(string $inputfile): array {
    if (is_file($inputfile)) {
        return parse_ish(file_get_contents($inputfile));
    } else {
        error_log("ERROR: ISH input file '$inputfile' not found.");
        return [];
    }
}

function parse_ish(string $input): array {

    // ---- state ----
    $lines = preg_split('/\R/u', $input);
    // stack frames: ['base'=>string, 'repeat'=>bool, 'indent'=>int, 'data'=>array]
    $stack    = [];
    $topLevel = [];

    // triple-quoted capture state
    $inTriple          = false;
    $tripleKey         = null;
    $tripleBuf         = [];
    $tripleTargetLevel = null;
    $tripleIsRepeat    = false; // BUG 3b FIX: was missing — repeat flag for triple-quoted keys
                                // was never stored, so text* entries were always stored as scalar

    // ---- main parse ----
    for ($i = 0; $i < count($lines); $i++) {
        $raw = $lines[$i];

        // echo $lines[$i] . "\n";

        // --- triple-quoted capture in progress ---
        if ($inTriple) {
            $pos = strpos($raw, '"""');
            if ($pos === false) {
                $tripleBuf[] = $raw;
                continue;
            }
            $tripleBuf[] = substr($raw, 0, $pos);
            $content = implode("\n", $tripleBuf);
            $stored  = transform_code($content);
            if ($tripleIsRepeat) {
                if (!isset($stack[$tripleTargetLevel]['data'][$tripleKey])
                    || !is_array($stack[$tripleTargetLevel]['data'][$tripleKey])) {
                    $stack[$tripleTargetLevel]['data'][$tripleKey] = [];
                }
                $stack[$tripleTargetLevel]['data'][$tripleKey][] = $stored;
            } else {
                $stack[$tripleTargetLevel]['data'][$tripleKey] = $stored;
            }
            $inTriple = false; $tripleKey = null; $tripleBuf = [];
            $tripleTargetLevel = null; $tripleIsRepeat = false;
            continue;
        }

        if (trim($raw) === '') continue;

        preg_match('/^([ \t]*)/', $raw, $m);
        $indent = strlen($m[1]);
        $line   = substr($raw, $indent);

        // --- close blocks whose scope has ended ---
        while (!empty($stack) && $indent <= end($stack)['indent']) {
            $child = array_pop($stack);
            if (!empty($stack)) {
                $parentIndex = array_key_last($stack);
                attach_child($stack[$parentIndex]['data'], $child['base'], $child['repeat'], $child['data']);
            } else {
                attach_child($topLevel, $child['base'], $child['repeat'], $child['data']);
            }
        }

        // --- block start? (single non-whitespace token on the line) ---
        if (($kw = is_block_start($line)) !== null) {
            [$base, $repeat] = base_and_repeatable($kw);
            $stack[] = ['base' => $base, 'repeat' => $repeat, 'indent' => $indent, 'data' => []];
            continue;
        }

        // --- key / value line ---
        if (($kv = split_key_value($raw)) !== null) {
            if (empty($stack)) continue;
            [$key, $value] = $kv;
            $top = array_key_last($stack);
            [$baseKey, $isRepeatKey] = base_and_repeatable($key);

            // BUG 2 + BUG 4 FIX: normalize away optional leading '= ' before the
            // triple-quote check. ISH allows both:
            //   text """..."""
            //   text = """..."""
            // The original code only matched /^"""/ so the '= ' prefix on the second
            // form silently bypassed the triple-quote branch, storing the raw string
            // '= """..."""' verbatim instead of the parsed narrative content.
            $normalizedValue = preg_replace('/^=\s*/', '', $value);

            if ($baseKey === 'text' && preg_match('/^"""(.*)$/', $normalizedValue, $mm)) {
                $after   = $mm[1];
                $closing = strpos($after, '"""');
                if ($closing !== false) {
                    // Entire triple-quoted content on one line
                    $content = substr($after, 0, $closing);
                    $stored  = transform_code($content);
                    if ($isRepeatKey) {
                        if (!isset($stack[$top]['data'][$baseKey])
                            || !is_array($stack[$top]['data'][$baseKey])) {
                            $stack[$top]['data'][$baseKey] = [];
                        }
                        $stack[$top]['data'][$baseKey][] = $stored;
                    } else {
                        $stack[$top]['data'][$baseKey] = $stored;
                    }
                } else {
                    // Multi-line: opening """ found, closing not yet seen
                    $inTriple          = true;
                    $tripleKey         = $baseKey;
                    $tripleIsRepeat    = $isRepeatKey;
                    $tripleBuf         = ($after !== '') ? [$after] : [];
                    $tripleTargetLevel = $top;
                }
            } else {
                // Plain key/value — use original $value (not $normalizedValue) to
                // avoid accidentally stripping a leading '=' from a real value.
                $stored = transform_code($value);
                if ($isRepeatKey) {
                    if (!isset($stack[$top]['data'][$baseKey])
                        || !is_array($stack[$top]['data'][$baseKey])) {
                        $stack[$top]['data'][$baseKey] = [];
                    }
                    $stack[$top]['data'][$baseKey][] = $stored;
                } else {
                    $stack[$top]['data'][$baseKey] = $stored;
                }
            }
            continue;
        }
    }

    // --- EOF: close all remaining open blocks ---
    while (!empty($stack)) {
        $child = array_pop($stack);
        if (!empty($stack)) {
            $parentIndex = array_key_last($stack);
            attach_child($stack[$parentIndex]['data'], $child['base'], $child['repeat'], $child['data']);
        } else {
            attach_child($topLevel, $child['base'], $child['repeat'], $child['data']);
        }
    }

    return $topLevel;
}

?>