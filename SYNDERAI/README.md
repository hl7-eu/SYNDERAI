**Synthetic Data: Examples – Realistic – using AI (SYNDERAI)**, pronounced **/ˈsɪn.də.raɪ/**

© [HL7 Europe](https://hl7europe.org) | Main Contributor: Dr. Kai U. Heitmann | [Privacy Policy](https://hl7europe.eu/privacy-policy-for-hl7-europe/) • AGPL-3.0 license

# SYNDERAI – methodology, functions and artifacts

## Directory Structure

### SYNTHETIC-DATA-GENERATION

This directory contains all scripts and data used for the synthetic data generation.

### SYNTHETIC-DATA

This directory contains all generated synthetic data as result of the synthetic data generation process.

### SYNTHETIC-INSTANCE-GENERATION

This directory contains the all parts for the **core process**: the generation of **synthetic example instances** based on the synthetic data as input and represented in FHIR Shorthand (FSH) format

### FSH-FHIR-GENERATOR

This directory contains subdirectories per artifact where the original gitgub repositories reside as copies and where all synthetic example instances from the core process will be transformed into FHIR JSON instances for further processing. 

### PERSONAS

This directory contains material about the SYNDERAI personas that all have a complete Hospital Dischare Report as core artifact along with all other derived artifacts. 

### MAPPINGS

This directory contains mapping files and concept maps used by the core process.

### CONSTANTS

This directory contains settings and global constants.

### TESTBEDS

This directory has some testing scripts and testing materials.

## Purpose

SYNDERAI provides realistic, privacy-preserving synthetic European healthcare data, including the first EU-Lab FHIR synthetic datasets. Explore reusable examples supported by AI for interoperability and secondary use in healthcare systems. And meet our Personas with their health story.

The main purpose of the SYNDERAI methodology is to create 25.000+ records of **synthetic example instances** in FHIR based on the base of the European EHRxF specifications

## Usage

tbd