**Synthetic Data: Examples – Realistic – using AI (SYNDERAI)**, pronounced **/ˈsɪn.də.raɪ/**

© [HL7 Europe](https://hl7europe.org) | Main Contributor: Dr. Kai U. Heitmann | [Privacy Policy](https://hl7europe.eu/privacy-policy-for-hl7-europe/) • AGPL-3.0 license

# SYNDERAI – methodology, functions and artifacts

## Directory Structure

### SYNTHETIC-DATA-GENERATION (content only partially present in GitHub)

This directory contains all scripts and data used for the synthetic data generation. It is **not entirely part** of this GitHub repository, especially regarding the generated data, just the scripts and parameters are present.

### SYNTHETIC-DATA (no content present in GitHub)

This directory contains all generated synthetic data as result of the synthetic data generation process.  It is **not part** of this GitHub repository due to size limitations. Generate your own synthetic data using the scripts in SYNTHETIC-DATA-GENERATION or ask the authors/contributors.

### SYNTHETIC-INSTANCE-GENERATION

This directory contains the all parts for the **core process**: the generation of **synthetic example instances** based on the synthetic data as input and represented in FHIR Shorthand (FSH) format

### FSH-FHIR-GENERATOR (content only partially present in GitHub)

This directory contains subdirectories per artifact where the original gitgub repositories reside as copies and where all synthetic example instances from the core process will be transformed into FHIR JSON instances for further processing. 

### PERSONAS

This directory contains material about the SYNDERAI personas that all have a complete Hospital Dischare Report as core artifact along with all other derived artifacts. 

### MAPPINGS

This directory contains mapping files and concept maps used by the core process.

### CONSTANTS

This directory contains settings and global constants.

### RECENT-RESULTS (content only partially present in GitHub)

This directory contains subdirectories per artifact where the recently created JSON and XML Bundles reside.

### PUBLICATIONS

This directory contains subdirectories per publishing date (organized in "waves") an within subdirectories per artifact where the published JSON and XML Bundles reside. The published subfolders typically are named like  `6.5.0+20251024`.

The content of this folder is identical with the website's `PUBLICATIONS` folder.

## Purpose

SYNDERAI provides realistic, privacy-preserving synthetic European healthcare data, including the first EU-Lab FHIR synthetic datasets. Explore reusable examples supported by AI for interoperability and secondary use in healthcare systems. And meet our Personas with their health story.

The main purpose of the SYNDERAI methodology is to create between 1,000 and 25,000+ records of **synthetic example instances** in FHIR based on the base of the European EHRxF specifications.

## Usage

See "Process Description" in "[Principles](https://synderai.net/index.php?menu=principles)".