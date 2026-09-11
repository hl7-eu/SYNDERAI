<?php

/**
 * return the report date
 * 
 * Use the most recent date of lab observations,
 * conditions, procedures or immunizations (if present)
 * as the composition date, today otherwise
 */

function evaluateReportDate($pdat) {

    $maxdates = array();
    if (isset($pdat->labobservations) && $pdat->labobservations !== NULL) {
        $maxdates[] = max(array_keys($pdat->labobservations));
    }
    if (isset($pdat->conditions) && $pdat->conditions !== NULL && count($pdat->conditions) > 0) {
        $maxdates[] = max(array_merge(
            array_column($pdat->conditions, 'start'),
            array_column($pdat->conditions, 'end')
        ));
    }
    if (isset($pdat->procedures) && $pdat->procedures !== NULL) {
        $maxdates[] = max(array_column($pdat->procedures, 'date'));
    }
    if (isset($pdat->medications) && $pdat->medications !== NULL) {
        $maxdates[] = max(array_merge(
            array_column($pdat->medications, 'start'),
            array_column($pdat->medications, 'end')
        ));
    }
    if (isset($pdat->immunizations) && $pdat->immunizations !== NULL) {
        $maxdates[] = max(array_column($pdat->immunizations, 'date'));
    }
    if (count($maxdates) === 0)
        return date('Y-m-d\TH:i:s\Z');
    else
        return max($maxdates);
}