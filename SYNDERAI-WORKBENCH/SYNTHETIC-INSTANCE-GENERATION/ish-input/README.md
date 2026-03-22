# Instance Short Hand (ISH)

by dr K. Heitmann

## Introduction

**Instance Short Hand (ISH)** is a lightweight, indentation-based notation designed to describe healthcare data instances in a human-friendly yet machine-parsable form. Its purpose is to bridge the gap between highly structured healthcare standards (such as HL7 FHIR or CDA) and the practical need to **prototype, test, and exchange clinical scenarios** in a concise text format.

ISH emphasizes:

- **Readability for domain experts**
  Clinical staff, researchers, and interoperability specialists can read and write ISH without deep technical knowledge of XML, JSON, or RDF. Its indentation and keyword style makes clinical content easy to follow.
- **Structured mapping to standards**
  Every ISH file can be parsed into a structured object tree. Codes expressed in `$system#code Display text` format are automatically split into machine-processable identifiers (`code`, `system`) and human-friendly labels (`display`). This allows direct mapping to FHIR elements, LOINC/SNOMED codes, or other controlled vocabularies.
- **Repeatability and hierarchy**
  By using the `*` suffix, ISH naturally supports repeatable elements (multiple diagnoses, entries, components) and nested hierarchies (sections, encounters, providers). This mirrors the way healthcare data is structured in formal standards while staying compact.
- **Rapid prototyping & synthetic data**
  ISH is particularly suited for projects that need **synthetic test data** or **clinical vignettes** for interoperability testing, training, or evaluation of algorithms. It allows quick authoring of narratives and coded data, while the parser guarantees consistent structure.

In short, ISH provides a **middle ground**:

- as simple to write as free-form notes,
- as structured as the healthcare standards it targets.

It is not meant to replace formal exchange formats but to **accelerate creation, exploration, and validation** of healthcare scenarios in research, education, and standards development contexts.

1) Blocks & nesting (indentation-based)

- A **block header** is a single word on its own line, e.g. `patient`, `encounter`, `section*`, `entry*`, `component*`.
- **Indentation defines parent/child**. A line belongs to the most recent header above it that has **less** indentation.
- You can nest arbitrarily (e.g., `section*` → `entry*` → `component*`).

**Storage**

- Header names ending in `*` are **repeatable**. They’re stored **without the star** as an **array of objects** under the parent.
  - Example: `section* …` ⇒ `section: [ {...}, {...} ]`
- Headers **without** `*` are **single objects** (last one wins if repeated at the same level).

## 2) Properties (key/value lines)

- An indented line with: **first word = key**, **the rest = value**.
  Example: `code $loinc#67852-4 Hospital Admission evaluation note`

- Property keys ending in `*` are **repeatable scalar values**. They’re stored **without the star** as an **array**.

  - Example:

    ```
    reason* $sct#714628002 Prediabetes (finding)
    reason* $sct#171183004 Diabetes mellitus screening (procedure)
    ```

    ⇒

    ```
    "reason" => [
      [
        "code"=>"714628002",
        "system"=>"$sct",
        "display"=>"Prediabetes (finding)"
      ],
      [
        "part1"=>"171183004",
        "system"=>"$sct",
        "part2"=>"Diabetes mellitus screening (procedure)"
      ],
    ]
    ```

## 3) Value splitting into code, system and display

Before storing **any** key/value:

- If the **value contains both `$` and `#`**, it’s split at the **first space**:
  - `code` = token after the first # (typically `<system>#<code>`)
  - `system` = token before the first # (typically `<system>#<code>`)
  - `display` = the remainder (typically the human display)
- If there is **no space**, `display` becomes an empty string.
- If the value does **not** contain both `$` and `#`, it’s stored as a plain string.

Examples from your snippet:

- `code $loinc#67852-4 Hospital Admission evaluation note` ⇒

  ```
  "code" => [
    "code"=>"67852-4",
    "system"=>"$loinc",
    "display"=>"Hospital Admission evaluation note"
   ]
  ```

- `relationship $v3-RoleCode#MTH mother` ⇒

  ```
  "relationship" => [
    "code"=>"MTH",
    "system"=>"$v3-RoleCode",
    "display"=>"mother"
   ]
  ```

This splitting applies equally to:

- single properties (`code`, `service`, …),
- **repeatable** properties (`code*`, `reason*`, …),
- and `text` (if you really put `$` and `#` inside text).

## 4) Multiline text

- `text """ … """` captures **everything between the triple quotes**, including newlines.
- Single-line triple quotes (`text """Single line"""`) also work.
- After capture, the same `code/system/display` rule applies (usually irrelevant for free text).

## 5) Comments and blank lines

- Lines that do **not** match a header or a key/value line (e.g., lines starting with `#` or empty lines) are **ignored** by the parser.
  (Your inline unit markers like `109 #kg` are part of a **value**, not comments.)

------

# Quick walk-through with a snippet

### `patient` block (singleton)

```
"patient" => [
  "birthdate" => "1966-09-30",
  "given" => "Luigi",
  "family" => "De Luca",
  "localid" => "8121c77e7bf9",
  "gender" => "male",
  "postcode" => "38057",
  "city" => "Serso",
  "street" => "Via Zannoni 29",
  "country" => "IT",
  "phone" => "+39 0334 8920354",
  "nameset" => "it",
  "latitude" => "46.0591",
  "longitude" => "11.2637",
  "preselected" => "" // (no value lines beneath; just the key present)
]
```

### `encounter` (singleton with repeatable reasons/services)

```
"encounter" => [
  "start" => "2025-04-01T08:45:00Z",
  "end"   => "2025-04-10T11:00:00Z",
  "reason" => [
    [
      "code"=>"714628002",
      "system"=>"$sct",
      "display"=>"Prediabetes (finding)"
    ],
    [
      "part1"=>"171183004",
      "system"=>"$sct",
      "part2"=>"Diabetes mellitus screening (procedure)"
    ],
  ]
]
```

### `section*` (repeatable)

Each `section*` becomes one object inside `section: [ … ]`. Inside those:

- `entry*` is an array of entries,
- `component*` is an array of components,
- codes/relationships/etc. are split into `part1/part2`,
- `text """…"""` is captured as a single string.

Examples:

- **Family history section** (abridged):

```
[
  "type" => "familyhistory",
  "code" => [
    "code"=>"10157-6",
    "system" => "$loinc",
    "display" => "History of family member diseases Narrative"
   ],
  "title" => "Family History",
  "text"  => "Mr. Luigi has a family history of diabetes (type 2, mother and maternal grandmother).",
  "entry" => [..]
]
```

- **Vital signs (components)**: `valueQuantity 155 #mm[Hg]` stays a plain string (no `$`), while `code $loinc#8480-6 Systolic blood pressure` is split into parts.

- **Care plan**: `activity $sct#306118006 Referral to endocrinology service (procedure)` becomes

  ```
  "activity" => [
    "code"=>"306118006",
    "system" => "$sct",
    "display"=>"Referral to endocrinology service (procedure)"
   ]
  ```

- **Discharge diagnosis**: repeatable `code*` yields an **array** of `part1/part2` objects:

  ```
  "code" => [
  	[
  		"code"=>"E11",
    	"system" => "$icd10",
    	"display"=>"Type 2 diabetes mellitus"
    ],
    [
    	"code"=>"306118006",
    	"system" => "$sct",
    	"display"=>"Diabetes mellitus type 2 (disorder)"
    ]
  ]
  ```

------

# Authoring tips

- Use `*` on a **header** (e.g., `section*`, `entry*`, `component*`) when you want **arrays of objects**.
- Use `*` on a **property** (e.g., `reason*`, `code*`) when you want **arrays of scalars** (which the parser may convert into `code/system/display`).
- Put multi-line narrative into `text """…"""`.
- Write coded values as `$<system>#<code> <display>` to get automatic `code/system/display` splitting.
- Units like `109 #mg/dL` are fine—they remain simple strings (no `$`).

------

# Round-trip

You also have a reverse serializer:

- Arrays of objects → `block*`
- Arrays of scalars → `key* value`
- Multiline strings on `text` → triple quotes

That lets you **parse → modify → serialize** while keeping the ISH shape predictable.