#!/usr/bin/env python3
"""
=============================================================================
Synthea Output Validator — European Epidemiology Reference
=============================================================================
Measures the statistical fit of a Synthea-generated conditions.csv against
WHO Europe / Eurostat / GBD 2019 reference prevalence data.

Metrics computed:
  1. Kullback-Leibler (KL) Divergence     — information-theoretic fit
  2. Chi-Square Goodness-of-Fit           — statistical significance test
  3. Jensen-Shannon Divergence            — symmetric, bounded [0,1] version of KL
  4. Per-condition over/under-sampling    — signed percentage deviation
  5. Weighted Mean Absolute Error (WMAE)  — practical calibration error

Reference sources embedded in EUROPEAN_REFERENCE_PREVALENCE:
  - WHO Europe: Hypertension fact sheet 2023
  - WHO Europe: Diabetes fact sheet 2022
  - Eurostat EHIS wave 3 (2019): Chronic conditions
  - ECDC: Antimicrobial resistance & infectious disease burden 2022
  - GBD 2019: Europe regional estimates (IHME)
  - ESC Atlas of Cardiology 2021
  - European Respiratory Society White Book 2013 / ERS 2022 update
  - Mental Health Europe / ECNP: 2023 report on mental disorders in Europe

Usage:
  python3 validate_synthea_europe.py --input conditions.csv [--population N]
  python3 validate_synthea_europe.py --input conditions.csv --output report.html

Requirements:
  pip install pandas numpy scipy matplotlib seaborn tabulate
=============================================================================
"""

import argparse
import sys
import warnings
from pathlib import Path

import numpy as np
import pandas as pd
from scipy.stats import chisquare
from scipy.special import kl_div
from tabulate import tabulate
import matplotlib.pyplot as plt
import matplotlib.patches as mpatches
import seaborn as sns

warnings.filterwarnings("ignore", category=RuntimeWarning)

# =============================================================================
# EUROPEAN REFERENCE PREVALENCE TABLE
# Prevalence expressed as % of total population (all ages combined)
# Sources cited inline per condition
# =============================================================================

EUROPEAN_REFERENCE_PREVALENCE = {
    # ---- Cardiovascular -------------------------------------------------------
    "59621000": {
        "display": "Essential hypertension",
        "ref_prevalence_pct": 32.0,
        "source": "WHO Europe 2023; EHIS 2019",
        "icd10": "I10",
        "category": "Cardiovascular"
    },
    "414545008": {
        "display": "Ischemic heart disease",
        "ref_prevalence_pct": 4.5,
        "source": "ESC Atlas of Cardiology 2021; GBD 2019 Europe",
        "icd10": "I20-I25",
        "category": "Cardiovascular"
    },
    "22298006": {
        "display": "Myocardial infarction",
        "ref_prevalence_pct": 1.8,
        "source": "ESC Atlas 2021; EuroHeart 2022",
        "icd10": "I21",
        "category": "Cardiovascular"
    },
    "84114007": {
        "display": "Heart failure",
        "ref_prevalence_pct": 2.2,
        "source": "ESC Heart Failure Atlas 2021",
        "icd10": "I50",
        "category": "Cardiovascular"
    },
    "49436004": {
        "display": "Atrial fibrillation",
        "ref_prevalence_pct": 2.5,
        "source": "ESC/EHRA 2020; EuroObservational Research Programme",
        "icd10": "I48",
        "category": "Cardiovascular"
    },

    # ---- Metabolic / Endocrine ------------------------------------------------
    "44054006": {
        "display": "Diabetes mellitus type 2",
        "ref_prevalence_pct": 7.5,
        "source": "IDF Diabetes Atlas 10th Ed. 2021; WHO Europe 2022",
        "icd10": "E11",
        "category": "Metabolic"
    },
    "15777000": {
        "display": "Prediabetes",
        "ref_prevalence_pct": 8.0,
        "source": "IDF Atlas 2021; Diabetes Care Europe estimates",
        "icd10": "R73.09",
        "category": "Metabolic"
    },
    "162864005": {
        "display": "Obesity (BMI 30+)",
        "ref_prevalence_pct": 17.0,
        "source": "WHO Europe Obesity Report 2022; Eurostat EHIS 2019",
        "icd10": "E66",
        "category": "Metabolic"
    },
    "55822004": {
        "display": "Hyperlipidaemia",
        "ref_prevalence_pct": 22.0,
        "source": "EAS Dyslipidaemia survey; Eurostat EHIS 2019",
        "icd10": "E78",
        "category": "Metabolic"
    },
    "237602007": {
        "display": "Metabolic syndrome",
        "ref_prevalence_pct": 25.0,
        "source": "IDF; DECODE Study; GBD 2019 Europe",
        "icd10": "E88.81",
        "category": "Metabolic"
    },

    # ---- Respiratory ----------------------------------------------------------
    "13645005": {
        "display": "COPD",
        "ref_prevalence_pct": 6.5,
        "source": "ERS White Book 2013; BOLD Europe 2022; GBD 2019",
        "icd10": "J44",
        "category": "Respiratory"
    },
    "195967001": {
        "display": "Asthma",
        "ref_prevalence_pct": 7.0,
        "source": "GINA 2023; ERS / European Lung Foundation survey",
        "icd10": "J45",
        "category": "Respiratory"
    },
    "444814009": {
        "display": "Viral sinusitis",
        "ref_prevalence_pct": 0.8,
        "source": "EPOS 2020 guidelines; GP consultation rates EU",
        "icd10": "J01",
        "category": "Respiratory"
    },
    "195662009": {
        "display": "Acute viral pharyngitis",
        "ref_prevalence_pct": 0.7,
        "source": "ECDC Antimicrobial Resistance 2022; Eurostat health care use",
        "icd10": "J02.9",
        "category": "Respiratory"
    },

    # ---- Mental Health --------------------------------------------------------
    "35489007": {
        "display": "Depression",
        "ref_prevalence_pct": 6.9,
        "source": "ECNP/EBC 2023 Size and Burden of Mental Disorders in Europe",
        "icd10": "F32-F33",
        "category": "Mental Health"
    },
    "197480006": {
        "display": "Anxiety disorder",
        "ref_prevalence_pct": 6.5,
        "source": "ECNP/EBC 2023; WHO Mental Health Atlas Europe 2022",
        "icd10": "F40-F41",
        "category": "Mental Health"
    },
    "80583007": {
        "display": "Severe anxiety (panic)",
        "ref_prevalence_pct": 2.0,
        "source": "ECNP/EBC 2023; ESEMeD study",
        "icd10": "F41.0",
        "category": "Mental Health"
    },
    "36923009": {
        "display": "Major depression single episode",
        "ref_prevalence_pct": 4.0,
        "source": "ECNP/EBC 2023; ESEMeD; GBD 2019 Europe",
        "icd10": "F32",
        "category": "Mental Health"
    },

    # ---- Musculoskeletal ------------------------------------------------------
    "396275006": {
        "display": "Osteoarthritis",
        "ref_prevalence_pct": 9.5,
        "source": "EULAR / Arthritis Research UK; GBD 2019 Europe",
        "icd10": "M15-M19",
        "category": "Musculoskeletal"
    },
    "69896004": {
        "display": "Rheumatoid arthritis",
        "ref_prevalence_pct": 0.8,
        "source": "EULAR 2022; Eurostat EHIS 2019",
        "icd10": "M05-M06",
        "category": "Musculoskeletal"
    },
    "278860009": {
        "display": "Chronic low back pain",
        "ref_prevalence_pct": 8.0,
        "source": "EuroSpine / Airaksinen 2006; GBD 2019; Eurostat EHIS 2019",
        "icd10": "M54.5",
        "category": "Musculoskeletal"
    },
    "82423001": {
        "display": "Chronic pain",
        "ref_prevalence_pct": 19.0,
        "source": "Breivik et al. 2006 European Pain Survey; Pain Alliance Europe 2022",
        "icd10": "G89.29",
        "category": "Musculoskeletal"
    },
    "64572001": {
        "display": "Osteoporosis",
        "ref_prevalence_pct": 5.6,
        "source": "IOF European Audit 2021; Kanis et al. 2021",
        "icd10": "M81",
        "category": "Musculoskeletal"
    },

    # ---- Oncology -------------------------------------------------------------
    "254837009": {
        "display": "Malignant neoplasm of breast",
        "ref_prevalence_pct": 1.4,
        "source": "ECIS/IARC GLOBOCAN 2020 Europe; ECDC Cancer Burden",
        "icd10": "C50",
        "category": "Oncology"
    },
    "363418001": {
        "display": "Malignant neoplasm of prostate",
        "ref_prevalence_pct": 1.1,
        "source": "ECIS/IARC GLOBOCAN 2020 Europe",
        "icd10": "C61",
        "category": "Oncology"
    },
    "363406005": {
        "display": "Colorectal cancer",
        "ref_prevalence_pct": 0.7,
        "source": "ECIS/IARC GLOBOCAN 2020 Europe",
        "icd10": "C18-C20",
        "category": "Oncology"
    },
    "254637007": {
        "display": "Non-small cell lung cancer",
        "ref_prevalence_pct": 0.5,
        "source": "ECIS/IARC GLOBOCAN 2020 Europe; ERS Lung Cancer Report",
        "icd10": "C34",
        "category": "Oncology"
    },

    # ---- Neurological ---------------------------------------------------------
    "230690007": {
        "display": "Cerebrovascular accident (stroke)",
        "ref_prevalence_pct": 1.6,
        "source": "ESO European Stroke Organisation; GBD 2019 Europe",
        "icd10": "I63-I64",
        "category": "Neurological"
    },
    "26929004": {
        "display": "Alzheimer disease",
        "ref_prevalence_pct": 1.5,
        "source": "Alzheimer Europe Dementia Monitor 2022; Eurostat Ageing",
        "icd10": "G30",
        "category": "Neurological"
    },
    "56193007": {
        "display": "Dementia",
        "ref_prevalence_pct": 2.0,
        "source": "Alzheimer Europe 2022; WHO Europe NCD Report 2022",
        "icd10": "F00-F03",
        "category": "Neurological"
    },
    "32798002": {
        "display": "Parkinson disease",
        "ref_prevalence_pct": 0.3,
        "source": "European Parkinson's Disease Association; GBD 2019",
        "icd10": "G20",
        "category": "Neurological"
    },

    # ---- Renal ----------------------------------------------------------------
    "431855005": {
        "display": "Chronic kidney disease stage 1",
        "ref_prevalence_pct": 3.0,
        "source": "ERA-EDTA 2020; KDIGO CKD Prevalence in Europe",
        "icd10": "N18.1",
        "category": "Renal"
    },
    "431856006": {
        "display": "Chronic kidney disease stage 2",
        "ref_prevalence_pct": 3.1,
        "source": "ERA-EDTA 2020",
        "icd10": "N18.2",
        "category": "Renal"
    },
    "433144002": {
        "display": "Chronic kidney disease stage 3",
        "ref_prevalence_pct": 3.9,
        "source": "ERA-EDTA 2020; CKD Prognosis Consortium Europe",
        "icd10": "N18.3",
        "category": "Renal"
    },

    # ---- Gastrointestinal -----------------------------------------------------
    "68496003": {
        "display": "Polyp of colon",
        "ref_prevalence_pct": 2.5,
        "source": "European Society of Gastrointestinal Endoscopy 2022; ESGE",
        "icd10": "K63.5",
        "category": "Gastrointestinal"
    },
    "40055000": {
        "display": "Chronic sinusitis",
        "ref_prevalence_pct": 1.2,
        "source": "EPOS 2020; Fokkens et al. Rhinology 2020",
        "icd10": "J32",
        "category": "Gastrointestinal"
    },

    # ---- Infectious -----------------------------------------------------------
    "307426000": {
        "display": "Acute infective cystitis",
        "ref_prevalence_pct": 1.5,
        "source": "EAU Guidelines on Urological Infections 2023; ECDC",
        "icd10": "N30.0",
        "category": "Infectious"
    },

    # ---- Substance Use --------------------------------------------------------
    "10939881000119105": {
        "display": "Unhealthy alcohol drinking behaviour",
        "ref_prevalence_pct": 7.6,
        "source": "WHO Europe Alcohol & Health Status Report 2022; Eurostat EHIS",
        "icd10": "F10",
        "category": "Substance Use"
    },
}

# =============================================================================
# VALIDATION ENGINE
# =============================================================================

def load_synthea_conditions(filepath: str) -> pd.DataFrame:
    """Load Synthea conditions output (CSV or pre-aggregated markdown table)."""
    path = Path(filepath)
    if not path.exists():
        raise FileNotFoundError(f"Input file not found: {filepath}")

    suffix = path.suffix.lower()

    if suffix == ".csv":
        df = pd.read_csv(filepath, low_memory=False)
        # Standard Synthea conditions.csv columns: START, STOP, PATIENT, CODE, DESCRIPTION
        if "CODE" not in df.columns:
            raise ValueError("Expected 'CODE' column in Synthea conditions.csv")
        df["CODE"] = df["CODE"].astype(str).str.strip()
        counts = df["CODE"].value_counts().reset_index()
        counts.columns = ["code", "count"]
        counts["display"] = df.groupby("CODE")["DESCRIPTION"].first().reindex(counts["code"]).values
        return counts

    elif suffix in (".md", ".txt"):
        # Parse the markdown table format from the uploaded file
        rows = []
        with open(filepath) as f:
            for line in f:
                line = line.strip()
                if line.startswith("|") and not line.startswith("| ---") and not line.startswith("| **"):
                    parts = [p.strip() for p in line.split("|")[1:-1]]
                    if len(parts) >= 3 and parts[0] not in ("Code", "---"):
                        code = parts[0].strip()
                        display = parts[1].strip()
                        count_str = parts[2].replace(",", "").strip()
                        try:
                            count = int(count_str)
                            rows.append({"code": code, "display": display, "count": count})
                        except ValueError:
                            continue
        return pd.DataFrame(rows)

    else:
        raise ValueError(f"Unsupported file format: {suffix}. Use .csv or .md")


def compute_metrics(synthea_df: pd.DataFrame, total_patients: int) -> pd.DataFrame:
    """
    Compute per-condition fit metrics against European reference prevalence.

    For each condition in the reference table, compute:
      - synthea_count: number of records in Synthea output
      - synthea_prevalence_pct: count / total_patients * 100
      - ref_prevalence_pct: European reference (embedded table above)
      - deviation_pct: signed % difference from reference
      - over_under: "OVER" / "UNDER" / "OK" / "MISSING"
    """
    results = []
    synthea_map = dict(zip(synthea_df["code"].astype(str), synthea_df["count"]))

    for code, meta in EUROPEAN_REFERENCE_PREVALENCE.items():
        synthea_count = synthea_map.get(code, 0)
        synthea_prev = (synthea_count / total_patients) * 100 if total_patients > 0 else 0
        ref_prev = meta["ref_prevalence_pct"]
        deviation = ((synthea_prev - ref_prev) / ref_prev) * 100 if ref_prev > 0 else float("nan")

        if synthea_count == 0:
            status = "❌ MISSING"
        elif abs(deviation) <= 20:
            status = "✅ OK"
        elif deviation > 20:
            status = "⬆  OVER"
        else:
            status = "⬇  UNDER"

        results.append({
            "SNOMED": code,
            "Condition": meta["display"],
            "Category": meta["category"],
            "ICD-10": meta.get("icd10", ""),
            "Synthea Count": synthea_count,
            "Synthea Prev %": round(synthea_prev, 3),
            "EU Ref Prev %": ref_prev,
            "Deviation %": round(deviation, 1) if not np.isnan(deviation) else "—",
            "Status": status,
            "Source": meta["source"],
        })

    return pd.DataFrame(results)


def compute_kl_divergence(synthea_prevs: np.ndarray, ref_prevs: np.ndarray) -> float:
    """KL divergence D(ref || synthea) — how much info lost using Synthea vs ref."""
    eps = 1e-9
    p = ref_prevs / ref_prevs.sum()
    q = np.clip(synthea_prevs, eps, None)
    q = q / q.sum()
    return float(np.sum(kl_div(p, q)))


def compute_js_divergence(synthea_prevs: np.ndarray, ref_prevs: np.ndarray) -> float:
    """Jensen-Shannon divergence — symmetric, bounded [0,1]. 0 = perfect fit."""
    eps = 1e-9
    p = ref_prevs / ref_prevs.sum()
    q = np.clip(synthea_prevs, eps, None)
    q = q / q.sum()
    m = 0.5 * (p + q)
    js = 0.5 * np.sum(kl_div(p, m)) + 0.5 * np.sum(kl_div(q, m))
    return float(np.clip(js, 0, 1))


def compute_wmae(synthea_prevs: np.ndarray, ref_prevs: np.ndarray) -> float:
    """Weighted Mean Absolute Error, weighted by reference prevalence (emphasises common diseases)."""
    weights = ref_prevs / ref_prevs.sum()
    return float(np.sum(weights * np.abs(synthea_prevs - ref_prevs)))


def run_chisquare(synthea_counts: np.ndarray, ref_prevs: np.ndarray, total_patients: int):
    """Chi-square goodness-of-fit test.
    We scale expected counts to match the observed total to satisfy scipy's
    sum-equality requirement while preserving the relative proportions."""
    raw_expected = (ref_prevs / 100) * total_patients
    mask = raw_expected > 5  # chi-square requires expected >= 5
    if mask.sum() < 2:
        return None, None, "Insufficient expected counts for chi-square"
    obs = synthea_counts[mask].astype(float)
    exp = raw_expected[mask]
    # Rescale expected to match observed total (preserves shape of distribution)
    exp_scaled = exp * (obs.sum() / exp.sum())
    stat, p = chisquare(f_obs=obs, f_exp=exp_scaled)
    return stat, p, f"df={mask.sum()-1}"


def plot_comparison(results_df: pd.DataFrame, output_path: str = None):
    """
    Generate a grouped bar chart comparing Synthea vs EU reference prevalence
    per disease category.
    """
    plot_df = results_df[results_df["Synthea Prev %"].notna()].copy()
    plot_df = plot_df.sort_values("EU Ref Prev %", ascending=False).head(30)

    fig, axes = plt.subplots(2, 1, figsize=(18, 14))
    fig.suptitle(
        "Synthea Output vs. European Reference Prevalence\n(WHO Europe / Eurostat / GBD 2019)",
        fontsize=14, fontweight="bold", y=0.98
    )

    # --- Top plot: absolute prevalence comparison ---
    x = np.arange(len(plot_df))
    w = 0.4
    ax1 = axes[0]
    bars1 = ax1.bar(x - w/2, plot_df["EU Ref Prev %"], w, label="EU Reference", color="#2166ac", alpha=0.85)
    bars2 = ax1.bar(x + w/2, plot_df["Synthea Prev %"], w, label="Synthea Output", color="#d6604d", alpha=0.85)
    ax1.set_xticks(x)
    ax1.set_xticklabels(plot_df["Condition"], rotation=45, ha="right", fontsize=8)
    ax1.set_ylabel("Prevalence (%)", fontsize=10)
    ax1.set_title("Absolute Prevalence: EU Reference vs Synthea Output (Top 30 conditions)", fontsize=11)
    ax1.legend(fontsize=9)
    ax1.grid(axis="y", alpha=0.3)
    ax1.set_ylim(0, max(plot_df["EU Ref Prev %"].max(), plot_df["Synthea Prev %"].max()) * 1.15)

    # --- Bottom plot: % deviation from reference ---
    ax2 = axes[1]
    numeric_dev = pd.to_numeric(plot_df["Deviation %"], errors="coerce").fillna(0)
    colors = ["#d6604d" if v > 20 else "#4dac26" if abs(v) <= 20 else "#2166ac" for v in numeric_dev]
    ax2.bar(x, numeric_dev, color=colors, alpha=0.85)
    ax2.axhline(0, color="black", linewidth=0.8, linestyle="--")
    ax2.axhline(20, color="#d6604d", linewidth=0.8, linestyle=":", alpha=0.6, label="+20% threshold")
    ax2.axhline(-20, color="#2166ac", linewidth=0.8, linestyle=":", alpha=0.6, label="-20% threshold")
    ax2.set_xticks(x)
    ax2.set_xticklabels(plot_df["Condition"], rotation=45, ha="right", fontsize=8)
    ax2.set_ylabel("Deviation from EU Reference (%)", fontsize=10)
    ax2.set_title("Per-Condition Deviation from European Reference Prevalence", fontsize=11)
    over_patch  = mpatches.Patch(color="#d6604d", label="Over-sampled (>+20%)")
    ok_patch    = mpatches.Patch(color="#4dac26", label="Within ±20% — OK")
    under_patch = mpatches.Patch(color="#2166ac", label="Under-sampled (<-20%)")
    ax2.legend(handles=[over_patch, ok_patch, under_patch], fontsize=9)
    ax2.grid(axis="y", alpha=0.3)

    plt.tight_layout()

    if output_path:
        plt.savefig(output_path, dpi=150, bbox_inches="tight")
        print(f"\n📊 Chart saved to: {output_path}")
    else:
        plt.show()


def plot_category_heatmap(results_df: pd.DataFrame, output_path: str = None):
    """Heatmap of deviation by disease category."""
    pivot = results_df.copy()
    pivot["Deviation_num"] = pd.to_numeric(pivot["Deviation %"], errors="coerce")
    cat_summary = (
        pivot.groupby("Category")["Deviation_num"]
        .agg(["mean", "min", "max", "count"])
        .reset_index()
        .sort_values("mean")
    )
    cat_summary.columns = ["Category", "Mean Deviation %", "Min Dev %", "Max Dev %", "N Conditions"]

    fig, ax = plt.subplots(figsize=(10, 5))
    heat_data = cat_summary[["Mean Deviation %"]].T
    heat_data.columns = cat_summary["Category"]

    sns.heatmap(
        heat_data,
        ax=ax,
        cmap="RdBu_r",
        center=0,
        annot=True,
        fmt=".1f",
        linewidths=0.5,
        cbar_kws={"label": "Mean % deviation from EU reference"}
    )
    ax.set_title(
        "Mean Deviation from EU Reference by Disease Category\n(negative = under-sampled, positive = over-sampled)",
        fontsize=11, fontweight="bold"
    )
    ax.set_ylabel("")
    ax.set_xticklabels(ax.get_xticklabels(), rotation=30, ha="right", fontsize=9)
    plt.tight_layout()

    if output_path:
        plt.savefig(output_path, dpi=150, bbox_inches="tight")
        print(f"🗺  Heatmap saved to: {output_path}")
    else:
        plt.show()


def print_summary_report(results_df: pd.DataFrame, metrics: dict):
    """Print a formatted summary report to stdout."""
    sep = "=" * 80
    print(f"\n{sep}")
    print("  SYNTHEA ↔ EUROPEAN EPIDEMIOLOGY VALIDATION REPORT")
    print(sep)

    # --- Global metrics ---
    print("\n📐 GLOBAL FIT METRICS")
    print("-" * 50)
    for k, v in metrics.items():
        print(f"  {k:<35} {v}")

    # --- Per-condition table ---
    print(f"\n📋 PER-CONDITION RESULTS ({len(results_df)} reference conditions checked)")
    print("-" * 80)
    display_cols = ["Condition", "Category", "Synthea Prev %", "EU Ref Prev %", "Deviation %", "Status"]
    print(tabulate(
        results_df[display_cols].fillna("—"),
        headers="keys",
        tablefmt="rounded_outline",
        showindex=False,
        floatfmt=".2f"
    ))

    # --- Category summary ---
    print("\n📂 CATEGORY SUMMARY")
    print("-" * 50)
    pivot = results_df.copy()
    pivot["Deviation_num"] = pd.to_numeric(pivot["Deviation %"], errors="coerce")
    missing = results_df[results_df["Status"] == "❌ MISSING"]
    over    = results_df[results_df["Status"].str.startswith("⬆")]
    under   = results_df[results_df["Status"].str.startswith("⬇")]
    ok      = results_df[results_df["Status"].str.startswith("✅")]

    print(f"  ✅ Within ±20% of reference  : {len(ok):>4} conditions")
    print(f"  ❌ Missing entirely           : {len(missing):>4} conditions")
    print(f"  ⬆  Over-sampled (>+20%)      : {len(over):>4} conditions")
    print(f"  ⬇  Under-sampled (<-20%)     : {len(under):>4} conditions")

    # --- Top 10 worst deviations ---
    print("\n🔴 TOP 10 CONDITIONS NEEDING RECALIBRATION (by absolute deviation)")
    print("-" * 50)
    worst = results_df.copy()
    worst["abs_dev"] = pd.to_numeric(worst["Deviation %"], errors="coerce").abs()
    worst = worst.sort_values("abs_dev", ascending=False).head(10)
    for _, row in worst.iterrows():
        print(f"  {row['Status']}  {row['Condition']:<45} "
              f"EU:{row['EU Ref Prev %']:>5.1f}%  Synthea:{row['Synthea Prev %']:>6.3f}%  "
              f"Dev:{row['Deviation %']:>7}")

    print(f"\n{sep}")
    print("  RECOMMENDATIONS")
    print(sep)
    if len(missing) > 0:
        print("\n  🔧 MISSING CONDITIONS — Add or enable Synthea modules for:")
        for _, row in missing.iterrows():
            print(f"     • {row['Condition']} (SNOMED {row['SNOMED']}, ICD-10 {row['ICD-10']})")

    if len(under) > 0:
        print("\n  📉 UNDER-SAMPLED — Increase onset probabilities in modules for:")
        for _, row in under.sort_values("Deviation %").head(10).iterrows():
            print(f"     • {row['Condition']}: Synthea {row['Synthea Prev %']:.2f}% vs EU ref {row['EU Ref Prev %']:.1f}%")

    if len(over) > 0:
        print("\n  📈 OVER-SAMPLED — Reduce onset probabilities or suppress modules for:")
        for _, row in over.sort_values("Deviation %", ascending=False).head(5).iterrows():
            print(f"     • {row['Condition']}: Synthea {row['Synthea Prev %']:.2f}% vs EU ref {row['EU Ref Prev %']:.1f}%")

    print(f"\n{sep}\n")


# =============================================================================
# MAIN
# =============================================================================

def main():
    parser = argparse.ArgumentParser(
        description="Validate Synthea output against European epidemiology reference data.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__
    )
    parser.add_argument("--input", "-i", required=True,
                        help="Path to Synthea conditions.csv or aggregated .md table")
    parser.add_argument("--population", "-p", type=int, default=None,
                        help="Total number of simulated patients (auto-detected if omitted)")
    parser.add_argument("--output", "-o", default=None,
                        help="Optional: save CSV results to this path")
    parser.add_argument("--charts", "-c", action="store_true",
                        help="Generate and save comparison charts alongside --output")
    args = parser.parse_args()

    print("\n🔬 Loading Synthea conditions data...")
    synthea_df = load_synthea_conditions(args.input)
    print(f"   Loaded {len(synthea_df):,} unique condition codes, "
          f"{synthea_df['count'].sum():,} total records")

    # Estimate total patients
    if args.population:
        total_patients = args.population
    else:
        # Heuristic: max count of any single code is a lower bound; better to use
        # patient count from patients.csv if available. Here we approximate using
        # the maximum single-code count (medication review / employment are near 1:1 per patient).
        total_patients = int(synthea_df["count"].max())
        print(f"   ⚠  --population not provided. Estimating ~{total_patients:,} patients "
              f"from max code frequency. Provide --population for accurate prevalence %.")

    print(f"\n🇪🇺 Comparing against {len(EUROPEAN_REFERENCE_PREVALENCE)} European reference conditions...")
    results_df = compute_metrics(synthea_df, total_patients)

    # Numeric arrays for statistical tests
    ref_arr     = results_df["EU Ref Prev %"].values
    synthea_arr = results_df["Synthea Prev %"].values
    synthea_cnt = results_df["Synthea Count"].values

    kl   = compute_kl_divergence(synthea_arr, ref_arr)
    js   = compute_js_divergence(synthea_arr, ref_arr)
    wmae = compute_wmae(synthea_arr, ref_arr)
    chi_stat, chi_p, chi_note = run_chisquare(synthea_cnt, ref_arr, total_patients)

    metrics = {
        "KL Divergence D(ref‖synthea)":  f"{kl:.4f}  (0=perfect, >0.5=poor)",
        "Jensen-Shannon Divergence":     f"{js:.4f}  (0=perfect, 1=max divergence)",
        "Weighted MAE (pp)":             f"{wmae:.3f} percentage points",
        "Chi-Square statistic":          f"{chi_stat:.1f}" if chi_stat else "N/A",
        "Chi-Square p-value":            (f"{chi_p:.2e}  ({chi_note})" if chi_p else "N/A"),
        "Interpretation":                (
            "✅ Good fit" if js < 0.1 else
            "🟡 Moderate fit — recalibration advised" if js < 0.25 else
            "🔴 Poor fit — significant recalibration required"
        ),
        "Estimated patients":            f"{total_patients:,}",
        "Reference conditions checked":  str(len(EUROPEAN_REFERENCE_PREVALENCE)),
    }

    print_summary_report(results_df, metrics)

    if args.output:
        out_path = Path(args.output)
        results_df.to_csv(out_path, index=False)
        print(f"💾 Results CSV saved to: {out_path}")

        if args.charts:
            chart_path = out_path.with_name(out_path.stem + "_comparison.png")
            heat_path  = out_path.with_name(out_path.stem + "_heatmap.png")
            plot_comparison(results_df, str(chart_path))
            plot_category_heatmap(results_df, str(heat_path))

    return results_df, metrics


if __name__ == "__main__":
    results, metrics = main()
