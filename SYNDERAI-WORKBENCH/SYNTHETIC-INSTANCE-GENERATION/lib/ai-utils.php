<?php

/**
 * AI Utility Functions — SynderAI
 *
 * Provides a collection of functions that call Large Language Model (LLM) APIs
 * to generate or enrich clinical content for synthetic patient records.
 *
 * Two AI backends are used:
 *
 *   OpenAI (GPT)
 *     Endpoint : https://api.openai.com/v1/chat/completions
 *     Auth     : Bearer token via OPEN_AI_API_KEY constant
 *     Models   : gpt-3.5-turbo (availability check, reference ranges, XHTML table)
 *                gpt-4.1       (dosage, lab conclusion, care-plan goals)
 *
 *   Anthropic (Claude)
 *     Endpoint : https://api.anthropic.com/v1/messages
 *     Auth     : x-api-key header via ANTHROPIC_API_KEY constant
 *     Model    : claude-sonnet-4-6
 *     Beta     : Files API (anthropic-beta: files-api-2025-04-14)
 *
 * All AI calls use temperature = 0 for deterministic, reproducible output.
 *
 * Function overview:
 *   testAIavailability()               — smoke-test OpenAI connectivity
 *   getAIReferenceRange()              — lab reference range as JSON (GPT-3.5)
 *   getAIlabtable()                    — XHTML lab results table (GPT-3.5)
 *   getAIsuggestedMedicationDosage()   — FHIR FSH dosage suggestion (GPT-4.1)
 *   getAILabConclusion()               — clinical lab conclusion text (GPT-4.1)
 *   getAIGoals()                       — FHIR FSH Goal instances (GPT-4.1)
 *   getAIHospitalCourse()              — discharge report + procedure list (Claude)
 *   unused_getAIHospitalCourse()       — DEPRECATED OpenAI version, do not use
 *
 * Standard result array shape (returned by most functions on success):
 * [
 *   'text'  / 'xhtml' / 'rr' => string   The AI-generated content
 *   'code'                   => int       HTTP status code from the API call
 *   'error'                  => string    cURL error string (empty on success)
 * ]
 *
 * Response validation pattern:
 *   Most functions check three nested keys in the OpenAI response before
 *   accessing the content: $phpres['choices'], ['choices'][0]['message'],
 *   and ['choices'][0]['message']['content']. If any key is missing a warning
 *   is echoed and the content field is set to an empty string.
 *
 * External dependencies:
 *   config.php  — defines OPEN_AI_API_KEY, ANTHROPIC_API_KEY, MAPPINGS constant
 */

/* INCLUDES */
include_once("config.php");


/**
 * Verify that the OpenAI API is reachable and responding correctly.
 *
 * Sends the minimal prompt "Say this is a test" to gpt-3.5-turbo and checks
 * whether the API returns HTTP 200. The actual response content is read but
 * not validated beyond confirming its presence.
 *
 * The function also builds a date-anchored age-calculation prompt (commented
 * out) that was used during development to verify the model's arithmetic; it
 * is retained for reference but is not sent.
 *
 * @return bool  TRUE if the API returned HTTP 200, FALSE otherwise (in which
 *               case the raw decoded response is var_dump()ed for debugging).
 */
function testAIavailability() {

    $today = date("M Y");

    // Development prompt (not currently sent — kept for reference)
    $prompt = <<<AIP
Get the age as of $today of a patient born September 24, 1992. Return the age in years and months".
AIP;

    $payload = [
        "model"       => "gpt-3.5-turbo",
        "messages"    => [
            [
                "role"    => "user",
                "content" => "Say this is a test"   // $prompt
            ]
        ],
        "temperature" => 0
    ];

    $jsondata = json_encode($payload);

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPEN_AI_API_KEY
    ];

    $openaiurl = "https://api.openai.com/v1/chat/completions";

    $ch = curl_init($openaiurl);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
    curl_setopt($ch, CURLOPT_POST,           TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $jsondata);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $phpres = json_decode($response, TRUE);

    if ($code !== 200) {
        var_dump($phpres);  // debug output on failure
        return FALSE;
    } else {
        $res = $phpres['choices'][0]['message']['content'];
        return TRUE;
    }
}


/**
 * Ask the AI for the normal reference range of a lab test for a specific patient.
 *
 * Instructs GPT-3.5-turbo (acting as a laboratory doctor) to return the
 * reference range as a plain JSON object. The prompt explicitly forbids
 * surrounding prose so that the response can be parsed directly.
 *
 * Expected AI response format (raw JSON string in 'rr'):
 * {
 *   "high":    <numeric>,
 *   "low":     <numeric>,
 *   "unit":    "<unit string>",
 *   "display": "<human-readable range string>"
 * }
 *
 * Note: the returned 'rr' field is the raw string from the AI — callers are
 * responsible for json_decode()ing it before use.
 *
 * @param  int|string $patage     Patient age in years (interpolated into prompt).
 * @param  string     $patgender  Patient gender (e.g. "male", "female").
 * @param  string     $labtest    Lab test name (e.g. "Haemoglobin", "eGFR").
 *
 * @return array|null  Associative array on HTTP 200:
 *                       'rr'    => string  Raw JSON reference range from the AI.
 *                       'code'  => int     HTTP status code.
 *                       'error' => string  cURL error (empty on success).
 *                     NULL on non-200 response.
 */
function getAIReferenceRange($patage, $patgender, $labtest) {

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

    $payload = [
        "model"       => "gpt-3.5-turbo",
        "messages"    => [["role" => "user", "content" => $prompt]],
        "temperature" => 0,
        "max_tokens"  => 4096
    ];

    $jsondata  = json_encode($payload);
    $headers   = [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPEN_AI_API_KEY
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
    curl_setopt($ch, CURLOPT_POST,           TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $jsondata);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $phpres = json_decode($response, TRUE);

    if ($code !== 200) {
        return NULL;
    } else {
        return [
            'rr'    => $phpres['choices'][0]['message']['content'],
            'code'  => $code,
            'error' => $error
        ];
    }
}


/**
 * Generate an XHTML lab results table for a patient using the AI.
 *
 * Instructs GPT-3.5-turbo (acting as both a lab IT vendor and physician) to
 * produce a well-formed XHTML table from a plain-text list of lab results.
 *
 * The prompt requests:
 *   - Columns: Test | Result | Reference Range | Unit
 *   - Bold formatting + "H"/"L" suffix on out-of-range values
 *   - CSS class "hl7__eu__lab__eport" on the <table> element
 *
 * The AI is instructed to return XHTML only — no surrounding prose or markdown.
 * Response validation checks all three nested keys in the choices array and
 * sets 'xhtml' to an empty string if any key is absent.
 *
 * @param  int|string $patage     Patient age in years.
 * @param  string     $patgender  Patient gender (e.g. "male", "female").
 * @param  string     $sectxt2    Plain-text lab results, one per line, in the
 *                                format expected by the AI prompt.
 *
 * @return array  Always returns an array (never NULL), even on failure:
 *                  'xhtml' => string  XHTML table, or "" if validation failed.
 *                  'code'  => int     HTTP status code.
 *                  'error' => string  cURL error (empty on success).
 */
function getAIlabtable($patage, $patgender, $sectxt2) {

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

    $payload = [
        "model"       => "gpt-3.5-turbo",
        "messages"    => [["role" => "user", "content" => $prompt]],
        "temperature" => 0
    ];

    $jsondata = json_encode($payload);
    $headers  = [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPEN_AI_API_KEY
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
    curl_setopt($ch, CURLOPT_POST,           TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $jsondata);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $phpres = json_decode($response, TRUE);

    // Validate all nested keys before accessing the content
    $valid = TRUE;
    if (!isset($phpres['choices'])) {
        lognlsev (2, WARNING, "............ +++ AI returned no value for \$phpres['choices'] getAIlabtable");
        $valid = FALSE;
    }
    if (!isset($phpres['choices'][0]['message'])) {
        lognlsev (2, WARNING, "............ +++ AI returned no value for \$phpres['choices'][0]['message'] getAIlabtable");
        $valid = FALSE;
    }
    if (!isset($phpres['choices'][0]['message']['content'])) {
        lognlsev (2, WARNING, "............ +++ AI returned no value for \$phpres['choices'][0]['message']['content'] getAIlabtabl");
        $valid = FALSE;
    }
    if (!$valid) var_dump($phpres);

    return [
        'xhtml' => $valid ? $phpres['choices'][0]['message']['content'] : "",
        'code'  => $code,
        'error' => $error
    ];
}


/**
 * Ask the AI for a suggested medication dosage in FHIR FSH format.
 *
 * Instructs GPT-4.1 (acting as a physician) to suggest an appropriate dosage
 * for the given medication, taking the patient's age, gender, and active
 * diagnoses into account.
 *
 * The prompt is carefully structured to request FHIR FSH output covering:
 *   - dosage.text
 *   - dosage.doseAndRate.doseQuantity (value, unit, system, code in UCUM)
 *   - dosage.timing.repeat (frequency, period, periodUnit in UCUM)
 *   - dosage.asNeededBoolean (if applicable)
 *   - Tablet/capsule shorthand when the strength matches the dose form
 *
 * The AI is instructed to return FSH text only — no markdown markers or prose.
 *
 * @param  int|string $patage         Patient age in years.
 * @param  string     $patgender      Patient gender (e.g. "male", "female").
 * @param  string     $conditions4ai  Comma-separated or newline-separated list
 *                                    of active diagnoses (plain text or SNOMED
 *                                    display names).
 * @param  string     $medication     Medication name including strength
 *                                    (e.g. "Metformin 500 mg").
 *
 * @return array  Associative array:
 *                  'text'  => string  Raw FHIR FSH dosage snippet, or "" on failure.
 *                  'code'  => int     HTTP status code.
 *                  'error' => string  cURL error (empty on success).
 */
function getAIsuggestedMedicationDosage($patage, $patgender, $conditions4ai, $medication) {

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
AIP;
    $prompt = trim($prompt);

    $payload = [
        "model"       => "gpt-4.1",
        "messages"    => [["role" => "user", "content" => $prompt]],
        "temperature" => 0
    ];

    $jsondata = json_encode($payload);
    $headers  = [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPEN_AI_API_KEY
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
    curl_setopt($ch, CURLOPT_POST,           TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $jsondata);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $phpres = json_decode($response, TRUE);

    $valid = TRUE;
    if (!isset($phpres['choices'])) {
        echo "+++ AI returned no value for \$phpres['choices'] getAIsuggestedMedicationDosage\n";
        $valid = FALSE;
    }
    if (!isset($phpres['choices'][0]['message'])) {
        echo "+++ AI returned no value for \$phpres['choices'][0]['message'] getAIsuggestedMedicationDosage\n";
        $valid = FALSE;
    }
    if (!isset($phpres['choices'][0]['message']['content'])) {
        echo "+++ AI returned no value for \$phpres['choices'][0]['message']['content'] getAIsuggestedMedicationDosage\n";
        $valid = FALSE;
    }

    return [
        'text'  => $valid ? $phpres['choices'][0]['message']['content'] : "",
        'code'  => $code,
        'error' => $error
    ];
}


/**
 * Ask the AI for a brief clinical conclusion over a patient's lab results.
 *
 * Instructs GPT-4.1 (acting as a reviewing laboratory doctor) to produce a
 * short plain-text clinical commentary (≤ 100 words, no headline) based on a
 * structured lab results table.
 *
 * Expected $labtable line format (pipe-delimited):
 *   | date | analyte | measurement/unit | normal range | "L" or "H" flag |
 *
 * @param  int|string $patage     Patient age in years.
 * @param  string     $patgender  Patient gender (e.g. "male", "female").
 * @param  string     $labtable   Pipe-delimited lab results table (plain text).
 *
 * @return array  Associative array:
 *                  'text'  => string  Clinical conclusion (≤ 100 words), or "" on failure.
 *                  'code'  => int     HTTP status code.
 *                  'error' => string  cURL error (empty on success).
 */
function getAILabConclusion($patage, $patgender, $labtable) {

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
    $prompt = trim($prompt);

    $payload = [
        "model"       => "gpt-4.1",
        "messages"    => [["role" => "user", "content" => $prompt]],
        "temperature" => 0
    ];

    $jsondata = json_encode($payload);
    $headers  = [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPEN_AI_API_KEY
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
    curl_setopt($ch, CURLOPT_POST,           TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $jsondata);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $phpres = json_decode($response, TRUE);

    $valid = TRUE;
    if (!isset($phpres['choices'])) {
        echo "+++ AI returned no value for \$phpres['choices'] getAILabConclusion\n";
        $valid = FALSE;
    }
    if (!isset($phpres['choices'][0]['message'])) {
        echo "+++ AI returned no value for \$phpres['choices'][0]['message'] getAILabConclusion\n";
        $valid = FALSE;
    }
    if (!isset($phpres['choices'][0]['message']['content'])) {
        echo "+++ AI returned no value for \$phpres['choices'][0]['message']['content'] getAILabConclusion\n";
        $valid = FALSE;
    }

    return [
        'text'  => $valid ? $phpres['choices'][0]['message']['content'] : "",
        'code'  => $code,
        'error' => $error
    ];
}


/**
 * Ask the AI to generate FHIR Goal instances for a care-plan item.
 *
 * Instructs GPT-4.1 (acting as the treating physician) to produce one or more
 * FHIR Goal instances in FHIR Shorthand (FSH) format, scoped to a single
 * care-plan item and informed by the patient's overall active problem list.
 *
 * The prompt requests:
 *   - FSH Instance / InstanceOf / Title headers
 *   - description.text
 *   - target.measure with a verified LOINC code (the model is asked to
 *     validate codes against the FHIR LOINC CodeSystem lookup endpoint)
 *   - target.detailQuantity / target.detailRange with UCUM units where applicable
 *
 * The AI is instructed to return FSH only — no markdown fences or extra text.
 *
 * Note: the LOINC verification step relies on the model's ability to follow
 * the provided URL pattern at inference time; actual HTTP verification is not
 * guaranteed by all model versions.
 *
 * @param  int|string $patage          Patient age in years.
 * @param  string     $patgender       Patient gender (e.g. "male", "female").
 * @param  string     $careplanitem    Name/description of the care-plan item.
 * @param  string     $careplanreason  Reason or rationale for the care-plan item.
 * @param  string     $conditions4ai   Active problem list (plain text, one per line
 *                                     or comma-separated).
 *
 * @return array  Associative array:
 *                  'text'  => string  FHIR FSH Goal instance(s), or "" on failure.
 *                  'code'  => int     HTTP status code.
 *                  'error' => string  cURL error (empty on success).
 */
function getAIGoals($patage, $patgender, $careplanitem, $careplanreason, $conditions4ai) {

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
    $prompt = trim($prompt);

    $payload = [
        "model"       => "gpt-4.1",
        "messages"    => [["role" => "user", "content" => $prompt]],
        "temperature" => 0
    ];

    $jsondata = json_encode($payload);
    $headers  = [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPEN_AI_API_KEY
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
    curl_setopt($ch, CURLOPT_POST,           TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $jsondata);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $phpres = json_decode($response, TRUE);

    $valid = TRUE;
    if (!isset($phpres['choices'])) {
        echo "+++ AI returned no value for \$phpres['choices'] getAIGoals\n";
        $valid = FALSE;
    }
    if (!isset($phpres['choices'][0]['message'])) {
        echo "+++ AI returned no value for \$phpres['choices'][0]['message'] getAIGoals\n";
        $valid = FALSE;
    }
    if (!isset($phpres['choices'][0]['message']['content'])) {
        echo "+++ AI returned no value for \$phpres['choices'][0]['message']['content'] getAIGoals\n";
        $valid = FALSE;
    }

    return [
        'text'  => $valid ? $phpres['choices'][0]['message']['content'] : "",
        'code'  => $code,
        'error' => $error
    ];
}


/**
 * Generate a hospital discharge report (and optionally a procedure list) using Claude.
 *
 * This is the only function in this file that uses the Anthropic Claude API
 * (claude-sonnet-4-6) instead of OpenAI GPT. It takes the last encounter in a
 * hospital stay cluster and asks Claude to write a realistic inter-colleague
 * discharge letter from the treating hospital physician to the patient's
 * primary care doctor (≤ 150 words, plain text, delimited by %%TEXT%%).
 *
 * Optionally ($includeProcedures = TRUE), a second section is added to the
 * prompt requesting a pipe-delimited list of SNOMED-coded procedures
 * (diagnostic and therapeutic), delimited by %%PROCEDURES%%. The SNOMED
 * procedure reference list can be supplied to Claude in two ways, controlled
 * by the internal $USEFILEUPLOAD flag:
 *
 *   $USEFILEUPLOAD = TRUE  — Upload snomed-procedures.txt to Claude's Files
 *                            API (Beta) first, then attach it as a "document"
 *                            block in the message. Requires the beta header:
 *                            anthropic-beta: files-api-2025-04-14
 *
 *   $USEFILEUPLOAD = FALSE — Pass a public URL to the file at synderai.net
 *                            directly in the prompt text (no file upload step).
 *                            This is the current active mode.
 *
 *
 * @param  int|string $patage             Patient age in years.
 * @param  string     $patgender          Patient gender (e.g. "male", "female").
 * @param  array      $stayinfo           Hospital stay data. Must contain:
 *                                          'encounters' => array  List of encounter
 *                                          records; the LAST element is used.
 *                                        Each encounter record must contain:
 *                                          'reason'    => ['code'=>..., 'display'=>...]
 *                                          'discharge' => ['text'=>..., 'code'=>..., 'display'=>...]
 * @param  bool       $includeProcedures  If TRUE, appends a procedure extraction
 *                                        task to the prompt. Default: FALSE.
 *
 * @return array  Associative array (though see bugs — output is currently broken):
 *                  'text'  => string  Discharge narrative + optional procedure list.
 *                  'code'  => int|string  HTTP status code, or a sentinel string
 *                                         ('no-encounter-info', 'no-encounters-for-stay')
 *                                         for early-exit cases.
 *                  'error' => string  Error description or cURL error.
 */
function getAIHospitalCourse($patage, $patgender, $stayinfo, $includeProcedures = FALSE) {

    // Internal flag: TRUE = upload snomed-procedures.txt via Claude Files API (Beta)
    //                FALSE = reference the file by its public URL (current mode)
    $USEFILEUPLOAD = FALSE;

    // -------------------------------------------------------------------------
    // Early exits — return sentinel error arrays when encounter data is missing
    // -------------------------------------------------------------------------
    if ($stayinfo["encounters"] === NULL) {
        return ['text' => "", 'code' => 'no-encounter-info', 'error' => "No encounter info."];
    }
    if (count($stayinfo["encounters"]) === 0) {
        return ['text' => "", 'code' => 'no-encounters-for-stay', 'error' => "No encounters for this stay."];
    }

    // Use the last encounter in the cluster as the representative discharge encounter
    $encounterinfo    = $stayinfo["encounters"][count($stayinfo["encounters"]) - 1];
    $start            = $encounterinfo["start"];
    $end              = $encounterinfo["end"];
    $reasoncode       = $encounterinfo["reason"]["code"];
    $reasondisplay    = $encounterinfo["reason"]["display"];
    $dischargetext    = $encounterinfo["discharge"]["text"];
    $dischargecode    = $encounterinfo["discharge"]["code"];
    $dischargedisplay = $encounterinfo["discharge"]["display"];

    // Path to the local SNOMED procedure reference file (used for file upload mode)
    $snomedprocs = MAPPINGS . "/snomed-procedures.txt";

    // -------------------------------------------------------------------------
    // Build the optional procedure-extraction sub-prompt
    // -------------------------------------------------------------------------
    if ($includeProcedures) {
        if ($USEFILEUPLOAD) {
            // STEP 0a: File-upload mode — instruct Claude to read the attached file
            $proceduresPrompt = <<<AIP
    2. If you the look at the text of the hospital course, can you find a list of
    procdures performed using the enclosed snomed-procedure list with code/display with 
    all possible procedures? 
    Add an appropriate date to the procedure YYYY-MM-DD, e.g. 2000-03-16
    within the stay period $start to $end.
    Split the list into "diagnostic" procedures and final 
    "therpeutic" procedures.

    Return the list and only this list in the format
    diagnostic|date|text|snomed-code|snomed-display
    therapeutic|date|text|snomed-code|snomed-display
    
    Embrace the list with this pattern: %%PROCEDURES%%

    Attached is the snomed-procedure.txt file.
AIP;
        } else {
            // STEP 0b: URL-reference mode — point Claude to the public file at synderai.net
            $proceduresPrompt = <<<AIP
    2. If you the look at the text of the hospital course, can you find a list of
    procdures performed using the snomed-procedure list that can be found at
    https://synderai.net/supporting-materials/snomed-procedures.txt
    with code/display with all possible procedures?
    Add an appropriate date to the procedure YYYY-MM-DD, e.g. 2000-03-16
    within the stay period $start to $end.
    Split the list into "diagnostic" procedures and final 
    "therpeutic" procedures.

    Return the list and only this list in the format
    diagnostic|date|text|snomed-code|snomed-display
    therapeutic|date|text|snomed-code|snomed-display
    
    Embrace the list with this pattern: %%PROCEDURES%%
AIP;
        }
    }

    // -------------------------------------------------------------------------
    // Build the main discharge-narrative prompt (Part 1)
    // -------------------------------------------------------------------------
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

    if ($includeProcedures) $prompt = $prompt . "\n" . $proceduresPrompt;
    $prompt = trim($prompt);

    // -------------------------------------------------------------------------
    // STEP 1 (file-upload mode only): Upload snomed-procedures.txt to
    //         Claude's Files API (Beta) to obtain a file_id for attachment.
    // -------------------------------------------------------------------------
    if ($includeProcedures && $USEFILEUPLOAD) {
        $claudeUrl = "https://api.anthropic.com/v1/files";
        $headers   = [
            "x-api-key: " . ANTHROPIC_API_KEY,
            "anthropic-version: 2023-06-01",
            "anthropic-beta: files-api-2025-04-14"
            // NOTE: Do NOT set Content-Type manually for multipart — cURL handles it
        ];

        $ch = curl_init($claudeUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                "file" => new CURLFile($snomedprocs)
                // NOTE: No "purpose" field — Claude's Files API doesn't use it
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $fileData = json_decode($response, true);
        $fileId   = isset($fileData["id"]) ? $fileData["id"] : NULL;  // e.g. "file_011CNha8..."

        // STEP 2a: Build message payload with both text prompt and uploaded file reference
        $payload = [
            "model"      => "claude-sonnet-4-6",
            "max_tokens" => 1024,
            "messages"   => [
                [
                    "role"    => "user",
                    "content" => [
                        [
                            "type" => "text",
                            "text" => $prompt         // Text prompt comes first
                        ],
                        [
                            // File reference — XML/TXT is treated as a "document" block
                            "type"   => "document",
                            "source" => [
                                "type"    => "file",
                                "file_id" => $fileId
                            ]
                        ]
                    ]
                ]
            ]
        ];
    } else {
        // STEP 2b: URL-reference mode — send the text prompt only (no file attachment)
        $payload = [
            "model"      => "claude-sonnet-4-6",
            "max_tokens" => 1024,
            "messages"   => [
                [
                    "role"    => "user",
                    "content" => [
                        [
                            "type" => "text",
                            "text" => $prompt
                        ]
                    ]
                ]
            ]
        ];
    }

    $jsondata = json_encode($payload);

    // -------------------------------------------------------------------------
    // STEP 3: Send the main prompt to Claude and retrieve the response
    // -------------------------------------------------------------------------
    $headers = [
        "Content-Type: application/json",
        "x-api-key: " . ANTHROPIC_API_KEY,
        "anthropic-version: 2023-06-01",
        "anthropic-beta: files-api-2025-04-14"   // required even in URL-reference mode
    ];

    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
    curl_setopt($ch, CURLOPT_POST,           TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $jsondata);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    // Correct extraction using the Anthropic response schema:
    //   $result["content"][0]["text"]

    $result = json_decode($response, true);
    $valid = isset($result["content"][0]["text"]);
    
    if (!$valid) echo "+++ AI returned no content: " . $result["error"]["message"] . "\n";

    return [
         'text'  => $valid ? $result["content"][0]["text"] : "",
         'code'  => $code,
         'error' => $error
    ];
}


/**
 * Ask GPT-4.1 to add a missing target.measure LOINC code to a FHIR FSH Goal.
 *
 * When getAIGoals() generates FHIR Goal instances, the target.measure element
 * (which must reference a verified LOINC code) is occasionally omitted — either
 * because the model could not confirm a valid LOINC code at generation time, or
 * because it judged the goal type to be non-quantifiable. This function provides
 * a second-pass correction by submitting the incomplete FSH back to GPT-4.1 and
 * asking it to supply the missing target.measure binding.
 *
 * The prompt instructs the model to:
 *   - Identify any Goal instance that lacks a target.measure element.
 *   - Add a target.measure with an appropriate LOINC code and display name,
 *     using the pattern: * target.measure = http://loinc.org#{code} "{display}"
 *   - Verify the suggested LOINC code against the FHIR LOINC CodeSystem lookup
 *     endpoint before emitting it. If the code cannot be confirmed, the model is
 *     instructed to omit the target.measure element entirely rather than emit an
 *     unverified code. This matches the verification behaviour of getAIGoals().
 *   - Return the corrected FSH text only — no surrounding prose or markdown.
 *
 * @param  string $oldfsh  The incomplete FSH string containing one or more FHIR
 *                         Goal instances that are missing a target.measure element.
 *                         Typically the 'text' value returned by getAIGoals().
 *
 * @return array  Associative array:
 *                  'text'  => string  Corrected FSH with target.measure added,
 *                                     or "" if the AI response was malformed.
 *                  'code'  => int     HTTP status code from the OpenAI API call.
 *                  'error' => string  cURL error string (empty on success).
 */
function fixMissingTargetMeasurewithAI($olddesc, $oldfsh) {

    // The prompt embeds the incomplete FSH directly so the model has full context.
    // LOINC verification is explicitly required before any target.measure is emitted,
    // matching the verification behaviour of getAIGoals().
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
    $prompt = trim($prompt);

    $payload = [
        "model"       => "gpt-4.1",
        "messages"    => [["role" => "user", "content" => $prompt]],
        "temperature" => 0   // deterministic output for reproducible FSH corrections
    ];

    $jsondata = json_encode($payload);
    $headers  = [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPEN_AI_API_KEY
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
    curl_setopt($ch, CURLOPT_POST,           TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $jsondata);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $phpres = json_decode($response, TRUE);

    // Validate all three nested keys before accessing the content
    $valid = TRUE;
    if (!isset($phpres['choices'])) {
        echo "+++ AI returned no value for \$phpres['choices'] fixMissingTargetMeasurewithAI\n";
        $valid = FALSE;
    }
    if (!isset($phpres['choices'][0]['message'])) {
        echo "+++ AI returned no value for \$phpres['choices'][0]['message'] fixMissingTargetMeasurewithAI\n";
        $valid = FALSE;
    }
    if (!isset($phpres['choices'][0]['message']['content'])) {
        echo "+++ AI returned no value for \$phpres['choices'][0]['message']['content'] fixMissingTargetMeasurewithAI\n";
        $valid = FALSE;
    }

    return [
        'text'  => $valid ? $phpres['choices'][0]['message']['content'] : "",
        'code'  => $code,
        'error' => $error
    ];
}

/**
 * DEPRECATED — Hospital discharge report generator using OpenAI (non-functional).
 *
 * This function is the original OpenAI GPT-4.1 implementation of the hospital
 * course generator and has been superseded by {@see getAIHospitalCourse()},
 * which uses the Anthropic Claude API instead.
 *
 * It is retained in the codebase for reference only and MUST NOT be called in
 * production. It contains active debug statements (var_dump / exit) that would
 * halt execution immediately.
 *
 * What it attempted to do:
 *   1. Upload snomed-procedures.txt to the OpenAI Files API
 *      (endpoint: https://api.openai.com/v1/files, purpose: "assistants").
 *   2. Submit the discharge prompt + file reference to the OpenAI Responses
 *      API (endpoint: https://api.openai.com/v1/responses) using the
 *      "input_file" content block.
 *   3. Parse and return the AI-generated discharge narrative.
 *
 * Known issues that prevented it from working:
 *   - var_dump($response) and var_dump($phpres);exit; halt execution after
 *     each API call.
 *   - The Responses API endpoint and payload shape differ from the Chat
 *     Completions API; the validation block still checks $phpres['choices']
 *     which is not present in Responses API replies.
 *
 * @deprecated  Use {@see getAIHospitalCourse()} instead.
 *
 * @param  int|string $patage         Patient age in years.
 * @param  string     $patgender      Patient gender.
 * @param  array      $encounterinfo  Single encounter record with keys:
 *                                      'reason'    => ['code', 'display']
 *                                      'discharge' => ['text', 'code', 'display']
 *
 * @return array  Would return ['text', 'code', 'error'] on success, but
 *                execution is halted by debug statements before any return.
 */
function unused_getAIHospitalCourse($patage, $patgender, $encounterinfo) {
    // This function uses ChatGPT and has been replaced by getAIHospitalCourse() (Claude).

    $reasoncode       = $encounterinfo["reason"]["code"];
    $reasondisplay    = $encounterinfo["reason"]["display"];
    $dischargetext    = $encounterinfo["discharge"]["text"];
    $dischargecode    = $encounterinfo["discharge"]["code"];
    $dischargedisplay = $encounterinfo["discharge"]["display"];
    $snomedprocs      = MAPPINGS . "/snomed-procedures.txt";

    $prompt = <<<AIP
    You are a doctor in a hospital and are doing the patient discharge management.

    A $patage year old $patgender patient was admitted for the reason $reasondisplay (SNOMED: $reasoncode).
    The patient was finally discharged with $dischargetext (ICD-10: $dischargecode $dischargedisplay).
    
    1. Invent a text authored by you as treating hospital physician back
    to the primary care doctor of the patient (inter-colleague discharge report). 
    The text should briefly summarize diagnostic assement folling the admission reason.
    and the treatment in hospital.
      
    Return the text only with no headline, pure text and not more than 500 words.
    Embrace the text with this pattern: %%TEXT%%
    
    2. If you the look at the text of the hospital course, can you find a list of
    procdures performed using the enclosed snomed-procedure XML with code/display with 
    all possible procedures? Split the list into "diagnostic" procedures and final 
    "therpeutic" procedures.

    Return the list and only this list in the format
    diagnostic|text|snomed-code|snomed-display respectively
    therapeutic|text|snomed-code|snomed-display respectively
    
    Embrace the list with this pattern: %%PROCEDURES%%

    Attached is the XML.
AIP;
    $prompt = trim($prompt);
    // var_dump($prompt);  // DEBUG — halts-adjacent; remove before any reuse

    // STEP 1: Upload snomed-procedures.txt to OpenAI Files API
    $headers = [
        "Content-Type: multipart/form-data",
        "Authorization: Bearer " . OPEN_AI_API_KEY
    ];

    $ch = curl_init("https://api.openai.com/v1/files");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            "file"    => new CURLFile($snomedprocs),
            "purpose" => "assistants"
        ]
    ]);

    $response = curl_exec($ch);
    curl_close($ch);
    // var_dump($response);  // DEBUG — halts execution here in practice

    $fileData = json_decode($response, true);
    $fileId   = $fileData["id"];

    // STEP 2: Submit prompt + file reference to OpenAI Responses API
    $payload = [
        "model" => "gpt-4.1",
        "input" => [
            [
                "role"    => "user",
                "content" => [
                    ["type" => "input_text", "text" => $prompt],
                    ["type" => "input_file", "file_id" => $fileId]
                ]
            ]
        ],
        "temperature" => 0
    ];

    $jsondata = json_encode($payload);
    $headers  = [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPEN_AI_API_KEY
    ];

    $ch = curl_init("https://api.openai.com/v1/responses");
    curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
    curl_setopt($ch, CURLOPT_POST,           TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $jsondata);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $phpres = json_decode($response, TRUE);
    // var_dump($phpres); exit;  // DEBUG — halts execution; function never returns

    // NOTE: The validation below uses $phpres['choices'] (OpenAI Chat Completions schema)
    // but the Responses API returns a different structure — this would always fail.
    $valid = TRUE;
    if (!isset($phpres['choices'])) {
        echo "+++ AI returned no value for \$phpres['choices'] unused_getAIHospitalCourse\n";
        $valid = FALSE;
    }
    if (!isset($phpres['choices'][0]['message'])) {
        echo "+++ AI returned no value for \$phpres['choices'][0]['message'] unused_getAIHospitalCourse\n";
        $valid = FALSE;
    }
    if (!isset($phpres['choices'][0]['message']['content'])) {
        echo "+++ AI returned no value for \$phpres['choices'][0]['message']['content'] unused_getAIHospitalCourse\n";
        $valid = FALSE;
    }

    return [
        'text'  => $valid ? $phpres['choices'][0]['message']['content'] : "",
        'code'  => $code,
        'error' => $error
    ];
}


/**
 * Apply post-processing corrections to AI-generated FHIR Shorthand (FSH) output.
 *
 * Large language models occasionally emit UCUM unit codes without the required
 * curly-brace delimiters (e.g. "#tbl" instead of "#{tbl}"). This function
 * corrects the known offenders by normalising them to the UCUM annotation
 * syntax required by the FHIR FSH specification.
 *
 * Examples for orrections applied:
 *   #events/hr  →  #{events/h}   (heart-rate unit, non-standard abbreviation)
 *   #events/h   →  #{events/h}   (heart-rate unit, missing braces)
 *   #tbl        →  #{tbl}        (tablet dose form, missing braces)
 *   #cap        →  #{cap}        (capsule dose form, missing braces)
 *
 * The replacements are applied in a safe order: the longer pattern
 * "#events/hr" is corrected before "#events/h" to avoid a partial match
 * leaving a trailing "r" in the output.
 *
 * @param  string $fsh  Raw FSH string as returned by the AI (e.g. from
 *                      getAIsuggestedMedicationDosage() or getAIGoals()).
 *
 * @return string  The corrected FSH string with all known unit-code issues fixed.
 */
function applyCorrectionsOnAIflawsInFSH($fsh) {
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