# Planned Future Activities
**Synthetic Data: Examples – Realistic – using AI (SYNDERAI)**, pronounced **/ˈsɪn.də.raɪ/**

© [HL7 Europe](https://hl7europe.org) | Main Contributor: Dr. Kai U. Heitmann | [Privacy Policy](https://hl7europe.eu/privacy-policy-for-hl7-europe/) • AGPL-3.0 license

This page lists what is ahead. The record of what has been done to the Synthea
module set, with the measured results of every calibration round, is in
[SUMMARY-EU-MODULES.md](SUMMARY-EU-MODULES.md); the earlier analysis of the US
baseline that used to sit here has moved there.

------

## Sustaining the public FHIR server

A publicly available FHIR® server carrying the most recent SYNDERAI packages is
in operation. <!-- TODO: endpoint URL and the package release it serves. The
site does not currently publish either, and neither is stated anywhere in the
repository, so the sentence above is deliberately left without them rather than
given a guessed address. -->

The server began as teaching infrastructure: in November 2025 it was set up for
project work by students at the [Hasso Plattner Institute](https://hpi.de) at
the University of Potsdam, where the European Patient Summary was the subject of
their semester tasks.

What remains open is operation beyond the current funding. The sponsoring
[xShare project](https://xshare-project.eu) ends later in 2026, so continued
hosting depends on partnering with supporting organisations and companies.
Fast continuous validation of the published packages against the EU
implementation guides is the second half of that question, since it needs the
same infrastructure.

------

## The next calibration round: calibrate against age-standardised prevalence

This is the largest single item ahead, and it changes the target rather than the
rates.

Every prevalence figure produced up to and including the 5.0 packages is a
**crude** prevalence over the generated cohort, whose mean age is 41. The
European references are adult-population figures. On a cohort younger than the
reference population, crude prevalence systematically understates anything
age-dependent, so a module can overshoot badly and still read as on target.
Standardising to the EU27 age structure changed the verdict on most
age-dependent conditions when it was first measured; the figures are in
[SUMMARY-EU-MODULES.md](SUMMARY-EU-MODULES.md#the-age-standardisation-finding).

The measurement side of this is largely built. `measure3.py` reports crude and
standardised side by side, takes the per-condition measurement basis and
reference population from its own table, and standardises against
`eu27-age-weights.csv`. Two traps were found and closed while building it, both
recorded in that file: a coarse open-ended 75+ band inflated dementia by 44 %
on its own, and scoping a reference as "% of adults" where it describes "% of
the total population" moved the same row by 46 points. The four mis-coded SNOMED
rows of the old validation table are corrected, and several rows now carry code
sets rather than single codes, which gives a hand-maintained equivalent of
subsumption rollup.

What remains, in order.

**Re-measure before tuning anything.** The priority list from the first
standardised measurement predates the band-width and scope corrections. Acting
on it now would be correcting from an estimate, which is the error this project
has already made twice. One run of the current script settles which rows are
actually out and by how much.

**Replace the four approximate weight bands with exact Eurostat values.** The
bands from 60–64 upward are exact EU27 shares, verified against Alzheimer Europe
2025 to three decimals. The bands 0–17, 18–29, 30–44 and 45–59 are approximate
Eurostat 2023 shares, as the file itself records. For a condition scoped to
adults those three adult bands carry 64 % of the total weight, so this is not a
cosmetic correction, and the banner the script prints on every run says as much.

**Then retune, on the figures that run produces**, using two measured
regularities rather than linear assumptions: prevalence rises roughly as
incidence to the power 0.74, not linearly, and a risk-tiered module moves when
the modules it reads move, so anything downstream of a changed module has to be
re-measured rather than assumed stable.

------

## Decisions owed before the next round

These are not tuning. Each needs a choice, and tuning before the choice is made
produces a number that satisfies nothing.

**Chronic sinusitis is targeted at two incompatible values inside SYNDERAI.**
The module remarks cite GA²LEN at 10–11 % and the validation table cites EPOS at
1.2 %. That is a factor of nine.

**The validation table's two dementia rows overlap.** ICD-10 F00 *is* Alzheimer
dementia, so "Alzheimer disease G30" at 1.5 % and "Dementia F00–F03" at 2.0 %
cannot both be met at the measured 65 % Alzheimer share. The subtype row should
be retired or redefined as all-dementia.

**The European conception rate has to be chosen.** `female_reproduction.json`
carries the US Synthea default of 19.3 % conception per reproductive cycle,
which produces 5.57 pregnancies per ever-pregnant woman against a European
figure of about 2.5 to 3 including losses. Setting a European rate means
choosing a target total fertility rate — 1.1 to 1.8 across the EU27 — and a
contraceptive-prevalence mix, and it cascades through births, prenatal care and
the whole obstetric pathway.

**Whether "history of" substitution should exist at all.** Every Condition
produced through the EPS history-of mapping already carries
`clinicalStatus = inactive`, which is the FHIR-native way to say the same thing.
Substituting a "history of" concept on top is redundant double-encoding, and it
is what let four wrong codes into a published package. Dropping it would remove
the class of defect permanently.

------

## Validation infrastructure

**Validate by SNOMED subsumption, not exact code match.** The EU modules code
site- and subtype-specifically: hip and knee osteoarthritis rather than
`396275006`, STEMI and NSTEMI alongside myocardial infarction, generalised
anxiety and panic disorder rather than `197480006`. Exact-match validation
reports these as missing. Any future validation has to roll up via `<<code`
descendant sets.

**Correct the four mis-coded rows of the validation table** before it is used as
a baseline again. They are listed in the summary; each one queried a valid
SNOMED concept that does not mean what its row label said.

**A distributional measure over the full code set** — rather than per-condition
deviation on a selected list — is still open. It was originally planned as a
Kullback-Leibler divergence against a reference distribution and replaced in R4
by direct per-condition deviation, which is easier to act on but says nothing
about the shape of the whole.

------

## Coverage gaps in the module set

**Eurostat life tables** still have to replace the US mortality assumptions.
European cause-of-death distributions differ from the US pattern, cardiovascular
disease dominating earlier, and mortality feeds every comorbidity chain.

**Brain and CNS tumours**, roughly 2 % of European cancer incidence, are a
documented omission. SNOMED's generic `126952004` is "Neoplasm of brain", which
is not malignancy-specific, and no unambiguous malignant rollup has been chosen.

**Tick-borne encephalitis, occupational disease** and other region-specific
conditions remain unmodelled.

------

## Laboratory values and reference ranges

Work on the generator rather than on the modules, arising from the validation of
the 5.0 packages.

**Reference ranges should come from a cited table, not from a language model.**
The intervals are reproducible since October 2026 — one interval per LOINC code,
unit, sex and age band — but they are not citable, and reproducible is not the
same as right. The concept is a static table under `MAPPINGS`, LOINC by sex by
age band with a source per row, and the model kept only as a logged fallback. A
measured coverage curve exists for staging the table: 30 codes cover 88 % of the
reference ranges in an EPS corpus and 62 % in a laboratory corpus, 50 codes cover
96 % and 85 %.

**Therapeutic ranges need the patient's medication list.** A table over LOINC,
sex and age cannot express that an INR under vitamin K antagonist therapy is read
against a target of 2.0 to 3.0 rather than against 0.9 to 1.2. Until that rule
exists, every range is published as a population interval and says so.

**The reference range should follow the age at the measurement.** It currently
follows the patient's age when the corpus was generated, so a laboratory series
spanning years carries one interval throughout. For an adult series the error is
mostly invisible; for a paediatric measurement in an adult patient's history it
is not.

**Laboratory values lose their decimals in the CSV export.** Synthea's CSV
exporter formats every numeric observation to one decimal place, which is a
display convention in the wrong place: its own FHIR exporter keeps five
significant digits. A specific gravity of urine then reads 1 against a reference
range of 1.005 to 1.03. The fix needs a per-LOINC precision table on the
SYNDERAI side first, then the exporter change, in that order.

------

## Conformance backlog

The validation run of release 4.0.1 produced a ticket list that is still open,
and one item on it is worth naming here because of its size: `Encounter.status`
is written as `completed`, which is not a FHIR R4 encounter status. One word,
and it accounts for 1008 of that run's errors. Four non-UCUM unit spellings on
observation values are the next largest group and are unchanged in 5.0.0.
