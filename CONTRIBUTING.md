# Contributing to SYNDERAI

SYNDERAI generates synthetic example data for the European EHRxF specifications. Because the output is published as example instances that implementers validate against, a contribution that looks harmless can change thousands of generated resources. This document says what we expect of a change, and how a change reaches `master`.

The project is run by [HL7 Europe](https://hl7europe.org) and licensed under AGPL-3.0.

## Who can contribute

Anyone with a GitHub account. The repository is public and forking is enabled, so no permission on this repository is needed to propose a change: fork it, work on a branch in your fork, and open a pull request against `master`.

Repository permissions decide where you may push, not whether you may contribute:

| | push a branch here | open a pull request | merge into `master` |
|---|---|---|---|
| anyone (fork) | no, push to your fork | yes | no |
| team member with write | yes, a feature branch | yes | no |
| maintainer | yes | yes | yes |

## Nobody pushes to `master` (except the SYNDERAI maintainers)

Only the maintainers write to `master`. Everyone else — outside contributors and team members with write access alike — proposes changes through a pull request, and a maintainer merges it.

Current maintainers: **@kheitmann** (Kai Heitmann) and **@gcangioli** (Giorgio Cangioli).

What that means in practice:

- Write access lets you push a feature branch to this repository. It does not let you merge it. Open a pull request and ask for review.
- A pull request needs an approving review from one of the maintainers, who are listed in `docs/CODEOWNERS`.
- Force pushes to `master` and deletion of `master` are blocked for everyone.
- The maintainers can merge and, where it is warranted, push to `master` directly. That exception exists so a release or a one-line fix is not blocked on availability, not as a way around review.

## What a good pull request looks like

This project has learned the hard way that a change described in intent rather than in evidence costs more than it saves. Four rounds of calibration work were based on a priority list that the next measurement contradicted, each time because a number had not been re-measured. So:

**State the measured effect, not the expected one.** "Fixes the unit" is not enough; "4202 occurrences of `structural-example-url` in validation run 4751, one per LAB bundle" is. Give the count, the path, and where the number comes from.

**Say how you verified it, with the command.** A verification that another person can run is worth more than an assurance. For example:

```bash
grep -rn 'identifier.system = "http://example.org"' php/twig/templates/
```

**Distinguish absent from clean.** A check that returns zero because the thing it looks for was not in the corpus is not a passing check. If your evidence comes from a run that did not contain the workstream you changed, say so. We have twice mistaken a null result for an all-clear.

**One cause per pull request.** A validation run may report a single defect hundreds of times; that is still one pull request. Conversely, do not bundle an EPS fix and a LAB fix because they were found on the same day.

**Prevalence and conformance are different claims.** A conformance fix is verified against a validator run. A prevalence or calibration change is verified against a measurement on the Synthea corpus, standardised to the EU27 age structure, with the measurement output committed beside it. Do not use one to argue the other.

## Things that need a decision before a patch

Some findings are not defects but conflicts between two sources, and patching them produces a number that satisfies nothing. If your change touches one of these, open an issue first and let it be decided:

- a reference figure that two sources state differently
- a profile canonical that differs between a published package and the IG source, where the fix is the package version and not the template
- anything the validator reports as inferred or unverified rather than violated

A rate raised to satisfy an unsourced reference is worse than the deviation it removes.

## What not to put in the repository

- **No real patient data, ever**, and nothing derived from it. Everything here is synthetic by construction. See the SYNDERAI Synthetic Data Generation and Use Policy.
- **No credentials.** No API keys, no personal access tokens, no `config.php` with real values. If you commit one by accident, revoke it first, then tell a maintainer; rewriting history does not un-leak a secret.
- **No generated corpora.** Synthea output, merged CSV files and package build directories stay out. Commit the script that produces them and the measurement that describes them.
- **No edits to the IG master copies** under `FSH-FHIR-GENERATOR/*-master-copy` as part of an unrelated change. Those are the implementation guide sources; changing one is its own issue and its own pull request.

## Issues

Issues are the project's working record, so write them to be read again in six months. What a useful issue carries:

- what happens, with the counts and paths from the run that found it
- which run: validator version, ruleset version, corpus composition
- the cause, if known, with the file and line
- the fix, or the decision that has to be made first
- how to verify it afterwards

If a finding cannot be confirmed from the evidence at hand, say that rather than leaving the reader to assume it was confirmed. Issues in this repository distinguish *confirmed*, *not reproducible from this export*, and *not covered by this run*, and that distinction is the point.

Before opening an issue, search the existing ones, including closed. A closed issue sitting over a live defect should be reopened or commented on, not duplicated.

## Licensing of contributions

By contributing you agree that your contribution is licensed under AGPL-3.0, the licence of this repository, and that you have the right to submit it.

Sign off each commit to state that:

```
git commit -s -m "LAB: replace example.org identifier system"
```

This adds a `Signed-off-by:` line, the [Developer Certificate of Origin](https://developercertificate.org/). If you contribute on behalf of an organisation, make sure you are permitted to do so under this licence.

## Repository layout

| Path | What it holds |
|---|---|
| `SYNDERAI-WORKBENCH/` | methodology, functions and artifacts; see its own README |
| `SYNDERAI-WORKBENCH/SYNTHETIC-DATA-GENERATION/` | the European Synthea fork, modules, and the prevalence measurement |
| `SYNDERAI-WORKBENCH/FSH-FHIR-GENERATOR/` | the EPS, LAB and HDR implementation guide master copies |
| `website/` | the content of synderai.net |

The README also mentions a `material/` directory for design material. It is not present in the repository root as of 23 October 2026 — either it was removed or never committed, and the README needs correcting either way.

## Questions

Open an issue. For anything about the data policy or the use of the generated examples, address it to HL7 Europe rather than to this repository.
