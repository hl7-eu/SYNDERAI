# From Encounters to Summaries

*Condition summarisation in the SynderAI pipeline. Last revised 30 July 2026.*

Every synthetic patient in SynderAI carries a full medical history. Conditions accumulate over a simulated lifetime, encounter by encounter, in the same way a real record grows. When we exported a cohort of 200 patients as European Patient Summaries and counted what landed on the problem list, the average patient arrived with just under sixteen entries.

That number was the starting point for this work. Sixteen problems is not a summary. It is an archive.

A closer look showed what was filling the list. Acute viral pharyngitis appeared in 81 per cent of patients. Acute bronchitis in 80 per cent. Otitis media in 64 per cent. Almost all of these were recorded as resolved, some of them several times over for the same patient, decades apart. Four fifths of all condition entries in the cohort carried a clinical status of inactive.

The obvious reading is that the generator is producing too much disease. The more accurate reading is that the generator is producing exactly what it was designed to produce, and that we were asking it the wrong question.

## What Synthea actually models

Synthea, developed by MITRE, simulates a longitudinal health record. Its modules advance a patient through time and write a record entry whenever something clinically meaningful happens. A throat infection in 2004 becomes a Condition resource. A second throat infection in 2011 becomes another one. Both are correct. Both happened. A real electronic health record would hold both, because a record is a chronological account of encounters with the health system.

This is the right design for what Synthea is for. Researchers testing an analytics pipeline, a data warehouse or a quality measure need the full encounter history. Collapsing it would destroy the very structure they are testing against.

The encounter-based record is therefore not a defect to be corrected upstream. It is the source material.

## What a patient summary is

The International Patient Summary and its European profile serve a different purpose entirely. An EPS exists so that a clinician who has never met the patient, often in another country and often under time pressure, can understand what matters about them now. It is a document produced for a moment of care, not a transcript of everything that has ever been recorded.

That difference has a concrete consequence for the problem list. The question each entry must answer is not "did this happen" but "would a clinician treating this patient today need to know it". A throat infection that cleared in 2004 fails that test. Chronic kidney disease at stage four passes it without argument. A stroke three years ago passes it too, even though the acute episode itself resolved, because it changes how the patient is treated now.

Between those extremes sit the interesting cases, and they cannot be settled by clinical status alone. Acute respiratory failure that resolved is noise on a summary. Acute respiratory failure that is still running is the most important line on the page. The same concept, the same code, opposite verdicts.

So the gap is not in the generator and not in the specification. It sits between them. Producing an EPS from a longitudinal record requires a summarisation step, and that step was missing from our pipeline.

## Two algorithms, kept apart on purpose

We built the summarisation as two separate passes. The separation matters more than it might appear.

**Suppression** asks whether a condition belongs in a summary at all. It works in tiers. Symptoms, signs and administrative items are removed regardless of status, because a fever recorded during an encounter is an observation about that episode rather than a standing problem. Acute self-limiting illness, obstetric events and surgical episodes are removed only once resolved, which is what gives the same rule opposite behaviour for the two respiratory failure cases above. Conditions that resolved but still govern treatment are not removed at all. They are restated as history concepts, so a resolved ischaemic stroke becomes a recorded history of cerebrovascular accident rather than disappearing, and a miscarriage becomes a recorded past pregnancy history of miscarriage.

A small number of conditions leave the problem list without being discarded. A latex allergy belongs in the allergy section and a current pregnancy belongs in the pregnancy section. These are routed rather than dropped, so the information stays in the summary and appears where a reader expects to find it.

Routing applies only where the concept genuinely belongs to another section. It is tempting to send a resolved appendicitis to the procedures section, but that would be a category error. Appendicitis is a disorder and stays a Condition. The appendectomy is the procedure, and it is recorded separately, together with the corresponding history concept. So the appendicitis is dropped once resolved rather than routed, and nothing is lost.

The rules are written to generalise rather than to enumerate. A fixed list of the codes we happen to emit today would break the next time a module is added, so the classifier reads SNOMED semantic tags and display wording, and falls back on explicit tables only where the general rules would get it wrong. Unknown codes default to inclusion. Dropping a real problem from a summary is a safety question, whereas keeping a spurious one is a readability question, and those two are not equally serious.

**Consolidation** asks a different question: is this one problem or several. Nine depression episodes across twenty years are not nine problems. They are one recurrent illness. The pass groups entries by problem identity, then emits a single condition carrying the earliest onset, the current status and a count of episodes. FHIR already provides the right vocabulary for the result. A condition present now that had previously resolved is recorded with a clinical status of recurrence, so the episode history survives as meaning rather than as duplicated rows.

That distinction is narrower than it first appears. Several members in a group are not sufficient for recurrence, because nothing in the group need ever have remitted. Three overlapping codes for continuously present sleep apnoea, or a kidney disease that progressed from stage two to stage four, both produce multi-member groups in which the patient was never free of the problem. Those are plain active. Recurrence requires a resolved member alongside an active one.

Grouping uses SNOMED subsumption where a terminology server is available. Obstructive sleep apnoea is a kind of sleep apnoea, which is a kind of sleep disorder, so three entries collapse to the most specific one the record actually asserted. Staged conditions need a separate rule, because chronic kidney disease stage two and stage four are siblings rather than ancestor and descendant. There the pass keeps the highest stage reached together with the earliest onset, which reads as one problem that progressed rather than two unrelated ones.

Why keep the two passes apart? Because merging them invites a subtle error. If suppression and consolidation ran as one step, five episodes of acute pharyngitis would be a tempting candidate for collapsing into a single recurrent pharyngitis problem. No clinician ever asserted that diagnosis. Suppression removes those episodes before consolidation ever sees them, and the fabricated problem never gets a chance to appear.

## The epsConditionAdapter

The two passes are implemented in PHP and reached through a single adapter, which sits between the ISH intermediate representation and the FHIR rendering stage. The same summarised problem list feeds the European Patient Summary and the Hospital Discharge Report without duplicated logic.

### Invocation

```php
$filteredConditions = epsConditionAdapter::summariseConditions($pdat->conditions);
```

or, retaining the instance so that diagnostics remain available:

```php
$adapter = new epsConditionAdapter();
$filteredConditions = $adapter->summarise($pdat->conditions);
$report = $adapter->lastReport();
```

The return value has the same shape as the input, so `$filteredConditions` substitutes directly for `$pdat->conditions` anywhere downstream.

### Return contract

Every key present on an input record is present on the output record, so existing consumers continue to work. Two adjustments are made.

The `start`, `end` and `active` fields are recomputed rather than copied. Consolidation moves onset to the earliest episode in a group and abatement to the latest, so a representative entry's original dates would describe the wrong episode. The `active` field is regenerated in the same format the ISH builder uses.

Three keys are added: `clinicalStatus`, `episodes`, and for a restated concept `convertedFrom`. These carry information the ISH shape cannot express. A condition that is present now having previously resolved has an empty `end`, which is indistinguishable from a condition that was never resolved, so the recurrence distinction would be lost without an explicit status. The FHIR renderer requires `clinicalStatus` to populate `Condition.clinicalStatus` correctly.

Constructing the adapter with `annotate: false` suppresses the added keys and yields a strictly identical shape. Recurrence then collapses to active.

### Diagnostics

`lastReport()` returns what the previous call removed and why. Dropped records carry a `summaryReason` field, so any decision can be traced without re-running the filter. Conditions routed to other sections appear under `routed`, keyed by destination.

At log level 3 the pipeline prints the reduction for each patient:

```
14 source entries -> 2 problems, 0 routed, 12 dropped

PROBLEM LIST
  CODE         STATUS       EP   ONSET        ABATED       DISPLAY
  59621000     active       1    2022-08-26   -            Essential hypertension (disorder)
  386805003    resolved     1    2020-07-18   2024-02-02   Mild neurocognitive disorder (disorder)

DROPPED
  1x  444814009    Viral sinusitis                resolved acute self-limiting illness
  1x  307426000    Acute infective cystitis       resolved acute self-limiting illness
  4x  10509002     Acute bronchitis               resolved acute self-limiting illness
  3x  195662009    Acute viral pharyngitis        resolved acute self-limiting illness
  2x  65363002     Otitis media                   resolved acute self-limiting illness
  1x  91302008     Sepsis                         resolved acute self-limiting illness
```

A second diagnostic, `inspectActiveField()`, examines the ISH `active` field. Every observed value is a single flag digit concatenated with the start date, and the flag always agrees with whether `end` is empty. The field therefore carries no information that `end` does not. The adapter derives status from `end` and regenerates `active` only for output, so nothing depends on that format, but the diagnostic reports any record where the two disagree.

### Optional terminology resolution

Grouping of overlapping concepts uses SNOMED CT subsumption where a terminology endpoint is available. Without one, grouping falls back to explicit tables and the adapter still functions.

```php
$consolidator = new EpsProblemFilter(cacheDir: __DIR__ . '/cache/subsumes');
$consolidator->setSubsumptionResolver(
    EpsProblemFilter::firelySubsumesResolver('https://ehds.art-decor.cloud/', $token)
);
$adapter = new epsConditionAdapter(consolidator: $consolidator);
```

Results are cached on disk per query, so repeated runs over a cohort incur the lookup cost once. A terminology outage degrades grouping rather than halting the pipeline.

## What it produces

Run over the same 200-bundle cohort, the two passes reduce 3,171 condition entries to 759. The average problem list falls from 15.9 entries to 3.8. The resulting statuses divide into 541 active, 37 recurrence and 181 resolved. Fifty-four entries are restated as history concepts rather than dropped. Thirty-four patients finish with an empty problem list, which is a plausible outcome for younger synthetic patients and the one circumstance in which an explicit assertion of no known problems genuinely belongs.

The single record shown above is representative. Fourteen entries reduce to two: active essential hypertension and a resolved mild neurocognitive disorder. The twelve removed entries are four episodes of acute bronchitis, three of acute viral pharyngitis, two of otitis media, and one each of viral sinusitis, acute infective cystitis and sepsis.

Four entries per patient is a summary a clinician can read.

## What this does not fix

Summarisation should not become a convenient place to hide generator defects, and the analysis that motivated this work surfaced two that pointed upstream rather than at the export.

A small number of patients held contradictory kidney disease stages at the same time. The consolidation pass resolves that in the summary, but the source record was wrong: progression added a stage without closing the previous one, and in one variant added a lower stage than the patient already held. That has since been corrected in the module, which now closes the earlier stage on progression.

The prevalence work is less settled. Comparison of cohort rates against European reference figures shows several conditions generating above plausible European levels. At least one of those turned out to be a structural fault rather than a calibration error, in a module where a yearly loop re-entered the high-risk branch for every patient regardless of the tier assigned at intake. That fault has been corrected. Whether the remaining differences are calibration or further faults of the same kind is the subject of continuing work, reported separately.
