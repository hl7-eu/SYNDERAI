<?php

/**
 * Shared guards for vital sign entries.
 *
 * Included by every section runner that renders the `vitalsigns` template.
 * There is more than one: sections/vitalsign.php serves the EPS path and
 * sections/hdr.php serves the HDR path. A guard added to only one of them
 * leaves the other unprotected — which is exactly what happened between run 6
 * and run 7, where the fix was applied to the EPS runner while the errors came
 * from the HDR one.
 */

if (!function_exists('vitalHasCode')) {
  /**
   * Does this vital sign carry a usable observation code?
   *
   * Observation.code is 1..1. When the LOINC code is missing the template
   * renders `* code = # ""`, which the FSH parser cannot read and which then
   * takes the whole Observation down with it — five errors per occurrence.
   *
   * Accepts both shapes the pipeline uses: an associative array from the
   * getters, and the stdClass an entry has after json_decode(json_encode()).
   * `code` may itself be a list of codings or a single coding.
   *
   * @param  array|object $sdata  one vital sign entry
   * @return bool                 true when a non-empty code is present
   */
  function vitalHasCode($sdata) {
    $c = is_array($sdata) ? ($sdata["code"] ?? NULL) : ($sdata->code ?? NULL);
    if (is_array($c)) $c = $c[0] ?? NULL;
    if ($c === NULL) return FALSE;
    $code = is_array($c) ? ($c["code"] ?? "") : ($c->code ?? "");
    return trim((string) $code) !== "";
  }
}
