I now have enough to map Laura's clinical story to the EPS data blocks. The EPS logical model (from the GitHub FSH source) defines an **A.1 Header** and **A.2 Body**, with specific sub-sections. Here's the structured mapping:

------

## EPS Data Block Mapping — Laura Mendes

### 🗂️ A.1 — Report Header

| EPS Field                        | Value                                                        |
| -------------------------------- | ------------------------------------------------------------ |
| **A.1.1 Patient identification** | Laura Mendes (synthetic), female, age 45                     |
| **A.1.4.1 Date created**         | (not specified in story — would be populated at generation time) |
| **A.1.4.2 Date of last update**  | (not specified)                                              |

------

### 🧬 A.2 — Patient Summary Body

#### A.2.1 — Alerts

**A.2.1.1 Allergy**

- **Substance:** Iodine contrast media
- **Reaction type:** Allergy (moderate reaction)
- **FHIR Resource:** `AllergyIntolerance`

*(No A.2.1.2 Medical alerts — other than the allergy above)*

------

#### A.2.2 — Medical History / Current Problems

**A.2.2.1 Active problems**

- Invasive ductal carcinoma of the left breast, ER+/HER2−, Stage IIb
- Neutropenia (neutrophils 0.9 ×10⁹/L — low)
- Mild anaemia (Hb 10.4 g/dL)
- Mild hepatic enzyme elevation (ALT mildly elevated)
- **FHIR Resource:** `Condition` (clinical status: active)

**A.2.2.2 Resolved/inactive problems** — None documented

**A.2.2.3 Medical history / anamnesis** — Not provided beyond current diagnosis

------

#### A.2.3 — Medications

**A.2.3.1 Current medications**

| Medication                   | Category                                  | Note                                   |
| ---------------------------- | ----------------------------------------- | -------------------------------------- |
| Docetaxel + Cyclophosphamide | Chemotherapy                              | Cycle 2/6 completed                    |
| Ondansetron                  | Supportive (antiemetic)                   |                                        |
| Dexamethasone                | Supportive (anti-inflammatory/antiemetic) |                                        |
| Pantoprazole                 | Supportive (gastroprotection)             |                                        |
| Filgrastim                   | Supportive (G-CSF)                        | Occasional — based on neutrophil count |

- **FHIR Resource:** `MedicationStatement`

------

#### A.2.4 — Immunizations

*Not documented in this clinical story.*

------

#### A.2.5 — Medical Devices & Implants

*Not documented.*

------

#### A.2.6 — Procedures / Surgical History

*Not explicitly documented, though active chemotherapy implies an ongoing treatment procedure.*

- Could capture: Chemotherapy administration (Cycle 2/6)
- **FHIR Resource:** `Procedure`

------

#### A.2.7 — Diagnostic Results (Laboratory)

| Result           | Value          | Flag  |
| ---------------- | -------------- | ----- |
| Neutrophils      | 0.9 ×10⁹/L     | ⚠️ Low |
| WBC              | 3.2 ×10⁹/L     |       |
| Haemoglobin (Hb) | 10.4 g/dL      |       |
| ALT              | Mild elevation |       |
| Creatinine       | Normal         |       |

- **Source:** Provider A
- **FHIR Resource:** `Observation` / `DiagnosticReport`

------

### Summary of FHIR Resources Used

| EPS Block                 | FHIR Resource                     |
| ------------------------- | --------------------------------- |
| A.1.1 Patient             | `Patient`                         |
| A.2.1.1 Allergy           | `AllergyIntolerance`              |
| A.2.2.1 Active conditions | `Condition`                       |
| A.2.3.1 Medications       | `MedicationStatement`             |
| A.2.6 Procedures          | `Procedure`                       |
| A.2.7 Lab results         | `Observation`, `DiagnosticReport` |
| Document wrapper          | `Composition` + `Bundle`          |

------

A few observations worth noting:

- The **G-CSF (Filgrastim)** sits across two blocks: it's a current medication (A.2.3) but also contextually linked to the alert-level lab result (A.2.7 — neutropenia).
- The **mild ALT elevation** should be flagged as a potential chemotherapy adverse effect and could also feature as an active problem in A.2.2.1.
- The **iodine allergy** is the only mandatory alert-level item and is critical for any future imaging decisions.

The file covers all EPS data blocks derived from the clinical story:

- **Patient** (`patient-laura-mendes`) — demographics, A.1.1
- **AllergyIntolerance** — iodine contrast, moderate, A.2.1.1
- **Conditions** (×3) — breast cancer staging, neutropenia, mild anaemia, A.2.2.1
- **MedicationStatements** (×6) — full TC regimen + all supportive drugs including PRN Filgrastim, A.2.3
- **Procedure** — ongoing TC chemotherapy, cycle 2/6, A.2.6
- **Observations** (×5) — all five lab values from Provider A with LOINC codes, A.2.7
- **Composition** — the EPS document header tying all sections together, A.1.4

A few design choices worth noting:

- **`encounter`** has no `end` date since Laura is mid-treatment, unlike Luca's completed admission
- **`problems` section** uses `bodySite` and `stage` sub-fields on the primary condition, which Luca's simpler conditions didn't need — these extend the pattern naturally
- **Filgrastim** is included as a full medication entry with a `PRN` note in its text, since ISH has no native `asNeeded` field visible in Luca's format
- **ALT and creatinine** use `valueCodeableConcept` rather than `valueQuantity` since only qualitative descriptions were available in the source data