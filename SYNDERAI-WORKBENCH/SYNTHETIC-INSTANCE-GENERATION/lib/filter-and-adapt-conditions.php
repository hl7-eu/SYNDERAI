<?php 
/*
* Condition summarisation for the patient summary.
*
* Synthea writes one condition entry per clinical episode, which is correct for
* a longitudinal record. A patient summary needs one entry per problem carrying
* its current status. This call performs that reduction.
*
* Two passes run in sequence, and are kept separate on purpose:
*
* 1. Suppression. Removes what does not belong in a summary: resolved acute
* illness, symptoms and signs, administrative items. Conditions that
* resolved but still govern treatment are restated as history concepts
* rather than dropped, so a resolved stroke becomes a recorded history of
* cerebrovascular accident. Allergies and pregnancy status are routed to
* their own sections.
*
* 2. Consolidation. Merges episodes of one problem into a single entry with
* the earliest onset, the current status and an episode count. Overlapping
* concepts collapse to the most specific one asserted. Staged conditions
* keep the highest stage reached together with the earliest onset.
*
* Merging the two passes would let several episodes of an acute infection be
* consolidated into a recurrent problem no clinician ever asserted. Suppression
* removes those episodes before consolidation can see them.
*
* The return value has the same shape as the input and substitutes directly for
* $conditions downstream ($pdat->conditions). Two caveats. The 'start', 'end' and 
* 'active' fields are recomputed rather than copied, because consolidation moves onset
* and abatement across a group. Three keys are added: clinicalStatus, episodes,
* and for a restated concept convertedFrom. These are needed because a
* condition present now that previously resolved has an empty 'end', which is
* indistinguishable from one that never resolved. Construct the adapter with
* annotate: false for a strictly identical shape, at the cost of that
* distinction.
*
* $adapter->lastReport() returns what was removed and why. Each dropped record
* carries a summaryReason field, so any decision can be traced without
* re-running the filter.
*
* Typical reduction is from around sixteen entries per patient to around four.
*
* @see lib/eps-condition-adapter.php
* @see https://synderai.net/index.php?menu=epsca
*/

/* EPS condition consolidation functions */
require_once("lib/eps-condition-adapter.php");
use Synderai\epsConditionAdapter;

function filterAndAdaptConditions($conditions) {

    $adapter = new epsConditionAdapter();
    $filteredConditions = $adapter->summarise($conditions);

    // =====================================================================
    // Output
    // =====================================================================
    $report = $adapter->lastReport();

    lognl(2, "......... Results of the summary condition adapter run");
    lognl(2,
        sprintf(
            "............ %d source condition entries -> %d problems, %d routed, %d dropped",
            $report['in'],
            $report['stats']['problems'],
            $report['stats']['routed'],
            $report['stats']['dropped']
        )
    );

    lognl(3, "............ Problem List");
    lognl(3, sprintf(
        "............... %-12s %-12s %-4s %-12s %-12s %s\n",
        'CODE',
        'STATUS',
        'EP',
        'ONSET',
        'ABATED',
        'DISPLAY'
    ));

    foreach ($filteredConditions as $p) {
        lognl(
            3,
            sprintf
            (
                "............... %-12s %-12s %-4d %-12s %-12s %s\n",
                $p['code']['code'],
                $p['clinicalStatus'],
                $p['episodes'],
                $p['start'],
                $p['end'] !== '' ? $p['end'] : '-',
                $p['code']['display']
            )
        );
    }

    lognl(3, "............ Dropped");
    $byCode = [];
    foreach ($report['dropped'] as $d) {
        $byCode[$d['code']['code']]['n'] = ($byCode[$d['code']['code']]['n'] ?? 0) + 1;
        $byCode[$d['code']['code']]['display'] = $d['code']['display'];
        $byCode[$d['code']['code']]['reason'] = $d['summaryReason'] ?? '';
    }
    foreach ($byCode as $code => $info) {
        lognl(
            3,
            sprintf
            (
                "............... %dx %-12s %-38s %s\n",
                $info['n'],
                $code,
                substr($info['display'], 0, 36),
                $info['reason']
            )
        );
    }

    // ---------------------------------------------------------------------
    // Diagnostic on the 'active' field. Not part of the pipeline.
    // ---------------------------------------------------------------------

    $check = epsConditionAdapter::inspectActiveField($conditions);
    lognl(3, "............ 'active' Field Check");
    lognl(3, sprintf
    (
        "............... every value is flag digit + start date, flag agreeing with 'end': %s\n",
        $check['consistent'] ? 'yes' : 'NO - see rows below'
    ));
    if (!$check['consistent']) {
        foreach ($check['rows'] as $r) {
            if (!$r['agrees']) {
                lognl(
                    3,
                    sprintf
                    (
                        "............... index %d code %s active=%s start=%s end=%s\n",
                        $r['index'],
                        $r['code'],
                        $r['active'],
                        $r['start'],
                        $r['end'] ?: '(none)'
                    )
                );
            }
        }
    }
    lognl(3, "............... The field carries nothing 'end' does not. The adapter derives status from 'end' and ignores
    'active'.");

    return $filteredConditions;
}