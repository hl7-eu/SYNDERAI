A few translation decisions worth noting:

**New `organization` block** — the Sophia/Erasmus MC resource has no equivalent in the previous ISH files, but it's a first-class FHIR resource here (referenced as `performer` on all four lab results and as `author` on the Composition), so it deserves its own top-level block with `agb-z` carrying the Dutch identifier system value.

**Nested `section\*` inside `section\*`** — the JSON uses a two-level section hierarchy for both the Admission evaluation (containing Vital signs as a child section) and the Prenatal History (containing Social history and Procedures as child sections). This is represented with indented `section*` blocks, extending the ISH pattern naturally.

**`diagnosis\*`** on the encounter — the JSON distinguishes `reasonCode` (Twin pregnancy) from `diagnosis[].condition` (the MCDA condition resource). Both are preserved on the encounter block as `reason*` and `diagnosis*` respectively.

**Tobacco `component\*` on an `entry\*`** — the tobacco observation has two sub-components in the JSON (type of tobacco and consumption quantity) alongside the top-level `valueCodeableConcept`. This mirrors the blood pressure `component*` pattern already established in Luca's vital signs, applied here at entry level.

**`Discharge details` section** has no entries in the JSON (only a `text` with `status: generated` and no narrative div), so it is kept as a title-only stub, faithfully reflecting the empty section in the source.