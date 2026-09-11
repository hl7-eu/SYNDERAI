import csv
from collections import Counter, defaultdict

allergies = Counter()
patients_with = set()
allergy_types = defaultdict(set)

with open('output/merged/csv/allergies.csv') as f:
    for row in csv.DictReader(f):
        desc = row['DESCRIPTION']
        pat  = row['PATIENT']
        allergies[desc] += 1
        patients_with.add(pat)
        # Categorise
        if any(x in desc.lower() for x in ['milk','egg','wheat','nut','fish','shell','peanut','soy']):
            allergy_types['food'].add(pat)
        elif any(x in desc.lower() for x in ['rhinitis','pollen','dust','mite']):
            allergy_types['environmental'].add(pat)
        elif any(x in desc.lower() for x in ['penicillin','amoxicillin','ibuprofen','sulfa','drug']):
            allergy_types['drug'].add(pat)
        elif any(x in desc.lower() for x in ['bee','wasp','venom','insect']):
            allergy_types['insect_venom'].add(pat)

total = sum(1 for _ in csv.DictReader(open('patients.csv')))
print(f"Total patients:              {total:,}")
print(f"Patients with any allergy:   {len(patients_with):,}  ({len(patients_with)/total*100:.1f}%)  EU target 25-35%")
print()
print(f"By category:")
for cat, pts in sorted(allergy_types.items(), key=lambda x: -len(x[1])):
    print(f"  {cat:<20} {len(pts):>5} patients  ({len(pts)/total*100:.1f}%)")
print()
print(f"Top allergy descriptions:")
for desc, n in allergies.most_common(15):
    print(f"  {n:>5}  {desc}")