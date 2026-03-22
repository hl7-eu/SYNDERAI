<?php

/**
 * European Citizen Identifier (ECI) Generator
 *
 * Generates and validates fictional European Citizen Identifier numbers.
 * An ECI is a 10-digit number formatted as:
 *
 *   XXXX-XXXXXX-C
 *
 * where:
 *   XXXX     — first 4 digits of a randomly generated base number
 *   XXXXXX   — remaining 6 digits of the base number
 *   C        — a single Luhn check digit (mod-10 checksum)
 *
 * The full identifier (stripped of dashes) must satisfy the Luhn algorithm.
 *
 * @see http://en.wikipedia.org/wiki/Luhn_algorithm
 */


/**
 * Generate a new European Citizen Identifier (ECI).
 *
 * Internally this function:
 *   1. Generates a random 10-digit base number via {@see generateId()}.
 *   2. Computes a Luhn check digit for that base number via {@see luhn_checksum()}.
 *   3. Formats the result as "XXXX-XXXXXX-C".
 *   4. Validates the formatted string with {@see is_valid_luhn()} and emits a
 *      warning if the checksum does not verify (should never happen under
 *      normal circumstances).
 *
 * @return string  A valid ECI string in the format "XXXX-XXXXXX-C".
 */
function generateECI() {
    $i = generateId(10);                                        // 10-digit random base
    $j = luhn_checksum($i);                                     // Luhn check digit
    $k = substr($i, 0, 4) . "-" . substr($i, 4, 6) . "-" . $j; // formatted ECI

    if (!is_valid_luhn($k)) {
        echo "+++ Invalid LUHN Checksum, please check, son!";
    }

    return $k;
}



/**
 * Calculate the Luhn (mod-10) check digit for a numeric string.
 *
 * The algorithm works as follows:
 *   - Non-digit characters are stripped from the input.
 *   - Starting from the rightmost digit, every second digit is doubled;
 *     if doubling produces a two-digit number its digits are summed.
 *   - All resulting values are summed.
 *   - The check digit is (10 − (sum mod 10)) mod 10, i.e. the value that
 *     brings the total sum to the next multiple of 10.
 *
 * @see http://en.wikipedia.org/wiki/Luhn_algorithm
 *
 * @param  string $number  The numeric string for which to compute the check digit.
 *                         Any non-digit characters are silently ignored.
 *
 * @return int             The single check digit (0–9).
 */
function luhn_checksum($number) {
    // Strip everything that is not a digit
    $number = preg_replace("/[^0-9]/", "", $number);

    $sum = 0;
    foreach (str_split(strrev($number)) as $i => $digit) {
        // Even positions (0-indexed from the right): double the digit and sum its digits
        // Odd positions: add the digit as-is
        $sum += ($i % 2 == 0) ? array_sum(str_split($digit * 2)) : $digit;
    }

    return (10 - ($sum % 10)) % 10;
}


/**
 * Validate whether a number satisfies the Luhn (mod-10) algorithm.
 *
 * The entire number, including its trailing check digit, is evaluated.
 * A number is valid when the grand total of all transformed digits is
 * divisible by 10.
 *
 * Non-digit characters (such as the dashes in a formatted ECI) are stripped
 * before validation, so both "4111111111111111" and "4111-1111-1111-1111"
 * are accepted as input.
 *
 * @see http://en.wikipedia.org/wiki/Luhn_algorithm
 *
 * @param  string $number  The full numeric string to validate, including its
 *                         check digit. Non-digit characters are silently ignored.
 *
 * @return bool            TRUE if the number passes the Luhn check, FALSE otherwise.
 */
function is_valid_luhn($number) {
    // Strip everything that is not a digit (e.g. dashes in a formatted ECI)
    $number = preg_replace("/[^0-9]/", "", $number);

    $card_number_checksum = '';

    foreach (str_split(strrev((string) $number)) as $i => $d) {
        // Odd positions (1-indexed from the right): double the digit
        // Even positions: keep as-is
        $card_number_checksum .= $i % 2 !== 0 ? $d * 2 : $d;
    }

    // A valid Luhn number produces a digit sum that is a multiple of 10
    return array_sum(str_split($card_number_checksum)) % 10 === 0;
}


/**
 * Generate a random numeric string of a given length.
 *
 * The first digit is always in the range 1–9 (no leading zero), and every
 * subsequent digit is in the range 0–9. This mirrors the convention used
 * for account or ID numbers that must not start with zero.
 *
 * @param  int         $digits  Total number of digits to generate. Must be
 *                              between 2 and 12 (inclusive); values outside
 *                              this range cause the function to return NULL.
 *
 * @return string|null          The generated numeric string, or NULL if
 *                              $digits is out of the allowed range.
 */
function generateId($digits = 4) {
    if ($digits < 2 or $digits > 12) {
        return null;
    }

    $pin  = "";
    $pin .= mt_rand(1, 9);  // First digit: 1–9 (no leading zero)

    $i = 0; // Counter for remaining digits
    while ($i < $digits - 1) {
        $pin .= mt_rand(0, 9);
        $i++;
    }

    return $pin;
}

/**
 * Derives a $digits-length numeric string from a given MD5 hex string.
 * Strips non-numeric characters from the hash and pads with a secondary
 * MD5 pass if the hash yields insufficient digits.
 */
function generateIdMd5(string $md5, int $digits = 4): ?string
{
    if ($digits < 2 || $digits > 12) {
        return null;
    }

    // Extract only numeric characters from the MD5 hex string
    $numeric = preg_replace('/[^0-9]/', '', $md5);

    // If the MD5 doesn't yield enough digits, chain a second MD5 pass
    while (strlen($numeric) < $digits) {
        $numeric .= preg_replace('/[^0-9]/', '', md5($numeric));
    }

    // Ensure the first digit is 1–9 (no leading zero), same rule as generateId()
    $first = $numeric[0] === '0' ? '1' : $numeric[0];
    $pin   = $first . substr($numeric, 1, $digits - 1);

    return $pin;
}

/**
 * Generates a European Citizen Identifier (ECI) using an MD5 hash
 * as the entropy source instead of mt_rand().
 *
 * @param  string|null  $md5  Optional pre-computed MD5 hex string (32 chars).
 *                            If omitted, one is generated from a random seed.
 * @return string             Formatted ECI: XXXX-XXXXXX-C
 */
function generateECImd5(?string $md5 = null): string
{
    // Allow callers to supply their own MD5 (e.g. from a document hash,
    // patient seed, or external source); otherwise generate one fresh.
    if ($md5 === null || !preg_match('/^[a-f0-9]{32}$/i', $md5)) {
        $md5 = md5(uniqid('', true));
    }

    $i = generateIdMd5($md5, 10);               // 10-digit MD5-derived base
    $j = luhn_checksum($i);                     // Luhn check digit
    $k = substr($i, 0, 4) . "-"
       . substr($i, 4, 6) . "-" . $j;          // formatted ECI

    if (!is_valid_luhn($k)) {
        echo "+++ Invalid LUHN Checksum, please check, son!";
    }

    return $k;
}