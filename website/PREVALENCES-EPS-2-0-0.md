# SynderAI EPS Cohort vs. US Adult Prevalences

The cohort data extracted from the fragment:

- **47 lifetime conditions** with prevalences from 2% (asthma, hypothyroidism) to 56% (hypertension)
- N=50, mean age 56

US references gathered from NHANES 2021–2023 (CDC), AHA 2024 Statistics, and NIH sources.

*All US reference figures are for the general adult population (≥18 or ≥20 years) from NHANES 2021–2023, AHA/CDC 2024 unless noted. The cohort has mean age 56, which skews chronic disease prevalence upward.*

Here's the full analysis.

### ✅ Plausible / Good Fit

| Condition                             | Cohort        | US Reference                  | Notes                                                        |
| ------------------------------------- | ------------- | ----------------------------- | ------------------------------------------------------------ |
| Essential hypertension                | 56%           | 47.7% (NHANES 2021–23)        | Age 40–59 group: 52.5%; 60+: 71.6% — 56% fits a mean-age-56 cohort well |
| Diabetes mellitus type 2              | 16%           | ~12% diagnosed / 15.8% total  | The prevalence of total diabetes was 15.8% in all adults, diagnosed 11.3% — cohort sits right in that range |
| Sleep disorder                        | 14%           | ~14–15%                       | Consistent with US self-reported insomnia/sleep disorder rates |
| Gout                                  | 4%            | ~4%                           | Classic US prevalence, well-calibrated                       |
| Osteoporosis                          | 14%           | 12–13% in adults ≥50          | Slightly elevated but correct direction for age group        |
| Obstructive sleep apnea               | 10%           | 10–30% (definition-dependent) | Low end of the US range, acceptable                          |
| CKD (all stages)                      | ~22% combined | ~15% of US adults             | Elevated but cohort includes diabetic nephropathy subcohort; consistent with T2DM complication cascade |
| COPD / chronic obstructive bronchitis | 4%            | ~5–6%                         | Slightly low but within margin for a non-smoking-focused dataset |
| Recurrent UTI                         | 12%           | ~10–14% in women              | Gender mix (52F/48M) makes this reasonable                   |
| Metabolic syndrome                    | 44%           | ~35% (NHANES)                 | Elevated but consistent with a cardiovascularly-heavy, middle-aged cohort |

------

### 🔴 Significantly Overrepresented vs. US

**Anemia — 52% cohort vs. ~9% US** ← the biggest outlier by far

During August 2021–August 2023, the overall prevalence of anemia was 9.3% in people age 2 and older; highest in adults 60 and older at 12.5%. The cohort's 52% is roughly **5–6× the real rate**. This is a known Synthea artifact: anemia is generated as a downstream complication of CKD, heart failure, and other conditions, and each pathway independently adds an anemia entry, causing it to pile up in multimorbid patients. In a real EPS problem list, many of these would be the same episode of care or recorded at condition level only once.

**Ischemic heart disease — 32% cohort vs. ~7–8% US**

Prevalences of coronary disease currently sit around 7.8%, projected to rise to 9.2% by 2050. At 32% the cohort is **~4× overrepresented**. The related STEMI (10%) also runs high — US adult history of MI sits around 3–4%. This pattern — cardio-heavy — is characteristic of the original Synthea US calibration, where the cardiovascular module was seeded to a high-CV US reference population.

**Fibromyalgia — 6% cohort vs. ~2% US**

US adult fibromyalgia prevalence is consistently around 2%. Three times the expected rate, likely reflecting Synthea's tendency to attach this diagnosis to patients with chronic pain pathways alongside opioid prescriptions.

**Alzheimer's disease — 8% cohort vs. ~2–3% all adults**

For all US adults the figure is ~2–3%; it rises to ~11% for adults ≥65. With a mean cohort age of 56, 8% is too high unless a significant portion of patients are in their 70s–80s (which the age histogram does show). This one is borderline — worth flagging but not a clear error.

**Sepsis — 8% cohort vs. < 1% point prevalence**

Sepsis as an *active, ongoing chronic condition* in a problem list is epidemiologically unusual. In the US, sepsis incidence is about 1.7 million hospitalizations/year (~0.5% of adults annually); it doesn't persist as an active chronic diagnosis. This reflects how Synthea records acute hospitalisation events permanently in the problem list, which doesn't match EPS intent.

------

### 🟡 Significantly Underrepresented vs. US

**Hyperlipidemia — 18% cohort vs. ~45–53% US**

This is the starkest underrepresentation. US hypercholesterolaemia/hyperlipidemia affects around half of adults. The cohort's 18% likely reflects that Synthea models hyperlipidemia only as a named condition when it crosses specific thresholds and triggers a condition state, while in clinical practice virtually every cardiovascular patient carries this coded diagnosis. Combined with hypertriglyceridemia (14%), you reach ~32% — still well below reality.

**Asthma — 2% cohort vs. ~8% US**

Current asthma is one of the major tracked chronic conditions in the US, with ~8% adult prevalence. At 2%, the cohort appears to have asthma generation suppressed or rare in the EU-calibration module (asthma prevalence in Western Europe is indeed somewhat lower, 5–6%, but 2% is still low even by EU standards).

**Hypothyroidism — 2% cohort vs. ~5% US**

US adult hypothyroidism (mostly Hashimoto's) sits around 4–5%. At 2% the cohort is roughly half the expected rate.

------

### Summary verdict

The cohort fits real US epidemiology reasonably well for the **cardiometabolic core** (hypertension, diabetes, CKD staging, sleep disorders, gout). The structural weaknesses follow the two known Synthea generator patterns:

1. **Complication cascade inflation**: anemia, sepsis and to a lesser extent Alzheimer's are over-generated because each disease pathway independently fires the complication condition, creating implausible prevalences in a multimorbid cohort.
2. **Module coverage gaps**: hyperlipidemia (critically underrepresented) and asthma are either not triggered frequently enough or modelled with narrow phenotype criteria.

The ischemic heart disease inflation (4×) is consistent with Synthea having been originally calibrated to a high-CV US reference, and the EU recalibration not having fully corrected the cardiovascular module's base rates downward. For an EPS designed to demonstrate EU FHIR profiles, the cardiovascular domain likely warrants an additional prevalence cap.