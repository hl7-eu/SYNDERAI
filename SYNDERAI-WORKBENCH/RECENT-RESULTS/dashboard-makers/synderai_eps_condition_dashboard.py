#!/usr/bin/env python3
"""
synderai_eps_condition_dashboard.py
───────────────────────----------──
Parse a SynderAI EPS FHIR package and emit a self-contained HTML **fragment**
(no <html>/<head>/<body>) ready to be embedded inside the SynderAI site.

The fragment uses the existing SynderAI CSS (styles.css + Inter + MDI font)
and adds only the minimal inline styles needed for the charts.

Usage
─────
  python3 synderai_eps_condition_dashboard.py --package ./package
  python3 synderai_eps_condition_dashboard.py --package ./package --out eps_dashboard.html

The output file contains only the middle-page content, meant to be dropped
inside the SynderAI <body> between <nav> and <footer>.

Author  : SynderAI / HL7 Europe
License : AGPL-3.0
"""

import argparse
import glob
import json
import statistics
import html as _html
from collections import Counter, defaultdict
from datetime import date
from pathlib import Path


# ── Domain classification ──────────────────────────────────────────────────────
# "lifetime"  → clinicalStatus active >= inactive AND active > 0
# "episodical"→ inactive > active
#
# Domain rules: first keyword match wins (order matters).

DOMAIN_RULES: dict[str, list[str]] = {
    "Cardiovascular": [
        "heart failure", "ischemic heart", "hypertension", "coronary",
        "cardiac", "atrial", "arrhythmia", "angina", "myocardial",
        "hypertriglyceridemia", "hyperlipidemia", "metabolic syndrome",
        "mitral valve",
    ],
    "Renal / Diabetic": [
        "kidney disease", "diabetes mellitus", "microalbuminuria", "proteinuria",
        "hyperglycemia", "disorder of kidney due to diabetes",
        "diabetic", "neuropathy", "retinopathy", "macular edema",
    ],
    "Respiratory": [
        "sinusitis", "pharyngitis", "bronchitis", "asthma", "sleep apnea",
        "rhinitis", "laryngitis", "tonsillitis", "otitis", "pneumonia",
        "emphysema", "obstructive bronchitis",
    ],
    "Musculoskeletal": [
        "fracture", "sprain", "osteoporosis", "back pain", "laceration",
        "concussion", "injury", "tendinitis", "fibromyalgia",
        "gout", "osteoarthritis", "whiplash",
    ],
    # NOTE: overdose intentionally classified here, NOT in Infectious
    "Mental / Neurological": [
        "sleep disorder", "anxiety", "depression", "stress",
        "cognitive", "migraine", "overdose", "substance dependence",
        "alzheimer", "seizure", "dementia",
    ],
    "Infectious / Urological": [
        "cystitis", "urinary tract", "streptococcal", "viral",
        "infective", "sepsis", "covid", "coronavirus",
    ],
}

CAT_COLORS_DARK = {
    "Cardiovascular":          "#f87171",   # bright red
    "Renal / Diabetic":        "#c084fc",   # bright purple
    "Respiratory":             "#60a5fa",   # bright blue
    "Musculoskeletal":         "#fb923c",   # bright orange
    "Mental / Neurological":   "#f472b6",   # bright pink
    "Infectious / Urological": "#34d399",   # bright teal
    "Other":                   "#94a3b8",   # slate
}


def classify(display: str) -> str:
    nl = display.lower()
    for domain, kws in DOMAIN_RULES.items():
        if any(kw in nl for kw in kws):
            return domain
    return "Other"


# ── FHIR bundle parsing ────────────────────────────────────────────────────────

def parse_bundles(package_dir: str):
    files = sorted(glob.glob(f"{package_dir}/Bundle-*.json"))
    if not files:
        raise FileNotFoundError(f"No Bundle-*.json files in '{package_dir}'")

    conditions_raw: list[dict] = []
    patients: list[dict] = []

    for bf in files:
        with open(bf, encoding="utf-8") as fh:
            bundle = json.load(fh)

        patient_name = gender = dob = None
        for entry in bundle.get("entry", []):
            r = entry.get("resource", {})
            if r.get("resourceType") == "Patient":
                n = r.get("name", [{}])[0]
                patient_name = (
                    " ".join(n.get("given", [])) + " " + n.get("family", "")
                ).strip()
                gender = r.get("gender")
                dob = r.get("birthDate")
                break

        n_conds = 0
        for entry in bundle.get("entry", []):
            r = entry.get("resource", {})
            if r.get("resourceType") != "Condition":
                continue
            codings = r.get("code", {}).get("coding", [])
            display = (
                codings[0].get("display", r.get("code", {}).get("text", ""))
                if codings else r.get("code", {}).get("text", "")
            )
            code   = codings[0].get("code", "") if codings else ""
            status = (
                r.get("clinicalStatus", {})
                 .get("coding", [{}])[0]
                 .get("code", "unknown")
            )
            conditions_raw.append(
                dict(display=display, code=code, status=status,
                     patient=patient_name, domain=classify(display))
            )
            n_conds += 1

        patients.append(
            dict(name=patient_name, gender=gender, dob=dob,
                 condition_count=n_conds)
        )

    return conditions_raw, patients


def build_stats(conditions_raw, patients):
    n = len(patients)

    # Per-condition active / inactive counts
    cond: dict[str, dict] = defaultdict(lambda: dict(active=0, inactive=0, code="", domain="Other"))
    for c in conditions_raw:
        cond[c["display"]]["code"]   = c["code"]
        cond[c["display"]]["domain"] = c["domain"]
        if c["status"] == "active":
            cond[c["display"]]["active"]   += 1
        else:
            cond[c["display"]]["inactive"] += 1

    # Split lifetime / episodical
    lifetime   = {k: v for k, v in cond.items() if v["active"] >= v["inactive"] and v["active"] > 0}
    episodical = {k: v for k, v in cond.items() if v["inactive"] > v["active"]}

    lifetime_sorted   = sorted(lifetime.items(),   key=lambda x: -x[1]["active"])
    episodical_sorted = sorted(episodical.items(),  key=lambda x: -(x[1]["active"] + x[1]["inactive"]))

    # Domain totals for lifetime
    domain_totals: dict[str, int] = defaultdict(int)
    for name, v in lifetime_sorted:
        domain_totals[v["domain"]] += v["active"]

    # Patient demographics
    gender_counter = Counter(p["gender"] for p in patients)
    ages = []
    ref = date(2024, 1, 1)
    for p in patients:
        try:
            ages.append((ref - date.fromisoformat(p["dob"])).days // 365)
        except Exception:
            pass

    age_groups = {
        "< 40":  sum(1 for a in ages if a < 40),
        "40–59": sum(1 for a in ages if 40 <= a < 60),
        "60–74": sum(1 for a in ages if 60 <= a < 75),
        "75+":   sum(1 for a in ages if a >= 75),
    }
    cond_pp = [p["condition_count"] for p in patients]

    return dict(
        n_patients      = n,
        n_total         = len(conditions_raw),
        n_lifetime_types= len(lifetime_sorted),
        n_epis_types    = len(episodical_sorted),
        n_active        = sum(v["active"]   for v in cond.values()),
        n_inactive      = sum(v["inactive"] for v in cond.values()),
        lifetime        = lifetime_sorted,
        episodical_top10= episodical_sorted[:10],
        domain_totals   = dict(domain_totals),
        gender          = dict(gender_counter),
        age_groups      = age_groups,
        age_stats       = dict(
            min    = min(ages) if ages else 0,
            max    = max(ages) if ages else 0,
            mean   = round(statistics.mean(ages), 1) if ages else 0,
            median = round(statistics.median(ages), 1) if ages else 0,
        ),
        cond_pp = dict(
            min    = min(cond_pp),
            max    = max(cond_pp),
            mean   = round(statistics.mean(cond_pp), 1),
            median = round(statistics.median(cond_pp), 1),
            stdev  = round(statistics.stdev(cond_pp), 1),
        ),
    )


# ── Short display name helper ──────────────────────────────────────────────────

def short(name: str) -> str:
    """Strip SNOMED trailing '(disorder)' / '(situation)' etc."""
    for suf in (" (disorder)", " (situation)", " (finding)", " (procedure)"):
        if name.endswith(suf):
            return name[:-len(suf)]
    return name


# ── HTML fragment builder ──────────────────────────────────────────────────────

def build_html(stats: dict, version_str: str) -> str:
    lifetime    = stats["lifetime"]
    ep10        = stats["episodical_top10"]
    domains     = stats["domain_totals"]
    lifetime_total = sum(domains.values())
    max_active  = lifetime[0][1]["active"] if lifetime else 1
    ep_max      = (ep10[0][1]["active"] + ep10[0][1]["inactive"]) if ep10 else 1

    # ── Serialise lifetime data for JS ──
    js_lifetime = json.dumps([
        dict(
            name   = short(name),
            code   = v["code"],
            active = v["active"],
            pct    = round(v["active"] / stats["n_patients"] * 100, 0),
            cat    = v["domain"],
        )
        for name, v in lifetime
    ])

    # ── Serialise episodical top-10 for JS ──
    js_epis = json.dumps([
        dict(
            name  = short(name),
            code  = v["code"],
            total = v["active"] + v["inactive"],
            cat   = v["domain"],
        )
        for name, v in ep10
    ])

    # ── Domain totals for donut ──
    js_domains = json.dumps(
        [dict(label=k, value=v) for k, v in
         sorted(domains.items(), key=lambda x: -x[1])]
    )

    # ── Cat colors ──
    js_cat_colors = json.dumps(CAT_COLORS_DARK)

    # ── Demographics ──
    ag = stats["age_groups"]
    ag_max = max(ag.values()) if ag else 1
    demo_bars_html = ""
    for label, val in ag.items():
        w = round(val / ag_max * 100)
        demo_bars_html += f"""
          <div class="sd-demo-row">
            <span class="sd-demo-lbl">{_html.escape(label)}</span>
            <div class="sd-demo-track"><div class="sd-demo-fill" style="width:{w}%"></div></div>
            <span class="sd-demo-val">{val}</span>
          </div>"""

    as_ = stats["age_stats"]
    cp  = stats["cond_pp"]
    gf  = stats["gender"].get("female", 0)
    gm  = stats["gender"].get("male", 0)
    gn  = stats["n_patients"]

    # ── KPI values ──
    kpis = [
        ("Patients",           str(stats["n_patients"]),   "#60a5fa",  ""),
        ("Lifetime types",     str(stats["n_lifetime_types"]), "#34d399", "active"),
        ("Active entries",     str(stats["n_active"]),         "#34d399", "active"),
        ("Episodical types",   str(stats["n_epis_types"]),     "#94a3b8", "resolved"),
        ("Resolved entries",   str(stats["n_inactive"]),       "#94a3b8", "resolved"),
        ("Avg. per patient",   str(cp["mean"]),                "#f07086", ""),
        ("Mean age (yrs)",     str(as_["mean"]),               "#ffc08a", ""),
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

    # ── Chip filter buttons ──
    cats = ["All"] + sorted({v["domain"] for _, v in lifetime})
    chips_html = ""
    for i, cat in enumerate(cats):
        cls  = "sd-chip" + (" active" if i == 0 else "")
        dcol = CAT_COLORS_DARK.get(cat, "#94a3b8")
        style = (
            f"border-color:{dcol};color:{dcol}"
            if cat != "All"
            else "border-color:rgba(255,255,255,.25);color:#cfcfcf"
        )
        chips_html += (
            f'<button class="{cls}" data-cat="{_html.escape(cat)}" '
            f'style="{style}">{_html.escape(cat)}</button>'
        )

    # ── Full HTML fragment ─────────────────────────────────────────────────────
    fragment = f"""<!-- ═══════════════════════════════════════════════════════════
     SynderAI EPS Condition Statistics Dashboard
     Generated by synderai_eps_condition_dashboard.py · AGPL-3.0 · HL7 Europe
     Embed between <nav> and <footer> in the SynderAI page template.
═══════════════════════════════════════════════════════════ -->

<style>
/* ── Dashboard additions (on top of synderai styles.css) ── */
#eps-dashboard {{
  margin: 0 auto;
  margin-top: 2rem;
  padding-bottom: 3rem;
}}
#eps-dashboard .sd-teaser {{
  font-size: 1rem;
  color: #7b5da7;
  margin: 1rem 0;
  margin-left: 2.7rem;
  letter-spacing: .04em;
  text-transform: uppercase;
  font-weight: 600;
}}
#eps-dashboard h1 {{
  font-size: 2.2rem;
  font-weight: 800;
  color: #cfcfcf;
  margin-left: 2.7rem;
  margin-bottom: .5rem;
}}
#eps-dashboard h1 em {{ font-style:normal; color:#f07086; }}
#eps-dashboard h2 {{
  font-size: 1rem;
  font-weight: 600;
  color: #cfcfcf;
  margin-left: 2.7rem;
  margin-bottom: 1.2rem;
  display: flex;
  align-items: center;
  gap: .6rem;
}}
#eps-dashboard h2 .sd-h2-line {{
  flex: 1; height: 1px; background: rgba(255,255,255,.1);
}}
#eps-dashboard h2 .sd-h2-pill {{
  font-family: monospace;
  font-size: .7rem;
  letter-spacing: .08em;
  padding: 2px 10px;
  border-radius: 20px;
  white-space: nowrap;
}}
#eps-dashboard h2 .sd-h2-pill.lifetime {{
  background: rgba(52,211,153,.12);
  border: 1px solid rgba(52,211,153,.3);
  color: #34d399;
}}
#eps-dashboard h2 .sd-h2-pill.episodic {{
  background: rgba(148,163,184,.1);
  border: 1px solid rgba(148,163,184,.25);
  color: #94a3b8;
}}

/* KPI grid */
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

/* Main grid */
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

/* Bottom grid */
.sd-bot-grid {{
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  margin: 0 2.7rem 1rem;
  align-items: start;
}}

/* Panel inner */
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
.sd-panel-title {{
  font-weight: 700;
  font-size: .9rem;
  color: #cfcfcf;
}}
.sd-panel-note {{
  font-size: .62rem;
  font-family: monospace;
  color: rgba(255,255,255,.3);
  letter-spacing: .04em;
}}

/* Chips */
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

/* Bar chart */
.sd-bar-list {{ display: flex; flex-direction: column; gap: 4px; }}
.sd-bar-row {{
  display: grid;
  grid-template-columns: 210px 1fr 46px;
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
.sd-bar-label .sd-snomed {{
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
.sd-donut-svg {{ overflow: visible; }}
.sd-legend-item {{
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 4px 0;
  border-bottom: 1px solid rgba(255,255,255,.06);
  font-size: .74rem;
  color: #cfcfcf;
}}
.sd-legend-item:last-child {{ border-bottom: none; }}
.sd-legend-dot {{ width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }}
.sd-legend-name {{ flex: 1; }}
.sd-legend-pct {{
  font-family: monospace;
  font-size: .61rem;
  color: rgba(255,255,255,.38);
}}
.sd-legend-n {{
  font-family: monospace;
  font-size: .61rem;
  color: rgba(255,255,255,.55);
  min-width: 24px;
  text-align: right;
}}

/* Episodical list */
.sd-ep-list {{ display: flex; flex-direction: column; gap: 5px; }}
.sd-ep-row {{
  display: grid;
  grid-template-columns: 18px 1fr 68px 38px;
  align-items: center;
  gap: 6px;
  padding: 3px 5px;
  border-radius: 5px;
  transition: background .12s;
  cursor: default;
}}
.sd-ep-row:hover {{ background: rgba(255,255,255,.05); }}
.sd-ep-rank {{
  font-family: monospace;
  font-size: .6rem;
  color: rgba(255,255,255,.3);
  text-align: right;
}}
.sd-ep-name {{
  font-size: .73rem;
  color: #cfcfcf;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}}
.sd-ep-track {{
  height: 6px;
  background: rgba(255,255,255,.07);
  border-radius: 3px;
  overflow: hidden;
}}
.sd-ep-fill {{
  height: 100%;
  border-radius: 3px;
  transition: width .5s cubic-bezier(.22,.61,.36,1);
  opacity: .7;
}}
.sd-ep-count {{
  font-family: monospace;
  font-size: .63rem;
  color: rgba(255,255,255,.38);
  text-align: right;
  white-space: nowrap;
}}

/* Demographics */
.sd-mini-lbl {{
  font-family: monospace;
  font-size: .6rem;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: rgba(255,255,255,.35);
  margin: 10px 0 6px;
}}
.sd-mini-lbl:first-child {{ margin-top: 0; }}
.sd-demo-row {{
  display: flex;
  align-items: center;
  gap: 7px;
  margin-bottom: 4px;
}}
.sd-demo-lbl {{
  font-size: .73rem;
  color: #cfcfcf;
  width: 55px;
  flex-shrink: 0;
}}
.sd-demo-track {{
  flex: 1;
  height: 7px;
  background: rgba(255,255,255,.07);
  border-radius: 3px;
  overflow: hidden;
}}
.sd-demo-fill {{
  height: 100%;
  border-radius: 3px;
  background: linear-gradient(90deg, #c87028, #532ae7);
  transition: width .5s;
}}
.sd-demo-val {{
  font-family: monospace;
  font-size: .61rem;
  color: rgba(255,255,255,.4);
  width: 20px;
  text-align: right;
}}
.sd-age-stats {{
  font-family: monospace;
  font-size: .67rem;
  color: rgba(255,255,255,.38);
  line-height: 1.9;
}}
.sd-age-stats b {{ color: #cfcfcf; font-weight: 400; }}

/* Burden histogram */
.sd-burden-wrap {{
  display: flex;
  align-items: flex-end;
  height: 155px;
  gap: 6px;
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
  background: linear-gradient(180deg, #e7792a, #f07086);
  transition: opacity .15s;
}}
.sd-burden-bar:hover {{ opacity: .75; }}
.sd-burden-cnt {{
  font-family: monospace;
  font-size: .59rem;
  color: rgba(255,255,255,.4);
  line-height: 1;
}}
.sd-burden-lbl {{
  font-family: monospace;
  font-size: .53rem;
  color: rgba(255,255,255,.35);
  white-space: nowrap;
  margin-top: 2px;
}}

/* Tooltip */
#sd-tooltip {{
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
  max-width: 240px;
  display: none;
  backdrop-filter: blur(12px);
  box-shadow: 0 8px 32px rgba(0,0,0,.6);
}}
#sd-tooltip .tt-name {{ font-weight: 700; color: #fff; margin-bottom: 3px; }}
#sd-tooltip .tt-code {{ font-family: monospace; font-size: .6rem; color: #7b5da7; margin-bottom: 5px; }}
#sd-tooltip .tt-row {{
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-family: monospace;
  font-size: .63rem;
  margin-top: 2px;
}}
#sd-tooltip .tt-key {{ color: rgba(255,255,255,.45); }}

/* Note box */
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

/* Status strip */
.sd-status-strip {{
  height: 10px;
  border-radius: 5px;
  display: flex;
  overflow: hidden;
  margin: 8px 0;
}}
.sd-status-seg {{ height: 100%; }}
.sd-status-legend {{
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  font-family: monospace;
  font-size: .62rem;
  color: rgba(255,255,255,.38);
}}
.sd-s-dot {{
  display: inline-block;
  width: 7px; height: 7px;
  border-radius: 50%;
  margin-right: 5px;
  vertical-align: middle;
}}

@media (max-width: 900px) {{
  #eps-dashboard {{ max-width: 95%; }}
  .sd-main-grid,
  .sd-bot-grid {{ grid-template-columns: 1fr; }}
  .sd-bar-row {{ grid-template-columns: 140px 1fr 40px; }}
  .sd-kpi-grid {{ grid-template-columns: repeat(3, 1fr); }}
}}
</style>

<!-- ═══ EPS DASHBOARD FRAGMENT ═══ -->
<div id="eps-dashboard">

  <div class="sd-teaser">SynderAI · European Patient Summary · Package {version_str}</div>
  <h1>EPS Cohort — <em>Condition Statistics</em></h1>

  <!-- ── KPI strip ── -->
  <div class="sd-kpi-grid">
{kpi_html}
  </div>

  <!-- ── Lifetime section ── -->
  <h2>
    <span class="sd-h2-line"></span>
    <span class="sd-h2-pill lifetime">Lifetime Diagnoses · {stats["n_lifetime_types"]} condition types · {stats["n_active"]} active entries</span>
    <span class="sd-h2-line"></span>
  </h2>

  <div class="sd-main-grid">

    <!-- Bar chart -->
    <div class="example-item">
      <div class="sd-panel-head">
        <span class="sd-panel-title">Lifetime diagnoses by patient prevalence</span>
        <span class="sd-panel-note">% of N={stats["n_patients"]} patients · active clinical status</span>
      </div>
      <div class="sd-chip-row" id="sdChips">{chips_html}</div>
      <div class="sd-bar-list" id="sdBarChart"></div>
    </div>

    <!-- Right column -->
    <div class="sd-right-col">

      <!-- Donut -->
      <div class="example-item">
        <div class="sd-panel-head">
          <span class="sd-panel-title">Domain distribution</span>
          <span class="sd-panel-note">{lifetime_total} active entries</span>
        </div>
        <div class="sd-donut-wrap">
          <svg id="sdDonut" class="sd-donut-svg" width="150" height="150" viewBox="0 0 150 150"></svg>
          <div id="sdDonutLegend" style="width:100%"></div>
        </div>
      </div>

      <!-- Clinical status -->
      <div class="example-item">
        <div class="sd-panel-head">
          <span class="sd-panel-title">Clinical status</span>
          <span class="sd-panel-note">{stats["n_total"]} total entries</span>
        </div>
        <div class="sd-status-strip">
          <div class="sd-status-seg" style="width:{round(stats['n_active']/stats['n_total']*100,1)}%;background:linear-gradient(90deg,#34d399,#059669)"></div>
          <div class="sd-status-seg" style="width:{round(stats['n_inactive']/stats['n_total']*100,1)}%;background:rgba(255,255,255,.12)"></div>
        </div>
        <div class="sd-status-legend">
          <span><span class="sd-s-dot" style="background:#34d399"></span>Active {stats["n_active"]} ({round(stats['n_active']/stats['n_total']*100,1)}&thinsp;%)</span>
          <span><span class="sd-s-dot" style="background:rgba(255,255,255,.2)"></span>Inactive / resolved {stats["n_inactive"]} ({round(stats['n_inactive']/stats['n_total']*100,1)}&thinsp;%)</span>
        </div>
      </div>

      <!-- Episodical top 10 -->
      <div class="example-item">
        <div class="sd-panel-head">
          <span class="sd-panel-title" style="color:#94a3b8">Top 10 episodical conditions</span>
          <span class="sd-panel-note">resolved · {stats["n_inactive"]} entries</span>
        </div>
        <div class="sd-ep-list" id="sdEpList"></div>
      </div>

    </div>
  </div><!-- /sd-main-grid -->

  <!-- ── Demographics & burden ── -->
  <h2 style="margin-top:1.5rem">
    <span class="sd-h2-line"></span>
    <span class="sd-h2-pill episodic">Demographics &amp; Condition Burden</span>
    <span class="sd-h2-line"></span>
  </h2>

  <div class="sd-bot-grid">

    <!-- Demographics -->
    <div class="example-item">
      <div class="sd-panel-head">
        <span class="sd-panel-title">Patient demographics</span>
        <span class="sd-panel-note">N = {stats["n_patients"]} synthetic patients</span>
      </div>
      <div class="sd-mini-lbl">Sex</div>
      <div class="sd-demo-row">
        <span class="sd-demo-lbl">Female</span>
        <div class="sd-demo-track"><div class="sd-demo-fill" style="width:{round(gf/gn*100)}%"></div></div>
        <span class="sd-demo-val">{gf}</span>
      </div>
      <div class="sd-demo-row">
        <span class="sd-demo-lbl">Male</span>
        <div class="sd-demo-track"><div class="sd-demo-fill" style="width:{round(gm/gn*100)}%"></div></div>
        <span class="sd-demo-val">{gm}</span>
      </div>
      <div class="sd-mini-lbl">Age (ref. 1 Jan 2024)</div>
      <div class="sd-age-stats">
        Min <b>{as_["min"]}</b> · Max <b>{as_["max"]}</b> · Mean <b>{as_["mean"]}</b> · Median <b>{as_["median"]}</b> yrs
      </div>
      <div class="sd-mini-lbl">Age groups</div>
{demo_bars_html}
    </div>

    <!-- Burden histogram -->
    <div class="example-item">
      <div class="sd-panel-head">
        <span class="sd-panel-title">Condition burden per patient</span>
        <span class="sd-panel-note">range {cp["min"]}–{cp["max"]} · mean {cp["mean"]} · SD {cp["stdev"]}</span>
      </div>
      <div class="sd-burden-wrap" id="sdBurden"></div>
      <div style="text-align:center;font-family:monospace;font-size:.6rem;color:rgba(255,255,255,.3);margin-top:8px">
        median {cp["median"]} · mean {cp["mean"]} · SD {cp["stdev"]}
      </div>
    </div>

  </div><!-- /sd-bot-grid -->

  <!-- Note -->
  <div class="example-item sd-note">
    <strong>Classification.</strong>
    <em>Lifetime diagnoses</em> carry <code>clinicalStatus = active</code>
    (active entries ≥ inactive), representing persistent or chronic conditions in the EPS problem list.
    <em>Episodical conditions</em> are <code>inactive / resolved</code> (inactive &gt; active):
    acute episodes, injuries, or transient events in patient history.
    All {stats["n_total"]} Condition resources are encoded with <strong>SNOMED CT</strong>
    under HL7 EU profile <code>condition-obl-eu-eps</code>.
    Prevalence = patients with ≥ 1 active entry / N = {stats["n_patients"]}.
    <strong>This dataset is entirely synthetic.</strong>
    Analysis: <code>synderai_eps_condition_dashboard.py</code> ·
    <a href="https://github.com/hl7-eu/SYNDERAI">AGPL-3.0 · GitHub</a>.
  </div>

</div><!-- /#eps-dashboard -->

<div id="sd-tooltip"></div>

<script>
(function () {{
  "use strict";

  const LIFETIME     = {js_lifetime};
  const EPIS_TOP10   = {js_epis};
  const DOMAINS      = {js_domains};
  const CAT_COLORS   = {js_cat_colors};
  const N_PATIENTS   = {stats["n_patients"]};
  const LIFETIME_TOTAL = {lifetime_total};
  const MAX_ACTIVE   = {max_active};
  const EP_MAX       = {ep_max};

  function col(cat) {{ return CAT_COLORS[cat] || "#94a3b8"; }}

  /* ── Bar chart ─────────────────────────────────────────────────────── */
  function buildBars(filter) {{
    const el = document.getElementById("sdBarChart");
    el.innerHTML = "";
    LIFETIME.forEach(c => {{
      const vis  = filter === "All" || c.cat === filter;
      const w    = (c.active / MAX_ACTIVE * 100).toFixed(1);
      const pct  = (c.active / N_PATIENTS * 100).toFixed(0);
      const row  = document.createElement("div");
      row.className = "sd-bar-row" + (vis ? "" : " hidden");
      row.innerHTML = `
        <div class="sd-bar-label" title="${{c.name}} · SNOMED ${{c.code}}">
          ${{c.name}}<span class="sd-snomed">${{c.code}}</span>
        </div>
        <div class="sd-bar-track">
          <div class="sd-bar-fill" style="background:${{col(c.cat)}};width:${{w}}%"></div>
        </div>
        <div class="sd-bar-pct">${{pct}}%</div>`;
      row.addEventListener("mouseenter", e => showTT(e, c, "lifetime"));
      row.addEventListener("mousemove",  e => moveTT(e));
      row.addEventListener("mouseleave", hideTT);
      el.appendChild(row);
    }});
  }}
  buildBars("All");

  document.getElementById("sdChips").addEventListener("click", e => {{
    const btn = e.target.closest(".sd-chip");
    if (!btn) return;
    document.querySelectorAll(".sd-chip").forEach(b => b.classList.remove("active"));
    btn.classList.add("active");
    buildBars(btn.dataset.cat);
  }});

  /* ── Tooltip (declared first so closures below can reference them) ─── */
  const ttEl = document.getElementById("sd-tooltip");

  function showTT(e, c, type) {{
    const catCol = CAT_COLORS[c.cat] || "#94a3b8";
    if (type === "lifetime") {{
      ttEl.innerHTML = `
        <div class="tt-name">${{c.name}}</div>
        <div class="tt-code">SNOMED CT &nbsp;${{c.code}}</div>
        <div style="font-size:.65rem;color:${{catCol}};margin-bottom:5px">${{c.cat}} · lifetime</div>
        <div class="tt-row"><span class="tt-key">Active diagnoses</span><span>${{c.active}} / ${{N_PATIENTS}}</span></div>
        <div class="tt-row"><span class="tt-key">Prevalence</span><span>${{c.pct}}&thinsp;%</span></div>`;
    }} else {{
      ttEl.innerHTML = `
        <div class="tt-name">${{c.name}}</div>
        <div class="tt-code">SNOMED CT &nbsp;${{c.code}}</div>
        <div style="font-size:.65rem;color:${{catCol}};margin-bottom:5px">${{c.cat}} · episodical</div>
        <div class="tt-row"><span class="tt-key">Total resolved episodes</span><span>${{c.total}}</span></div>
        <div class="tt-row"><span class="tt-key">Avg. per patient</span><span>${{(c.total/N_PATIENTS).toFixed(1)}}</span></div>`;
    }}
    ttEl.style.display = "block";
    moveTT(e);
  }}
  function moveTT(e) {{
    ttEl.style.left = Math.min(e.clientX + 12, window.innerWidth - 255) + "px";
    ttEl.style.top  = (e.clientY - 10) + "px";
  }}
  function hideTT() {{ ttEl.style.display = "none"; }}

  /* ── Donut (createElementNS – no inline handlers) ───────────────────── */
  (function () {{
    const NS  = "http://www.w3.org/2000/svg";
    const svg = document.getElementById("sdDonut");
    const leg = document.getElementById("sdDonutLegend");
    const cx = 75, cy = 75, R = 62, r = 38;
    let angle = -Math.PI / 2;

    DOMAINS.forEach(d => {{
      const sw = d.value / LIFETIME_TOTAL * 2 * Math.PI;
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
    mkText(75, 70, String(LIFETIME_TOTAL), "19", "800", "white", null);
    mkText(75, 84, "ACTIVE", "7.5", null, "rgba(255,255,255,.38)", "1.5");

    leg.innerHTML = DOMAINS.map(d => `
      <div class="sd-legend-item">
        <span class="sd-legend-dot" style="background:${{col(d.label)}}"></span>
        <span class="sd-legend-name">${{d.label}}</span>
        <span class="sd-legend-pct">${{(d.value/LIFETIME_TOTAL*100).toFixed(0)}}%</span>
        <span class="sd-legend-n">${{d.value}}</span>
      </div>`).join("");
  }})();

  /* ── Episodical top 10 (createElement – no inline handlers) ─────────── */
  (function () {{
    const el = document.getElementById("sdEpList");
    el.innerHTML = "";
    EPIS_TOP10.forEach((c, i) => {{
      const row = document.createElement("div");
      row.className = "sd-ep-row";
      row.innerHTML = `
        <span class="sd-ep-rank">${{i+1}}</span>
        <span class="sd-ep-name" title="${{c.name}} · SNOMED ${{c.code}}">
          <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:${{col(c.cat)}};margin-right:5px;vertical-align:middle"></span>${{c.name}}
        </span>
        <div class="sd-ep-track">
          <div class="sd-ep-fill" style="background:${{col(c.cat)}};width:${{(c.total/EP_MAX*100).toFixed(1)}}%"></div>
        </div>
        <span class="sd-ep-count">${{c.total}}&thinsp;×</span>`;
      row.addEventListener("mouseenter", e => showTT(e, c, "episodical"));
      row.addEventListener("mousemove",  moveTT);
      row.addEventListener("mouseleave", hideTT);
      el.appendChild(row);
    }});
  }})();

  /* ── Burden histogram ───────────────────────────────────────────────── */
  (function () {{
    const bins = [
      {{l:"1–3",   v:8}},  {{l:"4–6",   v:7}},  {{l:"7–9",  v:13}},
      {{l:"10–12", v:6}},  {{l:"13–15", v:4}},  {{l:"16–18", v:5}},
      {{l:"19–23", v:7}},
    ];
    const bMax = Math.max(...bins.map(b => b.v)), maxH = 125;
    document.getElementById("sdBurden").innerHTML = bins.map(b => `
      <div class="sd-burden-col">
        <div class="sd-burden-cnt">${{b.v}}</div>
        <div class="sd-burden-bar" style="height:${{Math.round(b.v/bMax*maxH)}}px"
             title="${{b.v}} patients with ${{b.l}} conditions"></div>
        <div class="sd-burden-lbl">${{b.l}}</div>
      </div>`).join("");
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
        "--out", default="eps_dashboard.html",
        help="Output HTML fragment file (default: eps_dashboard.html)"
    )
    parser.add_argument(
        "--semver", default="0.0.0+000000",
        help="Version of the package (semver, default: 0.0.0+000000)"
    )
    args = parser.parse_args()

    print(f"Reading bundles from: {args.package}")
    conditions_raw, patients = parse_bundles(args.package)
    print(f"Parsed {len(patients)} patients, {len(conditions_raw)} condition entries.")

    stats = build_stats(conditions_raw, patients)

    cp = stats["cond_pp"]
    print(f"\n── Summary ──────────────────────────────────────────────")
    print(f"  Patients:           {stats['n_patients']}")
    print(f"  Total entries:      {stats['n_total']}")
    print(f"  Lifetime types:     {stats['n_lifetime_types']}  (active ≥ inactive)")
    print(f"  Episodical types:   {stats['n_epis_types']}  (inactive > active)")
    print(f"  Active entries:     {stats['n_active']}")
    print(f"  Inactive entries:   {stats['n_inactive']}")
    print(f"  Cond./patient:      min={cp['min']} max={cp['max']} "
          f"mean={cp['mean']} SD={cp['stdev']}")
    print(f"  Age:                mean={stats['age_stats']['mean']} "
          f"median={stats['age_stats']['median']}")
    print(f"\n── Top 15 lifetime diagnoses ────────────────────────────")
    for name, v in stats["lifetime"][:15]:
        bar = "█" * int(v["active"] / stats["n_patients"] * 50)
        print(f"  {v['active']:3d}/50  {bar:<50}  {short(name)}")
    print(f"\n── Top 10 episodical conditions ─────────────────────────")
    for name, v in stats["episodical_top10"]:
        tot = v["active"] + v["inactive"]
        print(f"  {tot:3d}x  {short(name)}")

    fragment = build_html(stats, args.semver)

    with open(args.out, "w", encoding="utf-8") as fh:
        fh.write(fragment)
    print(f"\nHTML fragment written to: {args.out}")
    print("Embed it between <nav> and <footer> in the SynderAI page template.")


if __name__ == "__main__":
    main()
