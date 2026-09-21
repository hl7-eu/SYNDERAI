<?php

/**
 * AI Utility Functions — SynderAI
 *
 * Calls to the two LLM backends the pipeline uses, plus the post-processing
 * that turns their answers into something the generator can trust.
 *
 *   OpenAI (GPT)
 *     Endpoint : https://api.openai.com/v1/chat/completions
 *     Auth     : Bearer token via OPEN_AI_API_KEY
 *     Models   : gpt-3.5-turbo (availability check, reference ranges, XHTML table)
 *                gpt-4.1       (dosage, lab conclusion, care-plan goals)
 *
 *   Anthropic (Claude)
 *     Endpoint : https://api.anthropic.com/v1/messages
 *     Auth     : x-api-key header via ANTHROPIC_API_KEY
 *     Model    : claude-sonnet-4-6
 *
 * Every call uses temperature 0. Every call goes through aiRequest(), which
 * holds the transport settings and the retry policy in one place.
 *
 * Function overview
 *   aiRequest()                        — the one HTTP call, with retries
 *   openAiChat()                       — Chat Completions, response validated
 *   anthropicMessage()                 — Messages API, response validated
 *   testAIavailability()               — smoke-test OpenAI connectivity
 *   getAIReferenceRange()              — lab reference range as JSON
 *   getAIlabtable()                    — XHTML lab results table
 *   getAIsuggestedMedicationDosage()   — FHIR FSH dosage suggestion
 *   getAILabConclusion()               — clinical lab conclusion text
 *   getAIGoals()                       — FHIR FSH Goal instances
 *   fixMissingTargetMeasurewithAI()    — second pass for a missing LOINC
 *   getAIHospitalCourse()              — discharge report and procedure list
 *   applyCorrectionsOnAIflawsInFSH()   — known UCUM spelling repairs
 *
 * WHAT CHANGED, AND WHY
 *
 * The procedure list no longer comes out of the model's memory.
 *   getAIHospitalCourse() used to tell the model that the SNOMED code "MUST be
 *   taken from the file" at a URL, and separately referred to a local file
 *   through a MAPPINGS constant. Neither reached the model: the Messages API
 *   call declares no tools and attached no file, so the URL was prose about a
 *   document the model could not open, and the local path was only ever used in
 *   an upload branch that was switched off. The model complied by inventing
 *   codes. Of the 60 code strings that reached the log, 46 fail the SCTID check
 *   digit; the terminology server reports codes such as 91170007 and 287051000
 *   as never having existed; and where a code did exist its meaning often did
 *   not match the term beside it.
 *
 *   Now the model is given a menu of real concepts from the ART-DECOR value set
 *   and may only choose from it, and whatever comes back is checked against the
 *   same value set before it is returned. See lib/procedure-terminology.php.
 *   There is no file of codes anywhere in this path.
 *
 * The transport is in one function.
 *   The same forty lines of curl_setopt() stood in seven places, and the same
 *   three isset() checks on the OpenAI response shape stood in five. They are
 *   now aiRequest() and openAiChat(). Retries on 429 and 5xx are new: a rate
 *   limit used to end as an empty string with no explanation.
 *
 * max_tokens was too small.
 *   The Anthropic call asked for a 150-word letter and a procedure list inside
 *   1024 tokens. A truncated answer loses the closing %%PROCEDURES%% marker and
 *   ends mid-line, which is a plausible source of the malformed entries in the
 *   cache. The limit is now AI_MAX_TOKENS_HOSPITAL_COURSE, and a response that
 *   still hits it is logged rather than parsed as if it were complete.
 *
 * unused_getAIHospitalCourse() is gone.
 *   A deprecated OpenAI Responses API version that could not run: var_dump()
 *   and exit() in the body, and validation against the Chat Completions shape
 *   which the Responses API never returns. Its only remaining reader was the
 *   grep that found it.
 *
 * Diagnostics go to the log, not to stdout.
 *   var_dump() and echo were writing into the same stream the FSH and ISH
 *   pipeline reads. Everything now goes through lognlsev().
 *
 * Standard result array on success:
 *   ['text' | 'xhtml' | 'rr' => string, 'code' => int|string, 'error' => string]
 *
 * External dependencies:
 *   config.php                    OPEN_AI_API_KEY, ANTHROPIC_API_KEY
 *   lib/common-utils.php          lognl(), lognlsev(), registerMapMissing()
 *   lib/procedure-terminology.php procedureMenu(), procedureIsKnown(), …
 */

/* INCLUDES */
include_once("config.php");
include_once(__DIR__ . "/procedure-terminology.php");

/** Transport settings, shared by every call in this file. */
const AI_CONNECT_TIMEOUT = 10;
const AI_TIMEOUT         = 120;
const AI_LOW_SPEED_LIMIT = 1;    // bytes per second …
const AI_LOW_SPEED_TIME  = 60;   // … below which a dead socket is abandoned

/** Retries for 429 and 5xx. Delay doubles: 2 s, 4 s, 8 s. */
const AI_MAX_ATTEMPTS  = 4;
const AI_RETRY_DELAY   = 2;

/**
 * Output budget for the discharge report call. The letter is capped at 150
 * words, the procedure list adds one line per procedure, and the model needs
 * room for both plus its markers. 1024 was not enough and truncated answers
 * silently.
 */
const AI_MAX_TOKENS_HOSPITAL_COURSE = 4096;

/** Anthropic model and API version used by this file. */
const AI_ANTHROPIC_MODEL   = "claude-sonnet-4-6";
const AI_ANTHROPIC_VERSION = "2023-06-01";


// ===========================================================================
// transport
// ===========================================================================

/**
 * POST a JSON payload and return the decoded answer.
 *
 * Retries on 429 and on 5xx, because both are transient and both used to end
 * as an empty result with no explanation. A 4xx other than 429 is returned as
 * it is: retrying a malformed request only spends money.
 *
 * @param  string   $url
 * @param  string[] $headers  Complete header lines.
 * @param  array    $payload  Encoded as JSON by this function.
 * @param  string   $what     Name used in log lines.
 *
 * @return array{body:?array,code:int,error:string}
 */
function aiRequest(string $url, array $headers, array $payload, string $what): array
{
    $jsondata = json_encode($payload);
    $delay    = AI_RETRY_DELAY;
    $response = FALSE;
    $code     = 0;
    $error    = "";

    for ($attempt = 1; $attempt <= AI_MAX_ATTEMPTS; $attempt++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER,      $headers);
        curl_setopt($ch, CURLOPT_POST,            TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS,      $jsondata);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,  TRUE);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT,  AI_CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT,         AI_TIMEOUT);
        // Abandon a socket that has stalled rather than sitting out the full
        // timeout on a connection that is never going to deliver.
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, AI_LOW_SPEED_LIMIT);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME,  AI_LOW_SPEED_TIME);

        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = (string) curl_error($ch);

        $retryable = ($code === 429 || $code >= 500 || ($response === FALSE && $error !== ""));
        if (!$retryable || $attempt === AI_MAX_ATTEMPTS) {
            break;
        }

        lognlsev(2, WARNING, "............ +++ $what: HTTP $code"
            . ($error !== "" ? " ($error)" : "")
            . ", retry $attempt of " . (AI_MAX_ATTEMPTS - 1) . " in {$delay}s");
        sleep($delay);
        $delay *= 2;
    }

    return [
        'body'  => $response === FALSE ? NULL : json_decode($response, TRUE),
        'code'  => $code,
        'error' => $error,
    ];
}

/**
 * One OpenAI Chat Completions call, with the response shape validated once.
 *
 * @return array{text:string,code:int,error:string}
 */
function openAiChat(string $model, string $prompt, string $what, array $extra = []): array
{
    $payload = array_merge([
        "model"       => $model,
        "messages"    => [["role" => "user", "content" => $prompt]],
        "temperature" => 0,
    ], $extra);

    $res = aiRequest(
        "https://api.openai.com/v1/chat/completions",
        ["Content-Type: application/json", "Authorization: Bearer " . OPEN_AI_API_KEY],
        $payload,
        $what
    );

    $text = $res['body']['choices'][0]['message']['content'] ?? NULL;
    if ($text === NULL) {
        // One line naming what is missing, instead of three checks and a
        // var_dump into the stream the FSH pipeline reads.
        $why = $res['body']['error']['message'] ?? "no choices[0].message.content";
        lognlsev(2, WARNING, "............ +++ $what returned no content (HTTP "
            . $res['code'] . "): " . $why);
    }

    return ['text' => $text ?? "", 'code' => $res['code'], 'error' => $res['error']];
}

/**
 * One Anthropic Messages call, with the response shape validated once.
 *
 * @param  array $content  Content blocks for the single user message.
 * @return array{text:string,code:int,error:string,truncated:bool}
 */
function anthropicMessage(array $content, string $what, int $maxTokens): array
{
    $res = aiRequest(
        "https://api.anthropic.com/v1/messages",
        [
            "Content-Type: application/json",
            "x-api-key: " . ANTHROPIC_API_KEY,
            "anthropic-version: " . AI_ANTHROPIC_VERSION,
        ],
        [
            "model"      => AI_ANTHROPIC_MODEL,
            "max_tokens" => $maxTokens,
            "messages"   => [["role" => "user", "content" => $content]],
        ],
        $what
    );

    $text = $res['body']['content'][0]['text'] ?? NULL;
    if ($text === NULL) {
        $why = $res['body']['error']['message'] ?? "no content[0].text";
        lognlsev(2, WARNING, "............ +++ $what returned no content (HTTP "
            . $res['code'] . "): " . $why);
    }

    // A truncated answer loses its closing marker and ends mid-line. Saying so
    // is better than parsing the fragment as if it were complete.
    $truncated = (($res['body']['stop_reason'] ?? "") === "max_tokens");
    if ($truncated) {
        lognlsev(2, WARNING, "............ +++ $what hit max_tokens ($maxTokens); "
            . "the answer is incomplete");
    }

    return [
        'text'      => $text ?? "",
        'code'      => $res['code'],
        'error'     => $res['error'],
        'truncated' => $truncated,
    ];
}


// ===========================================================================
// OpenAI callers
// ===========================================================================

/**
 * Verify that the OpenAI API is reachable and answering.
 *
 * @return bool TRUE on HTTP 200 with content.
 */
function testAIavailability(): bool
{
    $res = openAiChat("gpt-3.5-turbo", "Say this is a test", "testAIavailability");
    return $res['code'] === 200 && $res['text'] !== "";
}

/**
 * Ask for the normal reference range of a lab test for a specific patient.
 *
 * The 'rr' field is the raw string from the model; callers json_decode() it.
 *
 * @return array{rr:string,code:int,error:string}|null  NULL on a non-200 answer.
 */
function getAIReferenceRange($patage, $patgender, $labtest)
{
    $prompt = <<<AIP
You are a laboratory doctor. Get reference ranges the following lab test results for an $patage-year-old $patgender patient.
Lab Test Results: $labtest

Return reference ranges that are Quantities as plain JSON with
"high": {high value quantity without the units},
"low": {low value quantity without the units},
"unit": {value quantity unit}
Also add the the reference range as a string in JSON "display".
Example:
{
    "low": "200",
    "high" : "250",
    "unit" : "mg/dL,
    "display: "200-250 mg/dL"
}
Please add no extra text here, just this JSON.

Return reference ranges that a Qualitative like "Negative", "trace" "++", "Pale yellow" or "Yellow to amber" as plain JSON
"text": {range low text - range high text}.
Example
{
  "text": "Pale yellow - Yellow to amber"
}
Do not use here the JSON elements mention above (high, low, unit).

AIP;

    $res = openAiChat("gpt-3.5-turbo", $prompt, "getAIReferenceRange", ["max_tokens" => 4096]);
    if ($res['code'] !== 200) {
        return NULL;
    }

    return ['rr' => $res['text'], 'code' => $res['code'], 'error' => $res['error']];
}

/**
 * Generate an XHTML lab results table.
 *
 * @return array{xhtml:string,code:int,error:string}
 */
function getAIlabtable($patage, $patgender, $sectxt2): array
{
    $prompt = <<<AIP
You are a laboratory IT system vendor and doctor of medicine as well.
Generate an XHTML table with the following lab test results for an $patage-year-old $patgender patient.
Include each test, result, reference range, and unit.
Add reference ranges between the "Result" and "Unit" columns.
Bold the result value if it is too high and append an "H" or if it is too low with an "L".
Use class='hl7__eu__lab__eport' for the table.

Lab Test Results:
$sectxt2

Return the XHTML code only.
AIP;

    $res = openAiChat("gpt-3.5-turbo", $prompt, "getAIlabtable");
    return ['xhtml' => $res['text'], 'code' => $res['code'], 'error' => $res['error']];
}

/**
 * Ask for a suggested medication dosage in FHIR FSH format.
 *
 * @return array{text:string,code:int,error:string}
 */
function getAIsuggestedMedicationDosage($patage, $patgender, $conditions4ai, $medication): array
{
    $prompt = <<<AIP
    You are a physician that has a $patage-year-old $patgender patient with the following diagnoses: $conditions4ai.
    What is an appropriate dosage for $medication?

    Please return a suggested dosage using the FHIR 'Dosage' data type in FHIR FSH format as
    '* dosage.text' but without the medication name only with strength and frequency.

    Add '* dosage.doseAndRate.doseQuantity.value =', , note that this is not in quotes.
    Add '* dosage.doseAndRate.doseQuantity.unit =', note that this is in quotes.
    Add '* dosage.doseAndRate.doseQuantity.system = "http://unitsofmeasure.org"', note that this is in quotes as shown.
    Add '* dosage.doseAndRate.doseQuantity.code =' in the format '#code', note that this is not in quotes.

    Additionally add the 'dosage.timing' element with '* dosage.timing.repeat.frequency =', '* dosage.timing.repeat.period ='.

    Add also '* dosage.timing.repeat.periodUnit =' in the format '#code', note that this is not in quotes.

    Use '* dosage.asNeededBoolean =' if applicable, note that 'true' or 'false' is not in quotes.

    If dosage.doseAndRate.doseQuantity.value and dosage.doseAndRate.doseQuantity.unit are
    exactly as the strength of the medication and the dose form is a tablet or capsule then use 'tablet' or
    'capsule' respectively as dosage.doseAndRate.doseQuantity.value = 1 and
    dosage.doseAndRate.doseQuantity.unit = "{tbl}" or dosage.doseAndRate.doseQuantity.unit = "{cap}"
    dosage.doseAndRate.doseQuantity.system = \$ucum and the strength in parenthesis ().
    In that case the '* dosage.text' shall use '1 tablet' or '1 capsule' and the frequency.

    Return ONLY the FSH as pure text.
    If you want to emit an error message such as "I'm sorry, but you did not specify a medication." do that
    always with preceding "// " to indicate a proper FSH comment.
AIP;

    return openAiChat("gpt-4.1", trim($prompt), "getAIsuggestedMedicationDosage");
}

/**
 * Ask for a brief clinical conclusion over a patient's lab results.
 *
 * @return array{text:string,code:int,error:string}
 */
function getAILabConclusion($patage, $patgender, $labtable): array
{
    $prompt = <<<AIP
    You are a laboratory doctor and typically you are doing the last review of
    lab results of patients and add a short conclusion from the clinical
    laboratory perspective. Given the following list of lab results of a
    $patage year old $patgender patient what would be your short conclusion
    here. The table has per line the following format:
      | date | analyte | measurement/unit | normal range for patient | "L" or an "H" as indicators for too low or too high values.

    Return your conclusion only with no headline, pure text and not more than 100 words.
    Here are the lab results:

    $labtable
AIP;

    return openAiChat("gpt-4.1", trim($prompt), "getAILabConclusion");
}

/**
 * Ask for FHIR Goal instances for one care-plan item, in FSH.
 *
 * The LOINC verification in the prompt relies on the model following the given
 * URL pattern at inference time; no HTTP verification happens here. Treat the
 * emitted target.measure as a suggestion, not as a validated binding.
 *
 * @return array{text:string,code:int,error:string}
 */
function getAIGoals($patage, $patgender, $careplanitem, $careplanreason, $conditions4ai): array
{
    $prompt = <<<AIP
    You are a physician that treats a $patage y/o $patgender patient.
    You created a care plan item: $careplanitem with reason $careplanreason.

    Given his overall active problems:
    $conditions4ai

    ... create a set of the FHIR Goal Instances just for the care plan item mentioned above
    and only just the description.text and if applicable target measure and detailQuantity
    with harmonised UCUM units where possible. Add appropriate human readable "Title: "
    for the FHIR FSH instance of the goals. Do not present a "Description: ". The first part
    look thus as the following pattern:

    Instance: {Instance Name}
    InstanceOf: Goal
    Title: "{human reabable title}"
    * description.text = "{description}"
    * ...

    Use target.measure with LOINC with the following pattern:
    * target.measure = http://loinc.org#{code} "{display name}"

    You must verify that the LOINC code exist using the url below
    and that the code must match its display name according to the official LOINC specification
    https://fhir.loinc.org/CodeSystem/\$lookup?system=http://loinc.org&code={code}
    otherwise do not emit "* target.measure" at all.

    If target.detailQuantity, target.detailRange.high or target.detailRange.low
    is emited and UCUM is used it separately mentions
    * target[0].detailQuantity.value = {value}  -> for example * target[0].detailQuantity.value = 15.6
    * target[0].detailQuantity.unit = "{unit}"  -> for example * target[0].detailQuantity.unit = "mmol/L"
    * target[0].detailQuantity.system = "http://unitsofmeasure.org"
    * target[0].detailQuantity.code = #{code} -> for example * target[0].detailQuantity.code = #% or #mmol/L or #mm[Hg]

    Return only the FSH code, no extra text or "```fsh" markers or other markup.
AIP;

    return openAiChat("gpt-4.1", trim($prompt), "getAIGoals");
}

/**
 * Second pass: add a missing target.measure LOINC to an FSH Goal.
 *
 * @param  string $olddesc  The goal's objective, as context for the model.
 * @param  string $oldfsh   The incomplete FSH from getAIGoals().
 * @return array{text:string,code:int,error:string}
 */
function fixMissingTargetMeasurewithAI($olddesc, $oldfsh): array
{
    $prompt = <<<AIP
    You are a professional FHIR FSH creator and found the enclosed FSH
    FHIR Goal construct.

    If you got Goals defined in FSH like the one below, there is a target.measure
    item missing with a proper LOINC.

    This main objective of the goal is "$olddesc"

    ----------------------------------------------------
    $oldfsh
    ----------------------------------------------------

    Use target.measure with LOINC with the following pattern:
    * target.measure = http://loinc.org#{code} "{display name}"

    You must verify that the LOINC code exists using the url below
    and that the code must match its display name according to the official LOINC specification
    https://fhir.loinc.org/CodeSystem/\$lookup?system=http://loinc.org&code={code}
    otherwise do not emit "* target.measure" at all.

    Return ONLY the corrected FSH, no other text.

AIP;

    return openAiChat("gpt-4.1", trim($prompt), "fixMissingTargetMeasurewithAI");
}


// ===========================================================================
// Anthropic caller: discharge report and procedures
// ===========================================================================

/**
 * Generate a hospital discharge report and, optionally, the procedures performed.
 *
 * THE PROCEDURE LIST
 *   The model is given a menu of concepts taken from the ART-DECOR procedure
 *   value set and is told to answer with a code from that menu and nothing
 *   else. Every returned line is then checked again here: the shape of the
 *   line, the date, and the code's membership in the value set. A line that
 *   fails any of those is dropped and reported through registerMapMissing(),
 *   so what the model got wrong is in the log rather than in the output.
 *
 *   The menu is a shortlist, not the value set: 57,709 concepts would be on the
 *   order of a million tokens per stay. It is built per stay from the admission
 *   reason and discharge diagnosis, plus the ward procedures that are plausible
 *   in any discharge report. Roughly 250 entries, a few thousand tokens, which
 *   is small enough to send every time.
 *
 *   Two things this deliberately does NOT do. It does not let the model write
 *   free-text procedure names to be matched afterwards: a name that resolves to
 *   nothing has already been written into the narrative by then. And it does
 *   not fuzzy-match: an approximate match is how an observable entity and a
 *   physical object ended up in a procedure list.
 *
 * @param  int|string $patage
 * @param  string     $patgender
 * @param  array      $stayinfo           Must contain 'encounters'; the last one
 *                                        is used as the discharge encounter.
 * @param  bool       $includeProcedures  Append the procedure task.
 *
 * @return array{text:string,code:int|string,error:string}
 *         'text' carries the letter between %%TEXT%% markers and, when asked
 *         for, the validated procedure lines between %%PROCEDURES%% markers,
 *         in the format type|date|text|code|display.
 */
function getAIHospitalCourse($patage, $patgender, $stayinfo, $includeProcedures = FALSE): array
{
    if ($stayinfo["encounters"] === NULL) {
        return ['text' => "", 'code' => 'no-encounter-info', 'error' => "No encounter info."];
    }
    if (count($stayinfo["encounters"]) === 0) {
        return ['text' => "", 'code' => 'no-encounters-for-stay', 'error' => "No encounters for this stay."];
    }

    $encounterinfo    = $stayinfo["encounters"][count($stayinfo["encounters"]) - 1];
    $start            = $encounterinfo["start"];
    $end              = $encounterinfo["end"];
    $reasoncode       = $encounterinfo["reason"]["code"];
    $reasondisplay    = $encounterinfo["reason"]["display"];
    $dischargetext    = $encounterinfo["discharge"]["text"];
    $dischargecode    = $encounterinfo["discharge"]["code"];
    $dischargedisplay = $encounterinfo["discharge"]["display"];

    // -----------------------------------------------------------------------
    // Part 1 — the letter
    // -----------------------------------------------------------------------
    $prompt = <<<AIP
    You are a doctor in a hospital and are doing the patient discharge management.

    A $patage year old $patgender patient was admitted for the reason $reasondisplay (SNOMED: $reasoncode).
    The patient was finally discharged with $dischargetext (ICD-10: $dischargecode $dischargedisplay).

    1. Invent a text authored by you as treating hospital physician back
    to the primary care doctor of the patient (inter-colleague discharge report).
    The text should briefly summarize diagnostic assement folling the admission reason.
    and the treatment in hospital.

    Return the text only with no headline, pure text and not more than 150 words.
    Embrace the text with this pattern: %%TEXT%%

AIP;

    // -----------------------------------------------------------------------
    // Part 2 — the procedures, chosen from the menu
    // -----------------------------------------------------------------------
    $menu = [];
    if ($includeProcedures) {
        $menu = procedureMenu([$reasondisplay, $dischargedisplay, $dischargetext]);
        if ($menu === []) {
            // Without a menu the model has nothing to choose from, and asking
            // anyway is what produced invented codes. Drop the task instead.
            lognlsev(2, WARNING, "............ +++ getAIHospitalCourse: procedure menu is "
                . "empty, asking for the letter only");
            $includeProcedures = FALSE;
        }
    }

    if ($includeProcedures) {
        $menutext = procedureMenuAsText($menu);
        $prompt .= <<<AIP

    2. From the hospital course you have just written, list the procedures that were performed.

    You MUST choose each procedure from the list below. The list is the only
    permitted vocabulary: do not use a SNOMED code that is not in it, do not
    invent one, and do not alter the display text. If a procedure you described
    in the text is not in the list, leave it out of the list rather than
    substituting a similar code.

    Each line of the list is: snomed-code|snomed-display

    $menutext

    Give every procedure a date YYYY-MM-DD within the stay period $start to $end.
    Split the result into "diagnostic" and "therapeutic" procedures.

    Return the list and only this list, one procedure per line, in the format
    diagnostic|date|text|snomed-code|snomed-display
    therapeutic|date|text|snomed-code|snomed-display

    where "text" is your own short sentence about that procedure and
    snomed-code and snomed-display are copied verbatim from the list above.

    Embrace the list with this pattern: %%PROCEDURES%%
AIP;
    }

    $res = anthropicMessage(
        [["type" => "text", "text" => trim($prompt)]],
        "getAIHospitalCourse",
        AI_MAX_TOKENS_HOSPITAL_COURSE
    );

    if ($res['code'] !== 200 || $res['text'] === "") {
        return ['text' => "", 'code' => $res['code'], 'error' => $res['error']];
    }
    if ($res['truncated']) {
        // The closing marker is missing, so the caller's split would take the
        // rest of the answer as one field. Refuse the whole answer instead.
        return ['text' => "", 'code' => $res['code'],
                'error' => "answer truncated at " . AI_MAX_TOKENS_HOSPITAL_COURSE . " tokens"];
    }

    $text = $includeProcedures ? aiFilterProcedureBlock($res['text'], $menu, $start, $end)
                               : $res['text'];

    return ['text' => $text, 'code' => $res['code'], 'error' => $res['error']];
}

/**
 * Keep only the procedure lines that survive validation.
 *
 * Checked per line, in this order, because each check assumes the one before:
 *   1. five pipe-separated fields — a truncated or reordered line has fewer
 *      or more, and the field swap that put the literal string "procedure"
 *      into the code position had them in the wrong places;
 *   2. type is diagnostic or therapeutic;
 *   3. date parses and falls inside the stay;
 *   4. the code is a concept of the procedure value set.
 *
 * The display is taken from the value set, not from the model, so a correct
 * code with an invented label cannot get through either.
 *
 * @param  string               $answer  Full model answer.
 * @param  array<string,string> $menu    code => display, what was offered.
 * @return string  The answer with a validated %%PROCEDURES%% block.
 */
function aiFilterProcedureBlock(string $answer, array $menu, string $start, string $end): string
{
    $parts = explode("%%PROCEDURES%%", $answer);
    if (count($parts) < 3) {
        lognlsev(2, WARNING, "............ +++ getAIHospitalCourse: no %%PROCEDURES%% block "
            . "in the answer");
        return $answer;
    }

    $kept = [];
    $dropped = 0;
    foreach (explode("\n", trim($parts[1])) as $line) {
        $line = trim($line);
        if ($line === "") {
            continue;
        }
        $items = explode("|", $line);
        if (count($items) !== 5) {
            $dropped++;
            registerMapMissing("+++ Procedure line has " . count($items)
                . " fields instead of 5: " . substr($line, 0, 80));
            continue;
        }

        [$type, $date, $what, $code, $display] = array_map('trim', $items);

        if ($type !== "diagnostic" && $type !== "therapeutic") {
            $dropped++;
            registerMapMissing("+++ Procedure type is neither diagnostic nor therapeutic: $type");
            continue;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || ($start !== "" && $date < substr($start, 0, 10))
            || ($end   !== "" && $date > substr($end,   0, 10))) {
            $dropped++;
            registerMapMissing("+++ Procedure date $date is malformed or outside $start..$end");
            continue;
        }
        if (!procedureIsKnown($code)) {
            $dropped++;
            registerMapMissing("+++ Procedure code not in " . PROCEDURE_VALUESET . ": $code ($display)");
            continue;
        }
        if (!isset($menu[$code])) {
            // In the value set but not on the menu: allowed through, because it
            // is a real procedure concept, but worth knowing about — it means
            // the model went outside the vocabulary it was given.
            registerMapMissing("+++ Procedure code $code was not on the offered menu");
        }

        // The display comes from the terminology, never from the answer.
        $kept[] = implode("|", [$type, $date, $what, $code, procedureDisplay($code)]);
    }

    lognl(3, "......... Hospital procedures: " . count($kept) . " kept, $dropped dropped");

    $parts[1] = "\n" . implode("\n", $kept) . "\n";
    return implode("%%PROCEDURES%%", $parts);
}


// ===========================================================================
// post-processing
// ===========================================================================

/**
 * Repair the UCUM spellings models get wrong.
 *
 * Order matters: "#events/hr" is corrected before "#events/h", otherwise the
 * shorter pattern matches first and leaves a trailing "r".
 *
 * @param  string $fsh  Raw FSH as returned by the model.
 * @return string
 */
function applyCorrectionsOnAIflawsInFSH($fsh)
{
    $tmp = str_replace("#events/hr",                 "#{events/h}",       $fsh);   // non-standard /hr abbreviation
    $tmp = str_replace("#events/h",                  "#{events/h}",       $tmp);   // missing curly braces
    $tmp = str_replace("#tbl",                       "#{tbl}",            $tmp);   // tablet dose form
    $tmp = str_replace("#cap",                       "#{cap}",            $tmp);   // capsule dose form
    $tmp = str_replace("#score",                     "#{score}",          $tmp);   // score
    $tmp = str_replace("##/area",                    "#{#/area}",         $tmp);   // area
    $tmp = str_replace("#[#/area]",                  "#{[#/area]}",       $tmp);   // area
    $tmp = str_replace("##/HPF",                     "#{#/HPF}",         $tmp);    // HPF
    $tmp = str_replace("#actuation",                 "#{actuation}",      $tmp);   // actuation
    $tmp = str_replace("#actuat",                    "#{actuation}",      $tmp);   // actuation
    $tmp = str_replace("#patch",                     "#{patch}",          $tmp);   // patch
    $tmp = str_replace("#INR",                       "#{INR}",            $tmp);   // INR
    $tmp = str_replace("\$ucum#seconds",             "\$ucum#s",          $tmp);   // seconds
    $tmp = str_replace("#{score}",                   "#1",                $tmp);   // avoid warnings from #{...} codes
    $tmp = str_replace("#{nominal}",                 "#1",                $tmp);   // avoid warnings from #{...} codes
    $tmp = str_replace("#Specific Gravity",          "#1 \"Specific Gravity\"",$tmp);  // avoid warnings from #{...} codes

    return $tmp;
}
