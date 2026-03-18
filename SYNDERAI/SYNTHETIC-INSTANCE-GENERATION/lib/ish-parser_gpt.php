<?php
// ---- helpers ----
function is_block_start(string $line): ?string {
    return preg_match('/^(\S+)\s*$/', $line, $m) ? $m[1] : null;
}
function split_key_value(string $line): ?array {
    return preg_match('/^\s+(\S+)\s+(.*)$/', $line, $m) ? [$m[1], rtrim($m[2])] : null;
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
 * If value contains BOTH '$' and '#', split at first space into ['code'=>..., 'display'=>...].
 * Otherwise return original string.
 */
function transform_code(string $value) {
    if (strpos($value, '$') !== false && strpos($value, '#') !== false) {
        $pos = strpos($value, ' ');  // space as split?
        if ($pos === false) {
            // No space to split at: keep full token $s#c as code c and system s, empty display
            $code = after("#", $value);
            $system = before("#", $value);
            return ['code' => $code, 'system' => $system, 'display' => ''];
        }
        $cosy = substr($value, 0, $pos); // code and system split from display
        $code = after("#", $cosy);
        $system = before("#", $cosy);
        $display = ltrim(substr($value, $pos + 1)); // display value
        return ['code' => $code, 'system' => $system, 'display' => $display];
    }
    return $value;
}

function parse_ish_file($inputfile) {
    if (is_file($inputfile)) {
        parse_ish(file_get_contents($inputfile));
    } else {
        lognlsev(0, "ERROR", "......... +++ ISH input file '$inputfile' not found.\n");
    }
}

function parse_ish($input) {

    // ---- state ----
    $lines = preg_split('/\R/', $input);
    // stack frames: ['base'=>string,'repeat'=>bool,'indent'=>int,'data'=>array]
    $stack = [];
    $topLevel = []; // export as variables later

    // triple-quoted capture
    $inTriple = false;
    $tripleKey = null;
    $tripleBuf = [];
    $tripleTargetLevel = null; // int index into $stack

    // ---- main parse ----
    for ($i = 0; $i < count($lines); $i++) {
        echo $lines[$i] . "\n";
        $raw = $lines[$i];

        // Triple-quoted capture in progress
        if ($inTriple) {
            $pos = strpos($raw, '"""');
            if ($pos === false) { $tripleBuf[] = $raw; continue; }
            $tripleBuf[] = substr($raw, 0, $pos);
            $content = implode("\n", $tripleBuf);
            $stack[$tripleTargetLevel]['data'][$tripleKey] = transform_code($content);
            $inTriple = false; $tripleKey = null; $tripleBuf = []; $tripleTargetLevel = null;
            continue;
        }

        if (trim($raw) === '') continue;

        preg_match('/^([ \t]*)/', $raw, $m);
        $indent = strlen($m[1]);
        $line = substr($raw, $indent);

        // Close blocks whose scope ended
        while (!empty($stack) && $indent <= end($stack)['indent']) {
            $child = array_pop($stack);
            if (!empty($stack)) {
                $parentIndex = array_key_last($stack);
                attach_child($stack[$parentIndex]['data'], $child['base'], $child['repeat'], $child['data']);
            } else {
                attach_child($topLevel, $child['base'], $child['repeat'], $child['data']);
            }
        }

        // Block start? (single word)
        if (($kw = is_block_start($line)) !== null) {
            [$base, $repeat] = base_and_repeatable($kw);
            $stack[] = ['base'=>$base, 'repeat'=>$repeat, 'indent'=>$indent, 'data'=>[]];
            continue;
        }

        // Key/value?
        if (($kv = split_key_value($raw)) !== null) {
            if (empty($stack)) continue; // ignore stray
            [$key, $value] = $kv;
            $top = array_key_last($stack);

            // Handle repeatable key on key/value line: foo* value => parent['foo'][] = value
            [$baseKey, $isRepeatKey] = base_and_repeatable($key);

            // Triple-quoted (single-line or multi-line) only for literal base key 'text'
            if ($baseKey === 'text' && preg_match('/^"""(.*)$/', $value, $mm)) {
                $after = $mm[1];
                $closing = strpos($after, '"""');
                if ($closing !== false) {
                    $content = substr($after, 0, $closing);
                    $stored = transform_code($content);
                    if ($isRepeatKey) {
                        if (!isset($stack[$top]['data'][$baseKey]) || !is_array($stack[$top]['data'][$baseKey])) {
                            $stack[$top]['data'][$baseKey] = [];
                        }
                        $stack[$top]['data'][$baseKey][] = $stored;
                    } else {
                        $stack[$top]['data'][$baseKey] = $stored;
                    }
                } else {
                    $inTriple = true;
                    $tripleKey = $baseKey;            // store under base name (no '*')
                    $tripleBuf = [];
                    if ($after !== '') $tripleBuf[] = $after;
                    $tripleTargetLevel = $top;
                }
            } else {
                $stored = transform_code($value);
                if ($isRepeatKey) {
                    if (!isset($stack[$top]['data'][$baseKey]) || !is_array($stack[$top]['data'][$baseKey])) {
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

    // EOF: close remaining blocks
    while (!empty($stack)) {
        $child = array_pop($stack);
        if (!empty($stack)) {
            $parentIndex = array_key_last($stack);
            attach_child($stack[$parentIndex]['data'], $child['base'], $child['repeat'], $child['data']);
        } else {
            attach_child($topLevel, $child['base'], $child['repeat'], $child['data']);
        }
    }

    // Export top-level variables by base name
    foreach ($topLevel as $name => $val) {
        $$name = $val;
    }

    // var_dump($topLevel);exit;
    return $topLevel;

}

?>