#!/usr/bin/env python3
"""
synderai_eps_careplan_dashboard.py
───────────────────────────────────
Parse a SynderAI EPS FHIR package and emit a self-contained HTML **fragment**
(no <html>/<head>/<body>) ready to be embedded inside the SynderAI site.

Covers CarePlan resources only.  For Condition statistics use
synderai_eps_dashboard.py.

The fragment uses the existing SynderAI CSS (styles.css + Inter + MDI font)
and adds only the minimal inline styles needed for the charts.

Usage
─────
  python3 synderai_eps_careplan_dashboard.py --package ./package
  python3 synderai_eps_careplan_dashboard.py --package ./package --out eps_careplan_dashboard.html

Author  : SynderAI / HL7 Europe
License : AGPL-3.0
"""

import argparse
import glob
import json
import statistics
import html as _html
from collections import Counter, defaultdict
from pathlib import Path

# ── Domain classification for care plan types ────────────────────────────────
# Key = domain label  Value = list of lowercase substrings matched in plan name
# Order matters: first match wins.

DOMAIN_RULES: dict[str, list[str]] = {
    "Metabolic / Diabetes": [
        "diabetes self management", "weight management",
        "hyperlipidemia clinical management", "hyperlipidemia management",
    ],
    "Cardiovascular": [
        "hypertension", "heart failure", "cardiac",
        "coronary", "lipid", "cholesterol",
    ],
    "Respiratory": [
        "asthma", "obstructive pulmonary", "copd",
        "respiratory therapy", "respiratory",
    ],
    "Renal": [
        "dialysis", "kidney", "renal", "nephro",
    ],
    "Musculoskeletal": [
        "musculoskeletal", "orthopaedic", "fracture rehab",
        "osteoporosis", "arthritis",
    ],
    "Neurological / Mental": [
        "dementia", "alzheimer", "mental", "psychiatric",
        "cognitive", "neurolog",
    ],
    "Maternal": [
        "antenatal", "postnatal", "obstetric", "pregnancy",
        "maternity", "birth",
    ],
    "Oncology": [
        "cancer", "oncolog", "tumour", "tumor", "carcinoma",
    ],
}

DOMAIN_COLORS: dict[str, str] = {
    "Metabolic / Diabetes":  "#c084fc",   # purple
    "Cardiovascular":        "#f87171",   # red
    "Respiratory":           "#60a5fa",   # blue
    "Renal":                 "#a78bfa",   # violet
    "Musculoskeletal":       "#fb923c",   # orange
    "Neurological / Mental": "#f472b6",   # pink
    "Maternal":              "#34d399",   # teal
    "Oncology":              "#fbbf24",   # amber
    "General / Unspecified": "#94a3b8",   # slate
}


def classify(plan_name: str) -> str:
    nl = plan_name.lower()
    for domain, kws in DOMAIN_RULES.items():
        if any(kw in nl for kw in kws):
            return domain
    return "General / Unspecified"


def short(name: str) -> str:
    """Strip SNOMED trailing qualifiers from display names."""
    for suf in (
        " (record artifact)", " (regime/therapy)",
        " (procedure)", " (finding)", " (disorder)",
        " (situation)",
    ):
        if name.lower().endswith(suf):
            return name[: -len(suf)]
    return name


# ── FHIR bundle parsing ───────────────────────────────────────────────────────

def parse_bundles(package_dir: str):
    files = sorted(glob.glob(f"{package_dir}/Bundle-*.json"))
    if not files:
        raise FileNotFoundError(f"No Bundle-*.json files in '{package_dir}'")

    all_plans: list[dict] = []
    patients:  list[dict] = []

    for bf in files:
        with open(bf, encoding="utf-8") as fh:
            bundle = json.load(fh)

        # Index Goal resources by UUID for cross-referencing
        goals_by_uuid: dict[str, dict] = {}
        for entry in bundle.get("entry", []):
            r = entry.get("resource", {})
            if r.get("resourceType") == "Goal":
                uid = entry.get("fullUrl", "").replace("urn:uuid:", "")
                goals_by_uuid[uid] = r

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

        patients.append(
            dict(name=patient_name, gender=gender, dob=dob, plan_count=0)
        )
        bundle_plan_count = 0

        for entry in bundle.get("entry", []):
            r = entry.get("resource", {})
            if r.get("resourceType") != "CarePlan":
                continue

            cp_status = r.get("status", "unknown")
            period_start = r.get("period", {}).get("start", "")
            yr = int(period_start[:4]) if period_start and len(period_start) >= 4 else None

            # Resolve goal descriptions
            goal_descs: list[str] = []
            for gref in r.get("goal", []):
                guid = gref.get("reference", "").replace("urn:uuid:", "")
                gr   = goals_by_uuid.get(guid, {})
                desc = gr.get("description", {}).get("text", "")
                if desc:
                    goal_descs.append(desc)

            for act in r.get("activity", []):
                detail   = act.get("detail", {})
                codings  = detail.get("code", {}).get("coding", [])
                plan_name = (
                    codings[0].get("display", detail.get("description", ""))
                    if codings else detail.get("description", "")
                )
                plan_code = codings[0].get("code", "") if codings else ""

                reasons: list[dict] = []
                for rc in detail.get("reasonCode", []):
                    rcodings = rc.get("coding", [])
                    if rcodings:
                        reasons.append(
                            dict(
                                display = rcodings[0].get("display", ""),
                                code    = rcodings[0].get("code", ""),
                            )
                        )

                all_plans.append(
                    dict(
                        plan_name    = plan_name,
                        plan_code    = plan_code,
                        domain       = classify(plan_name),
                        cp_status    = cp_status,
                        period_start = period_start,
                        year         = yr,
                        decade       = (yr // 10 * 10) if yr else None,
                        reasons      = reasons,
                        goal_count   = len(goal_descs),
                        patient      = patient_name,
                    )
                )
                bundle_plan_count += 1

        patients[-1]["plan_count"] = bundle_plan_count

    return all_plans, patients


# ── Statistics builder ────────────────────────────────────────────────────────

def build_stats(all_plans: list[dict], patients: list[dict]) -> dict:
    n = len(patients)

    # ── Plan type frequency ──
    type_counter   = Counter(p["plan_name"]  for p in all_plans)
    code_map       = {p["plan_name"]: p["plan_code"] for p in all_plans}
    domain_map     = {p["plan_name"]: p["domain"]    for p in all_plans}

    # Unique patients per plan type
    plan_patients: dict[str, set] = defaultdict(set)
    for p in all_plans:
        plan_patients[p["plan_name"]].add(p["patient"])

    plan_types = [
        dict(
            name        = plan_name,
            code        = code_map[plan_name],
            domain      = domain_map[plan_name],
            count       = cnt,                              # total plan entries
            n_patients  = len(plan_patients[plan_name]),    # unique patients
        )
        for plan_name, cnt in type_counter.most_common()
    ]

    # ── Domain totals ──
    domain_totals: dict[str, int] = defaultdict(int)
    for pt in plan_types:
        domain_totals[pt["domain"]] += pt["count"]

    # ── Goals per plan type ──
    goals_by_type: dict[str, list[int]] = defaultdict(list)
    for p in all_plans:
        goals_by_type[p["plan_name"]].append(p["goal_count"])

    goals_stats = {
        name: dict(
            total = sum(gcounts),
            avg   = round(sum(gcounts) / len(gcounts), 1),
            n     = len(gcounts),
        )
        for name, gcounts in goals_by_type.items()
        if sum(gcounts) > 0
    }

    # ── Trigger / reason conditions ──
    all_reasons: list[dict] = []
    for p in all_plans:
        all_reasons.extend(p["reasons"])
    reason_counter  = Counter(r["display"] for r in all_reasons)
    reason_code_map = {r["display"]: r["code"] for r in all_reasons}

    trigger_conditions = [
        dict(display=disp, code=reason_code_map.get(disp, ""), count=cnt)
        for disp, cnt in reason_counter.most_common(12)
    ]

    # ── Decade distribution ──
    decade_counter: Counter = Counter()
    for p in all_plans:
        if p["decade"] is not None:
            decade_counter[p["decade"]] += 1

    decade_dist = [
        dict(decade=dec, label=f"{dec}s", count=cnt)
        for dec, cnt in sorted(decade_counter.items())
    ]

    # ── Per-patient burden ──
    pp_counts = [p["plan_count"] for p in patients]
    patients_with_plans = sum(1 for v in pp_counts if v > 0)
    pp_nonzero = [v for v in pp_counts if v > 0]

    return dict(
        n_patients          = n,
        n_patients_with     = patients_with_plans,
        n_total             = len(all_plans),
        n_types             = len(type_counter),
        n_active            = sum(1 for p in all_plans if p["cp_status"] == "active"),
        n_goals_total       = sum(p["goal_count"] for p in all_plans),
        avg_goals_per_plan  = round(
            sum(p["goal_count"] for p in all_plans) / len(all_plans), 1
        ),
        avg_plans_per_patient = round(statistics.mean(pp_nonzero), 1) if pp_nonzero else 0,
        plan_types          = plan_types,
        domain_totals       = dict(domain_totals),
        goals_stats         = goals_stats,
        trigger_conditions  = trigger_conditions,
        decade_dist         = decade_dist,
        pp_dist             = dict(sorted(Counter(pp_counts).items())),
    )


# ── HTML fragment builder ─────────────────────────────────────────────────────

def build_html(stats: dict, version_str: str) -> str:
    plan_types   = stats["plan_types"]
    domains      = stats["domain_totals"]
    domain_total = sum(domains.values())
    max_count    = plan_types[0]["count"] if plan_types else 1
    trigger      = stats["trigger_conditions"]
    trig_max     = trigger[0]["count"] if trigger else 1

    # ── Serialise for JS ──
    js_plans   = json.dumps([
        dict(
            name       = short(pt["name"]),
            code       = pt["code"],
            count      = pt["count"],
            n_patients = pt["n_patients"],
            pct        = round(pt["n_patients"] / stats["n_patients"] * 100),
            domain     = pt["domain"],
        )
        for pt in plan_types
    ])

    js_domains = json.dumps([
        dict(label=k, value=v)
        for k, v in sorted(domains.items(), key=lambda x: -x[1])
    ])

    js_trigger = json.dumps([
        dict(
            display = short(t["display"]),
            code    = t["code"],
            count   = t["count"],
            pct     = round(t["count"] / stats["n_patients"] * 100),
        )
        for t in trigger
    ])

    js_colors = json.dumps(DOMAIN_COLORS)

    # ── Goals per plan — top rows with goals ──
    goals_rows = sorted(
        [(name, gs) for name, gs in stats["goals_stats"].items()],
        key=lambda x: -x[1]["avg"],
    )

    goals_html = ""
    g_max_avg = goals_rows[0][1]["avg"] if goals_rows else 1
    for name, gs in goals_rows:
        col  = DOMAIN_COLORS.get(
            next((pt["domain"] for pt in plan_types if pt["name"] == name), "General / Unspecified"),
            "#94a3b8"
        )
        w    = round(gs["avg"] / g_max_avg * 100)
        goals_html += f"""
          <div class="sd-cp-goal-row">
            <span class="sd-cp-goal-name" title="{_html.escape(short(name))}">{_html.escape(short(name))}</span>
            <div class="sd-bar-track" style="height:8px">
              <div class="sd-bar-fill" style="background:{col};width:{w}%"></div>
            </div>
            <span class="sd-cp-goal-val">{gs['avg']:.1f}</span>
          </div>"""

    # ── Decade histogram ──
    decade_dist = stats["decade_dist"]
    d_max = max(d["count"] for d in decade_dist) if decade_dist else 1
    decade_html = ""
    for d in decade_dist:
        h = round(d["count"] / d_max * 120)
        decade_html += f"""
          <div class="sd-burden-col">
            <div class="sd-burden-cnt">{d['count']}</div>
            <div class="sd-burden-bar" style="height:{h}px;background:linear-gradient(180deg,#c87028,#532ae7)"></div>
            <div class="sd-burden-lbl">{d['label']}</div>
          </div>"""

    # ── Per-patient burden histogram ──
    pp_dist = stats["pp_dist"]
    pp_max  = max(pp_dist.values()) if pp_dist else 1
    pp_html = ""
    pp_grad = ["#34d399", "#1d8a6e", "#003087", "#1a4f9f", "#f47920", "#e4521b", "#d94f2b"]
    for i, (cnt, freq) in enumerate(sorted(pp_dist.items())):
        if cnt == 0:
            continue   # skip patients with zero plans
        h   = round(freq / pp_max * 120)
        col = pp_grad[min(i, len(pp_grad) - 1)]
        pp_html += f"""
          <div class="sd-burden-col">
            <div class="sd-burden-cnt">{freq}</div>
            <div class="sd-burden-bar" style="height:{h}px;background:{col}"></div>
            <div class="sd-burden-lbl">{cnt}</div>
          </div>"""

    # ── KPI cards ──
    active_pct = round(stats["n_active"] / stats["n_total"] * 100)
    kpis = [
        ("Patients w/ plans",    f"{stats['n_patients_with']}/{stats['n_patients']}",
                                                                    "#34d399",  ""),
        ("Care plan entries",    str(stats["n_total"]),              "#60a5fa",  ""),
        ("Unique plan types",    str(stats["n_types"]),              "#c084fc",  ""),
        ("Goals referenced",     str(stats["n_goals_total"]),        "#fbbf24",  ""),
        ("Avg. goals / plan",    str(stats["avg_goals_per_plan"]),   "#fb923c",  ""),
        ("Avg. plans / patient", str(stats["avg_plans_per_patient"]), "#f07086", ""),
        ("Active plans",         f"{active_pct}%",                   "#f87171",  "active"),
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
    domains_present = sorted({pt["domain"] for pt in plan_types})
    chips_html = '<button class="sd-chip active" data-cat="All" style="border-color:rgba(255,255,255,.25);color:#cfcfcf">All</button>'
    for d in domains_present:
        col = DOMAIN_COLORS.get(d, "#94a3b8")
        chips_html += (
            f'<button class="sd-chip" data-cat="{_html.escape(d)}" '
            f'style="border-color:{col};color:{col}">{_html.escape(d)}</button>'
        )

    # ── Build fragment ─────────────────────────────────────────────────────────
    fragment = f"""<!-- ═══════════════════════════════════════════════════════════
     SynderAI EPS CarePlan Statistics Dashboard
     Generated by synderai_eps_careplan_dashboard.py · AGPL-3.0 · HL7 Europe
     Embed between <nav> and <footer> in the SynderAI page template.
═══════════════════════════════════════════════════════════ -->

<style>
/* ── CarePlan dashboard (on top of synderai styles.css) ── */
#cp-dashboard {{
  margin: 0 auto;
  margin-top: 2rem;
  padding-bottom: 3rem;
}}
#cp-dashboard .sd-teaser {{
  font-size: 1rem;
  color: #7b5da7;
  margin: 1rem 0;
  margin-left: 2.7rem;
  letter-spacing: .04em;
  text-transform: uppercase;
  font-weight: 600;
}}
#cp-dashboard h1 {{
  font-size: 2.2rem;
  font-weight: 800;
  color: #cfcfcf;
  margin-left: 2.7rem;
  margin-bottom: .5rem;
}}
#cp-dashboard h1 em {{ font-style: normal; color: #f07086; }}
#cp-dashboard h2 {{
  font-size: 1rem;
  font-weight: 600;
  color: #cfcfcf;
  margin-left: 2.7rem;
  margin-bottom: 1.2rem;
  display: flex;
  align-items: center;
  gap: .6rem;
}}
#cp-dashboard h2 .sd-h2-line {{
  flex: 1; height: 1px; background: rgba(255,255,255,.1);
}}
#cp-dashboard h2 .sd-h2-pill {{
  font-family: monospace;
  font-size: .7rem;
  letter-spacing: .08em;
  padding: 2px 10px;
  border-radius: 20px;
  white-space: nowrap;
}}
#cp-dashboard h2 .sd-h2-pill.careplan {{
  background: rgba(192,132,252,.12);
  border: 1px solid rgba(192,132,252,.3);
  color: #c084fc;
}}
#cp-dashboard h2 .sd-h2-pill.goals {{
  background: rgba(251,191,36,.1);
  border: 1px solid rgba(251,191,36,.3);
  color: #fbbf24;
}}
#cp-dashboard h2 .sd-h2-pill.trigger {{
  background: rgba(248,113,113,.1);
  border: 1px solid rgba(248,113,113,.3);
  color: #f87171;
}}
#cp-dashboard h2 .sd-h2-pill.timeline {{
  background: rgba(148,163,184,.1);
  border: 1px solid rgba(148,163,184,.25);
  color: #94a3b8;
}}

/* Reuse shared dashboard CSS */
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
.sd-legend-pct {{ font-family: monospace; font-size: .61rem; color: rgba(255,255,255,.38); }}
.sd-legend-n   {{ font-family: monospace; font-size: .61rem; color: rgba(255,255,255,.55); min-width: 24px; text-align: right; }}

/* Goals per plan */
.sd-cp-goal-row {{
  display: grid;
  grid-template-columns: 1fr 100px 36px;
  align-items: center;
  gap: 8px;
  padding: 3px 4px;
  border-radius: 5px;
  transition: background .12s;
}}
.sd-cp-goal-row:hover {{ background: rgba(255,255,255,.05); }}
.sd-cp-goal-name {{
  font-size: .72rem;
  color: #cfcfcf;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}}
.sd-cp-goal-val {{
  font-family: monospace;
  font-size: .65rem;
  color: rgba(255,255,255,.45);
  text-align: right;
  white-space: nowrap;
}}

/* Trigger conditions */
.sd-trig-row {{
  display: grid;
  grid-template-columns: 1fr 90px 42px;
  align-items: center;
  gap: 7px;
  padding: 3px 4px;
  border-radius: 5px;
  transition: background .12s;
  cursor: default;
}}
.sd-trig-row:hover {{ background: rgba(255,255,255,.05); }}
.sd-trig-name {{
  font-size: .73rem;
  color: #cfcfcf;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}}
.sd-trig-pct {{
  font-family: monospace;
  font-size: .63rem;
  color: rgba(255,255,255,.4);
  text-align: right;
}}

/* Burden / decade histograms */
.sd-burden-wrap {{
  display: flex;
  align-items: flex-end;
  height: 140px;
  gap: 5px;
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

/* Status strip */
.sd-status-strip {{
  height: 10px;
  border-radius: 5px;
  overflow: hidden;
  margin: 8px 0;
}}
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

/* Tooltip */
#cp-tooltip {{
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
  max-width: 255px;
  display: none;
  backdrop-filter: blur(12px);
  box-shadow: 0 8px 32px rgba(0,0,0,.6);
}}
#cp-tooltip .tt-name {{ font-weight: 700; color: #fff; margin-bottom: 3px; }}
#cp-tooltip .tt-code {{ font-family: monospace; font-size: .6rem; color: #7b5da7; margin-bottom: 5px; }}
#cp-tooltip .tt-row {{
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-family: monospace;
  font-size: .63rem;
  margin-top: 2px;
}}
#cp-tooltip .tt-key {{ color: rgba(255,255,255,.45); }}

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

@media (max-width: 900px) {{
  #cp-dashboard {{ max-width: 95%; }}
  .sd-main-grid, .sd-bot-grid {{ grid-template-columns: 1fr; }}
  .sd-bar-row {{ grid-template-columns: 140px 1fr 40px; }}
  .sd-kpi-grid {{ grid-template-columns: repeat(3, 1fr); }}
}}
</style>

<!-- ═══ CAREPLAN DASHBOARD FRAGMENT ═══ -->
<div id="cp-dashboard">

  <div class="sd-teaser">SynderAI · European Patient Summary · Package {version_str}</div>
  <h1>EPS Cohort — <em>Care Plan Statistics</em></h1>

  <!-- ── KPI strip ── -->
  <div class="sd-kpi-grid">
{kpi_html}
  </div>

  <!-- ── Care plans section ── -->
  <h2>
    <span class="sd-h2-line"></span>
    <span class="sd-h2-pill careplan">Care Plans · {stats["n_types"]} plan types · {stats["n_total"]} entries · {stats["n_patients_with"]}/{stats["n_patients"]} patients</span>
    <span class="sd-h2-line"></span>
  </h2>

  <div class="sd-main-grid">

    <!-- Bar chart: plan types by frequency -->
    <div class="example-item">
      <div class="sd-panel-head">
        <span class="sd-panel-title">Care plan types by patient coverage</span>
        <span class="sd-panel-note">% of N={stats["n_patients"]} patients with ≥1 entry of that plan type</span>
      </div>
      <div class="sd-chip-row" id="cpChips">{chips_html}</div>
      <div class="sd-bar-list" id="cpBarChart"></div>
    </div>

    <!-- Right column -->
    <div class="sd-right-col">

      <!-- Donut: domain distribution -->
      <div class="example-item">
        <div class="sd-panel-head">
          <span class="sd-panel-title">Domain distribution</span>
          <span class="sd-panel-note">{domain_total} total entries</span>
        </div>
        <div class="sd-donut-wrap">
          <svg id="cpDonut" class="sd-donut-svg" width="148" height="148" viewBox="0 0 148 148"></svg>
          <div id="cpDonutLegend" style="width:100%"></div>
        </div>
      </div>

      <!-- Plan status -->
      <div class="example-item">
        <div class="sd-panel-head">
          <span class="sd-panel-title">Plan status</span>
          <span class="sd-panel-note">{stats["n_total"]} CarePlan entries</span>
        </div>
        <div class="sd-status-strip" style="background:linear-gradient(90deg,#34d399,#059669)"></div>
        <div class="sd-status-legend">
          <span><span class="sd-s-dot" style="background:#34d399"></span>Active {stats["n_active"]} ({active_pct}&thinsp;%)</span>
        </div>
        <div style="margin-top:.8rem;font-family:monospace;font-size:.65rem;color:rgba(255,255,255,.35)">
          All CarePlan resources carry <code style="font-family:monospace;font-size:.63rem;background:rgba(255,255,255,.07);padding:1px 4px;border-radius:3px">status = active</code>
        </div>
      </div>

    </div>
  </div><!-- /sd-main-grid -->

  <!-- ── Goals section ── -->
  <h2 style="margin-top:1.5rem">
    <span class="sd-h2-line"></span>
    <span class="sd-h2-pill goals">Goals · {stats["n_goals_total"]} referenced · avg {stats["avg_goals_per_plan"]} per plan</span>
    <span class="sd-h2-line"></span>
  </h2>

  <div class="sd-bot-grid">

    <!-- Goals per plan type -->
    <div class="example-item">
      <div class="sd-panel-head">
        <span class="sd-panel-title">Avg. goals per plan type</span>
        <span class="sd-panel-note">Plan types with ≥1 linked goal · {stats["n_goals_total"]} total goals</span>
      </div>
      <div style="display:flex;flex-direction:column;gap:5px">
{goals_html}
      </div>
    </div>

    <!-- Trigger conditions -->
    <div class="example-item">
      <div class="sd-panel-head">
        <span class="sd-panel-title">Top trigger conditions</span>
        <span class="sd-panel-note">reasonCode driving care plans · % of N={stats["n_patients"]}</span>
      </div>
      <div class="sd-bar-list" id="cpTrigger"></div>
    </div>

  </div><!-- /first bot-grid -->

  <!-- ── Timeline & burden ── -->
  <h2 style="margin-top:1.5rem">
    <span class="sd-h2-line"></span>
    <span class="sd-h2-pill timeline">Plan initiation timeline &amp; patient burden</span>
    <span class="sd-h2-line"></span>
  </h2>

  <div class="sd-bot-grid">

    <!-- Decade distribution -->
    <div class="example-item">
      <div class="sd-panel-head">
        <span class="sd-panel-title">Plan start decade</span>
        <span class="sd-panel-note">When care plans were initiated (period.start)</span>
      </div>
      <div class="sd-burden-wrap" id="cpDecade">
{decade_html}
      </div>
      <div style="text-align:center;font-family:monospace;font-size:.59rem;color:rgba(255,255,255,.28);margin-top:8px">
        Earliest 1940s · most recent 2020s · reflects condition onset dates
      </div>
    </div>

    <!-- Plans per patient -->
    <div class="example-item">
      <div class="sd-panel-head">
        <span class="sd-panel-title">Care plans per patient</span>
        <span class="sd-panel-note">Distribution across {stats["n_patients_with"]} patients with ≥1 plan</span>
      </div>
      <div class="sd-burden-wrap" id="cpBurden">
{pp_html}
      </div>
      <div style="text-align:center;font-family:monospace;font-size:.59rem;color:rgba(255,255,255,.28);margin-top:8px">
        avg {stats["avg_plans_per_patient"]} plans/patient · range 1–{max(k for k in stats["pp_dist"] if stats["pp_dist"][k] > 0)} · 6 patients with no plan
      </div>
    </div>

  </div><!-- /second bot-grid -->

  <!-- Methodological note -->
  <div class="example-item sd-note">
    <strong>Notes.</strong>
    All {stats["n_total"]} CarePlan resources carry <code>status = active</code> and
    <code>intent = plan</code> with a single <code>activity.detail</code> entry per resource.
    All plan types are encoded with <strong>SNOMED CT</strong>.
    <em>Patient coverage</em> = number of patients with ≥1 entry of a given plan type / N = {stats["n_patients"]}.
    <em>Goals per plan</em> = linked Goal resources via <code>CarePlan.goal</code> reference;
    plans without goals carry no goal references.
    <em>Trigger conditions</em> are taken from <code>activity.detail.reasonCode</code>
    and may differ from the patient's active conditions in the problem list.
    <strong>This dataset is entirely synthetic.</strong>
    Analysis: <code>synderai_eps_careplan_dashboard.py</code> ·
    <a href="https://github.com/hl7-eu/SYNDERAI">AGPL-3.0 · GitHub</a>.
  </div>

</div><!-- /#cp-dashboard -->

<div id="cp-tooltip"></div>

<script>
(function () {{
  "use strict";

  const PLANS      = {js_plans};
  const DOMAINS    = {js_domains};
  const TRIGGER    = {js_trigger};
  const CAT_COLORS = {js_colors};
  const N          = {stats["n_patients"]};
  const DOM_TOTAL  = {domain_total};
  const MAX_COUNT  = {max_count};
  const TRIG_MAX   = {trig_max};

  function col(cat) {{ return CAT_COLORS[cat] || "#94a3b8"; }}

  /* ── Bar chart ────────────────────────────────────────────────────── */
  function buildBars(filter) {{
    const el = document.getElementById("cpBarChart");
    el.innerHTML = "";
    PLANS.forEach(p => {{
      const vis = filter === "All" || p.domain === filter;
      const w   = (p.n_patients / MAX_COUNT * 100).toFixed(1);
      const row = document.createElement("div");
      row.className = "sd-bar-row" + (vis ? "" : " hidden");
      row.innerHTML = `
        <div class="sd-bar-label" title="${{p.name}} · SNOMED ${{p.code}}">
          ${{p.name}}<span class="sd-snomed">${{p.code}}</span>
        </div>
        <div class="sd-bar-track">
          <div class="sd-bar-fill" style="background:${{col(p.domain)}};width:${{w}}%"></div>
        </div>
        <div class="sd-bar-pct">${{p.pct}}%</div>`;
      row.addEventListener("mouseenter", e => showTT(e, p));
      row.addEventListener("mousemove",  e => moveTT(e));
      row.addEventListener("mouseleave", hideTT);
      el.appendChild(row);
    }});
  }}
  buildBars("All");

  document.getElementById("cpChips").addEventListener("click", e => {{
    const btn = e.target.closest(".sd-chip");
    if (!btn) return;
    document.querySelectorAll("#cpChips .sd-chip").forEach(b => b.classList.remove("active"));
    btn.classList.add("active");
    buildBars(btn.dataset.cat);
  }});

  /* ── Tooltip (declared first so closures below can reference them) ── */
  const ttEl = document.getElementById("cp-tooltip");

  function showTT(e, p) {{
    ttEl.innerHTML = `
      <div class="tt-name">${{p.name}}</div>
      <div class="tt-code">SNOMED CT &nbsp;${{p.code}}</div>
      <div style="font-size:.65rem;color:${{col(p.domain)}};margin-bottom:5px">${{p.domain}}</div>
      <div class="tt-row"><span class="tt-key">Patients with this plan</span><span>${{p.n_patients}} / ${{N}}</span></div>
      <div class="tt-row"><span class="tt-key">Patient coverage</span><span>${{p.pct}}&thinsp;%</span></div>
      <div class="tt-row"><span class="tt-key">Total plan entries</span><span>${{p.count}}</span></div>`;
    ttEl.style.display = "block";
    moveTT(e);
  }}
  function moveTT(e) {{
    ttEl.style.left = Math.min(e.clientX + 12, window.innerWidth - 265) + "px";
    ttEl.style.top  = (e.clientY - 10) + "px";
  }}
  function hideTT() {{ ttEl.style.display = "none"; }}

  /* ── Donut (createElementNS – no inline handlers) ───────────────── */
  (function () {{
    const NS  = "http://www.w3.org/2000/svg";
    const svg = document.getElementById("cpDonut");
    const leg = document.getElementById("cpDonutLegend");
    const cx = 74, cy = 74, R = 60, r = 38;
    let angle = -Math.PI / 2;

    DOMAINS.forEach(d => {{
      const sw = d.value / DOM_TOTAL * 2 * Math.PI;
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
    mkText(74, 69, String(DOM_TOTAL), "18", "800", "white", null);
    mkText(74, 82, "PLANS", "7", null, "rgba(255,255,255,.38)", "1.5");

    leg.innerHTML = DOMAINS.map(d => `
      <div class="sd-legend-item">
        <span class="sd-legend-dot" style="background:${{col(d.label)}}"></span>
        <span class="sd-legend-name">${{d.label}}</span>
        <span class="sd-legend-pct">${{(d.value/DOM_TOTAL*100).toFixed(0)}}%</span>
        <span class="sd-legend-n">${{d.value}}</span>
      </div>`).join("");
  }})();

  /* ── Trigger conditions bar ──────────────────────────────────────── */
  (function () {{
    const el = document.getElementById("cpTrigger");
    el.innerHTML = "";
    TRIGGER.forEach(t => {{
      const w  = (t.count / TRIG_MAX * 100).toFixed(1);
      const row = document.createElement("div");
      row.className = "sd-bar-row";
      row.innerHTML = `
        <div class="sd-bar-label" title="${{t.display}} · SNOMED ${{t.code}}">${{t.display}}<span class="sd-snomed">${{t.code}}</span></div>
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
        "--out", default="eps_careplan_dashboard.html",
        help="Output HTML fragment file (default: eps_careplan_dashboard.html)"
    )
    parser.add_argument(
        "--semver", default="0.0.0+000000",
        help="Version of the package (semver, default: 0.0.0+000000)"
    )
    args = parser.parse_args()

    print(f"Reading bundles from: {args.package}")
    all_plans, patients = parse_bundles(args.package)
    print(f"Parsed {len(patients)} patients, {len(all_plans)} CarePlan entries.")

    stats = build_stats(all_plans, patients)

    print(f"\n── Summary ─────────────────────────────────────────────────")
    print(f"  Patients:              {stats['n_patients']}")
    print(f"  Patients with plans:   {stats['n_patients_with']}")
    print(f"  Total plan entries:    {stats['n_total']}")
    print(f"  Unique plan types:     {stats['n_types']}")
    print(f"  Goals referenced:      {stats['n_goals_total']}")
    print(f"  Avg goals/plan:        {stats['avg_goals_per_plan']}")
    print(f"  Avg plans/patient:     {stats['avg_plans_per_patient']}")
    print(f"\n── Plan types by frequency ─────────────────────────────────")
    for pt in stats["plan_types"]:
        bar = "█" * int(pt["n_patients"] / stats["n_patients"] * 40)
        print(f"  {pt['n_patients']:2d}/50  {bar:<40}  {short(pt['name'])}")
    print(f"\n── Domain totals ───────────────────────────────────────────")
    for d, cnt in sorted(stats["domain_totals"].items(), key=lambda x: -x[1]):
        print(f"  {d:<28} {cnt:3d}")
    print(f"\n── Top trigger conditions ──────────────────────────────────")
    for t in stats["trigger_conditions"]:
        print(f"  {t['count']:3d}  {short(t['display'])}")

    fragment = build_html(stats, args.semver)

    with open(args.out, "w", encoding="utf-8") as fh:
        fh.write(fragment)
    print(f"\nHTML fragment written to: {args.out}")
    print("Embed it between <nav> and <footer> in the SynderAI page template.")


if __name__ == "__main__":
    main()
