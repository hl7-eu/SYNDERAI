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