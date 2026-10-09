# SYNDERAI — European generation run

`make_synthea_europe_filtered.sh` generates a European cohort across 291 NUTS-style regions, weighted by population share, then merges the per-region CSV output.

```bash
cd synthea-europe
./make_synthea_europe_filtered.sh 40000
```

Output: `output/regions/<Region>/csv/` per region, merged into `output/merged/csv/`.

## What it does

1. Stops Gradle, wipes `build/` and `.gradle/`, rebuilds the shadow jar so `src/main/resources/modules` is actually what runs.
2. Verifies the jar contains the R4 module set, that every `CallSubmodule` target resolves, and that `Diagnose_Knee_OA` still has `239873007` as `codes[0]`. Aborts before generating if any check fails.
3. Checks free disk against the requested population.
4. Runs each region, then merges.

## Measured behaviour

| | |
| --- | --- |
| Regions | 291, all backed by `zipcodes_europe.csv` and `demographics_europe.csv` |
| Fixed cost | ~4 s per region (JVM + Synthea init) → ~20 min for 291 regions |
| Generation | ~66 patients/s |
| **40,000 patients** | **~35 min total** |
| Records written per living patient | **1.31** (see below) |
| Disk, claims excluded | ~139 KB/record → **~15 GB peak** (regions + merged) |
| Disk, full CSV | ~700 KB/record → ~78 GB peak |

Validated end-to-end 2026-08-29 at `TOTAL=1500`: 291 regions OK, 0 failed, 0 skipped, 1,531 patients merged in 1,093 s. Re-measured 2026-10-02 over four runs at `TOTAL=40000`: 291/291 regions, 52,322 to 53,501 records each, about 40,000 of them living.

### `-p N` asks for N LIVING patients, and the file holds more

Synthea replaces anyone who dies before the reference date, so the deceased are written out on top of the N living. Since `lifecycle.death_by_natural_causes` was switched on (2026-10-01) that overhead stopped being negligible:

| | records written | living | ratio | merged CSV at `-p 40000` |
| --- | --- | --- | --- | --- |
| before | 41,830 | 39,466 | 1.06 | 4.9 GB |
| after | 52,460 | 40,097 | **1.31** | **7.0 GB** |

Two consequences. **Budget disk against the record count, not the requested count** — the precheck now carries the ratio, and the figures in the table above already include it. And **consumers that need living patients only** (European Patient Summary, Hospital Discharge Report, laboratory reports, medication) must filter on an empty `DEATHDATE`: that filter now removes 24 % of the records rather than 6 %, so a consumer that quietly omitted it would have gone unnoticed before and will not now.

## Tunables

| Variable | Default | Effect |
| -------- | ------- | ------ |
| `CSV_EXCLUDE` | `patient_expenses.csv,claims.csv,claims_transactions.csv` | `claims*` is 81 % of CSV volume and unused for prevalence work. Set to `""` to keep everything — then budget 78 GB for 40,000. |
| `KB_PER_PATIENT` | 182 (or 920 with no exclusions) | Only used by the disk precheck. It is per REQUESTED patient, so it carries the 1.31 record-to-living ratio: 139 KB per record x 1.31. |

```bash
CSV_EXCLUDE="" ./make_synthea_europe_filtered.sh 40000     # full CSV, needs ~78 GB
```

## Three things that were wrong before 2026-08-29

**Region weights summed to 1.409, not 1.** `-p 40000` produced ~56,400 patients. The script now reads its own `run_region` lines and normalises, so `TOTAL` means `TOTAL`.

**`./run_synthea` starts a Gradle build per region.** 291 Gradle+JVM startups per run, roughly 20 s each. The script builds the shadow jar once and now invokes it directly — about 80 minutes saved.

**No disk guard.** A 40,000-patient run with the full CSV export needs ~78 GB across `output/regions` and `output/merged`, which coexist. The precheck aborts with a clear message instead of filling the disk after hours of work.

## Deceased patients are included — deliberately

`generate.only_alive_patients` used to be defined **twice** in `src/main/resources/synthea.properties` (`false` at line 175, `true` further down). Java Properties takes the last, so `true` was running while the first line said the opposite. The duplicate has been removed; there is now one definition, `false`.

Measured on Nordrhein-Westfalen, 6,000 patients, seed 42, nothing else changed:

| | with deceased | living only |
| --- | --: | --: |
| Median absolute deviation | 15 % | 14 % |
| Within ±25 % | 30/38 | 29/38 |
| **Heart failure** | **+10 %** | **−89 %** |

Excluding the deceased costs nothing overall and destroys exactly one row. Synthea does not simply omit them: it re-rolls any patient who dies, so someone who would have developed heart failure at 70 and died at 78 is replaced by someone who did not — survivor bias in the *living* cohort.

Consumers needing a living cohort (EPS, HDR, laboratory, medication) should filter on an empty `DEATHDATE` in `patients.csv`.

## Calibration status

The module set carries the R4 work verified in `../Conditions-Prevalences/R4-VERIFICATION.md`.

**Production run, 2026-08-29:** 41,637 patients (39,990 living, 1,647 deceased) across all 291 regions, 291 OK / 0 failed / 0 skipped, 1,573 s. Measured against the European references: **median absolute deviation 13 %**, 28 of 38 rows within ±25 %, 37 of 38 within ±60 %, none 100 % or more off. That is better than the verification runs (16 %), so the calibration transfers to European geography and to twice the scale.

Measure any output with:

```bash
python3 ../R4-verification/measure3.py <reference_dir> output/merged
```

where both arguments are directories containing `csv/conditions.csv`.
