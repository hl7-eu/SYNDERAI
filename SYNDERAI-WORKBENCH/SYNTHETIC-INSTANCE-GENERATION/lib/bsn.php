<?php

declare(strict_types=1);

/**
 * Generieke elfproef (mod-11 test).
 *
 * @param string   $input      Reeks cijfers, lengte moet gelijk zijn aan aantal gewichten.
 * @param int[]    $weights    Gewichten per positie.
 * @param bool     $positiveSum  Indien true moet de gewogen som > 0 zijn.
 *
 * @throws InvalidArgumentException  Als lengte input niet overeenkomt met gewichten.
 */
function elfProef(string $input, array $weights, bool $positiveSum): bool
{
    if (strlen($input) !== count($weights)) {
        throw new InvalidArgumentException('Amount of weights must be equal to length of input');
    }

    $sum = 0;
    for ($i = 0, $len = strlen($input); $i < $len; $i++) {
        $digit = (int) $input[$i];
        $sum  += $digit * $weights[$i];
    }

    $validMod   = $sum % 11 === 0;
    $validRange = $positiveSum === false || $sum > 0;

    return $validMod && $validRange;
}

/**
 * Valideer een Nederlands BSN (Burgerservicenummer).
 *
 * Accepteert 8- of 9-cijferige invoer. 8-cijferige nummers worden met een
 * voorloopnul aangevuld. Nummers die met "00" beginnen zijn ongeldig.
 */
function validateBSN($input): bool
{
    $input = (string) $input;

    if (!preg_match('/^\d{8,9}$/', $input)) {
        return false;
    }

    $prepended = strlen($input) === 8 ? '0' . $input : $input;

    if (str_starts_with($prepended, '00')) {
        return false;
    }

    return elfProef($prepended, [9, 8, 7, 6, 5, 4, 3, 2, -1], false);
}

/**
 * Bereken de ontbrekende mod-11 controlecijfer (positie 9) voor een BSN-stam
 * van 8 cijfers.
 *
 * Retourneert het volledige 9-cijferige BSN, of null wanneer er geen geldig
 * enkelvoudig controlecijfer bestaat (gewogen som mod 11 == 10) of wanneer het
 * resultaat niet als BSN geldig is (bijv. begint met "00").
 *
 * @param string|int $stem  Precies 8 cijfers (de eerste 8 posities van het BSN).
 */
function completeBSN(string|int $stem): ?string
{
    $stem = (string) $stem;

    // 8-cijferige stam met een voorloopnul aanvullen tot de eerste 8 posities
    // van een 9-cijferig BSN.
    if (!preg_match('/^\d{8}$/', $stem)) {
        return null;
    }

    // Gewichten voor de eerste 8 posities.
    $weights = [9, 8, 7, 6, 5, 4, 3, 2];

    $sum = 0;
    for ($i = 0; $i < 8; $i++) {
        $sum += ((int) $stem[$i]) * $weights[$i];
    }

    // Laatste positie heeft gewicht -1:  sum - d9 ≡ 0 (mod 11)  =>  d9 = sum mod 11.
    $checkDigit = $sum % 11;

    // 10 kan niet als enkelvoudig cijfer worden weergegeven -> geen geldig BSN.
    if ($checkDigit === 10) {
        return null;
    }

    $bsn = $stem . (string) $checkDigit;

    return validateBSN($bsn) ? $bsn : null;
}

/**
 * Genereer een willekeurig geldig BSN 8 cijfers + mod-11-proef
 */
function generateBSN(): string
{
    do {
        $candidate = str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
        // echo "1 $candidate\n";
        $candidate = completeBSN($candidate);
        // echo "2 $candidate\n";
    } while (!validateBSN($candidate));

    return $candidate;
}

/**
 * Genereer een willekeurig geldig BSN, BSN 8 cijfers + mod-11-proef
 * met 4x "9" = 9999 aan het begin, dan 4 random cijfers + mod-11-proef
 */
function generateBSN9999(): string
{
    do {
        $candidate = str_pad((string) "9999" . rand(1000, 8888), 8, '0', STR_PAD_LEFT);
        // echo "3 $candidate\n";
        $candidate = completeBSN($candidate);
        // echo "4 $candidate\n";
    } while (!validateBSN($candidate));

    return $candidate;
}
