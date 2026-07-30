<?php
/**
 * epsConditionAdapter.php
 *
 * Summarises a SynderAI ISH conditions array for a patient summary, returning
 * an array of the same shape so it can be substituted directly:
 *
 *     $filteredConditions = epsConditionAdapter::summariseConditions($pdat->conditions);
 *
 * or, keeping the instance so diagnostics are available:
 *
 *     $adapter = new epsConditionAdapter();
 *     $filteredConditions = $adapter->summarise($pdat->conditions);
 *     $report = $adapter->lastReport();     // what was dropped, and why
 *
 * Runs two passes. ConditionSummaryFilter decides what belongs in a summary,
 * EpsProblemFilter decides what counts as one problem.
 *
 * ISH condition record example:
 *   [
 *     'code' => [
 *        'code'               => '59621000',
 *        'system'             => '$sct',
 *        'display'            => 'Essential hypertension (disorder)',
 *        'preferredTerm'      => 'Essential hypertension',
 *        'fullySpecifiedName' => 'Essential hypertension (disorder)',
 *     ],
 *     'start'     => '2022-08-26',
 *     'end'       => '',            // empty means still active
 *     'active'    => '12022-08-26',
 *     'encounter' => 'b42eeeda-...',
 *   ]
 *
 * SHAPE OF THE RETURN VALUE
 *   Every key present on the input is present on the output, so an existing
 *   consumer of $pdat->conditions keeps working. Two adjustments are made.
 *
 *   'start', 'end' and 'active' are RECOMPUTED, not copied. Consolidation moves
 *   onset to the earliest episode in a group and abatement to the latest, so a
 *   representative's original dates would describe the wrong episode. 'active'
 *   is regenerated in the observed format, flag digit followed by the start
 *   date, so it stays consistent with the other two.
 *
 *   A few keys are ADDED: clinicalStatus, episodes, and for a restated concept
 *   convertedFrom. These carry information the ISH shape cannot express. In
 *   particular 'recurrence' - present now, having previously resolved - is
 *   indistinguishable from plain 'active' when only 'end' is available.
 *   Existing consumers ignore the extra keys, and the EPS renderer needs
 *   clinicalStatus to populate Condition.clinicalStatus correctly.
 *
 *   Construct with annotate: false for a strictly identical shape. Recurrence
 *   then collapses to active.
 *
 * ON 'active'
 *   Every observed value is a single flag digit concatenated with the start
 *   date: '1' . '2022-08-26' open, '0' . '2022-04-12' closed. The flag always
 *   agrees with whether 'end' is empty, so the field carries no information
 *   'end' does not. Status is derived from 'end'; 'active' is regenerated only
 *   for output. inspectActiveField() reports any record where the two disagree.
 *
 * PHP 8.1+.
 */

declare(strict_types=1);

namespace SynderAI;

require_once __DIR__ . '/condition-summary-filter.php';
require_once __DIR__ . '/eps-problem-filter.php';

final class epsConditionAdapter
{
    private ConditionSummaryFilter $classifier;
    private EpsProblemFilter $consolidator;
    private bool $annotate;

    /** @var array<string,mixed> */
    private array $report = [];

    public function __construct(
        ?ConditionSummaryFilter $classifier = null,
        ?EpsProblemFilter $consolidator = null,
        bool $annotate = true
    ) {
        $this->classifier   = $classifier   ?? new ConditionSummaryFilter();
        $this->consolidator = $consolidator ?? new EpsProblemFilter();
        $this->annotate     = $annotate;
    }

    /**
     * Summarise one patient's conditions.
     *
     * @param array<int,array<string,mixed>> $ishConditions
     * @return list<array<string,mixed>> same shape as the input
     */
    public function summarise(array $ishConditions): array
    {
        $flat = array_map([$this, 'toFilterShape'], array_values($ishConditions));

        $classified = $this->classifier->filter($flat);
        $problems   = $this->consolidator->consolidate($classified['problems']);
        $problems   = $this->reinstateAbsenceIfEmpty($problems, $classified['dropped']);

        $this->report = [
            'in'      => count($ishConditions),
            'out'     => count($problems),
            'routed'  => array_map(
                fn(array $items): array => array_map([$this, 'toIshShape'], $items),
                $classified['routed']
            ),
            'dropped' => array_map([$this, 'toIshShape'], $classified['dropped']),
            'stats'   => [
                'problems' => count($problems),
                'routed'   => array_sum(array_map('count', $classified['routed'])),
                'dropped'  => count($classified['dropped']),
            ],
        ];

        return array_map([$this, 'toIshShape'], $problems);
    }

    /**
     * One-line form for the pipeline.
     *
     *     $filteredConditions = epsConditionAdapter::summariseConditions($pdat->conditions);
     *
     * @param array<int,array<string,mixed>> $ishConditions
     * @return list<array<string,mixed>>
     */
    public static function summariseConditions(array $ishConditions, bool $annotate = true): array
    {
        return (new self(annotate: $annotate))->summarise($ishConditions);
    }

    /**
     * What the last call to summarise() removed, and why. Conditions routed to
     * other sections sit under 'routed' keyed by verdict; each dropped record
     * carries summaryReason.
     *
     * @return array<string,mixed>
     */
    public function lastReport(): array
    {
        return $this->report;
    }

    // ------------------------------------------------------------- mapping

    /**
     * ISH -> filter shape. The untouched original rides along under _ish so
     * nothing is lost, including encounter and terminology metadata.
     *
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    public function toFilterShape(array $c): array
    {
        $end = trim((string)($c['end'] ?? ''));

        return [
            'code'           => (string)($c['code']['code'] ?? ''),
            'display'        => (string)($c['code']['display']
                                         ?? $c['code']['fullySpecifiedName'] ?? ''),
            'clinicalStatus' => $end === '' ? 'active' : 'resolved',
            'onset'          => (string)($c['start'] ?? ''),
            'abatement'      => $end,
            '_ish'           => $c,
        ];
    }

    /**
     * Filter shape -> ISH, with recomputed dates.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public function toIshShape(array $p): array
    {
        $ish = is_array($p['_ish'] ?? null) ? $p['_ish'] : [];

        $status = strtolower((string)($p['clinicalStatus'] ?? ''));
        $open   = in_array($status, EpsProblemFilter::ACTIVE_STATUSES, true);

        $start = (string)($p['onset'] ?? ($ish['start'] ?? ''));
        $end   = $open ? '' : (string)($p['abatement'] ?? ($ish['end'] ?? ''));

        $ish['start'] = $start;
        $ish['end']   = $end;

        // Regenerated, not copied: after consolidation the representative's
        // original value encodes the wrong episode's start date.
        if (array_key_exists('active', $ish)) {
            $ish['active'] = ($end === '' ? '1' : '0') . $start;
        }

        // Restatement replaces the concept, so the source terminology metadata
        // no longer describes it and is dropped rather than left to contradict.
        if (isset($p['converted_from'])) {
            $ish['code'] = [
                'code'    => (string)$p['code'],
                'system'  => (string)($ish['code']['system'] ?? '$sct'),
                'display' => (string)$p['display'],
            ];
            if ($this->annotate) {
                $ish['convertedFrom'] = (string)$p['converted_from'];
            }
        }

        if ($this->annotate) {
            $ish['clinicalStatus'] = $status;
            $ish['episodes']       = (int)($p['episodes'] ?? 1);
            if (isset($p['reason'])) {
                $ish['summaryReason'] = (string)$p['reason'];
            }
        }

        return $ish;
    }

    // ------------------------------------------------------------- helpers

    /**
     * "No current problems" is contradictory beside real problems and correct
     * when there are none. The classifier drops it unconditionally; this puts
     * it back in the one case where it belongs.
     *
     * @param list<array<string,mixed>> $problems
     * @param list<array<string,mixed>> $dropped
     * @return list<array<string,mixed>>
     */
    private function reinstateAbsenceIfEmpty(array $problems, array $dropped): array
    {
        if ($problems !== []) {
            return $problems;
        }
        foreach ($dropped as $d) {
            if ((string)($d['code'] ?? '') === '160245001') {
                $d['clinicalStatus'] = 'active';
                $d['abatement']      = '';
                $d['episodes']       = 1;
                unset($d['verdict'], $d['reason']);
                return [$d];
            }
        }
        return $problems;
    }

    /**
     * Diagnostic for the 'active' field. One row per condition showing whether
     * the flag digit agrees with 'end', so the ISH builder can be checked
     * without guessing.
     *
     * @param array<int,array<string,mixed>> $ishConditions
     * @return array{consistent:bool,rows:list<array<string,mixed>>}
     */
    public static function inspectActiveField(array $ishConditions): array
    {
        $rows = [];
        $consistent = true;
        foreach (array_values($ishConditions) as $i => $c) {
            $active = (string)($c['active'] ?? '');
            $start  = (string)($c['start'] ?? '');
            $end    = trim((string)($c['end'] ?? ''));
            $flag   = $active === '' ? '' : $active[0];
            $tail   = substr($active, 1);
            $agrees = ($flag === ($end === '' ? '1' : '0')) && ($tail === $start);
            if (!$agrees) {
                $consistent = false;
            }
            $rows[] = [
                'index'  => $i,
                'code'   => (string)($c['code']['code'] ?? ''),
                'active' => $active,
                'flag'   => $flag,
                'tail'   => $tail,
                'start'  => $start,
                'end'    => $end,
                'agrees' => $agrees,
            ];
        }
        return ['consistent' => $consistent, 'rows' => $rows];
    }
}
