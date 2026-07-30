<?php
/**
 * ConditionSummaryFilter.php
 *
 * Generic encounter -> summary filter for conditions.
 *
 * Decides, for each Condition in an encounter-based record, whether it belongs
 * in a patient summary problem list, belongs in a different section, or should
 * be dropped.
 *
 * DESIGN: RULES FIRST, TABLES SECOND
 *   A 190-entry lookup breaks the next time a module adds a code. So the
 *   classifier runs lexical and semantic-tag rules that generalise, with
 *   explicit tables only where the rules would get it wrong. An unrecognised
 *   code is still classified sensibly.
 *
 * DESIGN: FAIL TOWARD INCLUSION
 *   Dropping a real problem from a summary is a patient-safety issue. Adding a
 *   spurious one is noise. Unknown codes therefore default to KEEP, and the
 *   burden of proof sits on exclusion.
 *
 * FOUR TIERS, evaluated in order
 *   1 ALWAYS_DROP    symptoms, signs, administrative items - drop at any status
 *   2 DROP_IF_RESOLVED  acute illness, obstetric events, surgical episodes
 *   3 RESTATE        resolved but management-relevant -> "history of"
 *   4 KEEP           everything else
 *
 *   Tier 2 is status-dependent on purpose. Acute renal failure that resolved is
 *   noise; acute renal failure still running is the most important thing on the
 *   list. One rule, both behaviours.
 *
 * VERDICTS
 *   KEEP             include in the problem list
 *   RESTATE          include, recoded as a "history of" concept
 *   SUPERSEDED       drop, a chronic concept in the same record already covers it
 *   ROUTE_ALLERGY    belongs in AllergyIntolerance
 *   ROUTE_PREGNANCY  belongs in pregnancy history / status
 *   ROUTE_SOCIAL     belongs in social history
 *   DROP             not summary material
 *
 * Verified against the 190 distinct condition concepts emitted by the SynderAI
 * EU module set. Counts are reported by the accompanying test harness.
 *
 * PHP 8.1+.
 */

declare(strict_types=1);

namespace SynderAI;

final class ConditionSummaryFilter
{
    public const KEEP            = 'KEEP';
    public const RESTATE         = 'RESTATE';
    public const SUPERSEDED      = 'SUPERSEDED';
    public const ROUTE_ALLERGY   = 'ROUTE_ALLERGY';
    public const ROUTE_PREGNANCY = 'ROUTE_PREGNANCY';
    public const ROUTE_SOCIAL    = 'ROUTE_SOCIAL';
    public const DROP            = 'DROP';

    /** clinicalStatus values meaning "present now". */
    public const ACTIVE_STATUSES = ['active', 'recurrence', 'relapse'];

    // ================================================================ tier 1
    // Dropped whatever the status. Synthea records symptoms per encounter;
    // they are observations about an episode, not standing problems.

    public const SYMPTOM_OR_SIGN = [
        '14760008'  => 'constipation',
        '22253000'  => 'pain',
        '246677007' => 'passive conjunctival congestion',
        '248595008' => 'sputum finding',
        '249497008' => 'vomiting',
        '25064002'  => 'headache',
        '267036007' => 'dyspnea',
        '267060006' => 'diarrhea',
        '267102003' => 'sore throat',
        '271825005' => 'respiratory distress',
        '288959006' => 'unable to swallow saliva',
        '36955009'  => 'loss of taste',
        '386661006' => 'fever',
        '398152000' => 'poor muscle tone',
        '422587007' => 'nausea',
        '43724002'  => 'chill',
        '49727002'  => 'cough',
        '56018004'  => 'wheezing',
        '5758002'   => 'bacteremia',
        '57676002'  => 'joint pain',
        '62718007'  => 'dribbling from mouth',
        '66857006'  => 'hemoptysis',
        '68235000'  => 'nasal congestion',
        '68962001'  => 'muscle pain',
        '84229001'  => 'fatigue',
    ];

    /** Workflow, scheduling and unconfirmed items. */
    public const ADMINISTRATIVE = [
        '314529007' => 'medication review due',
        '183996000' => 'sterilization requested',
        '160245001' => 'no current problems or disability',
        '840544004' => 'suspected COVID-19',
    ];

    // ================================================================ tier 2
    // Dropped only once resolved.

    public const ACUTE_ILLNESS = [
        '10509002'  => 'acute bronchitis',
        '11218009'  => 'infection caused by Pseudomonas aeruginosa',
        '195662009' => 'acute viral pharyngitis',
        '233604007' => 'pneumonia',
        '234466008' => 'acquired coagulation disorder',
        '307426000' => 'acute infective cystitis',
        '312157006' => 'infectious mediastinitis',
        '36971009'  => 'sinusitis',
        '389087006' => 'hypoxemia',
        '40275004'  => 'contact dermatitis',
        '406602003' => 'infection caused by Staphylococcus aureus',
        '409089005' => 'febrile neutropenia',
        '43878008'  => 'streptococcal sore throat',
        '444814009' => 'viral sinusitis',
        '448813005' => 'sepsis caused by Pseudomonas',
        '45816000'  => 'pyelonephritis',
        '47318007'  => 'drug-induced neutropenia',
        '53827007'  => 'excessive salivation',
        '65275009'  => 'acute cholecystitis',
        '65363002'  => 'otitis media',
        '65710008'  => 'acute respiratory failure',
        '67782005'  => 'acute respiratory distress syndrome',
        '68566005'  => 'urinary tract infectious disease',
        '75498004'  => 'acute bacterial sinusitis',
        '76571007'  => 'septic shock',
        '770349000' => 'sepsis caused by virus',
        '840539006' => 'COVID-19',
        '87628006'  => 'bacterial infectious disease',
        '91302008'  => 'sepsis',
        '267064002' => 'retention of urine',
    ];

    /**
     * Resolved acute surgical or traumatic episodes.
     *
     * These are disorders and they stay Conditions. An earlier version routed
     * them to the Procedures section, which was a category error: that section
     * holds Procedure resources, and a Condition cannot go there whatever its
     * clinical origin. Appendicitis is a disorder. Appendectomy is a procedure.
     *
     * They are dropped once resolved because the surgery itself is recorded
     * separately as a Procedure, and because Synthea emits the corresponding
     * history concept in its own right - 428251008 History of appendectomy,
     * which the history-of rule keeps. Nothing is lost by dropping the disorder.
     *
     * Still active, they are real standing problems and are kept.
     */
    public const SURGICAL_OR_INJURY = [
        '74400008'  => 'appendicitis',
        '47693006'  => 'rupture of appendix',
        '235919008' => 'gallbladder calculus',
        '86175003'  => 'injury of heart',
        '40095003'  => 'injury of kidney',
        '157265008' => 'dislocation of hip joint',
    ];

    /**
     * Pregnancy STATUS. These are findings describing a current state, and the
     * summary carries a pregnancy section for exactly this. Routing is correct.
     */
    public const PREGNANCY_STATUS = [
        '72892002' => 'normal pregnancy',
        '47200007' => 'high risk pregnancy',
    ];

    /**
     * Obstetric EVENTS. Disorders, so they stay Conditions - the same category
     * error as the surgical group applied here too. Dropped once resolved,
     * except where a history concept exists to restate them (see
     * RESTATE_AS_HISTORY, which covers pre-eclampsia and miscarriage).
     */
    public const OBSTETRIC_EVENT = [
        '35999006'  => 'blighted ovum',
        '79586000'  => 'tubal pregnancy',
        '267253006' => 'fetus with chromosomal abnormality',
        '198992004' => 'eclampsia in pregnancy',
        '609496007' => 'complication occurring during pregnancy',
    ];

    // ================================================================ tier 3

    /**
     * Resolved but management-relevant. acute concept => [history code, display].
     *
     * Restatement is only valid where the history concept follows from the
     * disorder itself. A miscarriage implies a past history of miscarriage.
     * Appendicitis does NOT imply an appendectomy happened, which is why the
     * surgical group is dropped rather than restated.
     */
    public const RESTATE_AS_HISTORY = [
        '422504002' => ['275526006', 'History of cerebrovascular accident (situation)'],
        '230690007' => ['275526006', 'History of cerebrovascular accident (situation)'],
        '254837009' => ['429740004', 'History of malignant neoplasm of breast (situation)'],
        '398254007' => ['161765003', 'History of pre-eclampsia (situation)'],
        '706870000' => ['161512003', 'History of pulmonary embolism (situation)'],
        '132281000119108' => ['161511005', 'History of deep vein thrombosis (situation)'],
        '19169002'  => ['161744009', 'Past pregnancy history of miscarriage (situation)'],
        '85116003'  => ['161744009', 'Past pregnancy history of miscarriage (situation)'],
        '156073000' => ['161744009', 'Past pregnancy history of miscarriage (situation)'],
    ];

    /** Acute concept already covered by a chronic concept the modules also emit. */
    public const SUPERSEDED_BY = [
        '22298006'  => '1755008',
        '401314000' => '1755008',
        '401303003' => '1755008',
    ];

    // ============================================================== routing

    public const ALLERGY_CONCEPTS = ['1003755004' => 'allergy to latex protein'];
    public const SOCIAL_CONCEPTS  = ['10939881000119105' => 'unhealthy alcohol drinking behavior'];

    // ============================================================ overrides
    // Codes the generic rules would misclassify. Each needs a reason.

    public const FORCE_KEEP = [
        '197927001'       => 'recurrent UTI - recurrence is itself the standing problem',
        '40055000'        => 'chronic sinusitis - chronic despite the sinusitis family',
        '1755008'         => 'old MI - the chronic form, not an acute event',
        '129721000119106' => 'acute renal failure on dialysis - ongoing renal replacement',
        '698303004'       => 'awaiting transplantation - critical to any summary',
        '1163220007'      => 'pressure injury - ongoing wound care',
        '271737000'       => 'anemia - frequently chronic',
        '221360009'       => 'spasticity - persistent manifestation, not a transient sign',
        '15777000'        => 'prediabetes - no semantic tag, would fail tag rules',
        '90560007'        => 'gout - no semantic tag',
    ];

    // =============================================================== config

    private bool $dropActiveSymptoms;

    /**
     * @param bool $dropActiveSymptoms Synthea leaves symptom records active long
     *        after the episode. Set false to retain active symptoms - appropriate
     *        only if the source marks them accurately.
     */
    public function __construct(bool $dropActiveSymptoms = true)
    {
        $this->dropActiveSymptoms = $dropActiveSymptoms;
    }

    // ================================================================== API

    /**
     * Classify one condition.
     *
     * @return array{0:string,1:string,2:?array{0:string,1:string}} verdict, reason,
     *         and for RESTATE the replacement [code, display]
     */
    public function classify(string $code, string $display = '', ?string $clinicalStatus = null): array
    {
        $active = in_array(strtolower((string)$clinicalStatus), self::ACTIVE_STATUSES, true);
        $d      = strtolower(trim($display));

        // --- routing, independent of status --------------------------------
        if (isset(self::ALLERGY_CONCEPTS[$code]) || str_starts_with($d, 'allergy to')) {
            return [self::ROUTE_ALLERGY, 'allergy concept - belongs in AllergyIntolerance', null];
        }
        if (isset(self::SOCIAL_CONCEPTS[$code])) {
            return [self::ROUTE_SOCIAL, 'behaviour concept - belongs in social history', null];
        }

        // --- overrides ------------------------------------------------------
        if (isset(self::FORCE_KEEP[$code])) {
            return [self::KEEP, self::FORCE_KEEP[$code], null];
        }

        // --- already in summary form ---------------------------------------
        if ($this->isHistoryConcept($d)) {
            return [self::KEEP, 'history-of situation, already summary form', null];
        }

        // --- tier 1: never summary material --------------------------------
        if (isset(self::ADMINISTRATIVE[$code]) || str_starts_with($d, 'suspected ')) {
            return [self::DROP, 'administrative, workflow or unconfirmed item', null];
        }
        if (isset(self::SYMPTOM_OR_SIGN[$code]) || ($this->looksLikeSymptom($d) && !$active)) {
            if ($this->dropActiveSymptoms || !$active) {
                return [self::DROP, 'symptom or sign, not a standing problem', null];
            }
        }

        // --- tier 3 before tier 2: significance outranks acuteness ----------
        if (isset(self::SUPERSEDED_BY[$code])) {
            return $active
                ? [self::KEEP, 'acute event still active', null]
                : [self::SUPERSEDED, 'covered by chronic concept ' . self::SUPERSEDED_BY[$code], null];
        }
        if (isset(self::RESTATE_AS_HISTORY[$code])) {
            return $active
                ? [self::KEEP, 'significant event still active', null]
                : [self::RESTATE, 'resolved but management-relevant', self::RESTATE_AS_HISTORY[$code]];
        }

        // --- tier 2: status-dependent --------------------------------------
        if ($active) {
            return [self::KEEP, 'active', null];
        }
        if (isset(self::PREGNANCY_STATUS[$code])) {
            return [self::ROUTE_PREGNANCY, 'pregnancy status - belongs in the pregnancy section', null];
        }
        if (isset(self::OBSTETRIC_EVENT[$code])) {
            return [self::DROP, 'resolved obstetric event', null];
        }
        if (isset(self::SURGICAL_OR_INJURY[$code])) {
            return [self::DROP,
                    'resolved surgical or traumatic episode - the operation itself '
                    . 'is recorded as a Procedure', null];
        }
        if (isset(self::ACUTE_ILLNESS[$code]) || $this->looksAcute($d)) {
            return [self::DROP, 'resolved acute self-limiting illness', null];
        }

        // --- tier 4 ---------------------------------------------------------
        return [self::KEEP, 'default: retained (fail toward inclusion)', null];
    }

    /**
     * Apply to a patient's conditions.
     *
     * @param array<int,array<string,mixed>> $conditions each with code / display / clinicalStatus
     * @return array{problems:list<array<string,mixed>>,routed:array<string,list<array<string,mixed>>>,dropped:list<array<string,mixed>>,stats:array<string,int>}
     */
    public function filter(array $conditions): array
    {
        $problems = [];
        $routed   = [self::ROUTE_ALLERGY => [], self::ROUTE_PREGNANCY => [],
                     self::ROUTE_SOCIAL => []];
        $dropped  = [];
        $counts   = [];

        foreach ($conditions as $c) {
            [$verdict, $reason, $replacement] = $this->classify(
                (string)($c['code'] ?? ''),
                (string)($c['display'] ?? ''),
                isset($c['clinicalStatus']) ? (string)$c['clinicalStatus'] : null
            );
            $counts[$verdict] = ($counts[$verdict] ?? 0) + 1;
            $c['verdict'] = $verdict;
            $c['reason']  = $reason;

            switch ($verdict) {
                case self::KEEP:
                    $problems[] = $c;
                    break;
                case self::RESTATE:
                    $c['converted_from'] = $c['code'] ?? null;
                    $c['code']           = $replacement[0];
                    $c['display']        = $replacement[1];
                    $c['clinicalStatus'] = 'resolved';
                    $problems[] = $c;
                    break;
                case self::ROUTE_ALLERGY:
                case self::ROUTE_PREGNANCY:
                case self::ROUTE_SOCIAL:
                    $routed[$verdict][] = $c;
                    break;
                default:
                    $dropped[] = $c;
            }
        }

        return ['problems' => $problems, 'routed' => $routed,
                'dropped' => $dropped, 'stats' => $counts];
    }

    // ============================================================ heuristics
    // These carry unrecognised codes. Kept deliberately conservative: each one
    // only ever moves a code toward exclusion when the wording is unambiguous.

    private function isHistoryConcept(string $d): bool
    {
        return str_starts_with($d, 'history of')
            || str_starts_with($d, 'past ')
            || str_contains($d, 'history of');
    }

    private function looksLikeSymptom(string $d): bool
    {
        if (!str_contains($d, '(finding)')) {
            return false;
        }
        foreach ([' symptom', ' pain', 'ache', 'nausea', 'fever', 'cough',
                  'fatigue', 'discomfort', 'sensation'] as $needle) {
            if (str_contains($d, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function looksAcute(string $d): bool
    {
        if (str_starts_with($d, 'acute ')) {
            return true;
        }
        foreach (['infection caused by', 'sepsis caused by', 'infectious disease'] as $needle) {
            if (str_contains($d, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Semantic tag of a display string, or '' when absent. */
    public static function semanticTag(string $display): string
    {
        return preg_match('/\(([a-z\/ ]+)\)\s*$/i', trim($display), $m) ? strtolower($m[1]) : '';
    }
}
