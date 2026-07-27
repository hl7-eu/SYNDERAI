# SNOMED CT Code Audit and Remediation — SYNDERAI EU Module Set

*Date of action: 27 July 2026. Module set: `new-synthea-EU-modules-20260523`, corrected to v3.*

------

## Summary

A full SNOMED CT audit of the SYNDERAI EU Synthea module set was carried out after a spot check revealed that a colorectal screening state carried a plausible display text attached to an identifier belonging to a different concept. All 1,666 SNOMED codings across 156 modules were extracted with their provenance and ranked by suspicion, then validated against the ART-DECOR terminology API in two complementary passes: a term round trip, which searches the display text and checks whether the shipped identifier is returned, and a direct code lookup, which retrieves the concept behind each identifier. The two passes answer different questions and neither is sufficient alone. The term pass exposed identifiers pointing at unrelated concepts, while the code pass established that most residue from the first pass consisted of valid identifiers whose labels simply do not match any designation the index carries. 

Of 862 distinct codes, 32 were found to be defective and were corrected across 13 modules. The defects were not superficial. Several identifiers denoted concepts wholly unrelated to their labels, including a foot computed tomography coded as brain magnetic resonance imaging in the stroke module, a laminectomy coded as mastectomy in the breast cancer module, and a detergent compound coded as cannabis dependence in the substance use module. Two of the corrected entries were themselves introduced as terminology corrections during calibration round 2, which means the round 2 patch traded one incorrect identifier for another while recording the matter as resolved. Three corrections initially applied on the strength of the term pass alone were subsequently reverted or refined once the code pass demonstrated that the term index carries false negatives, a limitation that constrains the confidence attachable to any single-source terminology check. Two further identifiers that resolve nowhere were replaced by manual assignment, and the faecal occult blood state was aligned to the immunoassay concept to match the module remark, which documents the faecal immunochemical test as the primary European screening instrument. The corrected module set validates as well-formed JSON in all 215 files, and every change is recorded with its supporting evidence in the accompanying change ledger.

------

## Corrections applied

Thirteen modules were modified. The full record, including the evidence behind each decision, is held in `SYNDERAI_snomed_change_ledger.csv`.

| Category                                                | Count |
| ------------------------------------------------------- | ----- |
| Identifier denoted an unrelated concept                 | 21    |
| Identifier did not resolve at all                       | 5     |
| Display text corrected, identifier retained             | 1     |
| Reverted after the code pass contradicted the term pass | 2     |
| Manual assignment for non-resolving identifiers         | 2     |
| Alignment of screening concept to module intent         | 1     |

Two decisions merit explicit note. In `mci_europe.json` the identifier `113024001` was retained rather than replaced, because the proposed substitute carried an extension namespace and the existing concept is semantically adequate; correcting the label was the more portable remedy. In `diabetes_europe.json` the state `DM2_Retinopathy` was restored to `4855003`, which denotes retinopathy due to diabetes mellitus, in preference to the generic retinopathy concept that an earlier correction had introduced. Consistency between code and label had been achieved at the cost of clinical precision, and the reverse trade was judged preferable.

------

## SNOMED codes with Namespaces

**The identifiers listed in this section are to be resolved.** Each carries an extension namespace rather than belonging to the SNOMED CT international core. Extension concepts are valid within the edition that defines them but are not guaranteed to be present on a terminology server carrying only the international release together with a European national extension. For a dataset intended for use in a European Health Data Space context, this constitutes a portability risk independent of whether the identifiers are correct. Resolution requires either substitution with an international core concept of equivalent meaning, or an explicit decision to declare the relevant extension as a dependency of the SYNDERAI package.

Twenty-three distinct identifiers are affected, spanning three namespaces.

*Note on counting: an earlier working figure of 32 counted identifier and module combinations rather than distinct identifiers. The distinct count is 23.*

#### Namespace `1000119` — 18 codes

| SCTID               | Display in module                                            | Module                                                       |
| ------------------- | ------------------------------------------------------------ | ------------------------------------------------------------ |
| `108631000119101`   | History of autologous bone marrow transplant (situation)     | `bone_marrow_transplant.json`                                |
| `10939881000119105` | Unhealthy alcohol drinking behavior (finding)                | `encounter/substance_use_screening.json`, `substance_use-europe.json` |
| `119481000119105`   | History of aortic valve repair (situation)                   | `heart/avrr/savrr_operation.json`, `vhd_aortic.json`         |
| `120991000119102`   | History of undergoing in utero procedure while a fetus (situation) | `spina_bifida.json`                                          |
| `1231000119100`     | History of aortic valve replacement (situation)              | `heart/avrr/savrr_operation.json`, `heart/tavr/operation.json`, `vhd_aortic.json` |
| `129721000119106`   | Acute renal failure on dialysis (disorder)                   | `heart/avrr/outcomes.json`, `heart/cabg/outcomes.json`, `heart/tavr/outcomes.json` |
| `132281000119108`   | Acute deep venous thrombosis (disorder)                      | `covid19/diagnose_blood_clot.json`, `covid19/end_outcomes.json` |
| `1501000119109`     | Proliferative diabetic retinopathy due to type II diabetes mellitus (disorder) | `diabetic_retinopathy_treatment.json`, `metabolic_syndrome/diabetic_retinopathy_diagnoses.json` |
| `152621000119105`   | History of allotransplantation of bone marrow (situation)    | `bone_marrow_transplant.json`                                |
| `153351000119102`   | History of peripheral stem cell transplant (situation)       | `bone_marrow_transplant.json`                                |
| `1551000119108`     | Nonproliferative diabetic retinopathy due to type II diabetes mellitus (disorder) | `diabetic_retinopathy_treatment.json`, `metabolic_syndrome/diabetic_retinopathy_diagnoses.json` |
| `157141000119108`   | Proteinuria due to type 2 diabetes mellitus (disorder)       | `metabolic_syndrome/kidney_conditions.json`                  |
| `16602611000119108` | Awaiting transplantation of lung (situation)                 | `cystic_fibrosis.json`                                       |
| `371361000119107`   | Comprehensive metabolic panel (procedure)                    | `uti/ed_bundle.json`                                         |
| `426701000119108`   | Ultrasonography of abdomen, right upper quadrant and epigastrium (procedure) | `gallstones.json`                                            |
| `60951000119105`    | Blindness due to type 2 diabetes mellitus (disorder)         | `metabolic_syndrome/diabetic_retinopathy_diagnoses.json`     |
| `90781000119102`    | Microalbuminuria due to type 2 diabetes mellitus (disorder)  | `metabolic_syndrome/kidney_conditions.json`                  |
| `97331000119101`    | Macular edema and retinopathy due to type 2 diabetes mellitus (disorder) | `metabolic_syndrome/diabetic_retinopathy_diagnoses.json`     |

#### Namespace `1000124` — 4 codes

| SCTID             | Display in module                       | Module                                   |
| ----------------- | --------------------------------------- | ---------------------------------------- |
| `428211000124100` | Assessment of substance use (procedure) | `encounter/substance_use_screening.json` |
| `439101000124101` | Easy to chew diet (regime/therapy)      | `injuries/broken_jaw.json`               |
| `439121000124106` | Pureed diet (regime/therapy)            | `injuries/broken_jaw.json`               |
| `453131000124105` | Videotelephony encounter (procedure)    | `uti/telemed_path.json`                  |

#### Namespace `1000004` — 1 code

| SCTID              | Display in module                               | Module               |
| ------------------ | ----------------------------------------------- | -------------------- |
| `8730001000004107` | Organism isolated in blood by culture (finding) | `uti/ed_bundle.json` |

The diabetic complication concepts in namespace `1000119` form the largest coherent group and originate from the inherited Synthea metabolic syndrome and retinopathy submodules rather than from the European calibration work. They are the natural first target for substitution, since several have international core equivalents that differ only in the explicit typing of the diabetes.

------

## Outstanding

1. `$validate-code` against a full SNOMED CT release, to establish active, inactive or retired status for all 862 distinct codes. No check performed to date distinguishes a live concept from a retired one.
2. Resolution of the 23 extension namespace identifiers listed above.
3. Review of `91251008` in `osteoarthritis_europe.json`, replaced by `722138006`. The original denotes "Physical therapy procedure" and the substitution was probably unnecessary, though harmless.
4. Correction of the module remarks in `substance_use-europe.json`, which still record the calibration round 2 terminology changes as resolved when both identifiers introduced by that patch were themselves defective.
