# README.md — SYNDERAI TWIG Template Reference

## Table of Contents

1. [Introduction](#introduction)
2. [Output Structure — the Three Sections](#output-structure--the-three-sections)
3. [About Twig](#about-twig)
4. [Calling a Template from PHP](#calling-a-template-from-php)
5. [SYNDERAI Extensions and Global Variables](#synderai-extensions-and-global-variables)
   - [The HTML Bag — body row functions](#the-html-bag--body-row-functions)
   - [The HEAD Bag — header row functions](#the-head-bag--header-row-functions)
   - [Helper and Policy functions](#helper-and-policy-functions)
   - [Global constants](#global-constants)
6. [FSH Constructions](#fsh-constructions)
   - [Comments — Twig vs FSH](#comments--twig-vs-fsh)
   - [Single and multiple instances per template](#single-and-multiple-instances-per-template)
   - [Setting the Instance name](#setting-the-instance-name)
   - [Static templates with no Twig expressions](#static-templates-with-no-twig-expressions)
   - [Constant FSH instructions](#constant-fsh-instructions)
   - [Outputting variables](#outputting-variables)
   - [Local variables](#local-variables)
   - [Conditional instructions](#conditional-instructions)
   - [Loop instructions](#loop-instructions)
   - [FSH slice indexing](#fsh-slice-indexing)
   - [FHIR extensions](#fhir-extensions)
   - [FHIR narrative patterns (text.div)](#fhir-narrative-patterns-textdiv)
7. [HTML Constructions](#html-constructions)
   - [Building table head rows](#building-table-head-rows)
   - [Building table body rows](#building-table-body-rows)
   - [Emitting the bags into the output sections](#emitting-the-bags-into-the-output-sections)
   - [CSS table classes](#css-table-classes)
   - [Inline HTML in the FSH section](#inline-html-in-the-fsh-section)
8. [Twig Filters used in this Project](#twig-filters-used-in-this-project)
9. [Whitespace Control](#whitespace-control)
10. [Using Template Includes](#using-template-includes)
11. [Complete Example 1 — Device Use Statement](#complete-example-1--device-use-statement)
12. [Complete Example 2 — Patient (EU Core)](#complete-example-2--patient-eu-core)

---

## Introduction

This directory contains all Twig template files used by the SYNDERAI pipeline to generate:

- **FSH instances** — FHIR Shorthand source that is later compiled by SUSHI into FHIR R4 resources.
- **HTML table body rows** — assembled into the `Patient.text.div` narrative and into standalone HTML fragments for report viewers.
- **HTML table head rows** — optional column header rows that accompany the body rows above.

Each template produces exactly **three tagged sections** in its output (see next section). The SYNDERAI PHP script splits those sections and passes them to subsequent processing steps (SUSHI compilation, HTML rendering, etc.).

---

## Output Structure — the Three Sections

Every template must contain the three section-marker lines below, in this order. Each marker must appear at the very beginning of its line and is stripped from the final output by the SYNDERAI post-processor. The content of a section may be empty, but the marker itself is always required.

```
%%FSH%%    ← mandatory — FSH instance definition follows
%%HEAD%%   ← mandatory — HTML table <thead> rows follow (may be empty)
%%HTML%%   ← mandatory — standalone HTML table <tbody> rows follow (may be empty)
```

### %%FSH%%

Contains the complete FSH instance, from the `Instance:` keyword down to and including `text.div`. This is the primary output; the other two sections are supplementary.

### %%HEAD%%

Contains the `<tr><th>…</th></tr>` header rows for the HTML table, produced by the HEAD-bag functions and emitted with `emitHEAD() | raw`. Leave empty with a comment when no header is needed.

### %%HTML%%

Contains the `<tr><td>…</td></tr>` body rows for the HTML table — the same content that is also embedded inside `text.div` in the FSH section. Produced by the HTML-bag functions and emitted with `emitHTML() | raw`.

> **Why is `emitHTML()` called twice?**
> The HTML body rows are needed in two places: once *inside* the FSH `text.div` (to satisfy FHIR's human-readability rule) and once *in the `%%HTML%%` section* for external renderers that consume only the HTML fragment. Both calls return the same accumulated content.

---

## About Twig

Twig is a modern template engine for PHP. This project uses **Twig v3**.

- **Fast** — Twig compiles templates to optimised plain PHP code.
- **Secure** — Twig's sandbox mode restricts what untrusted templates can do.
- **Flexible** — A programmable lexer/parser allows custom tags, filters, and functions, several of which are defined for this project (see [SYNDERAI Extensions](#synderai-extensions-and-global-variables)).

Only a limited subset of the Twig language is used here. For the full Twig v3 reference see [https://twig.symfony.com/doc/3.x/](https://twig.symfony.com/doc/3.x/).

---

## Calling a Template from PHP

Templates are invoked through the `twigit()` helper function. A typical call looks like:

```php
list($fsh, $html, $head, $instanceDE, $instanceDU) =
    twigit(
        [
            "deinstanceid" => $deinstance,
            "duinstanceid" => $duinstance,
            "patient"      => $pdat,
            "device"       => $sdata,
        ],
        "device-use-eps"   // template name (without .twig extension)
    );
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$variables` | `array` | Associative array of variable names → values passed into the Twig context. |
| `$templateName` | `string` | Name of the `.twig` file (without extension) in the global template directory. |

**Return value** — `list()` destructuring in declaration order:

| Position | Variable | Contains |
|----------|----------|----------|
| 0 | `$fsh` | The rendered `%%FSH%%` section (FSH instance text). |
| 1 | `$html` | The rendered `%%HTML%%` section (HTML body rows). |
| 2 | `$head` | The rendered `%%HEAD%%` section (HTML header rows). |
| 3 … n | `$instance…` | All instance names registered by `setInstance()` calls during rendering, in the order they were called. |

---

## SYNDERAI Extensions and Global Variables

The following functions and constants are registered as Twig extensions by the SYNDERAI environment. They are available in every template without any `import` or `use` statement.

### The HTML Bag — body row functions

The *HTML bag* is an internal string buffer. The functions below append markup to it; `emitHTML()` flushes and returns the whole buffer. The bag is **stateful within a single template render**: calls accumulate in the order they appear in the template.

| Function call | Description |
|---------------|-------------|
| `addHTML_tr()` | Appends an opening `<tr>` tag to the HTML bag. Call once at the start of each logical row, before any `addHTML_td*` calls. |
| `addHTML_trend()` | Appends a closing `</tr>` tag to the HTML bag. Call once after all cells for the current row have been added. Typically used with the trailing whitespace-trim operator: `{{ addHTML_trend() -}}`. |
| `addHTML_td(text)` | Appends `<td>text</td>` to the HTML bag. Standard cell with normal styling. |
| `addHTML_tdgray(text)` | Appends `<td>text</td>` to the HTML bag with grayed-out text styling, used for secondary or less prominent data. |
| `addHTML_tdnb(text)` | Appends `<td>text</td>` to the HTML bag where *text* is rendered as a non-breaking gray pill. Used for compact coded values such as formatted dates. |
| `emitHTML()` | Returns the entire accumulated HTML bag as a string and **leaves the buffer intact** so it can be emitted again in the `%%HTML%%` section. Always used with the `\| raw` filter to prevent HTML-escaping. |

**Typical row pattern:**

```twig
{{ addHTML_tr() }}
{{ addHTML_td(patient.name) }}
{{ addHTML_tdnb(patient.birthdate|date('d-M-Y')) }}
{{ addHTML_trend() -}}
```

### The HEAD Bag — header row functions

The *HEAD bag* works identically to the HTML bag, but accumulates `<th>` cells that form the column header row of the table. Emit it in the `%%HEAD%%` section.

| Function call | Description |
|---------------|-------------|
| `addHEAD_tr()` | Appends an opening `<tr>` tag to the HEAD bag. |
| `addHEAD_trend()` | Appends a closing `</tr>` tag to the HEAD bag. |
| `addHEAD_th(text)` | Appends `<th>text</th>` to the HEAD bag. |
| `emitHEAD()` | Returns the entire accumulated HEAD bag as a string. Always used with the `\| raw` filter. |

**Typical header pattern:**

```twig
{{ addHEAD_tr() }}
{{ addHEAD_th("Name") }}
{{ addHEAD_th("Gender") }}
{{ addHEAD_th("Date of Birth") }}
{{ addHEAD_trend() -}}
```

> **Column alignment:** The number and order of `addHEAD_th()` calls must match the number and order of `addHTML_td*()` calls per row, so that header and data columns correspond correctly.

### Helper and Policy functions

| Function call | Description |
|---------------|-------------|
| `setInstance(name)` | Registers `name` as a tracked FSH instance identifier and returns it for inline output. The returned string is also collected into the `twigit()` return array in call order. May be called zero, one, or multiple times — see [Single and multiple instances per template](#single-and-multiple-instances-per-template). |
| `getUUID()` | Generates and returns a fresh RFC 4122 UUID v4 string at render time. Used where an identifier value is required but no pre-assigned id exists (e.g. practitioner identifiers). The UUID is ephemeral — not tracked across renders. |
| `syntheticDataPolicyMeta()` | Returns a block of FSH `* meta.tag` statements that mark the resource as synthetically generated, in accordance with the SYNDERAI data-governance policy. Output is raw FSH; always apply the `\| raw` filter. Must be placed directly after the `Usage:` line in the FSH instance header. |

**`getUUID()` usage:**

```twig
{# Generate a transient UUID for a practitioner identifier not tracked by the pipeline #}
* identifier.system           = "urn:oid:{{ HL7EUROPEEXAMPLESOID }}"
{% set practitioneridentifier = getUUID() %}
* identifier.value            = "{{ practitioneridentifier }}"
* identifier.assigner.display = "HL7 Europe"
```

**`syntheticDataPolicyMeta()` usage:**

```twig
Usage: #inline

{# Inject synthetic-data governance tags #}
{{ syntheticDataPolicyMeta() | raw }}

* id = "{{ patient.instanceid }}"
```

### Global constants

| Constant | Type | Description |
|----------|------|-------------|
| `HL7EUROPEEXAMPLESOID` | `string` | The OID root used for HL7 Europe example resources. Available in all templates without declaration. Used when constructing OID-based system URIs, e.g. `{{ HL7EUROPEEXAMPLESOID }}.{{ patient.instanceid }}`. |

---

## FSH Constructions

### Comments — Twig vs FSH

Two distinct comment syntaxes are used in these templates and they behave very differently:

**Twig comments** — `{# … #}` — are stripped by the Twig engine before the output reaches SUSHI. They never appear in the generated FSH.

```twig
{# This comment disappears from output entirely #}
* status = #active {# inline annotation — also stripped #}
```

**FSH line comments** — `// …` — are passed through by Twig (they are plain text) and are seen by SUSHI. Use them when you want the comment to appear in the compiled FSH output or when authoring a static template that has no Twig expressions.

```
// This comment survives into the FSH output and SUSHI sees it
* status = #active
```

Use Twig `{# #}` for all template logic, labelling, and HTML cell markers. Use FSH `//` only in static or near-static templates (see [Static templates](#static-templates-with-no-twig-expressions)).

> **Warning — non-nestable Twig comments:** Twig comments cannot be nested. The first `#}` encountered closes the open comment, regardless of any `{#` inside it. This means that a large block comment containing an inner `{# … #}` tag will be split into two separate comment segments at the inner tag's `#}`. This is exploited in some templates to comment out entire FSH instance blocks; see `provider-as-requester-eu-lab_fsh.twig` for an example.

### Single and multiple instances per template

The `%%FSH%%` section is not limited to a single `Instance:` block. A template may define zero, one, or several FSH instances, each beginning with its own `Instance:` keyword line.

**Pattern A — single instance with `setInstance()` (most common):**

```twig
Instance: {{ setInstance("Instance-Patient-" ~ patient.instanceid) }}
InstanceOf: PatientEuCore
...
```

`setInstance()` registers the name in the `twigit()` return array so the PHP caller can reference it.

**Pattern B — multiple instances, all registered:**

Used when two tightly related resources must be generated together (e.g. ResearchStudy + ResearchSubject). Call `setInstance()` once per instance; names are collected in call order.

```twig
Instance: {{ setInstance("Instance-ResearchStudy-" ~ researchstudyinstanceid) }}
InstanceOf: ResearchStudy
...

Instance: {{ setInstance("Instance-ResearchSubject-" ~ researchsubjectinstanceid) }}
InstanceOf: ResearchSubject
...
```

**Pattern C — multiple instances, some not registered:**

Used when secondary instances (e.g. Practitioner, Organization) are subordinate to a primary one. The primary is registered via `setInstance()`; the others use direct `{{ }}` interpolation.

```twig
{# Primary — registered #}
Instance: {{ setInstance("Instance-PractitionerRole-" ~ provider.instancerole) }}
InstanceOf: PractitionerRoleEuCore
...

{# Secondary — NOT registered; id interpolated directly #}
Instance: Instance-Organization-{{ provider.instanceroleorg }}
InstanceOf: OrganizationEuCore
...
```

**Pattern D — no `setInstance()` at all:**

Used when no instance names need to be returned to the PHP caller. All `Instance:` lines use direct interpolation. None of the instance names appear in the `twigit()` return array.

```twig
Instance: Instance-PractitionerRole-{{ hospital.instancerole }}
InstanceOf: PractitionerRoleEuCore
...
```

### Setting the Instance name

When using `setInstance()`, compose the name from a fixed resource-type prefix and a dynamic id using Twig's string concatenation operator `~`:

```twig
Instance: {{ setInstance("Instance-Patient-" ~ patient.instanceid) }}
```

### Static templates with no Twig expressions

Some templates contain no `{{ }}` expression tags and no `{% %}` block tags at all — the entire `%%FSH%%` section is plain FSH text that Twig passes through unchanged. These are used for governance fixtures and example bundles that are identical for every pipeline run.

```twig
%%FSH%% {# tag required #}

// Static FSH — no Twig variables
Instance: Patient-example-synth
InstanceOf: Patient
* id = "example-synth"
* gender = #female
```

The section markers and Twig comments are still processed; everything else is literal.

### Constant FSH instructions

FSH lines that do not depend on any variable are written as plain text in the template:

```twig
InstanceOf: PatientEuCore
Title: "Patient (EU Core)"
Description: "Patient (Synthetic Data)"
Usage: #inline

* status = #active
* address[+].use = #home
* address[=].type = #physical
```

Inline Twig comments can annotate constant lines without affecting output:

```twig
* status = #active {# always active for synthetic patients #}
```

FSH also supports multi-line string values using triple double-quotes `"""`. This is used both in `text.div` and in longer `description` fields:

```twig
* description = """
This study aims on treating **Diabetes Type 2** patients using a new drug.
"""
```

### Outputting variables

Variable values are interpolated using double-curly-brace syntax `{{ expression }}`. The expression is replaced by its string value in the output.

```twig
* id = "{{ patient.instanceid }}"
* birthDate = "{{ patient.birthdate }}"
* subject = Reference(urn:uuid:{{ patient.instanceid }}) "{{ patient.name }}"
```

Object properties are accessed with dot notation: `patient.name`, `device.display`, etc.

### Local variables

Use `{% set varname = expression %}` to capture a computed value into a local variable for reuse, avoiding repetition and improving readability.

```twig
{# Resolve preferred display term once, then use it in both FSH and HTML #}
{% set dp = procedure.code.preferredTerm ?? procedure.code.display %}
* code.coding[0] = {{ procedure.code.system }}#{{ procedure.code.code }} "{{ dp }}"
{{ addHTML_td(dp) }}
```

Local variables are also used to extract values computed across loop iterations:

```twig
{% set cvalue = "" %}
{% for cmp in vital.component %}
{% set cvalue = cvalue ~ (cmp.value.value ?? cmp.valueQuantity.value) %}
{% if not loop.last %}{% set cvalue = cvalue ~ " / " %}{% endif %}
{% endfor %}
{{ addHTML_td(cvalue) }}
```

> **Note:** Twig's scoping rules mean that `{% set %}` inside a `{% for %}` block does not persist after the loop ends unless the variable was initialised *before* the loop (as shown above).

### Conditional instructions

Use `{% if %} … {% else %} … {% endif %}` to emit FSH rules only when a value is present. The `is not empty` test is the standard guard for optional fields — it returns `false` for `null`, empty strings, empty arrays, and zero.

```twig
{# Only emit the MR identifier slice when a local id exists #}
{% if patient.localid is not empty %}
* identifier[+].type = $v2-0203#MR
* identifier[=].system = "http://local.setting.eu/identifier"
* identifier[=].value = "{{ patient.localid }}"
{% endif %}
```

With an `else` branch for absent data:

```twig
{% if device.start is empty %}
* timing.extension.url = "http://hl7.org/fhir/StructureDefinition/data-absent-reason"
* timing.extension.valueCode = #unknown
{% else %}
* timingPeriod.start = "{{ device.start }}"
{% endif %}
```

The **null-coalescing operator `??`** is a compact alternative to `{% if %}` when choosing between two possible value paths. It returns the left operand if it is defined and non-null, otherwise the right operand:

```twig
{# Use vital.value.value if defined, otherwise fall back to vital.valueQuantity.value #}
* valueQuantity.value = {{ vital.value.value ?? vital.valueQuantity.value }}

{# Preferred term with display fallback #}
{% set label = procedure.code.preferredTerm ?? procedure.code.display %}
```

### Loop instructions

Use `{% for item in collection %} … {% endfor %}` to iterate over array variables and emit one FSH rule per element.

```twig
{# One given-name slice per entry in the given-names array #}
{% for g in patient.given %}
* name[=].given[+] = "{{ g }}"
{% endfor %}

{# One reasonCode slice per reason in the encounter #}
{% for r in encounter.reason %}
* reasonCode[+] = {{ r.system }}#{{ r.code }} "{{ r.display }}"
{% endfor %}
```

Inside a loop, Twig provides the special `loop` object with useful metadata:

| Variable | Description |
|----------|-------------|
| `loop.index` | Current iteration (1-based). |
| `loop.index0` | Current iteration (0-based). |
| `loop.first` | `true` on the first iteration. |
| `loop.last` | `true` on the last iteration. |
| `loop.length` | Total number of items. |

`loop.last` is particularly useful for suppressing a trailing separator:

```twig
{% set cvalue = "" %}
{% for cmp in vital.component %}
{% set cvalue = cvalue ~ cmp.value.value %}
{% if not loop.last %}{% set cvalue = cvalue ~ " / " %}{% endif %}
{% endfor %}
{# result for two components: "120 / 80" #}
```

### FSH slice indexing

FSH uses two special index operators to address repeating elements:

| Operator | Meaning |
|----------|---------|
| `[+]` | **Append** — open a new slice at the next available index. |
| `[=]` | **Current** — address the slice most recently opened by `[+]`. |
| `[0]`, `[1]` … | **Explicit** — address a specific, known index directly. |

`[+]`/`[=]` is preferred for dynamic slices (loops, conditional blocks) because the index is determined at compile time by SUSHI. Explicit `[0]` is appropriate when exactly one element is always expected:

```twig
{# Dynamic — index determined by slice count at compile time #}
* identifier[+].type   = $v2-0203#JHN
* identifier[=].system = "http://ec.europa.eu/identifier/eci"
* identifier[=].value  = "{{ patient.eci }}"

{# Explicit — always exactly one coding #}
* code.coding[0] = {{ procedure.code.system }}#{{ procedure.code.code }} "{{ procedure.code.display }}"
```

Inside a `{% for %}` loop, combining `[+]` with `[=]` naturally produces one complete slice per iteration:

```twig
{% for g in patient.given %}
* name[=].given[+] = "{{ g }}"
{% endfor %}
```

### FHIR extensions

Simple (single-value) extensions are expressed as two FSH rules — `url` and a typed `value*` property:

```twig
* extension[+].url = "http://hl7.org/fhir/StructureDefinition/data-absent-reason"
* extension[=].valueCode = #unknown
```

Complex extensions (with nested sub-extensions) require an additional level of indexing:

```twig
{# patient-nationality: complex extension with a "code" sub-extension #}
{% if patient.countrycode is not empty %}
* extension[+].url = "http://hl7.org/fhir/StructureDefinition/patient-nationality"
* extension[=].extension[+].url = "code"
* extension[=].extension[=].valueCodeableConcept = urn:iso:std:iso:3166#{{ patient.countrycode|upper }}
{% endif %}
```

### FHIR narrative patterns (text.div)

FHIR requires every resource to carry a human-readable narrative in `Resource.text.div` (§ 2.4.1). Four distinct patterns are used across the templates:

**Pattern 1 — Body rows only** (most clinical-data templates)

`emitHTML()` is flushed inside `text.div`; `emitHEAD()` is not. The inline narrative table has data rows but no column header row. The HEAD bag is emitted separately in `%%HEAD%%` for external consumers.

```twig
* text.status = #generated
* text.div = """
<div xmlns="http://www.w3.org/1999/xhtml">
<table class="hl7__ips">{{ emitHTML() | raw }}</table>
</div>
"""
```

**Pattern 2 — Full table with headers** (socialhistory, vitalsigns, specimen, research-study-and-subject)

Both bags are flushed inside `text.div`, producing a complete table with a header row in the inline narrative. Used when the narrative is the primary rendering surface.

```twig
* text.status = #generated
* text.div = """
<div xmlns="http://www.w3.org/1999/xhtml">
<table class="hl7__ips">{{ emitHEAD() | raw }}{{ emitHTML() | raw }}</table>
</div>
"""
```

**Pattern 3 — Inline literal HTML** (provider templates)

No bag functions are used. The `text.div` carries a hand-authored single-cell table with a brief identification string. Appropriate when the resource contains only one or two identifying pieces of data.

```twig
* text.status = #generated
* text.div = """
<div xmlns="http://www.w3.org/1999/xhtml">
<table class="hl7__ips"><tr><td>Provider Role Id {{ provider.instancerole }}</td></tr></table>
</div>
"""
```

**Pattern 4 — Intentionally empty narrative** (servicerequest-eu-lab)

Used when the resource carries no clinically meaningful human-readable content of its own. `text.status = #empty` is the correct FHIR status code for this case.

```twig
* text.status = #empty
* text.div = "<div xmlns=\"http://www.w3.org/1999/xhtml\">intentionally left empty / not meaningful</div>"
```

> `text.status = #generated` — narrative was derived from structured data; do not edit manually.  
> `text.status = #empty` — resource deliberately has no narrative; the element is present only to satisfy the FHIR cardinality requirement.

---

## HTML Constructions

### Building table head rows

Call the HEAD-bag functions in the `%%FSH%%` section before any body-row calls, so that the column order is established early and clearly associated with the data below.

```twig
{# Column headers for the HTML table #}
{{ addHEAD_tr() }}
{{ addHEAD_th("Name") }}
{{ addHEAD_th("Gender") }}
{{ addHEAD_th("Date of Birth") }}
{{ addHEAD_trend() -}}
```

### Building table body rows

Open a row with `addHTML_tr()`, add one cell per column with the appropriate `addHTML_td*()` variant, then close with `addHTML_trend()`. Body-row calls may be interleaved with FSH rules so that related FSH and HTML output remain co-located in the template:

```twig
{{ addHTML_tr() }}

{# *** HTML td 1: name #}
* name[+].family = "{{ patient.family }}"
* name[=].text   = "{{ patient.name }}"
{{ addHTML_td(patient.name) }}

{# *** HTML td 2: gender #}
* gender = #{{ patient.gender }}
{{ addHTML_td(patient.gender) }}

{# *** HTML td 3: birthdate #}
* birthDate = "{{ patient.birthdate }}"
{{ addHTML_tdnb(patient.birthdate|date('d-M-Y')) }}

{{ addHTML_trend() -}}
```

**Cell variant selection guide:**

| Variant | Use when |
|---------|----------|
| `addHTML_td(text)` | Standard textual data — names, identifiers, free text. |
| `addHTML_tdgray(text)` | Secondary or supplementary data that should be visually de-emphasised. |
| `addHTML_tdnb(text)` | Compact coded values — dates, codes, short status strings — rendered as a non-breaking pill badge. |

### Emitting the bags into the output sections

At the end of the template, emit both bags in their respective sections:

```twig
%%HEAD%% {# tag required, content below maybe empty #}
{{ emitHEAD() | raw }}

%%HTML%% {# tag required, content below maybe empty #}
{{ emitHTML() | raw }}
```

When a section has no content (e.g. a template that does not produce a header row), leave a visible comment so the intent is clear:

```twig
%%HEAD%% {# tag required, content below maybe empty #}
<!-- empty -->
```

### CSS table classes

The `<table>` element inside `text.div` uses a CSS class that determines column widths, typography, and colour scheme in the vi7eti / SYNDERAI viewer. The class is chosen based on the clinical context:

| Class | Context | Used by |
|-------|---------|---------|
| `hl7__ips` | IPS / EPS resources — general clinical data | Patient, Procedure, Observation, DeviceUse, etc. |
| `hl7__eu__lab__report` | EU Laboratory Report resources | Specimen, DiagnosticReport lab context |
| `hl7__ips first25` | IPS context where the first column should be constrained to ~25% width | Research Study/Subject combined table |

```twig
{# Standard IPS table #}
<table class="hl7__ips">{{ emitHEAD() | raw }}{{ emitHTML() | raw }}</table>

{# EU Lab Report table #}
<table class="hl7__eu__lab__report">
{{ emitHEAD() | raw }}
{{ emitHTML() | raw }}
</table>

{# IPS table with narrowed first column #}
<table class="hl7__ips first25">{{ emitHEAD() | raw }} {{ emitHTML() | raw }}</table>
```

### Inline HTML in the FSH section

Plain HTML can also be written directly into the template body outside the bag functions. This is useful for wrapping structures or when rendering literal HTML that is not part of the row/cell pattern:

```twig
Hospital: {{ hospital.name }}, {{ hospital.postcode }} {{ hospital.city }}, {{ hospital.country }}
```

---

## Twig Filters used in this Project

Twig filters are applied to an expression with the pipe character: `{{ expression | filter }}`. Multiple filters may be chained.

| Filter | Example | Description |
|--------|---------|-------------|
| `raw` | `{{ emitHTML() \| raw }}` | Suppresses HTML-escaping of the value. **Required** whenever a function returns an HTML or FSH string that must not be entity-encoded. |
| `date(format)` | `{{ patient.birthdate \| date('d-M-Y') }}` | Formats a date/datetime value using PHP `date()` format codes. Common formats: `'d-M-Y'` → `14-Mar-1978` (HTML display); `'Y-m-d'` → `1978-03-14` (FHIR dateTime). |
| `upper` | `{{ patient.countrycode \| upper }}` | Converts a string to upper-case. Used to normalise ISO 3166-1 country codes regardless of input casing. |
| `first` | `{{ vital.code \| first }}` | Returns the first element of an array. Used when a field is an array of codings but only the first entry is needed. |
| `slice(start, length)` | `{{ condition.active \| slice(0, 1) }}` | Extracts a substring or sub-array. Used to extract a single status character from an encoded string (e.g. `"SYYYY-MM-DD"` → `"S"`). |
| `join(separator)` | `{{ names \| join(", ") }}` | Joins an array into a single string with the given separator. Used when a field (e.g. name prefix) may be supplied as an array that needs to appear as one string in a narrative. |

**Combining filters and functions:**

```twig
{# Date formatted for HTML display, emitted into a pill cell #}
{{ addHTML_tdnb(patient.birthdate|date('d-M-Y')) }}

{# Concatenated date range in a single cell #}
{{ addHTML_td(encounter.start|date('d-M-Y') ~ " – " ~ encounter.end|date('d-M-Y')) }}

{# ISO 3166-1 country code, normalised to upper-case #}
* extension[=].extension[=].valueCodeableConcept = urn:iso:std:iso:3166#{{ patient.countrycode|upper }}

{# First coding from an array of codings #}
{% set c = vital.code|first %}
* code = {{ c.system }}#{{ c.code }} "{{ c.display }}"

{# Active-condition flag extracted from encoded string "SYYYY-MM-DD" #}
{% set activeflag = condition.active|slice(0, 1) %}

{# Prefix array joined for narrative display #}
{{ hospital.practitioner.prefix|join(" ") ~ " " ~ hospital.practitioner.given|join(" ") }}
```

---

## Whitespace Control

By default, Twig preserves all whitespace around block tags and expression tags. This can produce unwanted blank lines in FSH output. Use the **trim operators** `{%-` / `-%}` and `{{-` / `-}}` to strip whitespace before or after a tag.

The most common case is trailing whitespace after `addHTML_trend()` and `addHEAD_trend()`, which would otherwise add a blank line between the last HTML row and the next FSH rule:

```twig
{{ addHTML_trend() -}}   {# trailing newline stripped #}

* text.status = #generated
```

Similarly for HEAD rows:

```twig
{{ addHEAD_trend() -}}
```

> Use whitespace control sparingly and only where spurious blank lines are observed in the output. Overuse can make templates harder to read.

---

## Using Template Includes

Twig provides several mechanisms for sharing markup across templates. The table below summarises the options relevant to this project.

| Directive | Use case |
|-----------|----------|
| `include` | Render a partial template inline — the simplest form of reuse. |
| `extends` + `block` | Template inheritance for shared page structure. |
| `embed` | Include a partial and override named blocks within it. |
| `macro` | Define a reusable inline snippet, similar to a function. |

### `include`

```twig
{# Render a partial inline, sharing all current variables #}
{% include 'partials/address.html.twig' %}

{# Pass specific variables only #}
{% include 'partials/patient-name.html.twig' with { 'patient': patient } %}

{# Pass specific variables and hide all others from the partial #}
{% include 'partials/patient-name.html.twig' with { 'patient': patient } only %}
```

### `extends` + `block`

```twig
{# base.fsh.twig #}
{% block instance_header %}{% endblock %}
{% block instance_body %}{% endblock %}

{# child.fsh.twig #}
{% extends 'base.fsh.twig' %}
{% block instance_header %}
Instance: {{ setInstance("Instance-Patient-" ~ patient.instanceid) }}
InstanceOf: PatientEuCore
{% endblock %}
```

### `embed`

Combines `include` with the ability to override named blocks within the included template:

```twig
{% embed 'partials/panel.html.twig' %}
  {% block title %}Patient Information{% endblock %}
  {% block body %}{{ patient.name }}{% endblock %}
{% endembed %}
```

### `macro`

Reusable inline snippets, similar to a function. Can be defined in the same file or imported from another:

```twig
{# Define #}
{% macro renderGivenNames(given) %}
  {% for g in given %}{{ g }} {% endfor %}
{% endmacro %}

{# Import from another file and use #}
{% import 'macros/patient.html.twig' as m %}
{{ m.renderGivenNames(patient.given) }}
```

---

## Complete Example 1 — Device Use Statement

Demonstrates: instance naming, HEAD/HTML bag usage, conditional FSH output, `addHTML_tdnb`, FHIR `Reference()` syntax, and `text.div` embedding.

```twig
{#
  Template: device-use-eps.twig
  Generates a FSH DeviceUseStatement instance (EU EPS profile).
  Variables: duinstanceid, deinstanceid, patient (object), device (object)
#}

%%FSH%% {# tag required #}
Instance: {{ setInstance("Instance-DeviceUse-" ~ duinstanceid) }}
InstanceOf: DeviceUseStatementEuEps
Title: "Device Use"
Description: "Device Use"
Usage: #inline

* id = "{{ duinstanceid }}"

{# Column headers for the HTML table #}
{{ addHEAD_tr() }}
{{ addHEAD_th("Device") }}
{{ addHEAD_th("Date (since)") }}
{{ addHEAD_trend() -}}

{{ addHTML_tr() }}

* status = #active {# always still active in our cases #}

{# *** HTML td 1: device display name #}
{{ addHTML_td(device.display) }}

{# *** HTML td 2: start date — absent-reason extension when unknown #}
{% if device.start is empty %}
* timing.extension.url = "http://hl7.org/fhir/StructureDefinition/data-absent-reason"
* timing.extension.valueCode = #unknown
{{ addHTML_td("?") }}
{% else %}
* timingPeriod.start = "{{ device.start }}"
{{ addHTML_tdnb(device.start|date('d-M-Y')) -}}
{% endif %}

* device  = Reference(urn:uuid:{{ deinstanceid }}) "{{ device.display }}"
* subject = Reference(urn:uuid:{{ patient.instanceid }}) "{{ patient.name }}"

{{ addHTML_trend() -}}

{# Embed FHIR narrative #}
* text.status = #generated
* text.div = """
<div xmlns="http://www.w3.org/1999/xhtml">
<table class="hl7__ips">{{ emitHTML() | raw }}</table>
</div>
"""

%%HEAD%% {# tag required, content below maybe empty #}
{{ emitHEAD() | raw }}

%%HTML%% {# tag required, content below maybe empty #}
{{ emitHTML() | raw }}
```

---

## Complete Example 2 — Patient (EU Core)

Demonstrates: multi-slice identifiers with conditional slices, looping over given names, conditional address lines, the `patient-nationality` complex extension, `syntheticDataPolicyMeta()`, `|upper` filter, and an empty `%%HEAD%%` section.

```twig
{#
  Template: patient-eu-core.fsh.twig
  Generates a FSH Patient instance conforming to PatientEuCore.
  Variables: patient (object) — keys documented in full below.

  patient object keys:
    instanceid   string    Unique resource id
    name         string    Full display name
    family       string    Family / surname
    given        string[]  Array of given names
    gender       string    FHIR AdministrativeGender code
    birthdate    date      Date of birth (FHIR-compatible string)
    eci          string    European Citizen Identifier
    match        string?   Optional external MR / match identifier
    localid      string?   Optional local MR identifier
    street1      string?   Address line 1 (optional)
    street2      string?   Address line 2 (optional)
    street3      string?   Address line 3 (optional)
    postcode     string    Postal code
    city         string    City name
    countryname  string    Full country name
    countrycode  string?   ISO 3166-1 alpha-2 code (optional, for nationality)
    phone        string    Phone number
#}

%%FSH%% {# tag required #}
Instance: {{ setInstance("Instance-Patient-" ~ patient.instanceid) }}
InstanceOf: PatientEuCore
Title: "Patient (EU Core)"
Description: "Patient {{ patient.name }} (Synthetic Data)"
Usage: #inline

{# Inject synthetic-data governance meta tags #}
{{ syntheticDataPolicyMeta() | raw }}

* id = "{{ patient.instanceid }}"

{{ addHTML_tr() }}

{# ── Identifiers ────────────────────────────────────────────── #}
{# Slice 1 (always): European Citizen Identifier, typed JHN #}
* identifier[+].type   = $v2-0203#JHN
* identifier[=].system = "http://ec.europa.eu/identifier/eci"
* identifier[=].value  = "{{ patient.eci }}"

{# Slice 2 (conditional): external match / cross-reference identifier #}
{% if patient.match is not empty %}
* identifier[+].type   = $v2-0203#MR
* identifier[=].system = "http://local.setting.eu/identifier"
* identifier[=].value  = "{{ patient.match }}"
{% endif %}

{# Slice 3 (conditional): local system identifier #}
{% if patient.localid is not empty %}
* identifier[+].type   = $v2-0203#MR
* identifier[=].system = "http://local.setting.eu/identifier"
* identifier[=].value  = "{{ patient.localid }}"
{% endif %}

{# ── Name ───────────────────────────────────────────────────── #}
{# *** HTML td 1: full display name #}
* name[+].family = "{{ patient.family }}"
{% for g in patient.given %}
* name[=].given[+] = "{{ g }}"
{% endfor %}
* name[=].text = "{{ patient.name }}"
{{ addHTML_td(patient.name) }}

{# ── Gender ─────────────────────────────────────────────────── #}
{# *** HTML td 2: administrative gender code #}
* gender = #{{ patient.gender }}
{{ addHTML_td(patient.gender) }}

{# ── Birth date ─────────────────────────────────────────────── #}
{# *** HTML td 3: date of birth — ISO 8601 in FSH, d-M-Y in HTML #}
* birthDate = "{{ patient.birthdate }}"
{{ addHTML_tdnb(patient.birthdate|date('d-M-Y')) }}

{# ── Address ────────────────────────────────────────────────── #}
{# Single home/physical address; street lines omitted when empty #}
* address[+].use  = #home
* address[=].type = #physical
{% if patient.street1 is not empty %}
* address[=].line[+] = "{{ patient.street1 }}"
{% endif %}
{% if patient.street2 is not empty %}
* address[=].line[+] = "{{ patient.street2 }}"
{% endif %}
{% if patient.street3 is not empty %}
* address[=].line[+] = "{{ patient.street3 }}"
{% endif %}
* address[=].postalCode = "{{ patient.postcode }}"
* address[=].city       = "{{ patient.city }}"
* address[=].country    = "{{ patient.countryname }}"

{# ── Telecom ─────────────────────────────────────────────────── #}
* telecom[+].system = #phone
* telecom[=].value  = "{{ patient.phone }}"

{# ── Nationality extension (conditional) ────────────────────── #}
{# Complex extension: parent = patient-nationality,             #}
{# child sub-extension "code" carries an ISO 3166-1 concept.   #}
{# |upper normalises country code to required upper-case form.  #}
{% if patient.countrycode is not empty %}
* extension[+].url = "http://hl7.org/fhir/StructureDefinition/patient-nationality"
* extension[=].extension[+].url = "code"
* extension[=].extension[=].valueCodeableConcept = urn:iso:std:iso:3166#{{ patient.countrycode|upper }}
{% endif %}

{{ addHTML_trend() -}}

{# ── FHIR narrative ──────────────────────────────────────────── #}
* text.status = #generated
* text.div = """
<div xmlns="http://www.w3.org/1999/xhtml">
<table class="hl7__ips">{{ emitHTML() | raw }}</table>
</div>
"""

%%HEAD%% {# tag required, content below maybe empty #}
<!-- empty -->

%%HTML%% {# tag required, content below maybe empty #}
{{ emitHTML() | raw }}
```
