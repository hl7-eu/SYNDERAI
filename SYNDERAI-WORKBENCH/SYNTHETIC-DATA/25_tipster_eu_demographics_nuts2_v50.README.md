# `25_tipster_eu_demographics_nuts2_v50.tsv`

The demographics file for **Release 5**. Derived from `v12` on 2026-10-03 by `AICODNG/SYNDERAI/tools/desensitise_demographics.py`, seed 20261003. The script is deterministic: the same input and seed reproduce this file exactly.

## Why this file replaces v12

v12 carried **real street addresses**. Its `invented` column was `false` on 31,330 of 33,343 rows, and 24 of the 36 countries — including DE, FR, GB, IT, ES and NL — had no invented row at all. That column recorded where a real source had been unavailable; it was never a privacy control, and its name invited the opposite reading.

`synderai-v7.php` writes those addresses into every European Patient Summary, Hospital Discharge Report and laboratory report it generates. The published artefacts therefore stated, in effect, that at a specific real dwelling lives someone with a given set of diagnoses. The person was fabricated; the letterbox was not.

The Netherlands is the sharpest case and the reason this was noticed. A Dutch postcode denotes one side of one street segment, so postcode plus house number **is** the address. All 1,067 NL rows carried a valid one, 1,053 of them distinct, with coordinates to six decimals — a ten-centimetre geocode of a building.

Under Recital 26 GDPR data is anonymous only where identification is not reasonably likely. A deliverable address identifies a household, and this dataset attaches Article 9 health data to it. Redistribution through HL7 Europe makes identification easier, not harder.

## What changed

| field | v12 | v50 |
| --- | --- | --- |
| `street` | real | generated, all 33,343 rows |
| `housenumber` | real | random 1–180, where v12 had one |
| `line` | real | rebuilt; every digit run replaced |
| `additional` | real | digits replaced |
| `postcode` | real | kept, except the fine tail in NL, GB and IE — 3,801 rows |
| `latitude`, `longitude` | 6 decimals, ~0.1 m | 2 decimals, ~1 km |
| `invented` | present | **removed** |

The version jumps from 12 to 50 to mark the Release 5 line; it is a renumbering, not twelve intermediate revisions.

Unchanged, because none of it identifies a dwelling and the pipeline needs all of it: `language`, `given`, `family`, `gender`, `birthdate`, `age`, `eci`, `countrycode`, `city`, `country`, `phone`, `region`. Those were checked and are synthetic in v12 already — the `eci` follows a uniform house scheme (`9999-999999-9`) across all 36 countries and is not a national identifier, the phone numbers are generated, and the name distribution is far too flat for a real register: 443 distinct given names across 1,067 NL rows, the commonest appearing 8 times, where a real Dutch sample would repeat `de Jong` about five times.

## Postcodes

In most countries the whole postcode denotes an area — a German five-digit code covers a town or district — so it is kept intact. Three encode something finer and had their tail regenerated, preserving each value's character-class pattern so the format mix is unchanged:

| | | |
| --- | --- | --- |
| NL | `9999 AA` | the two letters pick one side of one street segment |
| GB | `AA9 9AA` | the inward code reaches about fifteen delivery points |
| IE | `A99 A9A9` | an Eircode's last four characters identify a **single** delivery point |

## Street names

Generated per country from a pattern learned from v12 itself, so no opinion about 36 languages is baked into the script. Four shapes occur and each is detected separately: a thoroughfare word first (FR `rue`, IT `Piazza`, ES `Alameda`, PL `plac`), glued on at the end (DE `-straße`, NL `-straat`, CZ `-ická`), a stem plus a generic noun (GB `Lees fields`), or a bare name (GR). Stems are drawn from the given and family names already in the file.

## What this guarantees, and what it does not

**Guaranteed, asserted by the script and re-checked afterwards: no address in v50 reproduces any address in v12.** Every (country, city, street) triple was tested against the full v12 set and redrawn on collision; the final comparison over (country, city, street, house number) returns zero matches.

**Not guaranteed: that a generated street name exists nowhere in that city.** A plausible construction may coincide with a real street, and with a random house number could land on a real letterbox. The risk is small and, unlike v12, not systematic — but describe this dataset as **de-identified**, not as provably address-free. Checking the output against an official street register per country would close that gap and is the obvious next step if a stronger assurance is needed.

Thirty-two rows contain a number that also appeared as the house number in v12. All are coincidences: an apartment number drawn from 1–60 matching an old house number of the same value, on a different street with a different house number. No information carries across.

## Distributions preserved

Country, NUTS-2 region, city, gender, age, birthdate and language are byte-identical to v12, so any sampling or stratification built on v12 behaves the same. Only the address changed.
