# README.md

## Introduction

This directory contains all used TWIG template files for the creation of the FSH instances, the optional HTML parts (usually tables), and optionally a HTML table head row. The latter two are typicall yued in the `text.div` element of a resource.

The TWIG instructions have three major areas. It creates a **FSH part** beginning at the line starting with the 

```
%%FSH%%
```

tag on it. This tag is mandatory.

The **HTML part** is created by amalgamating the HTML strings created during the FSH conversion to the final one. There are special fucntion calls for the creation of **HTML table body rows**. Also a **HEAD table row** can be created. They are emited in the respective lines starting with the

```
%%HTML%%
```

tag and the 

```
%%HEAD%%
```

respectively. For instructions see *HTML Constructions* below.

The three tagged parts are post-processed by the SYNDERAI script and handled over to the subsequent processing.

## About TWIG

Twig is a modern template engine for PHP. We use v3 for the purposes descibed here.

- **Fast**: Twig *compiles* templates down to plain optimized PHP code. The overhead compared to regular PHP code was reduced to the very minimum.
- **Secure**: Twig has a *sandbox* mode to evaluate untrusted template code. This allows Twig to be used as a template language for applications where users may modify the template design.
- **Flexible**: Twig is powered by a flexible *lexer* and *parser*. This allows the developer to define its own custom tags and filters, and create its own DSL.

We use a limited set of instructions, expression and filters from the TWIG language and we do have a couple of extensions defined for the TWIG use in this context, see *TWIG Extensions and Variables*  below.

For a complete documentation of pure v3 TWIG see [here](https://twig.symfony.com/doc/3.x/). 

## TWIG Extensions and Variables

### HTML table body row adding and emiting functions

The following shorthand functions are defined to create HTML table rows. They are all added to the so-called HTML bag, a storage that can be emited later anywhere in the TWIG template. Together with the SYNDERAI and vi7eti stylesheets the expected outcome is also demostrated.

| Function call          | Definition                                                   |
| ---------------------- | ------------------------------------------------------------ |
| addHTML_tr()           | Adds a `<tr>` tag to the HTML bag.                           |
| addHTML_trend()        | Adds and table row end tag `</tr>` tag to the HTML bag.      |
| addHTML_td(*text*)     | Adds `<td>`*text*`</td>`  to the HTML bag.                   |
| addHTML_tdgray(*text*) | Adds `<td>`*text*`</td>`  to the HTML bag with a grayed-out text |
| addHTML_tdnb(*text*)   | Adds a `<tr>` tag to the HTML bag with a non-breaking gray pill with text |
| emitHTML()             | Emits the entire HTML bag, usually used with TWIG's `| raw` filter |

### HTML table head row adding and emiting functions

| Function call      | Definition                                                   |
| ------------------ | ------------------------------------------------------------ |
| addHEAD_tr()       | Adds a `<tr>` tag to the HEAD bag.                           |
| addHEAD_trend()    | Adds and table row end tag `</tr>` tag to the HEAD bag.      |
| addHEAD_th(*text*) | Adds `<th>`*text*`</th>`  to the HEAD bag.                   |
| emitHEAD()         | Emits the entire HEAD bag, usually used with TWIG's `| raw` filter |

### Helper functions

| Function call                | Definition                                                   |
| ---------------------------- | ------------------------------------------------------------ |
| setInstance(*instance-name*) | Sets the INSTANCE bag to *instance-name*, this is also part of the returned value |
| getUUID()                    | Emits a UUID                                                 |

### Global data for rendition

The constant `HL7EUROPEEXAMPLESOID` is available throughout any TWIG template.

## FSH Constructions

The purpose of the TWIG templates are to create FSH instances including the optional HTML parts using the TWIG language.

### Calling TWIG

The typical PHP call of a TWIG template is

```
list($tmpfsh, $tmphtml, $HEADdev, $instancede, $instancedu) =
  twigit(
    ["deinstanceid" => $deinstance,
     "duinstanceid" => $duinstance,
     "patient" => $pdat,
     "device" => $sdata],
    "device-use-eps"
  );
```

The twigit functions gets an array of variables with the instances and the name of the TWIG template, residing in the gobally defined TWIG template directory.

The twigit functions returns an array of the created content for FSH, the HTML part, the table HEAD row, and all set instances that have been declared through the FSH phase.

### Language elements used

#### Comments

```
{# now the device use statement #}
```

#### Setting Instance Names

```
Instance: {{ setInstance("Instance-DeviceUse-" ~ duinstanceid) }}
```

#### Constant instructions

Here are some examples of constant instructions (without the involvement of variables of other expressions).

```
InstanceOf: DeviceUseStatementEuEps
Title: "Device Use"
Description: "Device Use"
Usage: #inline

* status = #active {# always still active in our cases #}
```

#### Using Variables

This statement adds a FSH line where the expression between `{{ v }}` is replaced by the value of the variable `v`.

```
* id = "{{ duinstanceid }}"

* device = Reference(urn:uuid:{{ deinstanceid }}) "{{ device.display }}"

* subject = Reference(urn:uuid:{{ patient.instanceid }}) "{{ patient.name }}"
```

#### Conditional instructions

You can express conditions with the typical *if then else* instruction.

The following example checks the `device.start` variable existence and that the variable is populated with a value. If not set or empty the first part is emited/executed, otherwise the seconds part.

```
{% if device.start is empty %}
* timing.extension.url = "http://hl7.org/fhir/StructureDefinition/data-absent-reason"
* timing.extension.valueCode = #unknown
{% else %}
* timingPeriod.start = "{{ device.start }}"
{% endif %}
```

#### Loop instructions

You can express loops over array variables with the typical *for* instruction.

```
{# reason for encounter #}
{% for r in encounter.reason %}
* reasonCode[+] = {{ r.system }}#{{ r.code }} "{{ r.display }}"
{% endfor %}
```

#### Emiting HTML text in the FSH part

You can emit all so-far created HTML in appropriate FSH parts, like in `text.div`. using the `emitHTML()` instruction. Use TWIG's `|raw` filter to emit HTML unescaped.

```
{# populate .text #}
* text.status = #generated
* text.div = """
<div xmlns="http://www.w3.org/1999/xhtml">
<table class="hl7__ips">{{ emitHTML() | raw }}</table>
</div>
"""
```

## HTML Constructions

It creates the HTML part as well and includes that below after the |%%HTML%% tag

### Table head rows

```
{# addign headers for HTML table #}
{{ addHEAD_tr() }}
{{ addHEAD_th("Device") }}
{{ addHEAD_th("Date (since)") }}
{{ addHEAD_trend() -}}
```

### Table body rows

```
{{ addHTML_td(device.display) }}

{{ addHTML_tdnb(device.start|date('d-M-Y')) -}}
```

### Filter

```
{{ addHTML_td( encounter.start|date('d-m-Y') ~ " - " ~ encounter.end|date('d-m-Y') ) }}

{{ emitHEAD() | raw }}
```

### Usual HTML

```
Hospital: {{ hospital.name }}, {{ hospital.postcode }} {{ hospital.city }}, {{ hospital.country }}
```

### Emiting HTML and HEAD parts

```
%%HEAD%% {# tag required, content below maybe emtpy #}
{{ emitHEAD() | raw }}

%%HTML%% {# tag required, content below maybe emtpy #}
{{ emitHTML() | raw }}
```

## Complete Example

This is a complete example.

```
{# now the device use statement #}
Instance: {{ setInstance("Instance-DeviceUse-" ~ duinstanceid) }}
InstanceOf: DeviceUseStatementEuEps
Title: "Device Use"
Description: "Device Use"
Usage: #inline

* id = "{{ duinstanceid }}"

{# addign headers for HTML table #}
{{ addHEAD_tr() }}
{{ addHEAD_th("Device") }}
{{ addHEAD_th("Date (since)") }}
{{ addHEAD_trend() -}}

{{ addHTML_tr() }}

* status = #active {# always still active in our cases #}

{# *** HTML td 1: device #}
{{ addHTML_td(device.display) }}

{# *** HTML td 2: date since #}
{% if device.start is empty %}
* timing.extension.url = "http://hl7.org/fhir/StructureDefinition/data-absent-reason"
* timing.extension.valueCode = #unknown
{{ addHTML_td("?") }}
{% else %}
* timingPeriod.start = "{{ device.start }}"
{{ addHTML_tdnb(device.start|date('d-M-Y')) -}}
{% endif %}

* device = Reference(urn:uuid:{{ deinstanceid }}) "{{ device.display }}"

* subject = Reference(urn:uuid:{{ patient.instanceid }}) "{{ patient.name }}"

{{ addHTML_trend() -}}

{# populate .text #}
* text.status = #generated
* text.div = """
<div xmlns="http://www.w3.org/1999/xhtml">
<table class="hl7__ips">{{ emitHTML() | raw }}</table>
</div>
"""

%%HEAD%% {# tag required, content below maybe emtpy #}
{{ emitHEAD() | raw }}

%%HTML%% {# tag required, content below maybe emtpy #}
{{ emitHTML() | raw }}
```

