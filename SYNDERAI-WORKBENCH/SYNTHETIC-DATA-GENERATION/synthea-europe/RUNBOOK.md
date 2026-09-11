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
| **40,000 patients** | **~30 min total** |
| Disk, claims excluded | ~130 KB/patient → ~12 GB peak (regions + merged) |
| Disk, full CSV | ~680 KB/patient → ~62 GB peak |

Validated end-to-end 2026-08-29 at `TOTAL=1500`: 291 regions OK, 0 failed, 0 skipped, 1,531 patients merged in 1,093 s.

## Tunables

| Variable | Default | Effect |
| -------- | ------- | ------ |
| `CSV_EXCLUDE` | `patient_expenses.csv,claims.csv,claims_transactions.csv` | `claims*` is 81 % of CSV volume and unused for prevalence work. Set to `""` to keep everything — then budget 62 GB for 40,000. |
| `KB_PER_PATIENT` | 135 (or 700 with no exclusions) | Only used by the disk precheck. |

```bash
CSV_EXCLUDE="" ./make_synthea_europe_filtered.sh 40000     # full CSV, needs ~62 GB
```

## Three things that were wrong before 2026-08-29

**Region weights summed to 1.409, not 1.** `-p 40000` produced ~56,400 patients. The script now reads its own `run_region` lines and normalises, so `TOTAL` means `TOTAL`.

**`./run_synthea` starts a Gradle build per region.** 291 Gradle+JVM startups per run, roughly 20 s each. The script builds the shadow jar once and now invokes it directly — about 80 minutes saved.

**No disk guard.** A 40,000-patient run with the full CSV export needs ~62 GB across `output/regions` and `output/merged`, which coexist. The precheck aborts with a clear message instead of filling the disk after hours of work.

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
