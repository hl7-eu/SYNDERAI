# Principles
**Synthetic Data: Examples – Realistic – using AI (SYNDERAI)**, pronounced **/ˈsɪn.də.raɪ/**

© [HL7 Europe](https://hl7europe.org) | Main Contributor: Dr. Kai U. Heitmann | [Privacy Policy](https://hl7europe.eu/privacy-policy-for-hl7-europe/) • LGPL-3.0 license

## Introduction

One of the recurring challenges in developing, testing, and validating HL7 FHIR-based systems is the availability of medically realistic, safe, and standards-compliant sets of example/test data. To address this, the [xShare Project](https://xshare-project.eu) integrates synthetic data generated under this initiative, coordinated by [HL7 Europe](https://hl7europe.org).

SYNDERAI provides synthetic, high-quality HL7 FHIR instances that replicate real-world clinical records without exposing personal health information. These instances are used across the xShare toolbox — from transformation to visualization to sharing — enabling fully privacy-compliant test workflows, aligned with the European Electronic Health Record Exchange Format (EEHRxF) and General Data Protection Regulation (GDPR, EU 2016/679) principles.

## SYNDERAI Design

SYNDERAI datasets are designed to

- **represent realistic clinical scenarios**, including medications, allergies, problems, encounters, vital signs, depending on the covered use case,
- **be conformant to HL7 FHIR Implementation Guides**, including IPS, EU Laboratory Report, and Hospital Discharge Summary,
- **use realistic but not real patient data**, ensuring safety in both development and demonstration environments.

The project generates not just HL7 FHIR JSON instance files, but also includes metadata, test coverage indicators, and placeholders for multilingual expansion and narrative descriptions. 

Within xShare, SYNDERAI synthetic data is

- used in architecture testbeds for **download, share, and visualize** flows, as seen at [vi7eti.net](https://vi7eti.net),
- prepared to support **IHE Connectathon/Plugathon test cases**
- embedded in **documentation and walk-throughs** as examples of valid HL7 FHIR structures and content.

This data enables **xShare Adoption Sites** and developers to

- run the **Yellow Button tools** without privacy concerns
- **simulate end-to-end workflows** with repeatable, traceable data
- **demonstrate compliance** with technical and legal requirements.

## Major Achievements

Following the design, a couple of achievements were made: European patient cohorts and providers were compiled, assuring some realistic properties. Clincial "stories" were combined with the individuals to create use case based data sets that are subsequently turned into the appropriate example/testing instances. Add use of AI at any of these levels where appropriate, e.g. prompting for populating data fragments based on a given demographic and clinical context or to provide human readable text based on exsting granular data.  

![image-20250512215745584](img/eufam.png)

*Depositphotos.com © Robert Marmion*

### Patient cohorts, Provider crowds

#### Demographics

Large collections of human names in Europe with gender and age, mix newly to create use case bound personas

**25tipster** initiative 25.000 IPS sythetic data.

| **Number** | **Gender** | **NameSet** | **Title** | **GivenName** | **MiddleInitial** | **Surname**   | **StreetAddress**   | **City**    | **State** | **StateFull**    | **ZipCode** | **Country** | **CountryFull** | **EmailAddress**                                             | **TelephoneNumber** | **TelephoneCountryCode** | **MothersMaiden** | **Birthday** | **Age** | **TropicalZodiac** | **CCType** | **CCNumber**     | **CVV2** | **CCExpires** | **NationalID** | **UPS**                  | **WesternUnionMTCN** | **MoneyGramMTCN** | **Color** | **Occupation**                        | **Company**  | **Vehicle**                  | **Domain**                                  | **BloodType** | **Pounds** | **Kilograms** | **FeetInches** | **Centimeters** | **GUID**                             | **Latitude** | **Longitude** |
| ---------- | ---------- | ----------- | --------- | ------------- | ----------------- | ------------- | ------------------- | ----------- | --------- | ---------------- | ----------- | ----------- | --------------- | ------------------------------------------------------------ | ------------------- | ------------------------ | ----------------- | ------------ | ------- | ------------------ | ---------- | ---------------- | -------- | ------------- | -------------- | ------------------------ | -------------------- | ----------------- | --------- | ------------------------------------- | ------------ | ---------------------------- | ------------------------------------------- | ------------- | ---------- | ------------- | -------------- | --------------- | ------------------------------------ | ------------ | ------------- |
| **1**      | female     | Dutch       | Ms.       | Ida           | B                 | Assen         | Mollstrasse 41      | Weiterstadt | HE        | Hessen           | 64331       | DE          | Germany         | [IdaAssen@teleworm.us](mailto:IdaAssen@teleworm.us)          | 06150 35 05 43      | 49                       | Petersen          | 4/15/1944    | 79      | Aries              | MasterCard | 5187624373445239 | 854      | 9/2028        |                | 1Z 552 830 53 4721 517 5 | 1523092882           | 13691055          | Black     | Floor sander                          | System Star  | 2009 Aston Martin V8 Vantage | [VIPinterview.de](http://VIPinterview.de)   | A+            | 115.7      | 52.6          | 4' 11"         | 150             | ba53775a-a052-4020-bcb0-441e07ed7fab | 49.989312    | 8.504845      |
| **2**      | female     | Dutch       | Mrs.      | Eliane        | R                 | van de Kreeke | Akonmäentie 90      | IISALMI     | NS        | Northern Savonia | 74100       | FI          | Finland         | [ElianevandeKreeke@superrito.com](mailto:ElianevandeKreeke@superrito.com) | 046 030 3712        | 358                      | Elst              | 2/18/1969    | 55      | Aquarius           | Visa       | 4916644089442222 | 218      | 9/2025        | 180269-4983    | 1Z 709 631 86 4461 518 4 | 4342501321           | 35137143          | Green     | Teletype operator                     | Elek-Tek     | 1998 Seat Marbella           | [SoftballGroup.fi](http://SoftballGroup.fi) | AB+           | 134.9      | 61.3          | 5' 5"          | 164             | 27ed9343-58d4-4e9a-a973-2c06e9895fbc | 63.626351    | 27.233851     |
| **3**      | male       | Danish      | Mr.       | Bent          | A                 | Møller        | Achter de Hoven 157 | Maasbree    | LI        | Limburg          | 5993 CR     | NL          | Netherlands     | [BentAMoller@teleworm.us](mailto:BentAMoller@teleworm.us)    | 06-82923705         | 31                       | Christensen       | 9/5/1942     | 81      | Virgo              | Visa       | 4716842722061035 | 737      | 5/2028        |                | 1Z 288 7W1 91 0634 428 7 | 8372278393           | 70988556          | Orange    | Cementing and gluing machine operator | Funtown toys | 2010 Jaguar XF               | [SeekFashions.nl](http://SeekFashions.nl)   | B+            | 176.7      | 80.3          | 5' 5"          | 164             | c105f9a0-e158-4e1c-bf29-2c68ba484fd1 | 51.335932    | 6.088348      |

xxx

| **nl** | **Assen**     | **female** | **79** |
| ------ | ------------- | ---------- | ------ |
| **nl** | van de Kreeke | female     | 55     |
| **dk** | Møller        | male       | 81     |
| **nl** | Goorhuis      | male       | 82     |

| **nl** | **Ida** | **female** | **79** |
| ------ | ------- | ---------- | ------ |
| **nl** | Eliane  | female     | 55     |
| **dk** | Bent    | male       | 81     |
| **nl** | Reijer  | male       | 82     |

strato-proximity-match: nationality gender almost-same-age

=> source for personas step 1

#### Stratification

d [[1](#_ftn1)] b

#### Providers

#### Proximity

### Clinical relationship

![image-20250514085415926](img/threegens.png)

*Depositphotos.com © Yevhen Shkolenko*

### Synthea

Gives diagnoses lab values vital signs and immunizations in context., assoc patients are not used but bound tongue stratified personas 

=> source for personas step 2

#### Personas

Invent personas based on granular facts. 

### AI in SYNDERAI

The use of **Artificial Intelligence** is applied to just fragments and parts of the complete "story", SYNDERAI tells, not to invent whole stories. For example, the Lab Report is based on realistic lab values and projected on European citzizen/patients all over Europe, stratified [[1](#_ftn1)] by demographic and clinical factors to reach close clinical coverage. Only the normal lab value ranges based on the strata used is provided by concise calls to the AI API.

for fragments and parts, lab data normal range based on age and gender 

### The Human Text

invent human text for typical HDR sections based on granular synthetic but realistic facts such a medication. Lab results, diagnoses. 

## The xShare Yellow Button Story

SYNDERAI plays a critical role in enabling safe, reusable, and standards-aligned testing of the Yellow Button components. It supports transparency, traceability, and validation across the xShare toolbox — and provides a solid foundation for future testbeds, certification frameworks, and developer onboarding efforts in support of the EHDS.

Compared to the earlier xShare D3.3 deliverable, the SYNDERAI component has evolved significantly. What was previously described as a conceptual asset is now implemented and actively integrated into xShare workflows. The sets of example/test data is openly available, continuously expanded, and aligned with HL7 FHIR Implementation Guides such as IPS, EU Laboratory Report, and Hospital Discharge Summary.

It is now validated through real-world use in [visualization rendering via vi7eti](https://vi7eti.net), Smart Health Link generation, and IHE Connectathon/Plugathon test case development. These advancements make SYNDERAI a critical enabler for privacy-preserving, standards-compliant, and repeatable validation processes across the toolbox.

------

[[1](#_ftnref1)] *Stratification* of clinical trials is the partitioning of subjects and results by a factor other than the treatment given. – see Wikipedia https://en.wikipedia.org/wiki/Stratification_(clinical_trials)
