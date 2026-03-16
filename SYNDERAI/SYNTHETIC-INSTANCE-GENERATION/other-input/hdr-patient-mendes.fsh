// ============================================================
// EPS Patient Summary — Laura Mendes (synthetic)
// Based on HL7 Europe EPS IG v1.0.0-xtehr
// Sections: A.1 Header | A.2 Body
// ============================================================


// ------------------------------------------------------------
// A.1.1  PATIENT
// ------------------------------------------------------------
Instance: patient-laura-mendes
InstanceOf: PatientEuEps
Title: "Patient — Laura Mendes"
Description: "A.1.1 Patient/subject identification (synthetic)"
Usage: #example

* identifier[0].system = "urn:oid:2.16.840.1.113883.2.9.4.3.2"
* identifier[0].value = "MNSLRA810312F123"

* name[0].family = "Mendes"
* name[0].given[0] = "Laura"

* gender = #female
* birthDate = "1981-03-12"    // derived: age 45 as of 2026

* address[0].use = #home
* address[0].country = "PT"   // assumed; synthetic patient


// ------------------------------------------------------------
// A.2.1.1  ALLERGY — Iodine contrast media
// ------------------------------------------------------------
Instance: allergy-iodine-mendes
InstanceOf: AllergyIntoleranceEuEps
Title: "AllergyIntolerance — Iodine contrast media"
Description: "A.2.1.1 Allergy: iodine contrast media, moderate reaction"
Usage: #example

* patient = Reference(patient-laura-mendes)
* clinicalStatus = $allergyintolerance-clinical#active
* verificationStatus = $allergyintolerance-verification#confirmed
* type = #allergy

* code = $sct#372912004 "Iodinated contrast media (substance)"
* code.text = "Iodine contrast media"

* criticality = #high

* reaction[0].substance = $sct#372912004 "Iodinated contrast media (substance)"
* reaction[0].manifestation[0] = $sct#702809001 "Drug reaction with eosinophilia and systemic symptoms"
* reaction[0].manifestation[0].text = "Moderate allergic reaction"
* reaction[0].severity = #moderate


// ------------------------------------------------------------
// A.2.2.1  CONDITION — Invasive ductal carcinoma, left breast
// ------------------------------------------------------------
Instance: condition-idc-breast-mendes
InstanceOf: ConditionEuEps
Title: "Condition — Invasive ductal carcinoma left breast Stage IIb"
Description: "A.2.2.1 Active problem: ER+/HER2- invasive ductal carcinoma, left breast, Stage IIb"
Usage: #example

* subject = Reference(patient-laura-mendes)
* clinicalStatus = $condition-clinical#active
* verificationStatus = $condition-ver-status#confirmed
* category[0] = $condition-category#problem-list-item

* code = $sct#413448000 "Invasive ductal carcinoma of female breast (disorder)"
* code.text = "Invasive ductal carcinoma of the left breast (ER+/HER2-)"

* bodySite[0] = $sct#80248007 "Left breast structure (body structure)"

* stage[0].summary = $sct#261614003 "Stage IIb"
* stage[0].summary.text = "Stage IIb"

* note[0].text = "ER positive, HER2 negative. Currently on Docetaxel + Cyclophosphamide, cycle 2/6."


// ------------------------------------------------------------
// A.2.2.1  CONDITION — Neutropenia
// ------------------------------------------------------------
Instance: condition-neutropenia-mendes
InstanceOf: ConditionEuEps
Title: "Condition — Neutropenia"
Description: "A.2.2.1 Active problem: low neutrophil count (chemotherapy-related)"
Usage: #example

* subject = Reference(patient-laura-mendes)
* clinicalStatus = $condition-clinical#active
* verificationStatus = $condition-ver-status#confirmed
* category[0] = $condition-category#problem-list-item

* code = $sct#165517008 "Neutropenia (disorder)"
* code.text = "Neutropenia — likely chemotherapy-induced"

* note[0].text = "Neutrophils 0.9 ×10⁹/L (low). Filgrastim used occasionally depending on count."


// ------------------------------------------------------------
// A.2.2.1  CONDITION — Mild anaemia
// ------------------------------------------------------------
Instance: condition-anaemia-mendes
InstanceOf: ConditionEuEps
Title: "Condition — Mild anaemia"
Description: "A.2.2.1 Active problem: mild anaemia (Hb 10.4 g/dL)"
Usage: #example

* subject = Reference(patient-laura-mendes)
* clinicalStatus = $condition-clinical#active
* verificationStatus = $condition-ver-status#confirmed
* category[0] = $condition-category#problem-list-item

* code = $sct#271737000 "Anaemia (disorder)"
* code.text = "Mild anaemia"

* note[0].text = "Hb 10.4 g/dL. Likely chemotherapy-related."


// ------------------------------------------------------------
// A.2.3  MEDICATIONS — Chemotherapy regimen
// ------------------------------------------------------------
Instance: medstmt-docetaxel-mendes
InstanceOf: MedicationStatementEuEps
Title: "MedicationStatement — Docetaxel"
Description: "A.2.3 Current medication: Docetaxel (chemotherapy)"
Usage: #example

* subject = Reference(patient-laura-mendes)
* status = #active
* medicationCodeableConcept = $sct#372993008 "Docetaxel (substance)"
* medicationCodeableConcept.text = "Docetaxel"
* reasonReference[0] = Reference(condition-idc-breast-mendes)
* note[0].text = "Part of TC regimen (Docetaxel + Cyclophosphamide). Cycle 2 of 6 completed."

// ----
Instance: medstmt-cyclophosphamide-mendes
InstanceOf: MedicationStatementEuEps
Title: "MedicationStatement — Cyclophosphamide"
Description: "A.2.3 Current medication: Cyclophosphamide (chemotherapy)"
Usage: #example

* subject = Reference(patient-laura-mendes)
* status = #active
* medicationCodeableConcept = $sct#387420009 "Cyclophosphamide (substance)"
* medicationCodeableConcept.text = "Cyclophosphamide"
* reasonReference[0] = Reference(condition-idc-breast-mendes)
* note[0].text = "Part of TC regimen. Cycle 2 of 6 completed."

// ----
Instance: medstmt-ondansetron-mendes
InstanceOf: MedicationStatementEuEps
Title: "MedicationStatement — Ondansetron"
Description: "A.2.3 Current medication: Ondansetron (antiemetic, supportive)"
Usage: #example

* subject = Reference(patient-laura-mendes)
* status = #active
* medicationCodeableConcept = $sct#372487007 "Ondansetron (substance)"
* medicationCodeableConcept.text = "Ondansetron"
* reasonCode[0].text = "Chemotherapy-induced nausea and vomiting prophylaxis"

// ----
Instance: medstmt-dexamethasone-mendes
InstanceOf: MedicationStatementEuEps
Title: "MedicationStatement — Dexamethasone"
Description: "A.2.3 Current medication: Dexamethasone (supportive)"
Usage: #example

* subject = Reference(patient-laura-mendes)
* status = #active
* medicationCodeableConcept = $sct#372584003 "Dexamethasone (substance)"
* medicationCodeableConcept.text = "Dexamethasone"
* reasonCode[0].text = "Supportive therapy with chemotherapy (antiemetic / anti-inflammatory)"

// ----
Instance: medstmt-pantoprazole-mendes
InstanceOf: MedicationStatementEuEps
Title: "MedicationStatement — Pantoprazole"
Description: "A.2.3 Current medication: Pantoprazole (gastroprotection)"
Usage: #example

* subject = Reference(patient-laura-mendes)
* status = #active
* medicationCodeableConcept = $sct#372861008 "Pantoprazole (substance)"
* medicationCodeableConcept.text = "Pantoprazole"
* reasonCode[0].text = "Gastroprotection during chemotherapy"

// ----
Instance: medstmt-filgrastim-mendes
InstanceOf: MedicationStatementEuEps
Title: "MedicationStatement — Filgrastim (PRN)"
Description: "A.2.3 Current medication: Filgrastim — occasional use based on neutrophil count"
Usage: #example

* subject = Reference(patient-laura-mendes)
* status = #active
* medicationCodeableConcept = $sct#386947003 "Filgrastim (substance)"
* medicationCodeableConcept.text = "Filgrastim (G-CSF)"
* reasonReference[0] = Reference(condition-neutropenia-mendes)
* note[0].text = "PRN — administered when neutrophil count falls below threshold."


// ------------------------------------------------------------
// A.2.6  PROCEDURE — Chemotherapy administration
// ------------------------------------------------------------
Instance: procedure-chemo-mendes
InstanceOf: ProcedureEuEps
Title: "Procedure — TC Chemotherapy (Docetaxel + Cyclophosphamide)"
Description: "A.2.6 Ongoing chemotherapy procedure, cycle 2/6 completed"
Usage: #example

* subject = Reference(patient-laura-mendes)
* status = #in-progress
* code = $sct#385786002 "Chemotherapy (procedure)"
* code.text = "TC chemotherapy regimen — Docetaxel + Cyclophosphamide"
* reasonReference[0] = Reference(condition-idc-breast-mendes)
* note[0].text = "Cycle 2 of 6 completed."


// ------------------------------------------------------------
// A.2.7  OBSERVATIONS — Recent laboratory results (Provider A)
// ------------------------------------------------------------
Instance: obs-neutrophils-mendes
InstanceOf: Observation
Title: "Observation — Neutrophil count"
Description: "A.2.7 Lab result: Neutrophils 0.9 ×10⁹/L (low)"
Usage: #example

* status = #final
* category[0] = $observation-category#laboratory
* code = $loinc#26499-4 "Neutrophils [#/volume] in Blood"
* subject = Reference(patient-laura-mendes)
* valueQuantity.value = 0.9
* valueQuantity.unit = "×10⁹/L"
* valueQuantity.system = "http://unitsofmeasure.org"
* valueQuantity.code = #10*9/L
* interpretation[0] = $v3-ObservationInterpretation#L "Low"
* performer[0].display = "Provider A"

// ----
Instance: obs-wbc-mendes
InstanceOf: Observation
Title: "Observation — White Blood Cell count"
Description: "A.2.7 Lab result: WBC 3.2 ×10⁹/L"
Usage: #example

* status = #final
* category[0] = $observation-category#laboratory
* code = $loinc#6690-2 "Leukocytes [#/volume] in Blood by Automated count"
* subject = Reference(patient-laura-mendes)
* valueQuantity.value = 3.2
* valueQuantity.unit = "×10⁹/L"
* valueQuantity.system = "http://unitsofmeasure.org"
* valueQuantity.code = #10*9/L
* performer[0].display = "Provider A"

// ----
Instance: obs-haemoglobin-mendes
InstanceOf: Observation
Title: "Observation — Haemoglobin"
Description: "A.2.7 Lab result: Hb 10.4 g/dL"
Usage: #example

* status = #final
* category[0] = $observation-category#laboratory
* code = $loinc#718-7 "Hemoglobin [Mass/volume] in Blood"
* subject = Reference(patient-laura-mendes)
* valueQuantity.value = 10.4
* valueQuantity.unit = "g/dL"
* valueQuantity.system = "http://unitsofmeasure.org"
* valueQuantity.code = #g/dL
* performer[0].display = "Provider A"

// ----
Instance: obs-alt-mendes
InstanceOf: Observation
Title: "Observation — ALT"
Description: "A.2.7 Lab result: ALT mildly elevated"
Usage: #example

* status = #final
* category[0] = $observation-category#laboratory
* code = $loinc#1742-6 "Alanine aminotransferase [Enzymatic activity/volume] in Serum or Plasma"
* subject = Reference(patient-laura-mendes)
* valueCodeableConcept.text = "Mild elevation"
* interpretation[0] = $v3-ObservationInterpretation#H "High"
* performer[0].display = "Provider A"

// ----
Instance: obs-creatinine-mendes
InstanceOf: Observation
Title: "Observation — Creatinine"
Description: "A.2.7 Lab result: Creatinine normal"
Usage: #example

* status = #final
* category[0] = $observation-category#laboratory
* code = $loinc#2160-0 "Creatinine [Mass/volume] in Serum or Plasma"
* subject = Reference(patient-laura-mendes)
* valueCodeableConcept.text = "Normal"
* interpretation[0] = $v3-ObservationInterpretation#N "Normal"
* performer[0].display = "Provider A"


// ------------------------------------------------------------
// A.1.4  COMPOSITION — EPS Document Header
// ------------------------------------------------------------
Instance: composition-eps-mendes
InstanceOf: CompositionEuEps
Title: "Composition — EPS Laura Mendes"
Description: "A.1 EPS Document header and body composition for Laura Mendes"
Usage: #example

* status = #preliminary
* type = $loinc#60591-5 "Patient summary Document"
* subject = Reference(patient-laura-mendes)
* date = "2026-03-03"
* author[0].display = "Synthetic data generator"
* title = "Patient Summary — Laura Mendes"
* confidentiality = #N

// A.2.1 Alerts
* section[sectionAllergies].title = "Allergies and Intolerances"
* section[sectionAllergies].code = $loinc#48765-2 "Allergies and adverse reactions Document"
* section[sectionAllergies].entry[0] = Reference(allergy-iodine-mendes)

// A.2.2 Active Problems
* section[sectionProblems].title = "Problem List"
* section[sectionProblems].code = $loinc#11450-4 "Problem list - Reported"
* section[sectionProblems].entry[0] = Reference(condition-idc-breast-mendes)
* section[sectionProblems].entry[1] = Reference(condition-neutropenia-mendes)
* section[sectionProblems].entry[2] = Reference(condition-anaemia-mendes)

// A.2.3 Medications
* section[sectionMedications].title = "Medication Summary"
* section[sectionMedications].code = $loinc#10160-0 "History of Medication use Narrative"
* section[sectionMedications].entry[0] = Reference(medstmt-docetaxel-mendes)
* section[sectionMedications].entry[1] = Reference(medstmt-cyclophosphamide-mendes)
* section[sectionMedications].entry[2] = Reference(medstmt-ondansetron-mendes)
* section[sectionMedications].entry[3] = Reference(medstmt-dexamethasone-mendes)
* section[sectionMedications].entry[4] = Reference(medstmt-pantoprazole-mendes)
* section[sectionMedications].entry[5] = Reference(medstmt-filgrastim-mendes)

// A.2.6 Procedures
* section[sectionProceduresHx].title = "History of Procedures"
* section[sectionProceduresHx].code = $loinc#47519-4 "History of Procedures Document"
* section[sectionProceduresHx].entry[0] = Reference(procedure-chemo-mendes)

// A.2.7 Results
* section[sectionResults].title = "Diagnostic Results"
* section[sectionResults].code = $loinc#30954-2 "Relevant diagnostic tests/laboratory data Narrative"
* section[sectionResults].entry[0] = Reference(obs-neutrophils-mendes)
* section[sectionResults].entry[1] = Reference(obs-wbc-mendes)
* section[sectionResults].entry[2] = Reference(obs-haemoglobin-mendes)
* section[sectionResults].entry[3] = Reference(obs-alt-mendes)
* section[sectionResults].entry[4] = Reference(obs-creatinine-mendes)
