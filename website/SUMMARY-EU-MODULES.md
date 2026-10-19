# SYNDERAI — European module calibration: the record

**Synthetic Data: Examples – Realistic – using AI (SYNDERAI)**, pronounced **/ˈsɪn.də.raɪ/**

© [HL7 Europe](https://hl7europe.org) | Main Contributor: Dr. Kai U. Heitmann | [Privacy Policy](https://hl7europe.eu/privacy-policy-for-hl7-europe/) • AGPL-3.0 license

This is the standing record of how the Synthea module set was moved from US to
European epidemiology, what each step measured, and where the result still
misses. It replaces the earlier comparison document, which stopped at the first
calibration step, and it absorbs the analysis that used to sit under Planned
Future Activities. That page now carries only what is still ahead.

## Two numbering schemes, and how they relate

The published data packages and the module sets behind them are numbered
separately, and conflating them has caused confusion. The mapping:

| Package release | Module set behind it | When |
| --- | --- | --- |
| 1.0+, 2.0+ | stock Synthea, US calibration | from July 2025 |
| 3.0+ | **R3** — US module set replaced with European counterparts | May 2026 |
| 4.0+ | **R4** — measured recalibration | August 2026 |
| 5.0+ | **R4.1** — EPS provenance, discharge criterion, hypertension re-derivation | September 2026 |

There is no module set labelled R5: the module work behind the 5.0 packages is
what the action log calls R4.1. Aligning the two sequences, or stating this
mapping wherever a release is named, would remove the ambiguity.

Module changes made after the 5.0 packages were published — the troponin unit,
the arterial pH range and the distribution-width code split of 12 October 2026 —
are applied in the module tree but not yet generated. They will appear in the
next release.

## Read this first: every crude prevalence figure here understates age-dependent conditions

The measure used throughout R3 and R4 is **crude** prevalence over the generated
cohort, whose mean age is 41. The European references are adult-population
figures. On a cohort younger than the reference population, crude prevalence
systematically understates anything age-dependent, so a module can overshoot
badly and still read as on target.

This is not a caveat to be read at the end. It changes the verdict on 13 of 18
measured conditions, and it invalidates the target that both R3 and R4 were
optimising. The figures and the consequences are in [The
age-standardisation finding](#the-age-standardisation-finding) below. Every
table in this document states whether it is crude or standardised, and nothing
is compared across the two.

## Provenance of the figures

Nothing in this document is projected from module remarks. Where a figure is an
estimate rather than a measurement, it says so.

| Figure set | Source |
|---|---|
| R4 verification rounds 1–3 | ~20,800 patients each, Massachusetts geography, `Conditions-Prevalences/R4-VERIFICATION.md` |
| R4 production | 41,637 patients, 291 EU regions, 2026-08-29 |
| EPS 4.0.1 provenance | 250 bundles vs the 41,644-patient population of 2026-09-13, `Synthea/EPS-provenance/EPS-401-PROVENANCE.md` |
| Hypertension verification and Part 7 | 41,439 patients, 291 EU regions, 2026-09-30 |

The earlier version of this document closed with a table headed "Top 10 EU
Prevalences Achieved". Its figures were calibration targets taken from module
remarks, not measurements, and its own footnote said so. It has been removed
rather than corrected, because the measured tables below answer the same
question. The same table had already been removed from the action log for the
same reason.

------

# The releases

## R2 — the starting position

Analysing the conditions Synthea produced for the 1.0 and 2.0 packages showed a
distribution that does not resemble a European population. Administrative and
social findings dominated: medication review due, employment status, stress and
social isolation together accounted for roughly 57 % of all condition records,
while hypertension sat at about 1 %, type-2 diabetes at 0.24 % and depression at
0.001 %. Drug overdose appeared at 0.47 %, a US epidemiological signature.
Cancer, the second leading cause of death in Europe, barely registered.

Two things about the R2 assessment have to be said plainly, because both were
established later and both inflate it:

- **The percentages and the references were on different metric bases.** The
  Synthea column counted records, the reference column was population
  prevalence. For acute conditions the two differ by an order of magnitude,
  which is where the "+1,122 %" verdict on viral sinusitis came from.
- **Four rows were mis-coded in the validation table itself.** The codes queried
  were valid SNOMED concepts that do not mean what the row labels said, so those
  queries could only ever return zero. They are listed under [Three "MISSING"
  verdicts were table bugs](#three-missing-verdicts-were-table-bugs-not-data-gaps).

The direction of the R2 finding stands. Its magnitudes do not.

## R3 — replacing the US module set

February to May 2026. Method: read the module JSON and edit it against published
European references.

The EU calibration replaced large portions of the Synthea US module set with purpose-built European counterparts, removed all US-specific care-context modules (veterans, SNF, homelessness, SDOH, opioid abuse pipeline), and added new specialty pathways absent from the US baseline.

### Two counts of the same work

The two records of R3 state its size differently, and the difference is a
counting basis rather than a contradiction:

| | Modules removed | Newly created | Modified | Total touched |
| --- | --: | --: | --: | --: |
| File diff, `original-synthea-US-modules-20260221` against `new-synthea-EU-modules-20260523` | 53 | 39 | ~110 | — |
| Deliberate actions, as logged | 20 | 2 | 15 fixed, ~18 confirmed unchanged | 37 |

A file diff counts every differing file, including display-text corrections and
the sub-module trees that moved wholesale. The action count records what was
decided and done. The diff figure belongs in a description of the delta between
two trees; the action figure belongs in a description of the work. Quoting
either one as "the" number without naming its basis is what made them look
inconsistent.

------

## R4 — measuring what R3 generated

August 2026. R3 was built by reading module JSON against published references.
R4 measured what R3 actually generated, 1.4 million condition records over
41,864 patients, and corrected from the measurement. The two methods disagree,
and the disagreements were all in R3's favour on paper and against it in the
data.

### Before anything else: the metric was wrong

The R2 validation table (reproduced in [FUTURE-ACTIVITIES](FUTURE-ACTIVITIES.md)) compared Synthea record counts against European reference prevalences. Those are not the same quantity. Three distinct measures are in play:

| | Measure | Correct for |
| --- | --- | --- |
| **L** | Lifetime — distinct patients ever carrying the code | Survivorship: "people living with a past MI", "ever diagnosed with breast cancer" |
| **P** | 12-month period prevalence | Chronic disease. This is what WHO/Eurostat/EHIS-style references actually report |
| **A** | Annual incidence | Acute, recurrent conditions |

Using lifetime counts for an acute condition inflates it grotesquely. In R3, acute pharyngitis was **83 % lifetime but 6.3 % per year**; viral sinusitis **53.9 % lifetime but 2.2 % per year**. The R2 table's "+1,122 %" for viral sinusitis was largely a metric artefact, and so was much of its "−85 %" verdict on hypertension.

All figures below state their basis. Nothing is compared across bases.

### Three "MISSING" verdicts were table bugs, not data gaps

Verified against SNOMED CT International, version 20260801. Three codes in the R2 validation table are valid concepts that do not mean what the row label says. Querying them was guaranteed to return zero:

| R2 row label | Code used | What that code actually is | Correct code |
| ------------ | --------- | -------------------------- | ------------ |
| Osteoporosis | `64572001` | **Disease** — the root concept | `64859006` |
| Dementia | `56193007` | **Oculomotor nerve structure** | `52448006` |
| Malignant neoplasm of prostate | `363418001` | **Malignant neoplasm of pancreas** | `126906006` |

A fourth is a near miss: `32798002` is *Parkinsonism*, not Parkinson's disease (`49049000`).

Three of the eight ❌ MISSING verdicts in the original table were therefore measurement errors, not module failures. The genuine R3 gaps were COPD, depression, anxiety, generic osteoarthritis and Parkinson's.

### What was actually broken in R3

Reading the module tree against 1.4 million generated condition records found six defects, four of them structural rather than calibration errors.

**1. Knee osteoarthritis was exported as "Joint pain."** `osteoarthritis_europe.json :: Diagnose_Knee_OA` carried two codes, with `57676002 Joint pain (finding)` first and `239873007 Osteoarthritis of knee` second. Synthea's CSV exporter writes only `codes[0]`. The `OA_Type_Branch` sends **55 % of all incident osteoarthritis** to that state, so the largest OA site was recorded as a symptom finding and knee OA never appeared in the output at all. It was the only multi-code `ConditionOnset` in the entire module tree.

**2. `lung_cancer_europe.json` crashed patient generation.** The module loaded and its logic was sound, yet it produced zero cases. `LC_CT_Scan` was an `ImagingStudy` state whose `series` entry had no `instances` list; Synthea calls `s.instances.size()` unconditionally, so **every patient who reached it threw a NullPointerException and was discarded from the population entirely.** R3 was not missing lung cancer *codes* — it was missing the *patients*. A 400-patient control run produced 395 records; after the fix, 400 with three NSCLC and two SCLC.

**3. Five more submodules were doing the same thing.** `hiv/hiv_baseline`, `encounter/sdoh_hrsn`, `encounter/depression_screening`, `allergies/outgrow_food_allergies`, `allergies/severe_allergic_reaction` and `snf/skilled_nursing_facility` were referenced by `CallSubmodule` but absent from the tree. Each threw `RuntimeException: Unknown submodule` and discarded the patient. This is why HIV never appeared in R3 output. All six were restored unmodified from stock Synthea.

**4. Heart failure used an obsolete entry pattern.** `congestive_heart_failure_eu.json` drew a one-shot lottery at age 20 and then deferred onset by a fixed `Delay` of up to 65 years. A patient only acquired CHF if still being simulated when the delay expired, so realised prevalence collapsed to 0.043 % — a factor of ~50 below the module's own stated target. Two of its age buckets also overlapped by ten years, their labels and delays edited out of sync. `rheumatoid_arthritis.json` and `osteoporosis.json` shared the same pattern.

**5. Three concepts had two or three independent generators.** `hypertension_europe.json` emitted stroke, IHD and CKD codes at 0.5 / 0.4 / 0.3 % per year for every hypertensive patient, unguarded and unaware of the modules that own those concepts. The stroke arm alone produced 3.19 % CVA prevalence against a 1.6 % reference. `diabetes_europe.json` emitted IHD independently as well. Revealingly, `stable_ischemic_heart_disease_europe.json` had its rates **exactly halved** against the values documented in its own remarks — evidently to offset the duplicates.

**6. Depression episodes lasted six weeks.** Every exit from the review encounter fired `ConditionEnd` immediately, giving a mean episode duration of 68 days against a European median of about six months. Incidence was fine; duration was not. Separately, `anxiety_europe.json` created comorbid depression with no matching `ConditionEnd` at all, so those records never resolved.

### Seven new modules

Six conditions in the reference table were emitted by no module anywhere in the tree, and oncology was structurally incomplete.

| New module | Concept | EU reference |
| ---------- | ------- | -----------: |
| `hyperlipidaemia_europe.json` | `55822004` | 22.0 % |
| `metabolic_syndrome_europe.json` | `237602007` | 25.0 % |
| `chronic_pain_europe.json` | `82423001` | 19.0 % |
| `chronic_low_back_pain_europe.json` | `278860009` | 8.0 % |
| `colon_polyp_europe.json` | `68496003` | 2.5 % |
| `parkinson_europe.json` | `49049000` | 0.3 % |
| `other_cancers_europe.json` | 13 sites | see below |

Hyperlipidaemia and metabolic syndrome were the two largest single gaps in the table, and they sit directly upstream of the cardiovascular and diabetes modules that R3 otherwise calibrated well. A cohort with 34 % hypertension, 7.6 % type-2 diabetes and 0 % dyslipidaemia is not usable for cardiovascular risk work.

#### Malignant neoplasms were about half-covered

R3 modelled breast, prostate and colorectal cancer plus a little haematology (AML, multiple myeloma); lung cancer had a module that never ran. Those four solid-tumour sites are roughly **48 % of European cancer incidence**, so about half the oncological burden had no representation. Total cancer measured 3.2 % lifetime against a European survivor prevalence of 4–5 %, with a heavily distorted site mix.

`other_cancers_europe.json` adds the thirteen next most common sites in one age- and sex-stratified hazard: bladder, melanoma, stomach, kidney, pancreas, non-Hodgkin lymphoma, larynx, thyroid, liver, oesophagus, uterus, ovary, cervix. Site shares are normalised ECIS/GLOBOCAN European incidence shares, given separately for women and men; the three female-only sites' share is redistributed across the rest for men. Staging follows the European late-detection pattern (~55 % stage III–IV). All thirteen concept IDs verified against SNOMED CT International 20260801.

Brain and CNS tumours (~2 % of incidence) are a documented omission: SNOMED's generic `126952004` is "Neoplasm of brain", which is not malignancy-specific, and no unambiguous malignant rollup was chosen.

### The reference table's two dementia rows overlap

The table lists **"Alzheimer disease G30" (1.5 %)** and **"Dementia F00–F03" (2.0 %)** as if disjoint. **ICD-10 F00 *is* Alzheimer dementia.** At the module's 65 % Alzheimer share — measured at 67 %, matching Alzheimer Europe 2022 and correct — 2.0 % all-dementia implies 1.3 % Alzheimer, not 1.5 %. The two targets cannot both be met.

R4 calibrates all-dementia to the 2.0 % reference and leaves the subtype split alone. The "Dementia excluding AD" row should be retired in favour of an all-dementia row.

------

## R4.1 — the module work behind the 5.0 packages

September 2026. Where R4 measured the generated cohort, R4.1 measured the
*published artefact* and found that the two are not the same thing.

Triggered by an independent analysis of 250 EPS 4.0.1 bundles which found COPD at 3.2× its target. That analysis was right about the deviation and right about hypertension; the COPD finding turned out to be a sampling artefact of the EPS package, not a module fault.

### The EPS packages are not a population sample

Two independent filters act, and neither is a defect — but both must be stated wherever EPS figures are quoted.

**The age distribution comes from the European citizen records.** `synderai-v7.php` pairs each citizen record with a Synthea clinical story of the same sex and an age 1–4 years greater. The output age mix is the citizen list's: mean age **57.1** against **41.1** in the population, 75+ at **25.6 %** against **7.5 %**. The near-exact sex balance comes from there too.

**Every patient was required to have had an inpatient admission.** The run generated EPS, LAB and HDR together, and `synderai-v7.php` set `preselected->inpatient = TRUE` for *every* patient as soon as `HDR` appeared anywhere in `--artifacts`. 100 % of the 250 EPS patients had an inpatient encounter, against 23.2 % of the living population.

COPD decomposed: population 5.5 % → 11.1 % re-weighted to the EPS age structure → **19.8 %** adding the inpatient filter → 24.8 % observed. Age composition and the hospitalisation filter account for essentially all of it.

Why only COPD showed it: after age standardisation the residuals were COPD **+13.7 pp**, IHD +1.4, hypertension +1.1, metabolic syndrome +0.6, miscarriage −0.1. Hypertension (73–83 % in the old bands) and metabolic syndrome (61–75 %) are near-saturated, so a morbidity filter cannot lift them. COPD at 15–25 % has room to double.

**Fixed 2026-09-30:** the blanket preselection was removed. The cohort is now drawn for EPS, the flagship, and HDR is emitted for the patients whose data supports one — which the code already handled. The three artifacts still share one patient list. HDR yield is ~37 % of the citizen-record list, so ~410 patients give ~150 HDR bundles alongside ~410 EPS and ~330 LAB.

### The discharge criterion had drifted, and one branch was dead

The cache that fed the preselection registered every patient with **any** encounter of class `inpatient`, while the HDR getter additionally required discharge information. So the cache offered patients the getter then rejected: 38 % of preselected patients still produced no HDR.

Worse, the `$EXTRADISCHARGE` fallback in `getters/encounters.php` was **dead code**. It was populated only when `$APPROPRIATEREASONS[$reasoncode]` was absent, but read only when it was present — mutually exclusive. All 23 hand-written discharge syntheses had never once applied.

| Criterion | Share of living patients |
|---|---:|
| any inpatient encounter (the old cache) | 25.1 % |
| admission reason with a discharge synthesis (what HDR needs) | **12.9 %** |
| the same, with the dead branch repaired | **25.1 %** |

The 23 entries had been written precisely to close that gap. Repairing the branch nearly doubles the HDR-capable pool and halves the run size needed for a given number of HDR bundles — from ~728 patients to ~410 for 150 bundles.

**Fixed 2026-09-30.** The criterion now lives in one place, `lib/discharge-criterion.php`, used by the runtime getter, the pre-processing cache and the story matcher, so they cannot drift again. The matcher's Filter I now also requires discharge information, which makes its log message true for the first time.

### Four SNOMED codes in the EPS history-of mapping

`lib/eps-problem-filter.php` and `lib/condition-summary-filter.php` each carry the same table rewriting resolved conditions to "history of" concepts. Of six distinct target concepts, **four were defective**:

| Source | Old target | What it actually was | Corrected to | In the 250 bundles |
|---|---|---|---|---:|
| `398254007` pre-eclampsia | `161765003` | Past pregnancy history of **premature delivery** | `105651000119100` | 22 |
| `254837009` breast cancer | `429740004` | **Family** history of breast cancer | `429087003` | 4 |
| `132281000119108` DVT | `161511005` | **does not exist** in SNOMED CT | `161508001` | 10 |
| `706870000` pulmonary embolism | `161512003` | **does not exist** | substitution removed | 2 |

38 of the 98 mapped Condition entries in the published package carried a wrong or non-existent code. Two are typos onto neighbouring concepts, and in the breast-cancer case the file already carried the *correct display string* beside the wrong code — which is why it went unnoticed.

For pulmonary embolism no plain pre-coordinated "History of" concept exists in the International release, so the substitution was dropped and `706870000` is kept.

**A design note recorded in both files:** every Condition produced through this table already carries `clinicalStatus = inactive`, the FHIR-native way to say "no longer active". Substituting a "history of" concept on top is redundant double-encoding, and it is what let four errors through. Dropping the substitution entirely would remove this class of defect permanently — a semantic decision about EPS problem-list content, so it was left open.

### Pregnancy: diagnosed, deliberately not changed

History of miscarriage sits at 37.4 % of living women. The cause is **not** the miscarriage risk but the number of pregnancies:

| | measured | reference |
|---|---:|---:|
| Pregnancies per ever-pregnant woman | **5.57** (max 23) | EU TFR ~1.5; all pregnancies incl. losses ~2.5–3 |
| Miscarriage events per pregnancy | 24.6 % | ~15.3 % (Quenby et al. 2021) |
| History of miscarriage, ever-pregnant women | 49.1 % | — |

Two multiplicative errors, and the second partly follows from the first: with 5.57 pregnancies many occur past 40, where the module's own age table puts miscarriage risk at 39–60 %. The driver is `female_reproduction.json :: Contraceptive_Incidence`, which sets conception at **19.3 % per reproductive cycle** without contraception — the US Synthea default. The per-pregnancy miscarriage table in `pregnancy.json` is sourced entirely from US CDC data.

Not changed: setting a European conception rate means choosing a target total fertility rate (1.1–1.8 across the EU27) and a contraceptive-prevalence mix. That is an epidemiological parameter decision, not a bug fix, and it cascades through births, prenatal care and the whole obstetric pathway.

### Hypertension re-derived from its own targets

Neither the rates in use nor the rates documented in the remarks hit the EHIS reference. The rates were fitted per band so that the modelled prevalence **averaged over each band's age range** equals that band's reference — EHIS reports prevalence within an age group, not at its upper edge.

| Band | documented | in use since ~mid-2026 | **fitted** | band target | **measured after** |
|---|---:|---:|---:|---:|---:|
| 18–34 | 0.450 % | 1.125 % | **0.815 %** | 7 % | — |
| 35–44 | 1.100 % | 2.750 % | **1.098 %** | 18 % | 15.5 % (30–44) |
| 45–54 | 1.500 % | 3.750 % | **2.826 %** | 33 % | 35.8 % (45–59) |
| 55–64 | 1.700 % | 4.250 % | **3.017 %** | 50 % | — |
| 65–74 | 1.500 % | 3.750 % | **4.072 %** | 65 % | 59.1 % (60–74) |
| 75+ | 1.000 % | 2.500 % | **1.871 %** | 75 % | **76.1 %** |

Verified by a 41,439-patient run on 2026-09-30: all four measurable bands landed within 2.2 percentage points of prediction, and the EU27-adult-standardised figure moved from **46.5 % to 36.1 %** against a ~35 % target. The 75+ rate *falls* under the fit, which is correct — by that age most susceptible people already carry the diagnosis.

---

------

# Results

## Crude prevalence, R2 to R4

Basis per row: **P** = 12-month period prevalence, **L** = lifetime, **A** =
annual incidence. Bold marks rows within ±25 % of the European reference.

**These are crude figures over the generated cohort.** Read them together with
the standardised table that follows, which changes the verdict on most
age-dependent rows.

| Condition | EU ref | R2 | R3 | **R4** | Basis |
| --------- | -----: | -: | -: | -----: | :---: |
| Essential hypertension | 32.0 % | -85 % | **+7 %** | **+13 %** | P |
| Ischaemic heart disease | 4.5 % | -33 % | **-8 %** | **-6 %** | P |
| Myocardial infarction | 1.8 % | -78 % | -26 % | **+13 %** | L |
| Heart failure | 2.2 % | -99 % | -98 % | **+19 %** | P |
| Atrial fibrillation | 2.5 % | -94 % | +32 % | **-2 %** | P |
| Diabetes mellitus type 2 | 7.5 % | -86 % | **+1 %** | **+6 %** | P |
| Prediabetes | 8.0 % | -41 % | **-18 %** | **-19 %** | P |
| Obesity | 17.0 % | -65 % | **-16 %** | **-14 %** | P |
| Hyperlipidaemia | 22.0 % | -93 % | -100 % | **-24 %** | P |
| Metabolic syndrome | 25.0 % | -89 % | -100 % | **+11 %** | P |
| COPD | 6.5 % | -100 % | **-18 %** | **-13 %** | P |
| Asthma | 7.0 % | -100 % | **-24 %** | **-19 %** | P |
| Viral sinusitis | 0.8 % | +1122 % | +176 % | **-7 %** | A |
| Acute viral pharyngitis | 0.7 % | +687 % | +803 % | **+3 %** | A |
| Depression | 6.9 % | -100 % | -62 % | **-6 %** | P |
| Anxiety disorders (group) | 6.5 % | -100 % | **-13 %** | **-10 %** | P |
| Panic disorder | 2.0 % | **+1 %** | **+10 %** | **+14 %** | P |
| Osteoarthritis | 9.5 % | -100 % | -55 % | **+10 %** | P |
| Rheumatoid arthritis | 0.8 % | -96 % | -80 % | **+2 %** | P |
| Chronic low back pain | 8.0 % | -76 % | -100 % | -31 % | P |
| Chronic pain | 19.0 % | -86 % | -69 % | -34 % | P |
| Osteoporosis | 5.6 % | -100 % | -36 % | **-8 %** | P |
| Breast cancer | 1.4 % | -82 % | **-21 %** | **-7 %** | L |
| Prostate neoplasm | 1.1 % | -100 % | **-6 %** | **+25 %** | L |
| Colorectal cancer | 0.7 % | -84 % | **-4 %** | **-11 %** | L |
| Non-small cell lung cancer | 0.5 % | -76 % | -100 % | **+1 %** | L |
| Stroke | 1.6 % | -94 % | +172 % | **+7 %** | L |
| Alzheimer disease | 1.5 % | -63 % | **-3 %** | **-18 %** | P |
| All dementia incl. AD | 2.0 % | -72 % | +58 % | **-3 %** | P |
| Parkinson disease | 0.3 % | -100 % | -100 % | -31 % | P |
| CKD stage 1 | 3.0 % | -31 % | -86 % | -43 % | P |
| CKD stage 2 | 3.1 % | -41 % | **+25 %** | -29 % | P |
| CKD stage 3 | 3.9 % | -66 % | **+9 %** | -29 % | P |
| Polyp of colon | 2.5 % | -54 % | -100 % | **+0 %** | L |
| Chronic sinusitis | 1.2 % | +104 % | +193 % | +44 % | P |
| Acute infective cystitis | 1.5 % | -25 % | -26 % | -27 % | A |
| Unhealthy alcohol behaviour | 7.6 % | -71 % | -34 % | -33 % | P |

| | R2 | R3 | **R4** |
| --- | -: | -: | -: |
| Median absolute deviation | 86 % | 34 % | **13 %** |
| Within ±25 % | 1/37 | 15/37 | **28/37** |
| Within ±60 % | 7/37 | 22/37 | **37/37** |
| ≥100 % off or absent | 10/37 | 10/37 | **0/37** |

**R4 figures are the shipped production cohort**: 41,637 patients (39,990 living, 1,647 deceased) generated across all 291 European regions weighted by population share, 2026-08-29. Not a verification sample.

Getting there took three verification runs of ~20,800 patients each. Run 1 measured the release; runs 2 and 3 measured corrections derived from the run before. Every correction was applied only after measurement, never from estimate alone. The production run then reproduced the calibration on European geography at twice the scale — median absolute deviation 13 % against the verification runs' 16 %.

## The age-standardisation finding

The table below is the finding as first measured, and it is the reason the
measure was rebuilt. **Its standardised column predates two corrections to the
standardisation itself**, both now recorded in `eu27-age-weights.csv`: the band
widths above 75 were coarse, which inflated dementia by 44 % on its own, and
some rows were scoped to the adult population where their reference describes
the total population, worth a further 46 points on dementia. The direction of
the finding stands and is not in doubt. The individual magnitudes below do not
carry over, and the priority list derived from them has to be re-measured on the
current script before it is acted on.

Measured on one run of 41,439 patients across 291 European regions, 2026-09-30,
on the same basis throughout (12-month period prevalence, 2024), crude against
standardised to the EU27 adult age structure.

| Condition | EU ref | crude | dev | **standardised** | **dev** |
|---|---:|---:|---:|---:|---:|
| Essential hypertension | 32.0 % | 27.4 % | −14 | 36.1 % | **+13** |
| Ischaemic heart disease | 4.5 % | 4.0 % | −10 | 5.8 % | **+29** |
| Heart failure | 2.2 % | 2.3 % | +3 | 3.3 % | **+51** |
| Atrial fibrillation | 2.5 % | 2.3 % | −8 | 3.4 % | **+36** |
| Type-2 diabetes | 7.5 % | 8.0 % | +6 | 10.7 % | **+43** |
| Obesity | 17.0 % | 14.5 % | −15 | 18.3 % | +8 |
| Hyperlipidaemia | 22.0 % | 16.6 % | −24 | 22.4 % | **+2** |
| Metabolic syndrome | 25.0 % | 26.4 % | +5 | 34.8 % | **+39** |
| COPD | 6.5 % | 5.5 % | −15 | 7.6 % | +18 |
| Asthma | 7.0 % | 5.2 % | −25 | 6.5 % | **−7** |
| Osteoarthritis | 9.5 % | 10.1 % | +6 | 14.2 % | **+49** |
| Osteoporosis | 5.6 % | 4.8 % | −14 | 6.9 % | +23 |
| Chronic pain | 19.0 % | 11.9 % | −38 | 15.9 % | **−16** |
| CKD stage 1 | 3.0 % | 1.6 % | −46 | 2.2 % | **−27** |
| CKD stage 2 | 3.1 % | 2.0 % | −35 | 2.8 % | **−11** |
| CKD stage 3 | 3.9 % | 2.4 % | −39 | 3.3 % | **−16** |
| All dementia | 2.0 % | 2.0 % | −2 | 2.9 % | **+45** |
| Parkinson's disease | 0.3 % | 0.3 % | −6 | 0.4 % | **+38** |
| | | **median 14 %** | | **median 25 %** | |
| | | **13/18 within ±25 %** | | **9/18 within ±25 %** | |

**The standardisation changes the verdict on 13 of 18 rows.** Eight conditions that read as on target are substantially over — diabetes +43, osteoarthritis +49, heart failure +51, dementia +45, metabolic syndrome +39, Parkinson's +38, atrial fibrillation +36, IHD +29. Four that read as failures are close — hyperlipidaemia +2, asthma −7, CKD stage 2 −11, chronic pain −16.

Heart failure is the sharpest case: the module was rewritten in R4 to fix a −98 % crude deviation, and the crude measure then read +3 %. Standardised it is **+51 %**. The rewrite over-corrected and the measure hid it.

------

# What remains

Two kinds of item are mixed in the lists that follow, and separating them
matters for planning: rate tuning, which is arithmetic, and definition
questions, which need a decision before any tuning can be correct.

## Rate tuning, on the standardised measure

The direction is settled: the crude measure understates age-dependent conditions
on this cohort, so the rows to look at first are the ones it reported as on
target. Which rows those are, and by how much, has to come from a run of the
current script rather than from the first standardised table, whose magnitudes
predate the band-width and scope corrections. Nothing should be tuned before
that run exists.

Heart failure is the case to keep in view. The module was rewritten in R4 to fix
a −98 % crude deviation; the crude measure then read +3 %, and standardised it is
+51 %. The rewrite over-corrected and the measure hid it.

## Rate tuning, carried over from the crude R4 assessment

These rows sat outside ±25 % in the R4 production cohort and have not been
re-measured on the standardised basis.

| Row | Crude deviation | Note |
| --- | --: | --- |
| Chronic sinusitis | +44 % | Improved from +193 %, but drifted up from +28 % between verification and production. Needs a further cut, and a target decision first — see below. |
| Chronic pain | −34 % | New module. Needs roughly ×1.9 on incidence. |
| Unhealthy alcohol behaviour | −33 % | `substance_use_europe.json` was edited after R3 was generated and first measured in R4. |
| Chronic low back pain | −31 % | New module, first calibration. |
| Parkinson's disease | −31 % | n = 87 in the production cohort; partly sampling noise. On the standardised measure it is +38 %. |
| Acute infective cystitis | −27 % | Borderline. |

## Decisions owed, not tuning

**Four of the eleven standardisation weight bands are approximate.** The bands
from 60–64 upward are exact EU27 shares. The bands 0–17, 18–29, 30–44 and 45–59
are approximate Eurostat 2023 shares, constrained to sum to one with the exact
ones. They carry little weight for a steep-gradient condition such as dementia
and 64 % of the weight for an adult-scope condition such as hypertension, so
they have to be replaced with exact Eurostat values before an adult-scope
standardised figure is published.

**Chronic sinusitis is targeted at two incompatible values inside SYNDERAI.**
The module remarks cite GA²LEN at 10–11 %, the validation table cites EPOS at
1.2 %. That is a factor of nine, and no amount of tuning satisfies both.

**The two dementia rows of the validation table overlap.** ICD-10 F00 *is*
Alzheimer dementia, so "Alzheimer disease G30" at 1.5 % and "Dementia F00–F03"
at 2.0 % cannot both be met at the module's measured 65 % Alzheimer share, which
itself matches Alzheimer Europe. All-dementia is calibrated to the 2.0 %
reference; the subtype row should be retired or redefined as all-dementia.

**The CKD stages are a measurement-definition question.** Total CKD is right,
9.7 % ever against a reference of about 10 %, while per-stage period prevalence
runs low because progression ends the previous stage.

**The European conception rate is an epidemiological parameter decision.**
History of miscarriage sits at 37.4 % of living women, and the cause is the
number of pregnancies rather than the miscarriage risk: 5.57 pregnancies per
ever-pregnant woman against a European figure of about 2.5 to 3 including
losses. `female_reproduction.json :: Contraceptive_Incidence` carries the US
Synthea default of 19.3 % conception per reproductive cycle. Setting a European
rate means choosing a target total fertility rate, which ranges from 1.1 to 1.8
across the EU27, and a contraceptive-prevalence mix. It cascades through births,
prenatal care and the whole obstetric pathway, so it is not a bug fix.

**Whether "history of" substitution should exist at all.** Every Condition
produced through the EPS history-of table already carries
`clinicalStatus = inactive`, which is the FHIR-native way to say the same thing.
Substituting a "history of" concept on top is redundant double-encoding, and it
is what let four wrong codes through. Dropping the substitution would remove
this class of defect permanently. It is a semantic decision about problem-list
content.

## Validation infrastructure

**Most of it is built.** `measure3.py`, as of 2026-09-30, reports crude and
standardised prevalence side by side, carries the measurement basis and the
reference population per condition in its own table, and standardises against
`eu27-age-weights.csv`. Two traps were found and closed while building it, both
recorded in the weights file: a coarse open-ended 75+ band inflated dementia by
44 % on its own, because standardisation averages prevalence uniformly across a
band; and scoping a reference as "% of adults" where it describes "% of the
total population" moved the same row by 46 points.

The four mis-coded SNOMED rows are corrected in that table — osteoporosis
`64859006`, dementia `52448006`, prostate `126906006`, Parkinson's `49049000`.
Several rows now carry code sets rather than single codes: myocardial infarction
four, osteoarthritis three for knee, hip and generic, the anxiety group four,
stroke two, colorectal three. That is a hand-maintained equivalent of
subsumption rollup and it removes the exact-match failure mode, though it has to
be extended by hand whenever a module codes a new subtype.

What is still open:

- **The four approximate weight bands.** 60–64 upward are exact EU27 shares,
  verified against Alzheimer Europe 2025 to three decimals. 0–17, 18–29, 30–44
  and 45–59 are approximate Eurostat 2023 shares. For an adult-scope condition
  the three approximate adult bands carry 64 % of the weight, so the script's
  blanket "APPROXIMATE WEIGHTS" banner understates how much it matters and
  overstates how much of the file it covers.
- **A single-cohort mode and a machine-readable output.** The script compares two
  cohorts and prints a fixed-width table. A release wants one cohort measured and
  the result committed beside the package as its provenance.
- **A distributional measure over the full code set**, rather than per-condition
  deviation on a selected list of 38.

## Coverage gaps

- **Eurostat life tables** still have to replace the US mortality assumptions.
- **Brain and CNS tumours**, roughly 2 % of European cancer incidence, are a
  documented omission. SNOMED's generic `126952004` is "Neoplasm of brain",
  which is not malignancy-specific, and no unambiguous malignant rollup was
  chosen.
- **Tick-borne encephalitis, occupational disease** and other region-specific
  conditions remain unmodelled.

------

# Method notes

Five findings about how to calibrate a generator, each of which cost a round to
learn. They are recorded because every one of them would otherwise be repeated.

**Measure, then correct — never correct from an estimate.** Four of the six
defects in R3 were invisible from reading the module JSON. Two of them were not
missing diagnoses but missing *patients*: a malformed `ImagingStudy` state and
six unresolved `CallSubmodule` targets each threw at runtime and caused Synthea
to discard the patient silently. Lung cancer and HIV were absent from R3 for that
reason alone.

**Verify what the exporter writes, not what the module says.** Synthea's CSV
exporter writes only `codes[0]` of a `ConditionOnset`. One module listed
`Joint pain` before `Osteoarthritis of knee`, so 55 % of all incident
osteoarthritis was exported as a symptom and knee osteoarthritis never appeared
at all. A build-time check for this is part of the generation script.

**Do not let the generator discard patients to shape the output.** Synthea's
`generate.only_alive_patients` re-rolls any patient who dies rather than simply
omitting them, which biases the living cohort. Measured on identical cohorts the
overall cost is small, median absolute deviation 15 % with deceased patients and
14 % without, but heart failure moves from +10 % to −89 %. SYNDERAI keeps
deceased patients in the funds; consumers needing a living cohort filter on an
empty `DEATHDATE`.

**Prevalence does not scale linearly with incidence.** Tripling depression
incidence produced only a 2.26-fold rise in prevalence, because the recurrence
loop contributes cases that do not grow with first-onset incidence. Fitting
p ∝ i^0.74 to the two measured points gave the next factor as ×1.55 rather than
the linear ×1.38, and depression landed at −7 % crude.

**Risk-tiered modules move when their inputs move.** Atrial fibrillation drifted
from +54 % to +62 % between two runs without being touched, because the
preceding run enlarged the ischaemic heart disease and COPD populations it
reads. Correcting one module's rates shifts everything downstream of it.

And one about the measure itself, which is the subject of the standardisation
section above: **a crude prevalence over a cohort younger than the reference
population is not comparable to an adult-population reference.** It took two
full calibration rounds for that to surface.

------

# Disease-by-disease record

The sections below describe what was changed per condition and against which
references. They are the R3 and R4 record and have not been re-stated on the
standardised measure; for the current deviation of any condition, read the
results tables above.

### 1. Hypertension

**Module:** `hypertension.json` → `hypertension_europe.json`

Overall adult prevalence raised from ~25% (US default) to **~35%** (European calibration). Age-stratified onset probabilities were re-derived from survey data. Treatment pathway shifted to ESC/ESH first-line guidance (ACE inhibitors / ARBs preferred; thiazides as add-on). Blood-pressure control targets use the ESC/ESH threshold of <140/90 mmHg.

**Age-stratified point prevalence (EU):**

| Age band | Prevalence |
| -------- | ---------- |
| 18–34    | ~7%        |
| 35–44    | ~18%       |
| 45–54    | ~33%       |
| 55–64    | ~50%       |
| 65–74    | ~65%       |
| 75+      | ~75%       |

**Sources:** WHO Europe Hypertension Fact Sheet (2023); Eurostat EHIS Wave 3 (2019); ESC/ESH 2018 Hypertension Guidelines; GBD 2019 Europe (IHME).

------

### 2. Type 2 Diabetes

**Module:** absent/generic → `diabetes_europe.json`

Overall adult prevalence calibrated to **~7.5%** (IDF Atlas Europe 2021). Prediabetes prevalence set at ~8% of adults. Onset shifted to peak at 45–64 years, reflecting the European age pyramid. First-line therapy is metformin (EASD/ADA 2022 consensus). HbA1c target set to <53 mmol/mol (7.0%) per EASD guidelines. Complication rates derived from EURISC/EURODIAB studies.

**Age-stratified prevalence (EU):**

| Age band | Prevalence |
| -------- | ---------- |
| 20–39    | ~1.5%      |
| 40–49    | ~5.0%      |
| 50–59    | ~11.0%     |
| 60–69    | ~18.0%     |
| 70–79    | ~22.0%     |
| 80+      | ~23.0%     |

**Sources:** IDF Diabetes Atlas 10th Edition (2021); WHO Europe Diabetes Fact Sheet (2022); Eurostat EHIS Wave 3 (2019); EASD/ADA 2022 Consensus; GBD 2019 Europe.

------

### 3. COPD

**Module:** `copd.json` (non-functional for EU) → `copd_europe.json`

The original US module produced near-zero COPD in the EU cohort (it required US-specific attributes). Fully rebuilt. Target prevalence **~6.5% of adults** (BOLD Europe). Strong post-age-50 weighting (<2% under 50, >10% over 60). Smoking modelled as primary risk factor (~25% EU adults). GOLD staging distribution (GOLD 1–4) from BOLD Europe. Medication pathway follows GOLD 2023 preferred A/B/E steps (LAMA / ICS-LABA / triple therapy). Exacerbation rates from ECLIPSE and TORCH studies.

*Calibration round 2 patch:* All onset rates reduced by 30% after first run produced ~18% cohort prevalence against a target of ~12–13%.

**Sources:** ERS White Book (2013); ERS update (2022); BOLD Europe study (Buist et al., *Lancet*, 2007); GOLD 2023 COPD Guidelines; GBD 2019 Europe; Eurostat EHIS Wave 3 (2019); WHO Europe NCD Report (2022).

------

### 4. Asthma

**Module:** `asthma.json` (adult + child mixed) → `asthma_adult_europe.json`

The US module was replaced by an adult-onset-only module for EU purposes. Target prevalence **5–8% EU adults** per ERS/GINA 2022. Treatment follows GINA 2022 stepwise protocol (SABA rescue + ICS maintenance). Exacerbation rate modelled at ~0.5–1.5 per year in uncontrolled patients.

**Sources:** ERS/GINA 2022 Asthma Guidelines.

------

### 5. Stroke

**Module:** `stroke.json` → `stroke_europe-v2.json`

The US stroke module produced a ~10–13% cohort stroke prevalence. The EU v2 replacement targets **2–3% point prevalence** (living stroke survivors), consistent with ESO 2022. Key changes:

- **Incidence rates reduced ~50%** across all risk tiers
- **Stroke recurrence** lowered from 5%/yr to 3%/yr (ESO 26% 10-year recurrence data)
- **Alcoholism risk tier added** (reads `alcoholism == true` from `substance_use-europe.json`); 0.30–1.00%/yr by age band (WHO/ESO evidence on alcohol and stroke)
- **SNOMED code corrected:** Stroke recurrence aligned to ischaemic stroke (SNOMED 422504002), resolving the dual-code conflict with the hypertension/outcomes module using CVA (230690007)
- **Acute/chronic semantics fixed** (round 2): `ConditionEnd` of acute stroke immediately post-care; chronic `History of stroke` (SNOMED 275526006) onset added for persistent problem list

**Sources:** ESO Clinical Guidelines (2022); ESO Atlas of Stroke (2022).

------

### 6. Myocardial Infarction

**Module:** `myocardial_infarction.json` (0% firing rate for EU) → `myocardial_infarction_europe.json`

The US module reads a `mi_risk` attribute set only by removed US CVD modules; it never fired in EU context. Rebuilt from scratch. Target **2–3% history-of-MI prevalence** in EU adults. STEMI/NSTEMI split: 55%/45% (ESC ACVC Registry 2023). Risk tiers read attributes from the EU IHD and hypertension/diabetes modules. Treatment follows ESC STEMI 2017 and NSTEMI 2020 guidelines (dual antiplatelet + statin + beta-blocker).

*Calibration round 2:* `ConditionEnd` states added for STEMI/NSTEMI/MI after acute encounter; chronic "Old MI" (SNOMED 1755008) onset added for EPS problem list.

**Sources:** ESC Atlas of Cardiovascular Disease (2023); Eurostat CVD statistics; ESC ACVC Registry (2023); ESC STEMI Guidelines (2017); ESC NSTEMI Guidelines (2020).

------

### 7. Stable Ischaemic Heart Disease (IHD)

**Module:** `stable_ischemic_heart_disease.json` (0% firing rate for EU) → `stable_ischemic_heart_disease_europe.json`

The US module also reads a US-only attribute and produced 0% in EU context. Rebuilt with EU risk-tier logic reading `hypertension == true` and `diabetes == true` attributes. Target prevalence **~3–4% EU adults** (ESC Atlas 2023). Sets `ischemic_heart_disease = true` for downstream use by the MI module. First-line medications: aspirin, statin, beta-blocker, nitrate.

*Calibration round 2:* All annual onset rates halved after initial calibration produced ~20% cohort prevalence.

**Sources:** ESC Chronic Coronary Syndromes Guidelines (2019); ESC Atlas (2023); EUROASPIRE data.

------

### 8. Atrial Fibrillation

**Module:** `atrial_fibrillation.json` → `atrial_fibrillation_europe-v2.json`

The US module was producing 9–10% AF prevalence independently; combined with the EU v1 module it reached 17% cohort prevalence. Root cause: US module reads `atrial_fibrillation_risk` attribute set by removed US cardiovascular module, so it was still firing. The US module was removed; only the EU module remains.

v2 bug fixes:

- **Double-counting stroke removed:** `AF_Complication_Check/AF_Stroke` states deleted (stroke for AF patients handled by `stroke_europe-v2.json` alone)
- **Infinite loop fixed:** 98.5% branch loop corrected; `End_AF_Review` routes to `AF_Followup_Delay`
- **Alcoholism risk added:** ~2× AF incidence for `alcoholism == true` patients (ESC 2020 evidence on alcohol and AF)

Target cohort AF prevalence: **3–5%** (mean age ~52 cohort).

**Sources:** Lancet Regional Health – Europe (2024); ESC Atlas (2020); ESC AF Guidelines (2020).

------

### 9. Congestive Heart Failure (CHF)

**Module:** `congestive_heart_failure.json` → `congestive_heart_failure_eu.json`

The clinical state machine (HFrEF/HFmEF/HFpEF; NYHA I–IV; ACC/AHA stages C–D; device therapy) was preserved from the US module. EU calibration changes:

- Entry probabilities recalibrated to match European CHF prevalence (~2% of total population; ESC Atlas 2021)
- EU first-line pharmacotherapy added per ESC 2021 Heart Failure Guidelines: ACE-I/ARB, beta-blocker, MRA, SGLT-2 inhibitors (dapagliflozin for HFrEF)
- Existing medications (furosemide, metoprolol) confirmed EU-available

**Sources:** ESC Heart Failure Guidelines (2021); Savarese & Lund, *Card Fail Rev.* (2017).

------

### 10. Chronic Kidney Disease (CKD)

**Module:** `chronic_kidney_disease.json` → `ckd_europe_v2.json`

Target prevalence **8–12% EU adults** (ERA-EDTA 2022). KDIGO 2022 stage definitions (eGFR-based). Risk factors: hypertension, diabetes, age >60. Treatment: RAAS blockade, SGLT-2 inhibitors, dietary management.

v2 changes:

- Rates reduced ~33% after initial calibration produced ~18% cohort prevalence
- *Calibration round 2 patch:* Stage distribution at incident diagnosis fully restructured (Stage 1: 5% | Stage 2: 35% | Stage 3a: 35% | Stage 3b: 15% | Stage 4: 8% | Stage 5: 2%) to match EPIRCE/ERA-EDTA detection-stage proportions. Previously all new cases entered at Stage 1 (22%), producing an unrealistic pyramid.

**Sources:** ERA-EDTA Registry (2022); KDIGO CKD Guidelines (2022); EPIRCE study.

------

### 11. Alzheimer's Disease / Dementia

**Module:** `dementia.json` → `alzheimers_dementia_europe_v2.json` + `mci_europe.json`

The US dementia module was replaced by a two-module system: `mci_europe.json` handles all Mild Cognitive Impairment onset and MCI→dementia conversion; `alzheimers_dementia_europe_v2.json` handles direct dementia onset (cases not preceded by MCI).

v2 changes to the dementia module:

- Internal MCI pipeline removed (was double-counting with `mci_europe.json`)
- Direct dementia incidence rates reduced ~60% to prevent double-counting

*Round 3 patch:* Direct dementia rates raised ~10× after the round-2 graph repair unblocked the module and revealed rates were set far below EU references:

| Age band | Old rate/yr | New rate/yr | EuroCoDe reference |
| -------- | ----------- | ----------- | ------------------ |
| 60–69    | 0.05%       | 0.30%       | ~0.3%              |
| 70–79    | 0.15%       | 1.50%       | ~1.4%              |
| 80–89    | 0.50%       | 4.50%       | ~4.4%              |
| 90+      | 1.00%       | 9.00%       | ~9%                |

**MCI module (`mci_europe.json`):** Prevalence ~1–3% whole population, ~15% of over-65s. Annual incidence ~2–3% in >65s. Progression to dementia ~10–15%/year. New module; no US equivalent.

Target combined dementia prevalence: **~2% all adults / ~8% aged 65+** (EuroCoDe).

**Sources:** Alzheimer Europe Yearbook / EuroCoDe consensus (2021); EuroCoDe 2021 MCI data.

------

### 12. Depression

**Module:** (US module produced ~0 records in EU context) → `depression_europe_v2.json`

The US depression module was generating essentially zero diagnoses in the EU pipeline. Fully rebuilt. 12-month prevalence: **~6.9%** (ECNP/EBC 2023). Lifetime prevalence: ~15–20%. Female:male ratio 2:1 modelled via sex-stratified onset. First-line therapy: SSRIs (European guideline preference). Recurrence modelled at ~50%.

v2: Lifetime onset rates reduced ~38% after initial calibration produced 41% lifetime onset (EU target 20–30%).

**Sources:** ECNP/EBC 2023 Size and Burden of Mental Disorders in Europe; ESEMeD study; WHO Mental Health Atlas Europe (2022); NICE NG222; DGPPN S3 German Guidelines; HAS French Guidelines; GBD 2019 Europe.

------

### 13. Anxiety Disorders

**Module:** (US module producing near-zero records) → `anxiety_europe.json`

New module; the US anxiety module was generating near-zero diagnoses in EU context. 12-month prevalence: **~6.5%** (ECNP/EBC 2023). Covers GAD (primary), panic disorder, social anxiety, specific phobia. Female:male ratio ~2:1. First-line: SSRIs + CBT. Comorbid depression co-modelled (~50% co-occurrence per ESEMeD).

**Sources:** ECNP/EBC (2023); ESEMeD; WHO Mental Health Atlas Europe (2022); NICE NG198 (2020); GBD 2019 Europe.

------

### 14. Epilepsy

**Module:** `epilepsy.json` → `epilepsy-europe.json`

Five major bugs fixed vs the US original:

1. **Entry probability:** 10% → 1.7% (US rate was ~6× EU adult epilepsy prevalence of 0.6–1.0%)
2. **SUDEP rate:** 10%/cycle → 0.1%/cycle (US rate implied 97% 10-year SUDEP mortality; EU real rate ~1–3% lifetime)
3. **Post-stroke epilepsy added** for age 55+ (reads `stroke_eu == true`; ~8% of stroke survivors develop epilepsy — ESO 2021)
4. **Medication updated** to ILAE 2022/EAN first-line: levetiracetam 40%, lamotrigine 35%, carbamazepine 25%. Removed diazepam (acute-only) and clonazepam (2nd line)
5. **Attribute `epilepsy_eu = true`** set after onset for downstream inter-module use

**Sources:** ILAE (2022); Forsgren et al., *Epilepsia* (2005); ESO Post-Stroke Epilepsy (2021); Thurman et al. (2014) — SUDEP rates.

------

### 15. Fibromyalgia

**Module:** `fibromyalgia.json` → `fibromyalgia_europe.json`

Prevalence adjusted from the US 7:1 female:male ratio (~1.67%) to the EU 2:1 ratio (~2.7%; EULAR 2017). Medication pathway overhauled:

- Milnacipran removed (FDA-approved for fibromyalgia; **not EMA-approved**)
- Opioid pathway removed (EULAR 2017 explicitly recommends against opioids for fibromyalgia)
- Amitriptyline 25 mg added (EU/EAN/EULAR first-line)
- Pregabalin dose corrected: 100 mg → 150 mg (EMA-approved dose)
- Encounter setting changed: emergency hospital → ambulatory (EU outpatient management)

EU medication split: pregabalin 35% / duloxetine 35% / amitriptyline 30%.

**Sources:** EULAR 2017 Fibromyalgia Recommendations; EAN Guidelines.

------

### 16. Osteoarthritis

**Module:** `osteoarthritis.json` → `osteoarthritis_europe.json`

Target prevalence **5–10% EU adults** (EULAR/Eurostat 2022). Risk factors modelled: age >50, obesity (BMI >30), female sex. Treatment follows EULAR 2019 OA recommendations (exercise, weight management, NSAIDs, joint replacement).

**Sources:** EULAR OA Recommendations (2019); Eurostat (2022).

------

### 17. Rheumatoid Arthritis

**Module:** `rheumatoid_arthritis.json` — modified in place (SNOMED display label corrections only)

Probability transitions and prevalence unchanged. Changes limited to SNOMED-CT concept display text corrections (e.g., "Rheumatoid arthritis" → "Rheumatoid arthritis (disorder)"; "Encounter for problem" → "Encounter for problem (procedure)"; "Musculoskeletal care" → "Musculoskeletal care (regime/therapy)"). These align display text with the preferred SNOMED FSN format required by EU FHIR profiles.

------

### 18. Gout

**Module:** `gout.json` — modified in place (SNOMED display label corrections only)

Same pattern as rheumatoid arthritis: SNOMED-CT display text corrected to FSN format. No prevalence or probability changes.

------

### 19. Osteoporosis

**Module:** `osteoporosis.json` — modified in place

Minor correction: SNOMED value code for "At risk of osteoporosis" display label updated to "At increased risk of osteoporosis (finding)" (more precise SNOMED preferred term). No prevalence or probability changes.

------

### 20. Breast Cancer

**Module:** `breast_cancer.json` → `breast_cancer_europe.json`

Calibrated to ECIS/GLOBOCAN 2020 Europe data. Most common cancer in European women. Age-standardised incidence: ~92 per 100,000 women/year. Cumulative risk to age 75: ~7.4% (1 in 13 women). 5-year prevalence: ~1.4% of total population. 5-year survival (Eurocare-6): ~87%. Mammography screening modelled for 50–69 years (EU standard). Treatment follows ESMO Clinical Practice Guidelines (2023). The EU module replaces the previous `breast_cancer.json`; breast cancer sub-modules (`chemotherapy_breast.json`, `hormonetherapy_breast.json`, etc.) were retained and modified.

**Sources:** ECIS/IARC GLOBOCAN 2020 (Europe); ECDC Cancer Burden Report (2022); ESMO Breast Cancer Guidelines (2023); EU Council Recommendation on Cancer Screening (2022); Eurocare-6.

------

### 21. Colorectal Cancer

**Module:** `colorectal_cancer.json` → `colorectal_cancer_europe.json`

Second most common cancer in Europe by incidence. Age-standardised incidence: ~33 per 100,000 (both sexes); male ASR ~42, female ASR ~26. 5-year prevalence: ~0.7% of population. 5-year survival (Eurocare-6): ~62%. Screening (FIT/colonoscopy) modelled with EU country-variable coverage. Treatment follows ESMO CRC Guidelines (2023).

**Sources:** ECIS/IARC GLOBOCAN 2020 (Europe); ECDC Cancer Burden Report (2022); ESMO CRC Guidelines (2023); EU Council Recommendation on Cancer Screening (2022); GBD 2019 Europe; Eurocare-6.

------

### 22. Lung Cancer

**Module:** `lung_cancer.json` / `veteran_lung_cancer.json` → `lung_cancer_europe.json`

The veteran lung cancer module was removed entirely. EU calibration: age-standardised incidence ~36 per 100,000 (male ~48, female ~25 — rising). 5-year prevalence ~0.5% of population. NSCLC: ~85% / SCLC: ~15%. Stage at diagnosis: Stage III–IV in ~70% of cases (late detection). Treatment follows ESMO Lung Cancer Guidelines (2023).

**Sources:** ECIS/IARC GLOBOCAN 2020 (Europe); ECDC Cancer Burden Report (2022); ERS European Lung Cancer Report (2022); ESMO Lung Cancer Guidelines (2023); GBD 2019 Europe.

------

### 23. Prostate Cancer

**Module:** `veteran_prostate_cancer.json` removed → `prostate_cancer_europe.json`

The US module was a veteran-specific variant. EU module targets: most common male cancer in Europe, **prevalence 0.8–1.2%** (ECIS 2022). Treatment pathway: PSA screening, active surveillance for low-risk, radical prostatectomy or radiotherapy for intermediate/high risk, ADT (LHRH agonist) for advanced disease. 5-year survival ~89% all stages combined.

**Sources:** ECIS (2022); EAU Guidelines (2023).

------

### 24. Substance Use Disorders / Alcohol

**Module:** `opioid_addiction.json` + `prescribing_opioids_for_chronic_pain_and_treatment_of_oud.json` removed → `substance_use-europe.json`

The entire US opioid-centric substance use pipeline was replaced. The EU module shifts the distribution to reflect the European epidemiological reality where alcohol dominates substance disorder burden. Five bugs fixed in v2 (see below). Calibration references: WHO Europe and EMCDDA.

**Substance type distribution (v2 corrected):**

- Alcohol use disorder: 77.5%
- Cannabis use disorder: 15.3%
- Opioid use disorder: 4.1%
- Stimulant use disorder: 3.1%

**v2 bug fixes:**

1. `Substance_Type_Branch` probabilities summed to 288% (dead code for opioids/stimulants); corrected to sum to 100%
2. Alcohol complication check looped indefinitely (98.4% branch → infinite loop → 8% cirrhosis); fixed: 97.42% → `Terminal`
3. All substance types entered alcohol complication loop; fixed: `Check_If_Alcohol_Dependent` guard added
4. No `assign_to_attribute` after alcohol onset; fixed: `alcoholism = true` attribute set for downstream inter-module use (reads by stroke, AF modules)
5. `Alcohol_Cirrhosis` looped back allowing multiple onsets; fixed: → `Terminal`

*Calibration round 2:* AUD onset rates halved; annual complication rates reduced (cirrhosis 1.29 → 0.25%/yr). SNOMED codes corrected for alcoholic hepatitis (66870002 → 9953008) and pancreatitis (197456007 → 235498002).

**Target cohort prevalences:** Alcoholism ~7–8%; harmful alcohol use ~9–10%; liver cirrhosis ~0.15–0.25%; cannabis use disorder ~1.5%; opioid use disorder ~0.4%.

**Sources:** WHO Europe Alcohol and Health in the WHO European Region (2022); EMCDDA European Drug Report (2023); Eurostat EHIS Wave 3 (2019).

------

### 25. Sinusitis

**Module:** `sinusitis.json` — modified in place (multi-round calibration)

*Round 2 patch:* Monthly viral onset reduced 5× (0.9% → 0.18%/mo); bacterial/inflammation onset reduced 5× (0.05% → 0.01%/mo); chronic progression branch reduced from 15% → 5% to target GA²LEN EU chronic rhinosinusitis prevalence of ~10–11%.

*Round 3 patch:* `Chronic_Symptoms_Worsen` branch reverted 0.05 → 0.15 (its pre-round-2 value). Round 2 had over-corrected by cutting both monthly onset AND the chronic-progression branch simultaneously (~15× combined cut), collapsing chronic sinusitis to ~1%. The viral monthly onset reduction (0.18%/mo) was retained; only the progression branch was reverted. Expected chronic sinusitis prevalence post-round-3: ~5–7%.

**Sources:** GA²LEN European Chronic Rhinosinusitis Prevalence Study.

------

### 26. Sepsis

**Module:** `sepsis.json` — modified in place

*Calibration round 2 patch:* `ConditionEnd` states added (`End_Sepsis_Condition`, `End_Septic_Shock_Condition`, `End_ARDS_Condition`) before discharge. This fixes the acute-as-active classification issue in EPS problem lists (sepsis was persisting as an active condition post-discharge).

------

### 27. Hypothyroidism

**Module:** `hypothyroidism.json` → `hypothyroidism_europe_v2.json`

v1 produced ~16.7% female lifetime risk. v2 reduces annual incidence rates by ~55–60% across all age bands to achieve the EU reference target of **female ~7–8% lifetime prevalence, male ~1.5%**, overall adult prevalence ~3–5%.

| Sex    | Age band | Old rate/yr | New rate/yr |
| ------ | -------- | ----------- | ----------- |
| Female | 18–39    | 0.15%       | 0.06%       |
| Female | 40–59    | 0.35%       | 0.15%       |
| Female | 60+      | 0.40%       | 0.20%       |
| Male   | 18–59    | 0.05%       | 0.02%       |
| Male   | 60+      | 0.10%       | 0.05%       |

**Sources:** ETA 2023 European Thyroid Association data.

------

### 28. Obesity

**Module:** New — `obesity_europe.json`

No US equivalent for formal clinical obesity diagnosis. New module adds the clinical encounter, diagnosis code, and treatment for BMI ≥ 30. Target prevalence **15–20% EU adults** (WHO Europe 2022). EU treatment guidelines: lifestyle intervention, orlistat, GLP-1 agonists (semaglutide). Note: Synthea's internal BMI tracking is separate; this module adds the formal clinical diagnosis pathway.

**Sources:** WHO Europe (2022).

------

### 29. Allergies

**Module:** `allergies.json` + entire `allergies/` sub-folder (8 modules) removed → `allergy_europe.json`

The US allergy system (8 sub-modules for panel, drug/environmental/food incidence, immunotherapy, outgrow pathways, severe reaction) was replaced by a single consolidated EU module. Target: **25–35% EU population** affected by at least one allergic disease (EAACI 2022). EU-specific features: penicillin over-labelling, NSAID hypersensitivity, house dust mite dominance.

**Sources:** EAACI White Book (2022); EFA (2022); EAACI Food Allergy Guidelines (2014).

------

### 30. Diabetic Retinopathy

**Module:** New — `diabetic_retinopathy_treatment.json` + `eye/` sub-modules (4 new files)

No direct US equivalent in this module set. New diabetic eye complication pathway including intraocular pressure measurement, ophthalmic progression modelling, tomography, and visual acuity assessment. Linked to diabetes prevalence pathway. Eye sub-modules implement the "Eyes on FHIR" IG coding (HL7 Ophthalmology IG).

New metabolic syndrome sub-modules added: `metabolic_syndrome/diabetic_retinopathy_diagnoses.json` and `metabolic_syndrome/diabetic_retinopathy_progression.json` (replaced the US `metabolic_syndrome/eye_conditions.json`).

------

### 31. Adolescent Idiopathic Scoliosis (AIS)

**Module:** New — `AIS_From_School_Screening_to_SOSORT_Recommendations.json`

Entirely new module with no US equivalent. Models school-based scoliosis screening → SOSORT-guided treatment pathway for patients aged 10–18.

**Sources:** SOSORT 2016 Guidelines (Scoliosis and Spinal Disorders Research Society). Authors: Koubeh, Karakolah, Wahbeh. Version 2.0 (2026-01-24).

------

### 32. Haematological Oncology / Bone Marrow Transplant

**Module:** New — `bone_marrow_transplant.json` + `trigger_bone_marrow_transplant.json`

New transplant pathway for cancer and immune deficiency patients. `trigger_bone_marrow_transplant.json` acts as a router module. 60% autologous / 40% allogeneic split (based on 2017 study data). Post-transplant complications not modelled.

------

## Medication Modules — EU Adaptations

Several standalone medication modules were modified or added:

- **`moderate_opioid_pain_reliever.json`** and **`strong_opioid_pain_reliever.json`** — new top-level copies (previously only existed under `medications/` sub-folder); expose EU-available opioid formulations only
- **`otc_pain_reliever.json`** — new top-level copy calibrated for EU OTC availability (paracetamol-first, ibuprofen second; no OTC codeine per most EU national regulations)
- **`medications/hypertension_medication.json`** — modified to prefer ACE-I/ARB combinations per ESC/ESH 2018
- **`medications/statin.json`** — modified (EU formulary alignment)
- **`medications/emergency_inhaler.json`** and **`medications/maintenance_inhaler.json`** — modified (GINA 2022 stepwise alignment)

------

## Removed US-Specific Modules (no EU equivalent)

The following were removed without EU replacement, as they model care contexts absent or structurally different in Europe:

| Module(s)                                                    | Reason for removal                                           |
| ------------------------------------------------------------ | ------------------------------------------------------------ |
| `veteran.json` + all `veteran_*` modules (7 files)           | US Department of Veterans Affairs care system has no EU equivalent |
| `homelessness.json`                                          | US-specific SDOH module                                      |
| `encounter/sdoh_hrsn.json`                                   | US social determinants screening (HRSN)                      |
| `encounter/hark_screening.json`                              | US IPV screening protocol                                    |
| `encounter/depression_screening.json`                        | Replaced by rebuilt depression module                        |
| `home_hospice_snf.json` / `snf/skilled_nursing_facility.json` | US Skilled Nursing Facility structure; EU uses different post-acute care models |
| `home_health_treatment.json`                                 | US home health agency model                                  |
| `mend_program.json`                                          | US pediatric obesity program                                 |
| `wellness_encounters.json`                                   | US preventive care / annual wellness visit model             |
| `prescribing_opioids_for_chronic_pain_and_treatment_of_oud.json` | US opioid epidemic–specific module                           |
| `opioid_addiction.json`                                      | Replaced by `substance_use-europe.json`                      |
| `self_harm.json` / `veteran_self_harm.json`                  | US veteran-specific self-harm module                         |
| `total_joint_replacement.json`                               | Folded into EU osteoarthritis pathway                        |
| `metabolic_syndrome_care.json`                               | Replaced by integrated EU diabetes/CKD modules               |

------

------

# Known divergences between the records

This document and `synthea_eu_module_actions.md` are two accounts of the same
work, written at different times for different readers. Where they differ:

- **The R3 module counts** differ by counting basis, as set out above.
- **Module file names** differ in a few places: this document writes
  `atrial_fibrillation_europe-v2.json`, `stroke_europe-v2.json` and
  `substance_use-europe.json`, the action log writes the same files with
  underscores throughout. The tree is authoritative.
- **The cross-module SNOMED stroke inconsistency** — `230690007` in
  `hypertension_europe.json` and `outcomes.json` against `422504002` in
  `stroke_europe_v2.json` — was left unpatched deliberately in R3 and is
  resolved in R4 by removing the duplicate generators from
  `hypertension_europe.json`. The R3 text above still describes it as open.

------

*Maintained alongside `synthea_eu_module_actions.md` in the generation
repository, which carries the per-module action detail. Prevalences and
references cited in the disease sections are drawn from the `remarks` fields in
the module JSON.*
