<?php

/**
 * Cache Helper Functions
 *
 * Provides a simple file-based caching mechanism. Cached items are stored
 * as flat files organised into subdirectories by type, under the top-level
 * "cache/" directory.
 *
 * Directory structure:
 *   cache/
 *     <type>/
 *       <key>   ← raw file content is the cached value
 */


/*
 * Generation of the AI-derived laboratory reference ranges in
 * cache/referencerange/. See refRangeCacheKey() at the end of this file for
 * why a generation number is needed and when to bump it.
 *
 * v1 — the original keys, md5($age.$gender.$age.$gender.$display.$unit),
 *      answers from a prompt that was shown the measured value and chose the
 *      unit itself.
 * v2 — 2026-10-11, prompt without the measured value, unit prescribed, age
 *      banded, "type" requested. Retires the 4066 v1 entries.
 */
if (!defined("REFRANGE_CACHE_VERSION")) define("REFRANGE_CACHE_VERSION", 2);


/**
 * Age band for a laboratory reference range.
 *
 * A reference interval does not change from one birthday to the next; it
 * changes at the boundaries where laboratory medicine says it changes. Keying
 * the cache on the exact age meant one model call per year of life per test
 * and one answer per year of life, which is where the six different
 * thyrotropin intervals and the six different eGFR intervals in release 5 came
 * from. The bands below are the coarse ones that paediatric and adult
 * reference tables agree on; they are deliberately few, because every extra
 * band is another independent answer to the same question.
 *
 * The label is written to be dropped into a prompt as "... for a <label>
 * female patient", so it reads as an age description, not as a range notation.
 *
 * @param  int    $age  Patient age in years.
 * @return string       Band label, stable for the lifetime of a cache generation.
 */
function refRangeAgeBand($age) {
    $age = (int) $age;
    if ($age < 1)  return "newborn";
    if ($age < 2)  return "one-year-old";
    if ($age < 6)  return "2 to 5 year old";
    if ($age < 12) return "6 to 11 year old";
    if ($age < 18) return "12 to 17 year old";
    if ($age < 50) return "18 to 49 year old";
    if ($age < 65) return "50 to 64 year old";
    return "65 years or older";
}


/**
 * Retrieve an item from the file cache.
 *
 * Looks for a file at "cache/<type>/<key>". If the file exists its entire
 * contents are returned as a string; otherwise FALSE is returned so that
 * callers can distinguish a cache miss from an empty cached value.
 *
 * @param  string       $type  Cache namespace / subdirectory (e.g. "snomed", "dosage").
 * @param  string       $key   Unique identifier for the cached item (used as the filename).
 *
 * @return string|false        The cached content on a hit, or FALSE on a miss.
 */
function inCACHE($type, $key) {
    $cachedir = "cache/$type";

    if (is_file("$cachedir/$key")) {
        return file_get_contents("$cachedir/$key");
    } else {
        return FALSE;
    }
}


/**
 * Store an item in the file cache.
 *
 * Writes $content to "cache/<type>/<key>". If the target directory does not
 * exist yet, a notice is printed and nothing is written — the caller is
 * responsible for creating the directory beforehand (or the notice serves as
 * a prompt to do so manually).
 *
 * @param  string $type     Cache namespace / subdirectory (e.g. "html", "api").
 * @param  string $key      Unique identifier for the cached item (used as the filename).
 * @param  string $content  The data to cache.
 *
 * @return void
 */
function toCACHE($type, $key, $content) {
    $cachedir = "cache/$type";

    if (!is_dir($cachedir)) {
        lognlsev(1, ERROR, "+++ please create cache dir $cachedir in order to use the cache");
    } else {
        file_put_contents("$cachedir/$key", $content);
    }
}

/**
 * Cache key for an AI-derived laboratory reference range.
 *
 * Why this exists as a function rather than an md5() at each call site:
 *
 * 1. The key used to be built twice, in sections/labobservation.php and in
 *    sections/recentlabobservationEPS.php, as
 *      md5($age . $gender . ($age . $gender . $display . $unit))
 *    i.e. with age and gender folded in twice. Both call sites had to agree
 *    character for character or the LAB and the EPS branch would each ask the
 *    model separately for the same range and cache two answers for it.
 *
 * 2. The key carried no record of the question that produced the answer. The
 *    prompt in lib/ai-utils.php getAIReferenceRange(), the model behind it and
 *    the post-processing in the two sections are all part of what the cached
 *    value means. Changing any of them while keeping the key leaves 4066
 *    cached files in cache/referencerange/ answering the OLD question, and the
 *    change looks like it had no effect. $version is the salt that retires
 *    them. Bump it whenever the prompt, the model or the shape of the stored
 *    JSON changes - that is cheaper and far less error-prone than asking
 *    everyone to remember to run cache/empty-cache.sh.
 *
 * 3. The LOINC code is in the key now. It used to be keyed on the display text
 *    plus the unit only, so two different codes that share a display and a
 *    unit collided, and a display-text correction in the LOINC table created a
 *    new entry for a range that had not changed.
 *
 * Note what is deliberately NOT in the key: the measured value. The reference
 * range must not depend on the result it is compared against.
 *
 * @param  string $loinc    LOINC code of the test.
 * @param  string $display  Display name as sent to the model.
 * @param  string $unit     Unit of the measured value.
 * @param  int    $age      Patient age in years.
 * @param  string $gender   Patient gender as Synthea reports it.
 * @param  int    $version  Prompt/model generation, see point 2 above.
 *
 * @return string           The cache key (a file name under cache/referencerange/).
 */
function refRangeCacheKey($loinc, $display, $unit, $age, $gender, $version = REFRANGE_CACHE_VERSION) {
    $band = refRangeAgeBand($age);
    return md5("v$version|$loinc|$display|$unit|$band|$gender");
}
