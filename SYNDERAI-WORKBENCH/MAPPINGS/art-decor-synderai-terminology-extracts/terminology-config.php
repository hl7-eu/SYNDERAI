<?php
/**
 * Configuration for fetch-terminology.php
 *
 * Every value here can be overridden on the command line. Run the script with
 * -h for the list of options.
 */

declare(strict_types=1);

return [

    // --- API ------------------------------------------------------------

    // Endpoint shape:
    //   <base>/<type>/<oid>[/<effectiveDate>]/$extract?language=&format=&project=
    // Without <effectiveDate> the server does not answer with the newest
    // version alone: it returns every version of the artefact in one document.
    // fetch-terminology.php picks the one with the highest effectiveDate.
    'base'     => 'https://art-decor.org/exist/apps/api',
    'project'  => 'synderai-',
    'language' => 'en-US',

    // 'xml' returns DECOR XML, 'json' the JSON serialisation.
    'format'   => 'xml',

    // --- Output ---------------------------------------------------------

    'outdir' => '.',

    // Subdirectory per artefact type, relative to outdir. Set an entry to ''
    // to write that type straight into outdir.
    'directories' => [
        'valueset'   => 'valueset',
        'codesystem' => 'codesystem',
        'conceptmap' => 'conceptmap',
    ],

    // The API wraps every artefact in a collection element, and for value sets
    // and code systems additionally in a <project> element:
    //   <valueSets><project ident="synderai-"><valueSet .../></project></valueSets>
    //   <codeSystems><project ident="synderai-"><codeSystem .../></project></codeSystems>
    //   <conceptMaps><conceptMap .../></conceptMaps>
    // With unwrap enabled the artefact element itself becomes the document
    // root. XML only, the JSON serialisation carries no wrapper.
    'unwrap' => true,

    // Element names that count as an artefact root when unwrapping.
    'rootElements' => ['valueSet', 'codeSystem', 'conceptMap'],

    // Attributes the API adds to the artefact element that do not belong to
    // the DECOR artefact itself. Listed here so they can be dropped on unwrap.
    // Leave empty to keep everything the server sent.
    'stripAttributes' => [],

    // --- Transfer -------------------------------------------------------

    'connectTimeout' => 15,
    'timeout'        => 600,
    'retries'        => 3,
    'retryDelay'     => 2,

    // --- Artefacts ------------------------------------------------------

    // type: valueset | codesystem | conceptmap
    // oid:  the artefact's DECOR id
    // effectiveDate: 'dynamic' for the newest version, or a concrete
    //       effectiveDate such as 2026-09-09T22:00:00 to pin the artefact to
    //       that version. An empty value is read as dynamic, anything else is
    //       rejected before the request goes out.
    // name: file stem. For value sets and code systems this is the artefact's
    //       name attribute. Concept maps carry no name in DECOR, so the stem is
    //       derived from displayName.
        'artefacts' => [
        ['type' => 'valueset',   'oid' => '2.16.840.1.113883.3.1937.777.100.11.4', 'effectiveDate' => 'dynamic', 'name' => 'vs-alerts-snomed'],
        ['type' => 'valueset',   'oid' => '2.16.840.1.113883.3.1937.777.100.11.5', 'effectiveDate' => 'dynamic', 'name' => 'vs-procedures-snomed-extensional'],
        ['type' => 'valueset',   'oid' => '2.16.840.1.113883.3.1937.777.100.11.6', 'effectiveDate' => 'dynamic', 'name' => 'vs-ucum-concepts'],
        ['type' => 'valueset',   'oid' => '2.16.840.1.113883.3.1937.777.100.11.17','effectiveDate' => 'dynamic', 'name' => 'vs-synthetic-immunization-site'],
        ['type' => 'valueset',   'oid' => '2.16.840.1.113883.3.1937.777.100.11.18','effectiveDate' => 'dynamic', 'name' => 'vs-synthea-observation-value-snomed'],
        ['type' => 'codesystem', 'oid' => '2.16.840.1.113883.3.1937.777.100.5.1',  'effectiveDate' => 'dynamic', 'name' => 'cs-specimen-label'],
        ['type' => 'codesystem', 'oid' => '2.16.840.1.113883.3.1937.777.100.5.2',  'effectiveDate' => 'dynamic', 'name' => 'cs-icpc-1-nl-nhg-tabel-24'],
        ['type' => 'codesystem', 'oid' => '2.16.840.1.113883.3.1937.777.100.5.3',  'effectiveDate' => 'dynamic', 'name' => 'cs-synthea-laboratory-category'],
        ['type' => 'conceptmap', 'oid' => '2.16.840.1.113883.3.1937.777.100.24.1', 'effectiveDate' => 'dynamic', 'name' => 'cm-snomed-to-icpc1nl'],
        ['type' => 'conceptmap', 'oid' => '2.16.840.1.113883.3.1937.777.100.24.2', 'effectiveDate' => 'dynamic', 'name' => 'cm-specimen-to-snomed-ct'],
        ['type' => 'conceptmap', 'oid' => '2.16.840.1.113883.3.1937.777.100.24.3', 'effectiveDate' => 'dynamic', 'name' => 'cm-cvx-to-snomed-vaccine-products'],
        ['type' => 'conceptmap', 'oid' => '2.16.840.1.113883.3.1937.777.100.24.4', 'effectiveDate' => 'dynamic', 'name' => 'cm-rxnorm-snomed-ct'],
        ['type' => 'conceptmap', 'oid' => '2.16.840.1.113883.3.1937.777.100.24.5', 'effectiveDate' => 'dynamic', 'name' => 'cm-medication-to-route-of-administration'],
        ['type' => 'conceptmap', 'oid' => '2.16.840.1.113883.3.1937.777.100.24.6', 'effectiveDate' => 'dynamic', 'name' => 'cm-snomed-vaccine-products-to-atc'],
        ['type' => 'conceptmap', 'oid' => '2.16.840.1.113883.3.1937.777.100.24.7', 'effectiveDate' => 'dynamic', 'name' => 'cm-vaccine-products-to-route-of-administration'],
        ['type' => 'conceptmap', 'oid' => '2.16.840.1.113883.3.1937.777.100.24.8', 'effectiveDate' => 'dynamic', 'name' => 'cm-vaccine-products-to-administration-site'],
        ['type' => 'conceptmap', 'oid' => '2.16.840.1.113883.3.1937.777.100.24.9', 'effectiveDate' => 'dynamic', 'name' => 'cm-laboratory-category-to-loinc']
    ],
];
