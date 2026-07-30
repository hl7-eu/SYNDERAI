<?php
/**
 * EpsProblemFilter.php
 *
 * Turns Synthea's encounter-based condition record into an episode-based
 * problem list suitable for a European Patient Summary.
 *
 * WHY THIS EXISTS
 *   Synthea emits one Condition per clinical episode, which is correct for a
 *   longitudinal record. An EPS is a derived summary: one entry per problem,
 *   carrying its current status. Without a summarisation step the problem list
 *   runs to ~16 entries per patient, most of them resolved acute infections
 *   recorded several times over.
 *
 * TWO SEPARATE MECHANISMS - deliberately not merged
 *   1. SUPPRESSION  - does this problem belong in a summary at all?
 *   2. CONSOLIDATION - is this one problem or several?
 *
 *   Conflating them tempts one to fold five episodes of acute pharyngitis into
 *   a fabricated "recurrent pharyngitis" problem that no clinician ever
 *   asserted. Suppression removes them; consolidation never sees them.
 *
 * MEASURED EFFECT on a 200-bundle EPS cohort
 *   raw Synthea                 3171 entries   15.9 per patient
 *   after suppression            843 entries    4.2 per patient
 *   after consolidation          718 entries    3.6 per patient
 *   resulting status split: 491 active / 174 resolved / 53 recurrence
 *
 * INPUT
 *   An array of condition records, one per Synthea Condition:
 *     [
 *       'code'           => '195662009',          // SNOMED, required
 *       'display'        => 'Acute viral pharyngitis (disorder)',
 *       'clinicalStatus' => 'active'|'inactive'|'resolved'|'remission'|null,
 *       'onset'          => '2019-03-17',         // ISO date, optional
 *       'abatement'      => '2019-04-02'|null,
 *     ]
 *   Extra keys are preserved on the surviving record.
 *
 * OUTPUT
 *   process() returns ['problems' => [...], 'suppressed' => [...], 'stats' => [...]]
 *   Each problem gains: 'episodes', 'onset' (earliest), 'abatement' (latest,
 *   only when nothing is active), and a recalculated 'clinicalStatus'.
 *   Suppressed records gain 'suppressed_reason' so the drop is auditable.
 *
 * SUBSUMPTION
 *   Grouping overlapping concepts (OSA is-a sleep apnea is-a sleep disorder)
 *   is done by SNOMED subsumption where a resolver is supplied, falling back to
 *   the explicit tables below. The resolver is injectable because the right
 *   source differs per deployment - Firely $subsumes, Snowstorm, or ART-DECOR.
 *   A helper for Firely Server is provided. With no resolver the filter still
 *   works; it just relies entirely on the tables.
 *
 * PHP 8.1+. No curl_close() (deprecated 8.5, no-op since 8.0).
 */

declare(strict_types=1);

namespace SynderAI;

final class EpsProblemFilter
{
    // ---------------------------------------------------------------- config

    /**
     * Resolved self-limiting acute illness. Removed unless still active.
     * A clinician meeting this patient today does not need to know about a
     * throat infection that cleared in 2011.
     */
    public const SELF_LIMITING_ACUTE = [
        '195662009' => 'acute viral pharyngitis',
        '10509002'  => 'acute bronchitis',
        '65363002'  => 'otitis media',
        '307426000' => 'acute infective cystitis',
        '444814009' => 'viral sinusitis',
        '43878008'  => 'streptococcal sore throat',
        '36971009'  => 'sinusitis',
        '75498004'  => 'acute bacterial sinusitis',
        '233604007' => 'pneumonia',
        '45816000'  => 'pyelonephritis',
        '840539006' => 'COVID-19',
        '40275004'  => 'contact dermatitis',
        '65275009'  => 'acute cholecystitis',
        '409089005' => 'febrile neutropenia',
        '312157006' => 'infectious mediastinitis',
        '91302008'  => 'sepsis',
        '76571007'  => 'septic shock',
        '770349000' => 'sepsis caused by virus',
        '65710008'  => 'acute respiratory failure',
        '67782005'  => 'ARDS',
    ];

    /** Signs and findings secondary to an episode - not problems in their own right. */
    public const FINDING_NOT_PROBLEM = [
        '389087006' => 'hypoxemia',
        '271737000' => 'anemia',
    ];

    /**
     * Absence assertions. Valid only on an otherwise empty list; contradictory
     * when emitted alongside real problems, which is how Synthea produces them.
     */
    public const ABSENCE_ASSERTION = [
        '160245001' => 'no current problems or disability',
    ];

    /** Surgical episodes - these belong in the Procedures section. */
    public const SURGICAL_EPISODE = [
        '74400008'  => 'appendicitis',
        '47693006'  => 'rupture of appendix',
        '235919008' => 'gallbladder calculus',
        '86175003'  => 'injury of heart',
    ];

    /**
     * Obstetric events - these belong in a pregnancy history, not the EPS
     * problem list. Prior pre-eclampsia is deliberately NOT here: it is a
     * long-term cardiovascular risk marker and is routed to history instead.
     */
    public const OBSTETRIC_EVENT = [
        '19169002'  => 'miscarriage in first trimester',
        '156073000' => 'complete miscarriage',
        '35999006'  => 'blighted ovum',
        '79586000'  => 'tubal pregnancy',
        '85116003'  => 'miscarriage in second trimester',
        '267253006' => 'fetal chromosomal abnormality',
        '198992004' => 'eclampsia in pregnancy',
    ];

    /**
     * Resolved but management-relevant. Restated as "history of" rather than
     * dropped: a clinician must know about a prior stroke, MI, cancer or VTE.
     * Map: acute concept => [history concept, display].
     * An empty history code means "keep as-is, marked resolved".
     */
    public const HISTORY_OF = [
        '422504002' => ['275526006', 'History of cerebrovascular accident (situation)'],
        '230690007' => ['275526006', 'History of cerebrovascular accident (situation)'],
        '254837009' => ['429740004', 'History of malignant neoplasm of breast (situation)'],
        '398254007' => ['161765003', 'History of pre-eclampsia (situation)'],
        '706870000' => ['161512003', 'History of pulmonary embolism (situation)'],
        '132281000119108' => ['161511005', 'History of deep vein thrombosis (situation)'],
    ];

    /**
     * Acute concepts superseded by a chronic one that the modules already emit.
     * Different SNOMED hierarchies (disorder vs situation-with-explicit-context)
     * so subsumption cannot detect these.
     */
    public const SUPERSEDED_BY = [
        '22298006'  => '1755008',   // acute MI          -> old myocardial infarction
        '401314000' => '1755008',   // NSTEMI            -> old myocardial infarction
        '401303003' => '1755008',   // STEMI             -> old myocardial infarction
    ];

    /**
     * Staged conditions. Members are siblings, not ancestor/descendant, so
     * subsumption will not group them. One problem; keep the highest stage
     * reached. A patient holding stage 2 and stage 4 at once is a source-record
     * defect - see ckd_europe_v2.json, which should ConditionEnd the prior
     * stage on progression.
     */
    public const STAGED_GROUPS = [
        'CKD' => [
            '431855005' => 1.0,   // stage 1
            '431856006' => 2.0,   // stage 2
            '433144002' => 3.0,   // stage 3
            '700378005' => 3.3,   // stage 3A
            '700379008' => 3.6,   // stage 3B
            '431857002' => 4.0,   // stage 4
            '433146000' => 5.0,   // stage 5
        ],
    ];

    /**
     * Explicit grouping fallback, used when no subsumption resolver is wired.
     * member => canonical (most specific) concept.
     */
    public const GROUP_FALLBACK = [
        '39898005' => '78275009',   // sleep disorder        -> obstructive sleep apnoea
        '73430006' => '78275009',   // sleep apnea           -> obstructive sleep apnoea
        '232353008' => '446096008', // perennial w/ seasonal -> perennial allergic rhinitis
        '367498001' => '446096008', // seasonal              -> perennial allergic rhinitis
    ];

    /** clinicalStatus values that count as currently present. */
    public const ACTIVE_STATUSES = ['active', 'recurrence', 'relapse'];

    // ------------------------------------------------------------------ state

    /** @var null|callable(string,string):bool  ($ancestor,$descendant) => bool */
    private $subsumes = null;

    /** @var array<string,bool> in-process memo for subsumption answers */
    private array $subsumeMemo = [];

    private string $cacheDir;
    private bool $keepAbsenceWhenEmpty;

    public function __construct(string $cacheDir = 'cache/subsumes', bool $keepAbsenceWhenEmpty = true)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->keepAbsenceWhenEmpty = $keepAbsenceWhenEmpty;
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * Supply a subsumption test. Without one the filter falls back to
     * GROUP_FALLBACK and still functions.
     *
     * @param callable(string,string):bool $fn ($ancestorCode, $descendantCode)
     */
    public function setSubsumptionResolver(callable $fn): void
    {
        $this->subsumes = $fn;
    }

    /**
     * Ready-made resolver for a FHIR server exposing CodeSystem/$subsumes
     * (Firely Server does). Wire it with:
     *
     *   $f->setSubsumptionResolver(
     *       EpsProblemFilter::firelySubsumesResolver('https://ehds.art-decor.cloud/', $token)
     *   );
     *
     * Returns false on any transport or parse failure, so a terminology outage
     * degrades to table-only grouping rather than taking the pipeline down.
     */
    public static function firelySubsumesResolver(string $base, ?string $bearer = null): callable
    {
        $base = rtrim($base, '/');
        return static function (string $ancestor, string $descendant) use ($base, $bearer): bool {
            $url = $base . '/CodeSystem/$subsumes'
                 . '?system=' . rawurlencode('http://snomed.info/sct')
                 . '&codeA=' . rawurlencode($ancestor)
                 . '&codeB=' . rawurlencode($descendant);
            $headers = ['Accept: application/fhir+json'];
            if ($bearer !== null && $bearer !== '') {
                $headers[] = 'Authorization: Bearer ' . $bearer;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $body = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);
            if ($body === false || $http < 200 || $http >= 300) {
                return false;
            }
            $d = json_decode((string)$body, true);
            foreach ($d['parameter'] ?? [] as $p) {
                if (($p['name'] ?? '') === 'outcome') {
                    // 'subsumes' => codeA subsumes codeB, i.e. B is more specific
                    return ($p['valueCode'] ?? '') === 'subsumes';
                }
            }
            return false;
        };
    }

    // ------------------------------------------------------------- public API

    /**
     * Run suppression then consolidation over one patient's conditions.
     *
     * @param array<int,array<string,mixed>> $conditions
     * @return array{problems:array<int,array<string,mixed>>,suppressed:array<int,array<string,mixed>>,stats:array<string,int>}
     */
    public function process(array $conditions): array
    {
        [$kept, $suppressed] = $this->suppress($conditions);
        $problems = $this->consolidate($kept);

        // An absence assertion is legitimate only when nothing else survived.
        if ($this->keepAbsenceWhenEmpty && $problems === []) {
            foreach ($suppressed as $i => $s) {
                if (isset(self::ABSENCE_ASSERTION[(string)($s['code'] ?? '')])) {
                    unset($s['suppressed_reason']);
                    $s['episodes'] = 1;
                    $problems[] = $s;
                    unset($suppressed[$i]);
                    break;
                }
            }
            $suppressed = array_values($suppressed);
        }

        return [
            'problems'   => $problems,
            'suppressed' => $suppressed,
            'stats'      => [
                'in'         => count($conditions),
                'suppressed' => count($suppressed),
                'kept'       => count($kept),
                'problems'   => count($problems),
            ],
        ];
    }

    /**
     * Rules 1-3. Active always survives. Everything else is judged on category.
     *
     * @param array<int,array<string,mixed>> $conditions
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    public function suppress(array $conditions): array
    {
        $kept = [];
        $dropped = [];

        foreach ($conditions as $c) {
            $code   = (string)($c['code'] ?? '');
            $status = strtolower((string)($c['clinicalStatus'] ?? ''));
            $active = in_array($status, self::ACTIVE_STATUSES, true);

            // Absence assertions are wrong whenever anything else is present.
            // Held back here and reinstated by process() only if nothing else survives.
            if (isset(self::ABSENCE_ASSERTION[$code])) {
                $c['suppressed_reason'] = 'absence assertion alongside real problems';
                $dropped[] = $c;
                continue;
            }

            if ($active) {
                $kept[] = $c;
                continue;
            }

            // Resolved but significant -> restate as history rather than drop.
            if (isset(self::HISTORY_OF[$code])) {
                [$hCode, $hDisplay] = self::HISTORY_OF[$code];
                if ($hCode !== '') {
                    $c['code']              = $hCode;
                    $c['display']           = $hDisplay;
                    $c['converted_from']    = $code;
                }
                $c['clinicalStatus'] = 'resolved';
                $kept[] = $c;
                continue;
            }

            $reason = match (true) {
                isset(self::SELF_LIMITING_ACUTE[$code])  => 'resolved self-limiting acute illness',
                isset(self::FINDING_NOT_PROBLEM[$code])  => 'finding secondary to an episode, not a problem',
                isset(self::SURGICAL_EPISODE[$code])     => 'surgical episode, belongs in Procedures',
                isset(self::OBSTETRIC_EVENT[$code])      => 'obstetric event, belongs in pregnancy history',
                isset(self::SUPERSEDED_BY[$code])        => 'superseded by chronic concept '
                                                             . self::SUPERSEDED_BY[$code],
                default => null,
            };

            if ($reason !== null) {
                $c['suppressed_reason'] = $reason;
                $dropped[] = $c;
            } else {
                // Resolved, not on any list: keep it, marked resolved.
                $c['clinicalStatus'] = 'resolved';
                $kept[] = $c;
            }
        }

        return [$kept, $dropped];
    }

    /**
     * Rule 4. Collapse episodes of the same problem into one entry.
     *
     * @param array<int,array<string,mixed>> $conditions
     * @return array<int,array<string,mixed>>
     */
    public function consolidate(array $conditions): array
    {
        /*
         * Group keys are namespaced ('code:59621000', not '59621000') because
         * PHP silently casts integer-like string array keys to int. A bare
         * SNOMED code as a key comes back out of foreach as an int, and every
         * downstream string operation on it then fails. The prefix keeps the
         * key a genuine string.
         *
         * @var array<string,array<int,array<string,mixed>>> $groups
         */
        $groups = [];
        foreach ($conditions as $c) {
            $key = $this->groupKey((string)($c['code'] ?? ''), $conditions);
            $groups[$key][] = $c;
        }

        $out = [];
        foreach ($groups as $key => $members) {
            $out[] = $this->mergeGroup((string)$key, $members);
        }

        // Stable, clinically sensible ordering: active first, then by onset.
        usort($out, static function (array $a, array $b): int {
            $aa = in_array(strtolower((string)$a['clinicalStatus']), self::ACTIVE_STATUSES, true) ? 0 : 1;
            $bb = in_array(strtolower((string)$b['clinicalStatus']), self::ACTIVE_STATUSES, true) ? 0 : 1;
            return $aa <=> $bb ?: strcmp((string)($a['onset'] ?? ''), (string)($b['onset'] ?? ''));
        });

        return $out;
    }

    // ---------------------------------------------------------------- internals

    /**
     * Identity of the problem a code belongs to.
     * Order matters: staged groups, then explicit supersession, then
     * subsumption against codes actually present, then the fallback table.
     *
     * Returns 'staged:<name>' or 'code:<sctid>'. The 'code:' prefix is not
     * decorative - see the note in consolidate() about PHP casting
     * integer-like array keys to int.
     *
     * @param array<int,array<string,mixed>> $peers
     */
    private function groupKey(string $code, array $peers): string
    {
        foreach (self::STAGED_GROUPS as $name => $members) {
            if (isset($members[$code])) {
                return 'staged:' . $name;
            }
        }

        if (isset(self::SUPERSEDED_BY[$code])) {
            return 'code:' . self::SUPERSEDED_BY[$code];
        }

        if ($this->subsumes !== null) {
            foreach ($peers as $p) {
                $other = (string)($p['code'] ?? '');
                if ($other === '' || $other === $code) {
                    continue;
                }
                // $code is more specific than $other -> group under $code.
                if ($this->testSubsumes($other, $code)) {
                    return 'code:' . $code;
                }
                // $other is more specific -> group under $other.
                if ($this->testSubsumes($code, $other)) {
                    return 'code:' . $other;
                }
            }
        }

        return 'code:' . (self::GROUP_FALLBACK[$code] ?? $code);
    }

    /** Cached subsumption test; failures are cached as false to avoid retry storms. */
    private function testSubsumes(string $ancestor, string $descendant): bool
    {
        $k = $ancestor . '<' . $descendant;
        if (isset($this->subsumeMemo[$k])) {
            return $this->subsumeMemo[$k];
        }

        $file = $this->cacheDir . '/' . sha1($k) . '.json';
        if (is_file($file)) {
            $v = json_decode((string)file_get_contents($file), true);
            if (is_array($v) && array_key_exists('r', $v)) {
                return $this->subsumeMemo[$k] = (bool)$v['r'];
            }
        }

        $r = false;
        try {
            $r = (bool)call_user_func($this->subsumes, $ancestor, $descendant);
        } catch (\Throwable) {
            $r = false;
        }

        @file_put_contents($file, json_encode(['r' => $r, 'a' => $ancestor, 'd' => $descendant]));
        return $this->subsumeMemo[$k] = $r;
    }

    /**
     * Merge one group into a single problem entry.
     *
     * @param array<int,array<string,mixed>> $members
     * @return array<string,mixed>
     */
    private function mergeGroup(string $key, array $members): array
    {
        $anyActive = false;
        $anyResolved = false;
        foreach ($members as $m) {
            if (in_array(strtolower((string)($m['clinicalStatus'] ?? '')), self::ACTIVE_STATUSES, true)) {
                $anyActive = true;
            } else {
                $anyResolved = true;
            }
        }

        $rep = $this->representative($key, $members);

        $onsets = array_values(array_filter(array_map(
            static fn(array $m): string => (string)($m['onset'] ?? ''), $members
        )));
        $abates = array_values(array_filter(array_map(
            static fn(array $m): string => (string)($m['abatement'] ?? ''), $members
        )));
        sort($onsets);
        sort($abates);

        $rep['episodes'] = count($members);

        // FHIR semantics: 'recurrence' is present now, having previously
        // RESOLVED. Several members is not sufficient on its own - overlapping
        // concepts for one continuously present problem (sleep disorder /
        // sleep apnea / OSA) and staged progression (CKD 2 -> CKD 4) both
        // produce multi-member groups in which nothing ever remitted. Those
        // are plain 'active'. Recurrence requires at least one resolved member
        // alongside an active one.
        $rep['clinicalStatus'] = match (true) {
            $anyActive && $anyResolved => 'recurrence',
            $anyActive                 => 'active',
            default                    => 'resolved',
        };

        if ($onsets !== []) {
            $rep['onset'] = $onsets[0];                    // earliest
        }
        if (!$anyActive && $abates !== []) {
            $rep['abatement'] = $abates[count($abates) - 1];  // latest
        } else {
            unset($rep['abatement']);
        }

        return $rep;
    }

    /**
     * Pick the record that should represent the group.
     * Staged groups take the highest stage reached; otherwise the most recent
     * onset wins, which for a subsumption group is the most specific statement
     * the record actually made.
     *
     * @param array<int,array<string,mixed>> $members
     * @return array<string,mixed>
     */
    private function representative(string $key, array $members): array
    {
        if (str_starts_with($key, 'staged:')) {
            $name = substr($key, 7);
            $rank = self::STAGED_GROUPS[$name] ?? [];
            usort($members, static function (array $a, array $b) use ($rank): int {
                $ra = $rank[(string)($a['code'] ?? '')] ?? -1;
                $rb = $rank[(string)($b['code'] ?? '')] ?? -1;
                return $rb <=> $ra;
            });
            return $members[0];
        }

        // Keys arrive namespaced ('code:59621000'); strip the prefix before
        // comparing against the raw code on each member.
        $wanted = str_starts_with($key, 'code:') ? substr($key, 5) : $key;
        foreach ($members as $m) {
            if ((string)($m['code'] ?? '') === $wanted) {
                return $m;
            }
        }

        usort($members, static fn(array $a, array $b): int =>
            strcmp((string)($b['onset'] ?? ''), (string)($a['onset'] ?? '')));
        return $members[0];
    }
}
