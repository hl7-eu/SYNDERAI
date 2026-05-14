#!/usr/bin/env python3
"""
synderai_eps_medication_dashboard.py
─────────────────────────────────────
Parse a SynderAI EPS FHIR package and emit a self-contained HTML **fragment**
(no <html>/<head>/<body>) ready to be embedded inside the SynderAI site.

Covers MedicationStatement + Medication resources only.
For Condition statistics use  synderai_eps_dashboard.py
For CarePlan statistics use   synderai_eps_careplan_dashboard.py

The fragment uses the existing SynderAI CSS (styles.css + Inter + MDI font)
and adds only the minimal inline styles needed for the charts.

Usage
─────
  python3 synderai_eps_medication_dashboard.py --package ./package
  python3 synderai_eps_medication_dashboard.py --package ./package \\
          --out eps_medication_dashboard.html

Author  : SynderAI / HL7 Europe
License : AGPL-3.0
"""

import argparse
import glob
import json
import re
import statistics
import html as _html
from collections import Counter, defaultdict
from pathlib import Path


# ── Substance name normalisation ──────────────────────────────────────────────
# Extract an INN/common name from a full product display string.
# Priority: KNOWN_SUBSTANCES prefix match → regex strip of strength/form.

STRENGTH_RE = re.compile(
    r"\s+\d[\d,./]*\s*"
    r"(mg|microgram|mcg|unit|mL|g|%|hour|actuat|microgram/hour|"
    r"microgram/mL|mg/mL|mg/actuat|unit/mL|microgram/actuat)"
    r".*",
    re.IGNORECASE,
)

# Maps lowercase display prefix → clean INN/brand label
KNOWN_SUBSTANCES: list[tuple[str, str]] = [
    ("human isophane insulin",                          "Insulin (biphasic)"),
    ("acetaminophen 300 mg and hydrocodone",            "Acetaminophen / Hydrocodone"),
    ("acetaminophen 325 mg and oxycodone",              "Acetaminophen / Oxycodone"),
    ("acetaminophen 300 mg and codeine",                "Acetaminophen / Codeine"),
    ("hydrocodone bitartrate",                          "Hydrocodone / Ibuprofen"),
    ("chlorpheniramine maleate",                        "Chlorpheniramine / Ibu / Pseudo"),
    ("donepezil",                                       "Donepezil / Memantine"),
    ("hydrochlorothiazide 25 mg and losartan",          "Hydrochlorothiazide / Losartan"),
    ("60 actuat fluticasone",                           "Fluticasone / Salmeterol (DPI)"),
    ("120 actuat fluticasone",                          "Fluticasone (MDI)"),
    ("nda021457",                                       "Albuterol (MDI)"),
    ("nda020983",                                       "Albuterol (MDI)"),
    ("camila",                                          "Camila (norgestrel)"),
    ("vitamin b12",                                     "Vitamin B12"),
    ("human isophane",                                  "Insulin (biphasic)"),
]

# INN consolidation: maps normalised label → canonical INN
# (merges different strengths/salt forms of the same active substance)
INN_CONSOLIDATE: dict[str, str] = {
    "Albuterol sulfate":          "Albuterol",
    "Albuterol (MDI)":            "Albuterol",
    "Albuterol (MDI – ProAir)":   "Albuterol",
    "Albuterol (MDI – Ventolin)": "Albuterol",
    "Metoprolol tartrate":        "Metoprolol",
    "Metoprolol succinate":       "Metoprolol",
    "Alendronic acid":            "Alendronic acid (bisphosphonate)",
    "Ferrous sulfate":            "Ferrous sulfate (iron)",
    "Lisinopril":                 "Lisinopril (ACE-I)",
    "Losartan potassium":         "Losartan (ARB)",
    "Labetalol hydrochloride":    "Labetalol (alpha/beta-blocker)",
    "Carvedilol":                 "Carvedilol (beta-blocker)",
    "Prasugrel":                  "Prasugrel (P2Y12)",
    "Clopidogrel":                "Clopidogrel (P2Y12)",
    "Nitroglycerin":              "Nitroglycerin (nitrate)",
    "Galantamine":                "Galantamine (AChEI)",
    "Tacrolimus":                 "Tacrolimus (immunosupp.)",
    "Nicotine":                   "Nicotine (cessation)",
    "Levothyroxine sodium anhydrous": "Levothyroxine",
    "Fexofenadine hydrochloride": "Fexofenadine",
    "Diphenhydramine hydrochloride": "Diphenhydramine",
    "Naproxen sodium":            "Naproxen",
    "Tramadol hydrochloride":     "Tramadol",
    "Metformin hydrochloride":    "Metformin",
    "Oxycodone hydrochloride":    "Oxycodone",
    "Budesonide":                 "Budesonide (ICS)",
    "Fluticasone propionate":     "Fluticasone (nasal)",
    "Fluticasone / Salmeterol (DPI)": "Fluticasone / Salmeterol",
    "Fluticasone (MDI)":          "Fluticasone / Salmeterol",
}


def substance_name(display: str) -> str:
    """Return a clean INN / substance label from a product display string."""
    if not display:
        return "Unspecified"
    dl = display.lower().strip()
    # Prefix match
    for prefix, name in KNOWN_SUBSTANCES:
        if dl.startswith(prefix):
            return INN_CONSOLIDATE.get(name, name)
    # Remove parenthetical salt forms, then strip strength/form
    cleaned = re.sub(r"\(as [^)]+\)", "", display).strip()
    cleaned = STRENGTH_RE.sub("", cleaned).strip()
    return INN_CONSOLIDATE.get(cleaned, cleaned)


# ── ATC-inspired therapeutic classification ───────────────────────────────────

ATC_RULES: dict[str, list[str]] = {
    "Cardiovascular":          [
        "lisinopril", "amlodipine", "metoprolol", "carvedilol", "labetalol",
        "nitroglycerin", "hydrochlorothiazide", "furosemide", "losartan",
        "clopidogrel", "prasugrel", "aspirin", "epinephrine",
        "hydrochlorothiazide / losartan",
    ],
    "Lipid-lowering":          ["simvastatin", "atorvastatin", "rosuvastatin"],
    "Metabolic / Endocrine":   ["metformin", "insulin", "levothyroxine"],
    "Analgesic / Opioid":      [
        "oxycodone", "fentanyl", "tramadol", "hydrocodone",
        "acetaminophen", "naproxen", "ibuprofen", "codeine",
    ],
    "Respiratory":             [
        "albuterol", "fluticasone", "salmeterol", "budesonide",
        "fexofenadine", "diphenhydramine",
    ],
    "Neurological":            ["galantamine", "donepezil", "memantine"],
    "Musculoskeletal / Bone":  ["alendronic"],
    "Haematinic / Nutritional": ["ferrous", "vitamin b", "iron"],
    "Immunosuppressant":       ["tacrolimus"],
}

ATC_COLORS: dict[str, str] = {
    "Cardiovascular":          "#f87171",
    "Lipid-lowering":          "#fb923c",
    "Metabolic / Endocrine":   "#c084fc",
    "Analgesic / Opioid":      "#f472b6",
    "Respiratory":             "#60a5fa",
    "Neurological":            "#a78bfa",
    "Musculoskeletal / Bone":  "#fbbf24",
    "Haematinic / Nutritional":"#34d399",
    "Immunosuppressant":       "#818cf8",
    "Other / Unclassified":    "#94a3b8",
}

FREQ_LABELS: dict[tuple, str] = {
    (1,  1,   "d"):   "Once daily",
    (2,  1,   "d"):   "Twice daily",
    (3,  1,   "d"):   "Three times daily",
    (4,  1,   "d"):   "Four times daily",
    (6,  1,   "d"):   "Six times daily",
    (1,  4,   "h"):   "Every 4 hours",
    (1,  6,   "h"):   "Every 6 hours",
    (1,  72,  "h"):   "Every 72 hours",
    (1,  5,  "min"):  "PRN / SOS (angina)",
    (3,  15, "min"):  "3× / 15 min (acute)",
}


def atc_class(subst: str) -> str:
    sl = subst.lower()
    for cls, kws in ATC_RULES.items():
        if any(kw in sl for kw in kws):
            return cls
    return "Other / Unclassified"


# ── FHIR bundle parsing ───────────────────────────────────────────────────────

def parse_bundles(package_dir: str):
    files = sorted(glob.glob(f"{package_dir}/Bundle-*.json"))
    if not files:
        raise FileNotFoundError(f"No Bundle-*.json files in '{package_dir}'")

    all_meds:  list[dict] = []
    patients:  list[dict] = []

    for bf in files:
        with open(bf, encoding="utf-8") as fh:
            bundle = json.load(fh)

        # Index Medication resources
        meds_by_uuid: dict[str, dict] = {}
        for entry in bundle.get("entry", []):
            r = entry.get("resource", {})
            if r.get("resourceType") == "Medication":
                uid      = entry.get("fullUrl", "").replace("urn:uuid:", "")
                codings  = r.get("code", {}).get("coding", [])
                meds_by_uuid[uid] = dict(
                    display = (
                        codings[0].get("display", r.get("code", {}).get("text", ""))
                        if codings else r.get("code", {}).get("text", "")
                    ),
                    code   = codings[0].get("code",   "") if codings else "",
                    system = codings[0].get("system", "") if codings else "",
                )

        patient_name = gender = dob = None
        for entry in bundle.get("entry", []):
            r = entry.get("resource", {})
            if r.get("resourceType") == "Patient":
                n = r.get("name", [{}])[0]
                patient_name = (
                    " ".join(n.get("given", [])) + " " + n.get("family", "")
                ).strip()
                gender = r.get("gender")
                dob    = r.get("birthDate")
                break

        bundle_med_count = 0
        for entry in bundle.get("entry", []):
            r = entry.get("resource", {})
            if r.get("resourceType") != "MedicationStatement":
                continue

            med_ref  = r.get("medicationReference", {})
            med_uid  = med_ref.get("reference", "").replace("urn:uuid:", "")
            med_info = meds_by_uuid.get(med_uid, {})
            display  = med_ref.get("display", "") or med_info.get("display", "")
            code     = med_info.get("code",   "")
            system   = med_info.get("system", "")
            subst    = substance_name(display)
            atc      = atc_class(subst)

            reasons: list[dict] = []
            for rc in r.get("reasonCode", []):
                rcodings = rc.get("coding", [])
                if rcodings:
                    reasons.append(dict(
                        display = rcodings[0].get("display", ""),
                        code    = rcodings[0].get("code",    ""),
                    ))

            d0 = r.get("dosage", [{}])[0] if r.get("dosage") else {}
            timing = d0.get("timing", {}).get("repeat", {})
            dar    = (d0.get("doseAndRate") or [{}])[0]
            dq     = dar.get("doseQuantity", {}) or {}

            freq        = timing.get("frequency")
            period      = timing.get("period")
            period_unit = timing.get("periodUnit", "")
            freq_label  = FREQ_LABELS.get(
                (freq, period, period_unit), "Other / Unknown"
            )

            all_meds.append(dict(
                display      = display,
                substance    = subst,
                code         = code,
                system       = system,
                atc          = atc,
                status       = r.get("status", "unknown"),
                patient      = patient_name,
                reasons      = reasons,
                dosage_text  = d0.get("text", ""),
                freq         = freq,
                period       = period,
                period_unit  = period_unit,
                freq_label   = freq_label,
                dose_val     = dq.get("value"),
                dose_unit    = dq.get("unit", ""),
            ))
            bundle_med_count += 1

        patients.append(dict(
            name      = patient_name,
            gender    = gender,
            dob       = dob,
            med_count = bundle_med_count,
        ))

    return all_meds, patients


# ── Statistics builder ────────────────────────────────────────────────────────

def build_stats(all_meds: list[dict], patients: list[dict]) -> dict:
    n = len(patients)

    # ── Substance-level unique-patient counts ──
    subst_patients: dict[str, set] = defaultdict(set)
    subst_code:     dict[str, str] = {}
    for m in all_meds:
        if m["substance"] and m["substance"] != "Unspecified":
            subst_patients[m["substance"]].add(m["patient"])
            if m["code"]:
                subst_code[m["substance"]] = m["code"]

    substances = sorted(
        [
            dict(
                name      = subst,
                n_patients= len(pts),
                pct       = round(len(pts) / n * 100),
                atc       = atc_class(subst),
                code      = subst_code.get(subst, ""),
            )
            for subst, pts in subst_patients.items()
        ],
        key=lambda x: -x["n_patients"],
    )

    # ── ATC totals (by entry count) ──
    atc_totals: dict[str, int] = defaultdict(int)
    for m in all_meds:
        atc_totals[m["atc"]] += 1

    # ── Dosing frequency distribution ──
    freq_counter = Counter(m["freq_label"] for m in all_meds)

    # ── Reason conditions ──
    all_reasons: list[dict] = []
    for m in all_meds:
        all_reasons.extend(m["reasons"])
    reason_counter  = Counter(r["display"] for r in all_reasons)
    reason_code_map = {r["display"]: r["code"] for r in all_reasons}

    trigger_conditions = [
        dict(
            display = disp,
            code    = reason_code_map.get(disp, ""),
            count   = cnt,
            pct     = round(cnt / n * 100),
        )
        for disp, cnt in reason_counter.most_common(12)
        if disp
    ]

    # ── Per-patient burden ──
    pp_counts = [p["med_count"] for p in patients]

    return dict(
        n_patients          = n,
        n_total             = len(all_meds),
        n_substances        = len(substances),
        n_active            = sum(1 for m in all_meds if m["status"] == "active"),
        avg_per_patient     = round(statistics.mean(pp_counts), 1),
        max_per_patient     = max(pp_counts),
        substances          = substances,
        atc_totals          = dict(atc_totals),
        freq_distribution   = dict(freq_counter.most_common()),
        trigger_conditions  = trigger_conditions,
        pp_dist             = dict(sorted(Counter(pp_counts).items())),
        n_snomed            = sum(1 for m in all_meds if "snomed" in m["system"]),
        n_rxnorm            = sum(1 for m in all_meds if "rxnorm" in m["system"]),
    )


# ── HTML fragment builder ─────────────────────────────────────────────────────

def build_html(stats: dict, version_str: str) -> str:
    substances   = stats["substances"]
    atc_totals   = stats["atc_totals"]
    atc_total_n  = sum(atc_totals.values())
    max_pts      = substances[0]["n_patients"] if substances else 1
    trigger      = stats["trigger_conditions"]
    trig_max     = trigger[0]["count"] if trigger else 1
    freq_dist    = stats["freq_distribution"]
    freq_total   = sum(freq_dist.values())

    # ── JS payloads ──
    js_substances = json.dumps(substances)
    js_atc = json.dumps(
        [dict(label=k, value=v)
         for k, v in sorted(atc_totals.items(), key=lambda x: -x[1])]
    )
    js_trigger = json.dumps(trigger)
    js_colors  = json.dumps(ATC_COLORS)
    js_freq    = json.dumps(
        [dict(label=k, count=v, pct=round(v / freq_total * 100))
         for k, v in freq_dist.items()]
    )

    # ── KPI cards ──
    active_pct = round(stats["n_active"] / stats["n_total"] * 100)
    kpis = [
        ("Medication entries",    str(stats["n_total"]),          "#60a5fa",  ""),
        ("Unique substances",     str(stats["n_substances"]),      "#c084fc",  ""),
        ("Patients covered",      f"{stats['n_patients']}/50",     "#34d399",  ""),
        ("Avg. meds / patient",   str(stats["avg_per_patient"]),   "#f07086",  ""),
        ("Max meds (1 patient)",  str(stats["max_per_patient"]),   "#fb923c",  ""),
        ("Active statements",     f"{active_pct}%",                "#f87171",  "active"),
        ("SNOMED CT coded",       str(stats["n_snomed"]),          "#fbbf24",  "SCT"),
    ]
    kpi_html = ""
    for label, val, col, tag in kpis:
        tag_html = (
            f'<span class="sd-kpi-tag">{_html.escape(tag)}</span>' if tag else ""
        )
        kpi_html += f"""
      <div class="sd-kpi example-item">
        {tag_html}
        <div class="sd-kpi-value" style="color:{col}">{_html.escape(val)}</div>
        <div class="sd-kpi-label">{_html.escape(label)}</div>
      </div>"""

    # ── Chip filters ──
    atc_classes = sorted({s["atc"] for s in substances})
    chips_html  = (
        '<button class="sd-chip active" data-cat="All" '
        'style="border-color:rgba(255,255,255,.25);color:#cfcfcf">All</button>'
    )
    for cls in atc_classes:
        col = ATC_COLORS.get(cls, "#94a3b8")
        chips_html += (
            f'<button class="sd-chip" data-cat="{_html.escape(cls)}" '
            f'style="border-color:{col};color:{col}">{_html.escape(cls)}</button>'
        )

    # ── Frequency visual ──
    freq_items_html = ""
    freq_colors = [
        "#34d399","#60a5fa","#c084fc","#fb923c",
        "#f87171","#fbbf24","#f472b6","#a78bfa","#94a3b8","#818cf8",
    ]
    for i, (k, v) in enumerate(freq_dist.items()):
        col = freq_colors[i % len(freq_colors)]
        w   = round(v / freq_total * 100)
        pct = round(v / freq_total * 100)
        freq_items_html += f"""
          <div class="sd-freq-row">
            <div class="sd-bar-track" style="height:7px;flex:1">
              <div class="sd-bar-fill" style="background:{col};width:{w}%"></div>
            </div>
            <span class="sd-freq-label">{_html.escape(k)}</span>
            <span class="sd-freq-val">{v} ({pct}%)</span>
          </div>"""

    # ── Per-patient burden histogram ──
    pp_dist  = stats["pp_dist"]
    pp_max   = max(pp_dist.values()) if pp_dist else 1
    pp_grads = [
        "#34d399","#1d8a6e","#003087","#1a4f9f",
        "#f47920","#e4521b","#d94f2b","#b91c1c",
    ]
    pp_html  = ""
    for i, (cnt, freq_n) in enumerate(sorted(pp_dist.items())):
        h    = round(freq_n / pp_max * 125)
        col  = pp_grads[min(i, len(pp_grads) - 1)]
        pp_html += f"""
          <div class="sd-burden-col">
            <div class="sd-burden-cnt">{freq_n}</div>
            <div class="sd-burden-bar" style="height:{h}px;background:{col}"
                 title="{freq_n} patient(s) with {cnt} medication(s)"></div>
            <div class="sd-burden-lbl">{cnt}</div>
          </div>"""

    # ── Fragment ─────────────────────────────────────────────────────────────
    fragment = f"""<!-- ═══════════════════════════════════════════════════════════
     SynderAI EPS Medication Statistics Dashboard
     Generated by synderai_eps_medication_dashboard.py · AGPL-3.0 · HL7 Europe
     Embed between <nav> and <footer> in the SynderAI page template.
═══════════════════════════════════════════════════════════ -->

<style>
/* ── Medication dashboard (on top of synderai styles.css) ── */
#med-dashboard {{
  margin: 0 auto;
  margin-top: 2rem;
  padding-bottom: 3rem;
}}
#med-dashboard .sd-teaser {{
  font-size: 1rem;
  color: #7b5da7;
  margin: 1rem 0;
  margin-left: 2.7rem;
  letter-spacing: .04em;
  text-transform: uppercase;
  font-weight: 600;
}}
#med-dashboard h1 {{
  font-size: 2.2rem;
  font-weight: 800;
  color: #cfcfcf;
  margin-left: 2.7rem;
  margin-bottom: .5rem;
}}
#med-dashboard h1 em {{ font-style: normal; color: #f07086; }}
#med-dashboard h2 {{
  font-size: 1rem;
  font-weight: 600;
  color: #cfcfcf;
  margin-left: 2.7rem;
  margin-bottom: 1.2rem;
  display: flex;
  align-items: center;
  gap: .6rem;
}}
#med-dashboard h2 .sd-h2-line {{
  flex: 1; height: 1px; background: rgba(255,255,255,.1);
}}
#med-dashboard h2 .sd-h2-pill {{
  font-family: monospace;
  font-size: .7rem;
  letter-spacing: .08em;
  padding: 2px 10px;
  border-radius: 20px;
  white-space: nowrap;
}}
#med-dashboard h2 .sd-h2-pill.meds {{
  background: rgba(96,165,250,.12);
  border: 1px solid rgba(96,165,250,.3);
  color: #60a5fa;
}}
#med-dashboard h2 .sd-h2-pill.freq {{
  background: rgba(251,191,36,.1);
  border: 1px solid rgba(251,191,36,.3);
  color: #fbbf24;
}}
#med-dashboard h2 .sd-h2-pill.trigger {{
  background: rgba(248,113,113,.1);
  border: 1px solid rgba(248,113,113,.3);
  color: #f87171;
}}

/* Shared component styles */
.sd-kpi-grid {{
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: .8rem;
  margin: 0 2.7rem 1.8rem;
}}
.sd-kpi {{
  padding: 1rem 1.2rem !important;
  position: relative;
  text-align: left;
}}
.sd-kpi-tag {{
  position: absolute;
  top: 8px; right: 10px;
  font-size: .55rem;
  font-family: monospace;
  letter-spacing: .06em;
  text-transform: uppercase;
  padding: 1px 5px;
  border-radius: 3px;
  background: rgba(255,255,255,.08);
  color: rgba(255,255,255,.4);
  border: 1px solid rgba(255,255,255,.1);
}}
.sd-kpi-value {{
  font-size: 2rem;
  font-weight: 800;
  line-height: 1;
  margin-bottom: 4px;
}}
.sd-kpi-label {{
  font-size: .65rem;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: rgba(255,255,255,.45);
  font-family: monospace;
}}
.sd-main-grid {{
  display: grid;
  grid-template-columns: 1fr 290px;
  gap: 1rem;
  margin: 0 2.7rem 1rem;
  align-items: start;
}}
.sd-right-col {{
  display: flex;
  flex-direction: column;
  gap: 1rem;
}}
.sd-bot-grid {{
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  margin: 0 2.7rem 1rem;
  align-items: start;
}}
.sd-panel-head {{
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: .5rem;
  margin-bottom: 1rem;
  padding-bottom: .6rem;
  border-bottom: 1px solid rgba(255,255,255,.08);
}}
.sd-panel-title {{ font-weight: 700; font-size: .9rem; color: #cfcfcf; }}
.sd-panel-note  {{
  font-size: .62rem;
  font-family: monospace;
  color: rgba(255,255,255,.3);
  letter-spacing: .04em;
}}
.sd-chip-row {{
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
  margin-bottom: .9rem;
}}
.sd-chip {{
  padding: 2px 9px;
  border-radius: 20px;
  border: 1px solid;
  background: transparent;
  font-family: monospace;
  font-size: .6rem;
  letter-spacing: .04em;
  cursor: pointer;
  transition: opacity .15s, background .15s;
  color: inherit;
}}
.sd-chip:not(.active) {{ opacity: .35; }}
.sd-chip.active {{ opacity: 1; }}
.sd-chip.active[data-cat="All"] {{ background: rgba(255,255,255,.1); }}

/* Bar list */
.sd-bar-list {{ display: flex; flex-direction: column; gap: 4px; }}
.sd-bar-row {{
  display: grid;
  grid-template-columns: 220px 1fr 46px;
  align-items: center;
  gap: 7px;
  padding: 2px 4px;
  border-radius: 6px;
  transition: background .12s;
  cursor: default;
}}
.sd-bar-row:hover {{ background: rgba(255,255,255,.05); }}
.sd-bar-row.hidden {{ display: none; }}
.sd-bar-label {{
  font-size: .73rem;
  color: #cfcfcf;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}}
.sd-snomed {{
  font-family: monospace;
  font-size: .58rem;
  color: rgba(255,255,255,.28);
  margin-left: 4px;
}}
.sd-bar-track {{
  height: 10px;
  background: rgba(255,255,255,.07);
  border-radius: 3px;
  position: relative;
  overflow: hidden;
}}
.sd-bar-fill {{
  position: absolute;
  left: 0; top: 0;
  height: 100%;
  border-radius: 3px;
  transition: width .55s cubic-bezier(.22,.61,.36,1);
}}
.sd-bar-pct {{
  font-family: monospace;
  font-size: .63rem;
  color: rgba(255,255,255,.4);
  text-align: right;
}}

/* Donut */
.sd-donut-wrap {{
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}}
.sd-legend-item {{
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 4px 0;
  border-bottom: 1px solid rgba(255,255,255,.06);
  font-size: .73rem;
  color: #cfcfcf;
}}
.sd-legend-item:last-child {{ border-bottom: none; }}
.sd-legend-dot {{ width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }}
.sd-legend-name {{ flex: 1; }}
.sd-legend-pct {{ font-family: monospace; font-size: .61rem; color: rgba(255,255,255,.38); }}
.sd-legend-n   {{ font-family: monospace; font-size: .61rem; color: rgba(255,255,255,.55); min-width: 28px; text-align: right; }}

/* Frequency chart */
.sd-freq-row {{
  display: grid;
  grid-template-columns: 1fr 160px 80px;
  align-items: center;
  gap: 7px;
  padding: 3px 4px;
  border-radius: 5px;
  transition: background .12s;
}}
.sd-freq-row:hover {{ background: rgba(255,255,255,.05); }}
.sd-freq-label {{
  font-size: .72rem;
  color: #cfcfcf;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}}
.sd-freq-val {{
  font-family: monospace;
  font-size: .63rem;
  color: rgba(255,255,255,.4);
  text-align: right;
  white-space: nowrap;
}}

/* Burden histogram */
.sd-burden-wrap {{
  display: flex;
  align-items: flex-end;
  height: 145px;
  gap: 4px;
  padding: 0 2px;
}}
.sd-burden-col {{
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  gap: 3px;
}}
.sd-burden-bar {{
  width: 100%;
  border-radius: 4px 4px 0 0;
  transition: opacity .15s;
}}
.sd-burden-bar:hover {{ opacity: .75; }}
.sd-burden-cnt {{
  font-family: monospace;
  font-size: .58rem;
  color: rgba(255,255,255,.38);
  line-height: 1;
}}
.sd-burden-lbl {{
  font-family: monospace;
  font-size: .52rem;
  color: rgba(255,255,255,.32);
  white-space: nowrap;
  margin-top: 2px;
}}

/* Tooltip */
#med-tooltip {{
  position: fixed;
  background: rgba(10,10,20,.95);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: 10px;
  padding: 10px 13px;
  font-family: 'Inter', sans-serif;
  font-size: .75rem;
  color: #cfcfcf;
  pointer-events: none;
  z-index: 9999;
  max-width: 260px;
  display: none;
  backdrop-filter: blur(12px);
  box-shadow: 0 8px 32px rgba(0,0,0,.6);
}}
#med-tooltip .tt-name {{ font-weight: 700; color: #fff; margin-bottom: 3px; }}
#med-tooltip .tt-code {{ font-family: monospace; font-size: .6rem; color: #7b5da7; margin-bottom: 5px; }}
#med-tooltip .tt-row {{
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-family: monospace;
  font-size: .63rem;
  margin-top: 2px;
}}
#med-tooltip .tt-key {{ color: rgba(255,255,255,.45); }}

/* Note */
.sd-note {{
  margin: 0 2.7rem 0;
  padding: 1rem 1.3rem;
  font-size: .77rem;
  color: rgba(255,255,255,.5);
  line-height: 1.65;
}}
.sd-note strong {{ color: #cfcfcf; }}
.sd-note code {{
  font-family: monospace;
  font-size: .68rem;
  background: rgba(255,255,255,.07);
  padding: 1px 5px;
  border-radius: 3px;
}}
.sd-note a {{ color: #4e8cd4; }}

@media (max-width: 900px) {{
  #med-dashboard {{ max-width: 95%; }}
  .sd-main-grid, .sd-bot-grid {{ grid-template-columns: 1fr; }}
  .sd-bar-row {{ grid-template-columns: 140px 1fr 40px; }}
  .sd-kpi-grid {{ grid-template-columns: repeat(3, 1fr); }}
  .sd-freq-row {{ grid-template-columns: 1fr 90px; }}
}}
</style>

<!-- ═══ MEDICATION DASHBOARD FRAGMENT ═══ -->
<div id="med-dashboard">

  <div class="sd-teaser">SynderAI · European Patient Summary · {version_str}</div>
  <h1>EPS Cohort — <em>Medication Statistics</em></h1>

  <!-- ── KPI strip ── -->
  <div class="sd-kpi-grid">
{kpi_html}
  </div>

  <!-- ── Substances section ── -->
  <h2>
    <span class="sd-h2-line"></span>
    <span class="sd-h2-pill meds">Active medications · {stats["n_substances"]} substances · {stats["n_total"]} entries · 50/50 patients</span>
    <span class="sd-h2-line"></span>
  </h2>

  <div class="sd-main-grid">

    <!-- Substance bar chart -->
    <div class="example-item">
      <div class="sd-panel-head">
        <span class="sd-panel-title">Substances by patient prevalence</span>
        <span class="sd-panel-note">Unique patients prescribed each substance / N={stats["n_patients"]}</span>
      </div>
      <div class="sd-chip-row" id="medChips">{chips_html}</div>
      <div class="sd-bar-list" id="medBarChart"></div>
    </div>

    <!-- Right column -->
    <div class="sd-right-col">

      <!-- ATC donut -->
      <div class="example-item">
        <div class="sd-panel-head">
          <span class="sd-panel-title">Therapeutic class</span>
          <span class="sd-panel-note">{atc_total_n} entries</span>
        </div>
        <div class="sd-donut-wrap">
          <svg id="medDonut" width="148" height="148" viewBox="0 0 148 148" style="overflow:visible"></svg>
          <div id="medDonutLegend" style="width:100%"></div>
        </div>
      </div>

    </div>
  </div><!-- /sd-main-grid -->

  <!-- ── Dosing + Trigger + Burden ── -->
  <h2 style="margin-top:1.5rem">
    <span class="sd-h2-line"></span>
    <span class="sd-h2-pill freq">Dosing schedule · {stats["n_total"]} MedicationStatements</span>
    <span class="sd-h2-line"></span>
  </h2>

  <div class="sd-bot-grid">

    <!-- Dosing frequency breakdown -->
    <div class="example-item">
      <div class="sd-panel-head">
        <span class="sd-panel-title">Dosing schedule distribution</span>
        <span class="sd-panel-note">Derived from dosage.timing.repeat</span>
      </div>
      <div style="display:flex;flex-direction:column;gap:5px" id="medFreq">
{freq_items_html}
      </div>
    </div>

    <!-- Per-patient burden -->
    <div class="example-item">
      <div class="sd-panel-head">
        <span class="sd-panel-title">Medications per patient</span>
        <span class="sd-panel-note">Range 1–{stats["max_per_patient"]} · mean {stats["avg_per_patient"]}</span>
      </div>
      <div class="sd-burden-wrap" id="medBurden">
{pp_html}
      </div>
      <div style="text-align:center;font-family:monospace;font-size:.59rem;color:rgba(255,255,255,.28);margin-top:8px">
        All {stats["n_patients"]} patients have ≥1 medication · avg {stats["avg_per_patient"]} · max {stats["max_per_patient"]}
      </div>
    </div>

  </div>

  <h2 style="margin-top:1.5rem">
    <span class="sd-h2-line"></span>
    <span class="sd-h2-pill trigger">Prescribing indications · from reasonCode</span>
    <span class="sd-h2-line"></span>
  </h2>

  <div style="margin: 0 2.7rem 1rem">
    <div class="example-item">
      <div class="sd-panel-head">
        <span class="sd-panel-title">Top prescribing indications</span>
        <span class="sd-panel-note">Conditions cited in MedicationStatement.reasonCode · % of N={stats["n_patients"]}</span>
      </div>
      <div class="sd-bar-list" id="medTrigger"></div>
    </div>
  </div>

  <!-- Note -->
  <div class="example-item sd-note">
    <strong>Notes.</strong>
    All {stats["n_total"]} MedicationStatement resources carry
    <code>status = active</code> and reference a Medication resource via
    <code>medicationReference</code>.
    {stats["n_snomed"]} entries ({round(stats["n_snomed"]/stats["n_total"]*100)}%) are coded with
    <strong>SNOMED CT</strong>; {stats["n_rxnorm"]} with RxNorm (US brand names).
    <em>Substance prevalence</em> = unique patients prescribed ≥1 product containing
    that active substance / N = {stats["n_patients"]}.
    Substances are normalised from product display names (strength and form stripped);
    multi-ingredient products retain both INNs.
    <em>Dosing schedule</em> is derived from
    <code>dosage.timing.repeat.frequency / period / periodUnit</code>;
    entries without structured timing are grouped as "Other / Unknown".
    PRN / SOS refers to nitroglycerin spray (angina — 1 actuation / 5 min as needed).
    <strong>This dataset is entirely synthetic.</strong>
    Analysis: <code>synderai_eps_medication_dashboard.py</code> ·
    <a href="https://github.com/hl7-eu/SYNDERAI">AGPL-3.0 · GitHub</a>.
  </div>

</div><!-- /#med-dashboard -->

<div id="med-tooltip"></div>

<script>
(function () {{
  "use strict";

  const SUBSTANCES = {js_substances};
  const ATC_DOMAINS = {js_atc};
  const TRIGGER    = {js_trigger};
  const FREQ       = {js_freq};
  const COLORS     = {js_colors};
  const N          = {stats["n_patients"]};
  const ATC_TOTAL  = {atc_total_n};
  const MAX_PTS    = {max_pts};
  const TRIG_MAX   = {trig_max};

  function col(atc) {{ return COLORS[atc] || "#94a3b8"; }}

  /* ── Bar chart ────────────────────────────────────────────────────── */
  function buildBars(filter) {{
    const el = document.getElementById("medBarChart");
    el.innerHTML = "";
    SUBSTANCES.forEach(s => {{
      const vis = filter === "All" || s.atc === filter;
      const w   = (s.n_patients / MAX_PTS * 100).toFixed(1);
      const row = document.createElement("div");
      row.className = "sd-bar-row" + (vis ? "" : " hidden");
      row.innerHTML = `
        <div class="sd-bar-label" title="${{s.name}}${{s.code ? ' · ' + s.code : ''}}">
          ${{s.name}}${{s.code ? '<span class="sd-snomed">' + s.code + '</span>' : ''}}
        </div>
        <div class="sd-bar-track">
          <div class="sd-bar-fill" style="background:${{col(s.atc)}};width:${{w}}%"></div>
        </div>
        <div class="sd-bar-pct">${{s.pct}}%</div>`;
      row.addEventListener("mouseenter", e => showTT(e, s));
      row.addEventListener("mousemove",  e => moveTT(e));
      row.addEventListener("mouseleave", hideTT);
      el.appendChild(row);
    }});
  }}
  buildBars("All");

  document.getElementById("medChips").addEventListener("click", e => {{
    const btn = e.target.closest(".sd-chip");
    if (!btn) return;
    document.querySelectorAll("#medChips .sd-chip").forEach(b => b.classList.remove("active"));
    btn.classList.add("active");
    buildBars(btn.dataset.cat);
  }});

  /* ── Tooltip (declared first so closures below can reference them) ── */
  const ttEl = document.getElementById("med-tooltip");

  function showTT(e, s) {{
    ttEl.innerHTML = `
      <div class="tt-name">${{s.name}}</div>
      ${{s.code ? '<div class="tt-code">SNOMED CT &nbsp;' + s.code + '</div>' : ''}}
      <div style="font-size:.65rem;color:${{col(s.atc)}};margin-bottom:5px">${{s.atc}}</div>
      <div class="tt-row"><span class="tt-key">Patients prescribed</span><span>${{s.n_patients}} / ${{N}}</span></div>
      <div class="tt-row"><span class="tt-key">Patient prevalence</span><span>${{s.pct}}&thinsp;%</span></div>`;
    ttEl.style.display = "block";
    moveTT(e);
  }}
  function moveTT(e) {{
    ttEl.style.left = Math.min(e.clientX + 12, window.innerWidth - 270) + "px";
    ttEl.style.top  = (e.clientY - 10) + "px";
  }}
  function hideTT() {{ ttEl.style.display = "none"; }}

  /* ── Donut (createElementNS – no inline handlers) ───────────────── */
  (function () {{
    const NS  = "http://www.w3.org/2000/svg";
    const svg = document.getElementById("medDonut");
    const leg = document.getElementById("medDonutLegend");
    const cx = 74, cy = 74, R = 60, r = 38;
    let angle = -Math.PI / 2;

    ATC_DOMAINS.forEach(d => {{
      const sw = d.value / ATC_TOTAL * 2 * Math.PI;
      const a2 = angle + sw, lg = sw > Math.PI ? 1 : 0;
      const px = (r_, a_) => (cx + r_ * Math.cos(a_)).toFixed(2);
      const py = (r_, a_) => (cy + r_ * Math.sin(a_)).toFixed(2);
      const pathD = `M${{px(r,angle)}},${{py(r,angle)}} L${{px(R,angle)}},${{py(R,angle)}} A${{R}},${{R}},0,${{lg}},1,${{px(R,a2)}},${{py(R,a2)}} L${{px(r,a2)}},${{py(r,a2)}} A${{r}},${{r}},0,${{lg}},0,${{px(r,angle)}},${{py(r,angle)}}Z`;
      const path = document.createElementNS(NS, "path");
      path.setAttribute("d", pathD);
      path.setAttribute("fill", col(d.label));
      path.setAttribute("stroke", "rgba(11,11,11,.7)");
      path.setAttribute("stroke-width", "1.5");
      path.setAttribute("opacity", ".85");
      path.style.cssText = "cursor:pointer;transition:opacity .15s";
      path.addEventListener("mouseenter", () => path.setAttribute("opacity", "1"));
      path.addEventListener("mouseleave", () => path.setAttribute("opacity", ".85"));
      svg.appendChild(path);
      angle += sw;
    }});

    const mkText = (x, y, txt, fs, fw, fill, ls) => {{
      const t = document.createElementNS(NS, "text");
      t.setAttribute("x", x); t.setAttribute("y", y);
      t.setAttribute("text-anchor", "middle");
      t.setAttribute("font-family", fw ? "Inter,sans-serif" : "monospace");
      t.setAttribute("font-size", fs); t.setAttribute("fill", fill);
      if (fw) t.setAttribute("font-weight", fw);
      if (ls) t.setAttribute("letter-spacing", ls);
      t.textContent = txt;
      svg.appendChild(t);
    }};
    mkText(74, 69, String(ATC_TOTAL), "18", "800", "white", null);
    mkText(74, 82, "ENTRIES", "7", null, "rgba(255,255,255,.38)", "1.5");

    leg.innerHTML = ATC_DOMAINS.map(d => `
      <div class="sd-legend-item">
        <span class="sd-legend-dot" style="background:${{col(d.label)}}"></span>
        <span class="sd-legend-name">${{d.label}}</span>
        <span class="sd-legend-pct">${{(d.value/ATC_TOTAL*100).toFixed(0)}}%</span>
        <span class="sd-legend-n">${{d.value}}</span>
      </div>`).join("");
  }})();

  /* ── Trigger conditions ─────────────────────────────────────────── */
  (function () {{
    const el = document.getElementById("medTrigger");
    el.innerHTML = "";
    TRIGGER.forEach(t => {{
      const w   = (t.count / TRIG_MAX * 100).toFixed(1);
      const row = document.createElement("div");
      row.className = "sd-bar-row";
      row.innerHTML = `
        <div class="sd-bar-label" title="${{t.display}}${{t.code ? ' · ' + t.code : ''}}">${{t.display}}${{t.code ? '<span class="sd-snomed">' + t.code + '</span>' : ''}}</div>
        <div class="sd-bar-track">
          <div class="sd-bar-fill" style="background:#f87171;width:${{w}}%"></div>
        </div>
        <div class="sd-bar-pct">${{t.pct}}%</div>`;
      el.appendChild(row);
    }});
  }})();

}})();
</script>
"""
    return fragment


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument(
        "--package", default="./package",
        help="Directory containing unpacked EPS Bundle JSON files (default: ./package)"
    )
    parser.add_argument(
        "--out", default="eps_medication_dashboard.html",
        help="Output HTML fragment file (default: eps_medication_dashboard.html)"
    )
    parser.add_argument(
        "--semver", default="0.0.0+000000",
        help="Version of the package (semver, default: 0.0.0+000000)"
    )
    args = parser.parse_args()

    print(f"Reading bundles from: {args.package}")
    all_meds, patients = parse_bundles(args.package)
    print(f"Parsed {len(patients)} patients, {len(all_meds)} MedicationStatement entries.")

    stats = build_stats(all_meds, patients)

    print(f"\n── Summary ─────────────────────────────────────────────────")
    print(f"  Patients:              {stats['n_patients']}")
    print(f"  MedStatement entries:  {stats['n_total']}")
    print(f"  Unique substances:     {stats['n_substances']}")
    print(f"  All active:            {stats['n_active']} ({round(stats['n_active']/stats['n_total']*100)}%)")
    print(f"  SNOMED CT coded:       {stats['n_snomed']}")
    print(f"  Avg meds/patient:      {stats['avg_per_patient']}")
    print(f"\n── Substance ranking (patient prevalence) ──────────────────")
    for s in stats["substances"]:
        bar = "█" * int(s["n_patients"] / stats["n_patients"] * 40)
        print(f"  {s['n_patients']:2d}/50  {bar:<40}  {s['name']}")
    print(f"\n── ATC therapeutic class totals ────────────────────────────")
    for cls, cnt in sorted(stats["atc_totals"].items(), key=lambda x: -x[1]):
        print(f"  {cls:<30} {cnt:3d} entries")
    print(f"\n── Dosing schedule ─────────────────────────────────────────")
    for label, cnt in stats["freq_distribution"].items():
        print(f"  {cnt:3d}  {label}")
    print(f"\n── Top prescribing indications ─────────────────────────────")
    for t in stats["trigger_conditions"][:10]:
        print(f"  {t['count']:3d}  {t['display']}")

    fragment = build_html(stats, args.semver)

    with open(args.out, "w", encoding="utf-8") as fh:
        fh.write(fragment)
    print(f"\nHTML fragment written to: {args.out}")
    print("Embed it between <nav> and <footer> in the SynderAI page template.")


if __name__ == "__main__":
    main()
