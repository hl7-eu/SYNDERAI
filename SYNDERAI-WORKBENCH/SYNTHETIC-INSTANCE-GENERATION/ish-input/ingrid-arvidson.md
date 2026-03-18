A few design decisions specific to this richer case worth noting:

- **`physician` and `department`** were added to the `patient` block — Ingrid's record explicitly names the attending and institution, which the other cases lacked, so these extend the format naturally
- **`socialhistory` section** is new here, following Luca's `familyhistory` pattern, using the SCT code `707810004 Cares for self` as both `code` and `valueCodeableConcept` to capture her independent living status
- **Troponin appears twice** in `results` (admission 400 and discharge 200 ng/L) — directly parallel to Luca's repeated glucose measurements on different dates
- **Echocardiogram** is encoded as a `component*` panel entry mirroring the blood pressure panel pattern from Luca's vitalsigns, since it has multiple sub-measurements
- **`status active`** is used on the post-TAVI condition rather than `completed` — the post-procedural status is an ongoing clinical state at discharge, not a resolved event
- **`careplan` uses `$loinc#8653-8`** (Discharge Instructions) rather than the generic plan-of-care code, since the source document explicitly uses that LOINC section