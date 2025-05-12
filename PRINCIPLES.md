# Principles
**Synthetic Data: Examples – Realistic – using AI (SYNDERAI)**, pronounced **/ˈsɪn.də.raɪ/**

© [HL7 Europe](https://hl7europe.org) | Main Contributor: Dr. Kai U. Heitmann | [Privacy Policy](https://hl7europe.eu/privacy-policy-for-hl7-europe/) • LGPL-3.0 license

One of the recurring challenges in developing, testing, and validating HL7 FHIR-based systems is the availability of medically realistic, safe, and standards-compliant sets of example/test data. To address this, the [xShare Project](https://xshare-project.eu) integrates synthetic data generated under this initiative, coordinated by [HL7 Europe](https://hl7europe.org).

SYNDERAI provides synthetic, high-quality HL7 FHIR instances that replicate real-world clinical records without exposing personal health information. These instances are used across the xShare toolbox — from transformation to visualization to sharing — enabling fully privacy-compliant test workflows, aligned with the European Electronic Health Record Exchange Format (EEHRxF) and General Data Protection Regulation (GDPR, EU 2016/679) principles.

SYNDERAI datasets are designed to

- **represent realistic clinical scenarios**, including medications, allergies, problems, encounters, vital signs.
- **be conformant to HL7 FHIR Implementation Guides**, including IPS, EU Laboratory Report, and Hospital Discharge Summary.
- **Use realistic but not real patient data**, ensuring safety in both development and demonstration environments.

The project generates not just HL7 FHIR JSON instance files, but also includes metadata, test coverage indicators, and placeholders for multilingual expansion and narrative descriptions. 

The use of **Artificial Intelligence** is applied to just fragments and parts of the complete "story", SYNDERAI tells, not to invent whole stories. For example, the Lab Report is based on realistic lab values and projected on European citzizen/patients all over Europe, stratified [[1](#_ftn1)] by demographic and clinical factors to reach close clinical coverage. Only the normal lab value ranges based on the strata used is provided by concise calls to the AI API.

Within xShare, SYNDERAI synthetic data is

- used in architecture testbeds for **download, share, and visualize** flows, as seen at [vi7eti.net](https://vi7eti.net)
- prepared to support **IHE Connectathon/Plugathon test cases**
- embedded in **documentation and walk-throughs** as examples of valid HL7 FHIR structures.

This data enables **xShare Adoption Sites** and developers to

- run the **Yellow Button tools** without privacy concerns
- **simulate end-to-end workflows** with repeatable, traceable data
- **demonstrate compliance** with technical and legal requirements.

SYNDERAI plays a critical role in enabling safe, reusable, and standards-aligned testing of the Yellow Button components. It supports transparency, traceability, and validation across the xShare toolbox — and provides a solid foundation for future testbeds, certification frameworks, and developer onboarding efforts in support of the EHDS.

Compared to the earlier xShare D3.3 deliverable, the SYNDERAI component has evolved significantly. What was previously described as a conceptual asset is now implemented and actively integrated into xShare workflows. The sets of example/test data is openly available, continuously expanded, and aligned with HL7 FHIR Implementation Guides such as IPS, EU Laboratory Report, and Hospital Discharge Summary.

It is now validated through real-world use in [visualization rendering via vi7eti](https://vi7eti.net), Smart Health Link generation, and IHE Connectathon/Plugathon test case development. These advancements make SYNDERAI a critical enabler for privacy-preserving, standards-compliant, and repeatable validation processes across the toolbox.

------

[[1](#_ftnref1)] *Stratification* of clinical trials is the partitioning of subjects and results by a factor other than the treatment given. – see Wikipedia https://en.wikipedia.org/wiki/Stratification_(clinical_trials)
