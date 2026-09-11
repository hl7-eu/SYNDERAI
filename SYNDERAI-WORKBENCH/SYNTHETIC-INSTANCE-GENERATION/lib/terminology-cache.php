<?php
/**
 * terminology-cache.php
 *
 * Reads the artefacts that fetch-terminology.php has cached, so the pipeline
 * works against the ART-DECOR project instead of hand-maintained CSV files.
 *
 * File layout, as written by fetch-terminology.php:
 *   <TERMINOLOGY>/valueset/<name>.xml
 *   <TERMINOLOGY>/codesystem/<name>.xml
 *   <TERMINOLOGY>/conceptmap/<name>.xml
 * The name is the file stem configured in terminology-config.php.
 *
 * Three kinds of artefact, three sets of functions.
 *
 * Value sets — which codes a field is allowed to carry:
 *   valueset_codes()          code => displayName, the whole binding
 *   valueset_display()        one display name, with a fallback and with
 *                             synonyms resolved
 *   valueset_canonical_code() the code a value belongs to, null when the value
 *                             set knows neither it nor a synonym for it
 *   valueset_aliases()        synonym => code, synonyms shared by concepts
 *                             with different display names dropped
 *   valueset_designations()   every synonym or fully specified name per code
 *
 * Code systems — the concepts we define ourselves:
 *   codesystem_codes()        code => displayName, from the preferred
 *                             designation, which is language dependent
 *   codesystem_properties()   code => [property => value]
 *   codesystem_property()     one property of one concept
 *   codesystem_designations() every synonym or fully specified name per
 *                             concept, the counterpart of
 *                             valueset_designations()
 *   codesystem_property_definitions()
 *                             what those properties mean, from the head of the
 *                             code system
 *   codesystem_terms()        term => code, where a term is the code itself or
 *                             any of its designations; terms shared by concepts
 *                             with different preferred names are dropped
 *   codesystem_code_for_term()
 *                             the code a label belongs to, null when the code
 *                             system knows no unambiguous concept for it
 *
 * Concept maps — translate a code from one terminology into another:
 *   map_concept()             the pick, plus every candidate and an ambiguous
 *                             flag when the source has more than one target
 *   map_term()                the same, but entered through a label rather
 *                             than a code
 *   conceptmap_index()        the whole map, keyed by source code
 *
 * Two conventions that hold throughout:
 *
 * Missing artefacts raise a RuntimeException rather than returning an empty
 * result, because an empty binding turns every validation into a pass. A code
 * that is simply not in an artefact is not an error: map_concept() answers
 * null, valueset_canonical_code() answers null, valueset_display() falls back.
 *
 * Deprecated and abstract concepts are left out by default. They are part of
 * the artefact but not a usable value, and the same goes for a value set's
 * <exception> entries. Options bring them back where a caller needs them.
 *
 * Everything is loaded once per name and kept in a static cache.
 *
 * Requires PHP 8.0 with ext-dom and ext-simplexml.
 */

declare(strict_types=1);

/** Directory holding the frozen artefacts. */
const TERMINOLOGY = MAPPINGS . '/art-decor-synderai-terminology-extracts';

/** Directory holding the frozen concept maps. */
const CONCEPTMAP_DIR = TERMINOLOGY . '/conceptmap';

/**
 * Relationship preference, strongest first. A lower number wins.
 *
 * For SNOMED to ICPC, "wider" is ranked above "narrower": the ICPC rubric
 * being broader than the SNOMED concept is the expected direction of loss
 * when a fine-grained finding is filed into a coarse classification.
 * "narrower" claims more specificity than the source carries. Invert this
 * table for the opposite mapping direction.
 *
 * "equivalent" sits just behind "equal": DECOR uses it where the two concepts
 * denote the same thing without being the identical concept. It has to be
 * listed, otherwise it falls through to PHP_INT_MAX and would rank below
 * "inexact".
 */
const CONCEPTMAP_RELATIONSHIP_RANK = [
    'equal' => 0,
    'equivalent' => 1,
    'wider' => 2,
    'narrower' => 3,
    'inexact' => 4,
    'relatedto' => 4,
];

// =====================================================================
// shared
// =====================================================================

/**
 * Load a cached artefact and return its root element.
 *
 * Fails hard when the file is missing or unreadable: an empty result would
 * silently turn every validation into a pass.
 */
function terminology_document(string $type, string $name): DOMElement
{
    static $cache = [];
    $key = $type . '/' . $name;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
        throw new RuntimeException("invalid artefact name: $name");
    }

    $path = TERMINOLOGY . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR . $name . '.xml';
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("terminology cache misses $type '$name' ($path). "
            . 'Run fetch-terminology.php first.');
    }

    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $doc = new DOMDocument();
    $ok = $doc->load($path);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$ok || $doc->documentElement === null) {
        $first = isset($errors[0]) ? trim($errors[0]->message) : 'not well-formed';
        throw new RuntimeException("cannot parse $path: $first");
    }

    return $cache[$key] = $doc->documentElement;
}

/** All descendant elements of $parent with the given local name, namespace agnostic. */
function terminology_children(DOMElement $parent, string $localName): array
{
    $out = [];
    foreach ($parent->getElementsByTagNameNS('*', $localName) as $node) {
        if ($node instanceof DOMElement) {
            $out[] = $node;
        }
    }

    return $out;
}

/**
 * The value carried by a DECOR property element.
 *
 * The child element names the datatype and does not agree on the attribute:
 * <valueString value="…"/> and <valueInteger value="…"/> use @value,
 * <valueCode code="…"/> uses @code.
 */
function terminology_property_value(DOMElement $property): ?string
{
    foreach ($property->childNodes as $child) {
        if (!$child instanceof DOMElement || !str_starts_with($child->localName, 'value')) {
            continue;
        }
        foreach (['value', 'code'] as $attribute) {
            if ($child->hasAttribute($attribute)) {
                return trim($child->getAttribute($attribute));
            }
        }
        $text = trim($child->textContent);

        return $text !== '' ? $text : null;
    }

    return null;
}

/**
 * The preferred designation of a concept, in $language where one exists.
 * Falls back to any preferred designation, then to any designation at all.
 */
function terminology_preferred(DOMElement $concept, ?string $language = null): string
{
    $anyPreferred = '';
    $any = '';

    foreach ($concept->getElementsByTagNameNS('*', 'designation') as $d) {
        if (!$d instanceof DOMElement) {
            continue;
        }
        $term = trim($d->getAttribute('displayName'));
        if ($term === '') {
            continue;
        }
        $isPreferred = $d->getAttribute('type') === 'preferred';
        if ($isPreferred && ($language === null || $d->getAttribute('language') === $language)) {
            return $term;
        }
        if ($isPreferred && $anyPreferred === '') {
            $anyPreferred = $term;
        }
        if ($any === '') {
            $any = $term;
        }
    }

    return $anyPreferred !== '' ? $anyPreferred : $any;
}

// =====================================================================
// value sets
// =====================================================================

/**
 * Codes of a cached value set as code => displayName.
 *
 * Skipped by default:
 *   <exception>   codes that stand for "unknown", "not applicable" and the
 *                 like. They are part of the value set but not a real value.
 *   type="D"      deprecated concepts.
 *   type="A"      abstract concepts, which group the list but cannot be used
 *                 as a value. Pass includeAbstract to keep them.
 *
 * @param  array{includeAbstract?:bool,includeDeprecated?:bool,includeExceptions?:bool} $opt
 * @return array<string,string>
 */
function valueset_codes(string $name, array $opt = []): array
{
    static $cache = [];
    $key = $name . '|' . json_encode($opt);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $root = terminology_document('valueset', $name);

    $skipTypes = [];
    if (empty($opt['includeDeprecated'])) {
        $skipTypes[] = 'D';
    }
    if (empty($opt['includeAbstract'])) {
        $skipTypes[] = 'A';
    }

    $codes = [];
    $elements = terminology_children($root, 'concept');
    if (!empty($opt['includeExceptions'])) {
        $elements = array_merge($elements, terminology_children($root, 'exception'));
    }

    foreach ($elements as $concept) {
        $code = trim($concept->getAttribute('code'));
        if ($code === '' || in_array($concept->getAttribute('type'), $skipTypes, true)) {
            continue;
        }
        $display = trim($concept->getAttribute('displayName'));
        $codes[$code] = $display !== '' ? $display : $code;
    }

    if ($codes === []) {
        throw new RuntimeException("value set '$name' holds no usable code. "
            . 'An intensional value set is not expanded by the API and cannot be read this way.');
    }

    return $cache[$key] = $codes;
}

/**
 * Synonyms and fully specified names of a value set, as code => list of terms.
 * Useful where the pipeline has to recognise a unit or a label it did not emit
 * itself, for instance /mm^3 for the UCUM code /uL.
 *
 * @return array<string,string[]>
 */
function valueset_designations(string $name, ?string $type = null): array
{
    $root = terminology_document('valueset', $name);

    $out = [];
    foreach (terminology_children($root, 'concept') as $concept) {
        $code = trim($concept->getAttribute('code'));
        if ($code === '') {
            continue;
        }
        foreach ($concept->getElementsByTagNameNS('*', 'designation') as $d) {
            if ($type !== null && $d->getAttribute('type') !== $type) {
                continue;
            }
            $term = trim($d->getAttribute('displayName'));
            if ($term !== '') {
                $out[$code][] = $term;
            }
        }
    }

    return $out;
}

/**
 * Synonym => code, for the synonyms that are not a code themselves.
 *
 * A value set may name a unit in more than one way: the UCUM code /uL carries
 * /mm^3 as a synonym, [iU]/L carries IU/L, 10*3/uL carries K/uL, 10^3/uL and
 * x10^3/mm^3. A pipeline that writes one of those spellings would otherwise
 * look like it had emitted an unknown unit.
 *
 * A synonym that points at more than one code is kept when those codes all
 * carry the same display name, because then they denote the same concept and
 * the choice does not matter: vs-subset-vaccine-administered-code-set-cvx
 * holds CVX 3 and 03 side by side, both "measles, mumps and rubella virus
 * vaccine", because the pipeline emits the leading zero. The first code in
 * document order wins, which is the variant the value set lists first. Only
 * when the display names disagree is the synonym dropped rather than guessed.
 *
 * @return array<string,string>
 */
function valueset_aliases(string $name): array
{
    static $cache = [];
    if (isset($cache[$name])) {
        return $cache[$name];
    }

    $codes = valueset_codes($name);
    $seen = [];
    foreach (valueset_designations($name, 'synonym') as $code => $terms) {
        foreach ($terms as $term) {
            if ($term === (string) $code || isset($codes[$term])) {
                continue;               // the code itself, or a code in its own right
            }
            $seen[$term][$code] = true;
        }
    }

    $alias = [];
    foreach ($seen as $term => $targets) {
        $targetCodes = array_map('strval', array_keys($targets));
        $displays = [];
        foreach ($targetCodes as $target) {
            $displays[$codes[$target] ?? ''] = true;
        }
        if (count($displays) === 1) {
            $alias[(string) $term] = $targetCodes[0];
        }
    }

    return $cache[$name] = $alias;
}

/**
 * The code a value belongs to, following synonyms. Null when the value set
 * knows neither the code nor a synonym for it.
 */
function valueset_canonical_code(string $name, string|int|null $code): ?string
{
    $code = trim((string) $code);
    if ($code === '') {
        return null;
    }
    if (isset(valueset_codes($name)[$code])) {
        return $code;
    }

    return valueset_aliases($name)[$code] ?? null;
}

/**
 * Display name for a code, with a fallback for values the value set does not
 * know. The fallback defaults to the code itself, which keeps the behaviour of
 * a plain array lookup.
 *
 * Synonyms are followed by default: a value the pipeline spells differently is
 * still a known value. Pass $useSynonyms = false for a strict lookup.
 */
function valueset_display(string $name, string|int|null $code, ?string $fallback = null, bool $useSynonyms = true): string
{
    $code = trim((string) $code);
    $canonical = $useSynonyms ? valueset_canonical_code($name, $code) : $code;

    if ($canonical !== null && isset(valueset_codes($name)[$canonical])) {
        return valueset_codes($name)[$canonical];
    }

    return $fallback ?? $code;
}

// =====================================================================
// code systems
// =====================================================================

/**
 * Concepts of a cached code system as code => displayName.
 *
 * A DECOR code system carries no displayName attribute on the concept: the
 * name lives in <designation type="preferred">, and its language differs per
 * code system, en-US for cs-specimen-label and nl-NL for the ICPC table. Pass
 * $language to prefer one, the function falls back when it is absent.
 *
 * Concepts whose statusCode is not active, and type="D" or type="A", are left
 * out for the same reason as in a value set.
 *
 * @param  array{language?:string,includeAbstract?:bool,includeInactive?:bool} $opt
 * @return array<string,string>
 */
function codesystem_codes(string $name, array $opt = []): array
{
    static $cache = [];
    $key = $name . '|' . json_encode($opt);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $root = terminology_document('codesystem', $name);
    $language = $opt['language'] ?? null;

    $skipTypes = ['D'];
    if (empty($opt['includeAbstract'])) {
        $skipTypes[] = 'A';
    }

    $codes = [];
    foreach (terminology_children($root, 'codedConcept') as $concept) {
        $code = trim($concept->getAttribute('code'));
        if ($code === '' || in_array($concept->getAttribute('type'), $skipTypes, true)) {
            continue;
        }
        $status = $concept->getAttribute('statusCode');
        if (empty($opt['includeInactive']) && $status !== '' && $status !== 'active') {
            continue;
        }
        $display = terminology_preferred($concept, $language);
        $codes[$code] = $display !== '' ? $display : $code;
    }

    if ($codes === []) {
        throw new RuntimeException("code system '$name' holds no usable concept");
    }

    return $cache[$key] = $codes;
}

/**
 * Properties of a code system's concepts, as code => [property => value].
 *
 * This is where the invented-but-realistic values live: a code system of our
 * own states them as properties and says in its description that they are
 * invented, so nothing has to pretend they are an equivalence.
 *
 * Concepts without properties are absent from the result.
 *
 * @return array<string,array<string,string>>
 */
function codesystem_properties(string $name): array
{
    static $cache = [];
    if (isset($cache[$name])) {
        return $cache[$name];
    }

    $root = terminology_document('codesystem', $name);

    $out = [];
    foreach (terminology_children($root, 'codedConcept') as $concept) {
        $code = trim($concept->getAttribute('code'));
        if ($code === '') {
            continue;
        }
        foreach ($concept->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->localName !== 'property') {
                continue;
            }
            $property = trim($child->getAttribute('code'));
            $value = terminology_property_value($child);
            if ($property !== '' && $value !== null) {
                $out[$code][$property] = $value;
            }
        }
    }

    return $cache[$name] = $out;
}

/**
 * One property of one concept, or null when either is absent.
 * codesystem_property('cs-icpc-1-nl-nhg-tabel-24', 'A01', 'icpcCodeType') === 'rubric'
 */
function codesystem_property(string $name, string|int $code, string $property): ?string
{
    return codesystem_properties($name)[trim((string) $code)][$property] ?? null;
}

/**
 * Term => code for a code system, over every designation a concept carries.
 *
 * The counterpart of codesystem_codes(): that one answers "what is this code
 * called", this one answers "which code is this label". A pipeline that has a
 * label rather than a code — a LOINC System string such as Ser/Plas, a unit,
 * a name someone typed — needs this direction.
 *
 * A term that points at more than one concept is dropped rather than guessed,
 * the same rule as valueset_aliases(). "Amniotic fluid" is such a case in
 * cs-specimen-label: it belongs to both the plain and the supernatant concept,
 * so it resolves to neither.
 *
 * Matching is exact, including case. A label the pipeline spells differently
 * is a finding, not something to paper over in the comparison: the fix is a
 * designation on the concept, so the binding says which spellings are known.
 *
 * As in valueset_aliases(), a term shared by several concepts survives when
 * those concepts carry the same preferred designation, because then the choice
 * between them does not matter. The first in document order wins.
 *
 * @return array<string,string>
 */
function codesystem_terms(string $name): array
{
    static $cache = [];
    if (isset($cache[$name])) {
        return $cache[$name];
    }

    $root = terminology_document('codesystem', $name);

    $seen = [];
    foreach (terminology_children($root, 'codedConcept') as $concept) {
        $code = trim($concept->getAttribute('code'));
        if ($code === '') {
            continue;
        }
        $seen[$code][$code] = true;             // the code is a term for itself
        foreach ($concept->getElementsByTagNameNS('*', 'designation') as $d) {
            $term = trim($d->getAttribute('displayName'));
            if ($term !== '') {
                $seen[$term][$code] = true;
            }
        }
    }

    $preferred = codesystem_codes($name);

    $terms = [];
    foreach ($seen as $term => $codes) {
        $termCodes = array_map('strval', array_keys($codes));
        $displays = [];
        foreach ($termCodes as $candidate) {
            $displays[$preferred[$candidate] ?? ''] = true;
        }
        if (count($displays) === 1) {
            $terms[(string) $term] = $termCodes[0];
        }
    }

    return $cache[$name] = $terms;
}

/**
 * Synonyms and fully specified names of a code system, as code => list of
 * terms. The counterpart of valueset_designations(), for the case where a
 * concept carries a second name besides the preferred one:
 * cs-synthea-laboratory-category holds the category the pipeline emits as the
 * preferred designation and the label it shows as a synonym.
 *
 * @return array<string,string[]>
 */
function codesystem_designations(string $name, ?string $type = null): array
{
    $root = terminology_document('codesystem', $name);

    $out = [];
    foreach (terminology_children($root, 'codedConcept') as $concept) {
        $code = trim($concept->getAttribute('code'));
        if ($code === '') {
            continue;
        }
        foreach ($concept->getElementsByTagNameNS('*', 'designation') as $d) {
            if ($type !== null && $d->getAttribute('type') !== $type) {
                continue;
            }
            $term = trim($d->getAttribute('displayName'));
            if ($term !== '') {
                $out[$code][] = $term;
            }
        }
    }

    return $out;
}

/**
 * The concept a label belongs to, or null when the code system knows neither
 * the code nor a designation matching it, or when the label is ambiguous.
 */
function codesystem_code_for_term(string $name, string|int|null $term): ?string
{
    $term = trim((string) $term);

    return $term === '' ? null : (codesystem_terms($name)[$term] ?? null);
}

/**
 * The property declarations in the code system's head, as
 * code => [type, description]. Tells the caller what a property means without
 * looking into the XML.
 *
 * @return array<string,array{type:string,description:string}>
 */
function codesystem_property_definitions(string $name): array
{
    $root = terminology_document('codesystem', $name);

    $out = [];
    foreach ($root->childNodes as $child) {
        if (!$child instanceof DOMElement || $child->localName !== 'property') {
            continue;
        }
        $code = trim($child->getAttribute('code'));
        if ($code !== '') {
            $out[$code] = [
                'type' => $child->getAttribute('type'),
                'description' => trim($child->getAttribute('description')),
            ];
        }
    }

    return $out;
}

// =====================================================================
// concept maps
// =====================================================================

/**
 * Concept Map Resolver
 *
 * Translates a code from one terminology into another using a frozen
 * ART-DECOR concept map, e.g. SNOMED "414916001" (Obesity (disorder))
 * into ICPC-1-NL "T82" (Obesitas).
 *
 * Valid concept maps are the *.xml files in CONCEPTMAP_DIR. The map is
 * parsed once per request and held in a static index, so repeated calls
 * for different codes cost one hash lookup each.
 *
 * Supported serialisations:
 *   - ART-DECOR native  <element code="..." displayName="...">
 *                         <target code="..." relationship="..." .../>
 *   - FHIR XML          <element><code value="..."/>
 *                         <target><code value="..."/><relationship value="..."/>
 *   R4 spells the attribute "equivalence", R5 spells it "relationship";
 *   both are accepted and normalised to the R4 vocabulary.
 *
 * A source concept may resolve to more than one target. The map itself
 * encodes no rule for choosing between them, so this resolver picks by
 * relationship strength (see CONCEPTMAP_RELATIONSHIP_RANK) and reports the
 * full candidate list alongside the pick. Callers that must not guess should
 * branch on the "ambiguous" flag.
 *
 * Returned property array shape (all keys are present):
 * [
 *   "code"          => string   target code
 *   "display"       => string   target designation
 *   "system"        => string   target code system OID, taken from the
 *                               group/target the hit belongs to
 *   "relationship"  => string   equal|equivalent|wider|narrower|inexact|relatedto
 *   "comment"       => string   usage note from the map, "" when absent
 *   "sourceDisplay" => string   designation of the source concept in the map
 *   "sourceSystem"  => string   source code system OID, from group/source
 *   "ambiguous"     => bool     true when more than one target was offered
 *   "candidates"    => array    all targets, strongest first; each entry has
 *                               code, display, system, relationship, comment
 * ]
 * - or NULL for empty input and for a concept not found in the map.
 *   Call sites must null-check before subscripting.
 *
 * A row with relationship="unmatched" carries no target code and is treated as
 * no hit: SNOMED has nothing usable for that source, and the map says so.
 *
 * Note on language: target designations carry no language attribute in the
 * DECOR serialisation. In the SNOMED to ICPC-1-NL map they are Dutch, not
 * en-US.
 */

/**
 * Resolve a concept map name to a readable file path.
 *
 * Accepts the name with or without separators, so both "SNOMED-ICPC" and
 * "SNOMEDICPC" find SNOMEDICPC.xml.
 *
 * @throws RuntimeException when the name is unsafe or no file matches
 */
function conceptmap_path(string $cm): string
{
    if (preg_match('/^[A-Za-z0-9._-]+$/', $cm) !== 1) {
        throw new RuntimeException("Invalid concept map name: {$cm}");
    }

    $candidates = [
        $cm . '.xml',
        str_replace(['-', '_'], '', $cm) . '.xml',
    ];

    foreach ($candidates as $candidate) {
        $path = CONCEPTMAP_DIR . '/' . basename($candidate);
        if (is_readable($path)) {
            return $path;
        }
    }

    throw new RuntimeException("Concept map not found in " . CONCEPTMAP_DIR . ": {$cm}");
}

/**
 * Read one code value from an element that may use either serialisation.
 */
function conceptmap_attr(SimpleXMLElement $node, string $name): string
{
    if (isset($node[$name])) {
        return trim((string) $node[$name]);
    }
    if (isset($node->{$name}) && isset($node->{$name}['value'])) {
        return trim((string) $node->{$name}['value']);
    }

    return '';
}

/**
 * Read the code system declared on a group/source or group/target element.
 *
 * DECOR writes it as @codeSystem and repeats it as @canonicalUri in urn:oid
 * form. FHIR XML writes the canonical URI in @value. The OID form is
 * returned when available, because that is what the CDA templates expect.
 */
function conceptmap_group_system(?SimpleXMLElement $node): string
{
    if ($node === null) {
        return '';
    }

    $system = conceptmap_attr($node, 'codeSystem');
    if ($system !== '') {
        return $system;
    }

    // FHIR XML: <target value="http://..."/> or <target value="urn:oid:..."/>
    $uri = trim((string) ($node['value'] ?? ''));
    if ($uri === '') {
        $uri = conceptmap_attr($node, 'canonicalUri');
    }

    return str_starts_with($uri, 'urn:oid:') ? substr($uri, 8) : $uri;
}

/**
 * Build and memoise the lookup index for one concept map.
 *
 * The map is loaded on first use and kept for the lifetime of the request.
 * At roughly 1400 source concepts this stays well inside a normal memory
 * budget. Switch to XMLReader if a map ever grows past a few megabytes.
 *
 * @return array<string, array<string, mixed>> keyed by source code
 * @throws RuntimeException on unreadable or malformed XML
 */
function conceptmap_index(string $cm): array
{
    /** @var array<string, array<string, array<string, mixed>>> $indexes */
    static $indexes = [];

    if (isset($indexes[$cm])) {
        return $indexes[$cm];
    }

    $path = conceptmap_path($cm);

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        $first = $errors[0]->message ?? 'unknown parse error';
        throw new RuntimeException("Cannot parse concept map {$path}: " . trim($first));
    }

    $index = [];

    foreach ($xml->group as $group) {
        // Code systems are declared once per group and apply to every element
        // inside it. DECOR puts them in @codeSystem, FHIR XML in @value.
        $groupSourceSystem = conceptmap_group_system($group->source ?? null);
        $groupTargetSystem = conceptmap_group_system($group->target ?? null);

        foreach ($group->element as $element) {
            $source = conceptmap_attr($element, 'code');
            if ($source === '') {
                continue;
            }

            $targets = [];

            foreach ($element->target as $target) {
                // noMap marks a source that is deliberately left untranslated.
                if (strtolower(conceptmap_attr($target, 'noMap')) === 'true') {
                    continue;
                }

                $code = conceptmap_attr($target, 'code');
                if ($code === '') {
                    continue;           // relationship="unmatched" carries no code
                }

                // R5 uses "relationship", R4 uses "equivalence".
                $relationship = conceptmap_attr($target, 'relationship');
                if ($relationship === '') {
                    $relationship = conceptmap_attr($target, 'equivalence');
                }

                $comment = isset($target->comment) ? trim((string) $target->comment) : '';

                $targets[] = [
                    'code' => $code,
                    'display' => conceptmap_attr($target, 'displayName'),
                    'system' => $groupTargetSystem,
                    'relationship' => $relationship,
                    'comment' => $comment,
                ];
            }

            if ($targets === []) {
                continue;
            }

            // Strongest relationship first, original order preserved within a rank.
            usort($targets, static function (array $a, array $b): int {
                $ra = CONCEPTMAP_RELATIONSHIP_RANK[$a['relationship']] ?? PHP_INT_MAX;
                $rb = CONCEPTMAP_RELATIONSHIP_RANK[$b['relationship']] ?? PHP_INT_MAX;

                return $ra <=> $rb;
            });

            // A source code may appear in more than one group; merge rather than
            // overwrite.
            if (isset($index[$source])) {
                $index[$source]['targets'] = array_merge($index[$source]['targets'], $targets);
                continue;
            }

            $index[$source] = [
                'display' => conceptmap_attr($element, 'displayName'),
                'system' => $groupSourceSystem,
                'targets' => $targets,
            ];
        }
    }

    if ($index === []) {
        throw new RuntimeException("Concept map {$path} yielded no usable mappings");
    }

    $indexes[$cm] = $index;

    return $index;
}

/**
 * Translate a single code using a concept map.
 *
 * @param  string|null $c   source code, e.g. "414916001"
 * @param  string      $cm  concept map name, e.g. "cm-snomed-to-icpc1nl"
 * @return array<string, mixed>|null  null on empty input or no hit
 * @throws RuntimeException when the concept map cannot be loaded
 */
function map_concept(string|int|null $c, string $cm): ?array
{
    $c = trim((string) $c);

    if ($c === '') {
        return NULL;
    }

    $index = conceptmap_index($cm);

    if (!isset($index[$c])) {
        return NULL;
    }

    $entry = $index[$c];
    $best = $entry['targets'][0];

    return [
        'code' => $best['code'],
        'display' => $best['display'],
        'system' => $best['system'],
        'relationship' => $best['relationship'],
        'comment' => $best['comment'],
        'sourceDisplay' => $entry['display'],
        'sourceSystem' => $entry['system'],
        'ambiguous' => count($entry['targets']) > 1,
        'candidates' => $entry['targets'],
    ];
}

/**
 * Translate a label rather than a code.
 *
 * Some pipeline values arrive as text, not as a code: the LOINC System column
 * carries "Ser/Plas", while the concept map is keyed on the sanitised code
 * "Ser_Plas". This resolves the label against the code system that owns it and
 * then translates the code, so the caller does the lookup once instead of
 * twice.
 *
 * Answers null for an unknown label, for an ambiguous one, and for a label the
 * code system knows but the concept map does not translate. Those three cases
 * are deliberately not distinguished: each of them means the same thing to a
 * caller, namely that no target code can be filed.
 *
 * @param string|null $term the label to look up, trimmed by the callee
 * @param string      $cs   code system name, the source side of the map
 * @param string      $cm   concept map name
 * @return array<string, mixed>|null the same structure as map_concept()
 */
function map_term(string|int|null $term, string $cs, string $cm): ?array
{
    $code = codesystem_code_for_term($cs, $term);

    return $code === null ? null : map_concept($code, $cm);
}
