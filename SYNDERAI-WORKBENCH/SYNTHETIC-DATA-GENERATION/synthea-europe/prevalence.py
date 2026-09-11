
import csv
from collections import defaultdict

active_conditions = defaultdict(set)
lifetime_conditions = defaultdict(set)

with open('output/merged/csv/conditions.csv') as f:
    for row in csv.DictReader(f):
        pat  = row['PATIENT']
        desc = row['DESCRIPTION']
        stop = row.get('STOP', '').strip()
        lifetime_conditions[desc].add(pat)
        if not stop:
            active_conditions[desc].add(pat)

total = sum(1 for _ in csv.DictReader(open('output/merged/csv/patients.csv')))
print(f"Total patients: {total}")
print()
print(f"  {'Condition':<50} {'Active%':>8}  {'Lifetime%':>10}  EU ref%")
print("  " + "-"*82)

KEY = [
    # Original conditions
    'hypertension', 'diabetes', 'lung disease', 'depress', 'anxiety',
    'alzheimer', 'alcoholi', 'atrial', 'myocardial', 'ischemic',
    'dementia', 'cognitive',
    # Cancers
    'cancer', 'neoplasm', 'carcinoma', 'malignant', 'leukemia', 'lymphoma',
    # New conditions
    'hypothyroid', 'kidney', 'osteoarth', 'obesity', 'asthma',
    'prostate', 'stroke', 'cerebrovascular', 'heart failure',
    'congestive', 'parkinson', 'epilep', 'migraine', 'migrain',
    'rheumatoid', 'fibromyalg', 'osteoporosis', 'chronic obstructive',
]

EU_REF = {
    'Essential hypertension':         '30-45%',
    'Ischemic heart disease':          '5-10%',
    'Alcoholism':                       '4-8%',
    'Diabetes mellitus type 2':        '7-10%',
    'Chronic obstructive lung disease': '5-10%',
    'Prediabetes':                      '5-10%',
    'Atrial fibrillation':             '2-4%',
    'Alzheimer\'s disease':           '1-3%',
    'Depressive disorder':             '2-6% (active)',
    'Generalized anxiety disorder':    '2-5% (active)',
    'Mild cognitive impairment':       '1-3%',
    'Malignant neoplasm of breast':    '0.3-0.5%',
    'Hypothyroidism':                  '2-4%',
    'Chronic kidney disease':          '8-12%',
    'Osteoarthritis':                  '5-10%',
    'Obesity':                         '15-20%',
    'Asthma':                          '5-8%',
    'Ischemic stroke':                 '1.5-3%',
    'Neoplasm of prostate':            '0.8-1.2%',
}

for cond in sorted(active_conditions.keys(),
                   key=lambda x: -len(active_conditions[x])):
    cond_lower = cond.lower()
    if any(k in cond_lower for k in KEY) or len(active_conditions[cond]) > total * 0.005:
        a_pct = len(active_conditions[cond]) / total * 100
        l_pct = len(lifetime_conditions[cond]) / total * 100
        if a_pct >= 0.1 or l_pct >= 0.5:
            ref = next((v for k, v in EU_REF.items() if k.lower() in cond.lower()), '')
            print(f"  {cond:<50} {a_pct:>7.1f}%  {l_pct:>9.1f}%  {ref}")
