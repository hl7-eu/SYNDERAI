#!/bin/bash
# run_synthea_europe.sh
# Usage: ./make_synthea_europe_filtered.sh [total_patients]

TOTAL=${1:-10000}
COUNT=0
SKIPPED=0
ERRORS=0
SUCCESS=0
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REGIONS_DIR="${SCRIPT_DIR}/output/regions"
MERGED_DIR="${SCRIPT_DIR}/output/merged"
PROPS_FILE="/tmp/synthea_region.properties"

# --- FILTER: only run regions that have backing geography data ---
# Region is valid if it appears in the USPS column of zipcodes_europe.csv
# (verified: zipcodes and demographics_europe.csv back the identical 172 regions)
ZIP_CSV="${SCRIPT_DIR}/src/main/resources/geography/zipcodes_europe.csv"
VALID_REGIONS_FILE="/tmp/synthea_valid_regions.txt"
# extract USPS column CSV-correctly (handles quoted names with commas);
# fall back to awk if python3 is unavailable
if command -v python3 >/dev/null 2>&1; then
    python3 -c 'import csv,sys
for r in csv.DictReader(open(sys.argv[1],encoding="utf-8")): print(r["USPS"])' "${ZIP_CSV}" | sort -u > "${VALID_REGIONS_FILE}"
else
    awk -F, 'NR>1{print $2}' "${ZIP_CSV}" | sort -u > "${VALID_REGIONS_FILE}"
fi
echo "Loaded $(wc -l < "${VALID_REGIONS_FILE}" | tr -d ' ') backed regions from geography data."

# --- Normalise the region weights ------------------------------------------
# The run_region weights below are population shares that sum to ~1.409, not 1.
# Without this, "-p 40000" silently produced ~56,000 patients. Read the weights
# out of this script itself and divide by their sum, so TOTAL means TOTAL.
# LC_ALL=C on every awk that touches a number. awk reads and writes decimals
# through LC_NUMERIC: under a comma-decimal locale (de_DE and most of Europe)
# it parses "0.0481930080" as 0, stops at the period, and prints the sum as
# "0,000000000". The weights then sum to zero, every per-region count is a
# division by zero, and each java call gets an empty -p and dies in
# milliseconds - 291 empty region directories and no error anyone reads.
# The prefix is per command on purpose: exporting LC_ALL=C for the whole script
# would also reach the python3 call above, whose output contains region names
# like "Łódź" and "Šiaulių".
WEIGHT_SUM=$(grep -E "^run_region[[:space:]]+['\"]" "${BASH_SOURCE[0]}" \
             | LC_ALL=C awk '{print $NF}' | LC_ALL=C awk '{s+=$1} END{printf "%.9f", s}')
REGION_COUNT=$(grep -cE "^run_region[[:space:]]+['\"]" "${BASH_SOURCE[0]}")
echo "Region weights sum to ${WEIGHT_SUM} across ${REGION_COUNT} regions; normalising to 1."

# Fail once and loudly rather than 291 times and silently.
if ! LC_ALL=C awk -v ws="${WEIGHT_SUM}" 'BEGIN{exit !(ws+0 > 0)}'; then
    echo "ERROR: the region weights summed to '${WEIGHT_SUM}', which cannot be used"
    echo "       as a divisor. This is what a comma-decimal locale does to awk."
    echo "       Current LC_ALL='${LC_ALL-}' LC_NUMERIC='${LC_NUMERIC-}' LANG='${LANG-}'."
    exit 1
fi

# --- Export tunables --------------------------------------------------------
# claims.csv and claims_transactions.csv are ~81% of the CSV volume and are not
# used for prevalence work. Measured with exporter.years_of_history = 0:
#   with    claims: ~680 KB/patient  -> 40,000 patients needs ~62 GB peak
#   without claims: ~130 KB/patient  -> 40,000 patients needs ~11 GB peak
# ("peak" = region output plus the merged copy, which coexist.)
# Set CSV_EXCLUDE="" to keep everything, or override with your own list.
CSV_EXCLUDE="${CSV_EXCLUDE-patient_expenses.csv,claims.csv,claims_transactions.csv}"
# Per-patient CSV footprint in KB, used only for the disk precheck.
KB_PER_PATIENT="${KB_PER_PATIENT-$([ -n "${CSV_EXCLUDE}" ] && echo 135 || echo 700)}"

echo "Generating ${TOTAL} patients across ${REGION_COUNT} EU regions..."
echo "CSV exclusions: ${CSV_EXCLUDE:-<none>}"
echo ""

run_region() {
    local REGION="$1"
    local WEIGHT="$2"
    if ! grep -Fxq -- "$REGION" "${VALID_REGIONS_FILE}"; then
        SKIPPED=$((SKIPPED+1))
        echo "--- SKIP ${REGION}: no geography data (not in zipcodes_europe.csv)"
        return 0
    fi
    # LC_ALL=C for the same reason as the weight sum above: "$WEIGHT" is a
    # decimal literal and must be read with a period.
    local N=$(LC_ALL=C awk -v t="$TOTAL" -v w="$WEIGHT" -v ws="$WEIGHT_SUM" \
              'BEGIN{n=int(t*(w/ws)+0.5); print (n<1)?1:n}')
    if [ -z "${N}" ]; then
        echo "--- FAIL ${REGION}: could not compute a patient count from weight ${WEIGHT}"
        ERRORS=$((ERRORS+1))
        return 1
    fi
    local DIR_NAME=$(echo "${REGION}" | tr " /'" "___")
    local OUT_DIR="${REGIONS_DIR}/${DIR_NAME}"
    mkdir -p "${OUT_DIR}"
    # Write a temp properties file pointing output to the region directory
    {
        echo "exporter.baseDirectory = ${OUT_DIR}/"
        echo "exporter.csv.export = true"
        echo "exporter.fhir.export = false"
        echo "exporter.years_of_history = 0"
        echo "exporter.csv.excluded_files = ${CSV_EXCLUDE}"
    } > "${PROPS_FILE}"
    COUNT=$((COUNT+1))
    echo "*** ${COUNT}. ${REGION}: ${N} patients ... "
    # Run the shadow jar directly. ./run_synthea shells out to "./gradlew run",
    # which starts a Gradle build for every single region - ~291 JVM+Gradle
    # startups per run. The jar is built once above.
    if java -Xmx6g -jar "${JAR}" -p "${N}" -c "${PROPS_FILE}" "${REGION}" \
        > /tmp/synthea_last.log 2>&1; then
        echo "OK"
        SUCCESS=$((SUCCESS+1))
    else
        echo "FAILED"
        echo "    $(grep -m1 "Exception\|ERROR" /tmp/synthea_last.log || echo "see /tmp/synthea_last.log")"
        echo "+++++"
        cat /tmp/synthea_last.log
        echo "+++++"
        ERRORS=$((ERRORS+1))
    fi
}

### everthing starts here

# stop gradle and rebuild
./gradlew --stop
rm -rf build/ .gradle/                            # both gradle's daemon state AND build outputs
./gradlew clean                                   # belt and braces
./gradlew processResources --rerun-tasks          # force the resource copy
# If your fork uses Shadow/fat JAR:
./gradlew shadowJar --rerun-tasks

JAR="${SCRIPT_DIR}/build/libs/synthea-with-dependencies.jar"
if [ ! -f "${JAR}" ]; then
    echo "ERROR: shadow jar not found at ${JAR}"
    exit 1
fi

# The jar bundles src/main/resources/modules. Confirm the R4 work is actually in
# there before generating 40,000 patients against a stale build.
echo "Verifying module set in the jar ..."
MISSING=""
for m in hyperlipidaemia_europe metabolic_syndrome_europe chronic_pain_europe \
         chronic_low_back_pain_europe colon_polyp_europe parkinson_europe \
         other_cancers_europe hiv/hiv_baseline encounter/sdoh_hrsn \
         encounter/depression_screening snf/skilled_nursing_facility \
         allergies/outgrow_food_allergies allergies/severe_allergic_reaction; do
    unzip -l "${JAR}" "modules/${m}.json" 2>/dev/null | grep -q "modules/${m}.json" \
        || MISSING="${MISSING} ${m}"
done
# Knee osteoarthritis must be the FIRST code of Diagnose_Knee_OA, otherwise the
# CSV exporter writes "Joint pain" instead (the codes[0] bug fixed in R4).
if ! unzip -p "${JAR}" modules/osteoarthritis_europe.json 2>/dev/null \
     | python3 -c 'import json,sys; d=json.load(sys.stdin); c=d["states"]["Diagnose_Knee_OA"]["codes"]; sys.exit(0 if c[0]["code"]=="239873007" and len(c)==1 else 1)'; then
    MISSING="${MISSING} osteoarthritis_europe(codes[0]!=239873007)"
fi
if [ -n "${MISSING}" ]; then
    echo "ERROR: the jar is missing or stale for:${MISSING}"
    echo "       Sync SYNDERAI/modules into src/main/resources/modules and rebuild."
    exit 1
fi
echo "  module set OK"

# Every CallSubmodule target must resolve. A missing submodule throws
# RuntimeException at runtime and DISCARDS the patient silently - this is how
# HIV patients vanished from R3 entirely.
echo "Verifying submodule references ..."
python3 - "${SCRIPT_DIR}/src/main/resources/modules" <<'PYEOF' || exit 1
import json, glob, os, sys
root = sys.argv[1]
have = {os.path.relpath(f, root)[:-5] for f in glob.glob(os.path.join(root, "**", "*.json"), recursive=True)
        if "lookup_tables" not in f}
missing = {}
for f in glob.glob(os.path.join(root, "**", "*.json"), recursive=True):
    if "lookup_tables" in f: continue
    try: d = json.load(open(f))
    except Exception as e:
        print(f"  PARSE ERROR {f}: {e}"); sys.exit(1)
    for k, v in d.get("states", {}).items():
        if v.get("type") == "CallSubmodule" and v["submodule"] not in have:
            missing.setdefault(v["submodule"], []).append(os.path.basename(f))
for t, srcs in sorted(missing.items()):
    print(f"  MISSING SUBMODULE {t}  (called by {', '.join(sorted(set(srcs)))})")
sys.exit(1 if missing else 0)
PYEOF
echo "  submodule references OK"

# --- Disk precheck ----------------------------------------------------------
# Region output and the merged copy coexist, so budget twice the raw size.
NEED_MB=$(awk -v t="${TOTAL}" -v kb="${KB_PER_PATIENT}" 'BEGIN{printf "%d", 2*t*kb/1024*1.15}')
AVAIL_MB=$(df -m "${SCRIPT_DIR}" | awk 'NR==2{print $4}')
echo "Disk: need ~${NEED_MB} MB (regions + merged, 15% margin), ${AVAIL_MB} MB available."
if [ "${AVAIL_MB}" -lt "${NEED_MB}" ]; then
    echo "ERROR: not enough free disk space."
    echo "       Either free space, lower TOTAL, or widen CSV_EXCLUDE."
    echo "       A full CSV export costs ~680 KB/patient; excluding claims* costs ~130."
    exit 1
fi

START=$(date +%s)

rm -rf "${REGIONS_DIR}"
rm -rf "${MERGED_DIR}"

run_region 'Nordrhein-Westfalen' 0.0481930080
run_region 'Bayern' 0.0352962876
run_region 'Ile-de-France' 0.0330828216
run_region 'Baden-Württemberg' 0.0300923477
run_region 'Lombardia' 0.0269105072
run_region 'South East UK' 0.0248121693
run_region 'London' 0.0234550265
run_region 'Andalucía' 0.0234084696
run_region 'Auvergne-Rhone-Alpes' 0.0217505844
run_region 'Niedersachsen' 0.0212682759
run_region 'Cataluña' 0.0206096308
run_region 'North West UK' 0.0196216931
run_region 'Comunidad de Madrid' 0.0183196692
run_region 'East of England' 0.0169259259
run_region 'Hauts-de-France' 0.0168155778
run_region 'Hessen' 0.0167431108
run_region 'Lazio' 0.0160490374
run_region 'Occitanie' 0.0159016877
run_region 'Nouvelle-Aquitaine' 0.0159016877
run_region 'West Midlands UK' 0.0158783069
run_region 'Campania' 0.0155627029
run_region 'South West UK' 0.0152486772
run_region 'Grand Est' 0.0151705757
run_region 'Yorkshire and the Humber' 0.0146798942
run_region 'Scotland' 0.0144100529
run_region 'Prov-Alpes-Cote-Azur' 0.0140739075
run_region 'Masovian' 0.0138707314
run_region 'Valencia' 0.0136125339
run_region 'Veneto' 0.0132931421
run_region 'Sicilia' 0.0131310306
run_region 'East Midlands' 0.0130000000
run_region 'Silesian' 0.0122388806
run_region 'Piemonte' 0.0118341387
run_region 'Emilia-Romagna' 0.0118341387
run_region 'Rheinland-Pfalz' 0.0110866544
run_region 'Pays de la Loire' 0.0109666812
run_region 'Sachsen' 0.0108603962
run_region 'Puglia' 0.0103751353
run_region 'Zuid-Holland' 0.0100890246
run_region 'Toscana' 0.0100509123
run_region 'Attiki' 0.0100026455
run_region 'Normandie' 0.0098700131
run_region 'Berlin' 0.0097291022
run_region 'Bretagne' 0.0095044570
run_region 'Norte' 0.0093992456
run_region 'Noord-Holland' 0.0091281651
run_region 'Greater Poland' 0.0088731885
run_region 'Lesser Poland' 0.0086692071
run_region 'Nord-Est RO' 0.0083439153
run_region 'Wales' 0.0082857143
run_region 'Schleswig-Holstein' 0.0076927806
run_region 'Bourgogne-Fr-Comte' 0.0076766768
run_region 'Lower Silesian' 0.0076493004
run_region 'Sud-Muntenia' 0.0075502646
run_region 'Centre-Val-de-Loire' 0.0074938988
run_region 'Lisboa' 0.0074641068
run_region 'Galicia' 0.0072515368
run_region 'Eastern and Midland' 0.0072248677
run_region 'North East UK' 0.0070026455
run_region 'Łódź' 0.0069353657
run_region 'Brandenburg' 0.0067877476
run_region 'Nord-Vest RO' 0.0067804233
run_region 'Noord-Brabant' 0.0067260164
run_region 'Stockholm' 0.0065945021
run_region 'Castilla y León' 0.0063609972
run_region 'Sud-Est RO' 0.0062671958
run_region 'București-Ilfov' 0.0061402116
run_region 'Pomeranian' 0.0061194403
run_region 'Centru RO' 0.0060211640
run_region 'Thüringen' 0.0058827146
run_region 'Sachsen-Anhalt' 0.0058827146
run_region 'País Vasco' 0.0058521174
run_region 'Canarias' 0.0058521174
run_region 'Lublin' 0.0058134683
run_region 'Gelderland' 0.0057651569
run_region 'Subcarpathian' 0.0055074963
run_region 'Podkarpackian' 0.0055074963
run_region 'Yugozapaden' 0.0054735450
run_region 'Castilla-La Mancha' 0.0054704549
run_region 'Centro' 0.0054183886
run_region 'Kuyavian-Pomer' 0.0054055056
run_region 'Wien' 0.0050557850
run_region 'Northern Ireland' 0.0050529101
run_region 'Hovedstaden' 0.0050356552
run_region 'Calabria' 0.0050254562
run_region 'Hamburg' 0.0049776816
run_region 'Sud-Vest Oltenia' 0.0049550265
run_region 'Kentriki Makedonia' 0.0047037037
run_region 'West Pomeranian' 0.0046915709
run_region 'Västra Götaland' 0.0046781511
run_region 'Vest RO' 0.0046507937
run_region 'Helsinki-Uusimaa' 0.0046005291
run_region 'Southern IE' 0.0045079365
run_region 'Budapest' 0.0044497354
run_region 'Sardegna' 0.0043770102
run_region 'Niederösterreich' 0.0043474866
run_region 'Mecklenburg-Vorp' 0.0042989068
run_region 'Antwerpen' 0.0042964913
run_region 'Marche' 0.0042148987
run_region 'Liguria' 0.0042148987
run_region 'Oberösterreich' 0.0039567013
run_region 'Murcia' 0.0039438182
run_region 'Zürich' 0.0039228833
run_region 'Utrecht' 0.0038434380
run_region 'Skåne' 0.0038045205
run_region 'Středočeský' 0.0038042328
run_region 'Warmian-Masurian' 0.0037736549
run_region 'Észak-Alföld' 0.0037354497
run_region 'Jadranska Hrvatska' 0.0037222222
run_region 'Länsi-Suomi' 0.0036296296
run_region 'Yuzhen tsentralen' 0.0036216931
run_region 'Praha' 0.0035899471
run_region 'Abruzzo' 0.0035664528
run_region 'Pest' 0.0035317460
run_region 'Aragon' 0.0034349385
run_region 'Hainaut' 0.0034247394
run_region 'Midtjylland' 0.0033729389
run_region 'Holy Cross' 0.0033656922
run_region 'Overijssel' 0.0033630082
run_region 'Pohjois- ja Itä-Suomi' 0.0033518519
run_region 'Steiermark' 0.0033460992
run_region 'Syddanmark' 0.0033254327
run_region 'Podlaskie' 0.0032637015
run_region 'Friuli-V-Giulia' 0.0032422298
run_region 'Jihomoravský' 0.0032328042
run_region 'Oost-Vlaanderen' 0.0032068014
run_region 'Brussels Capital' 0.0032068014
run_region 'Dél-Alföld' 0.0031164021
run_region 'Moravskoslezský' 0.0031137566
run_region 'Etelä-Suomi' 0.0029682540
run_region 'Flemish Brabant' 0.0029577295
run_region 'Liège' 0.0029265955
run_region 'Extremadura' 0.0029260587
run_region 'Baleares' 0.0029260587
run_region 'Trentino-AA' 0.0029180068
run_region 'Észak-Magyarország' 0.0029047619
run_region 'West-Vlaanderen' 0.0028643275
run_region 'Viken' 0.0028407086
run_region 'Bern' 0.0028254100
run_region 'Asturias' 0.0027988361
run_region 'Panonska Hrvatska' 0.0027777778
run_region 'Opole' 0.0027537481
run_region 'Lubusz' 0.0027537481
run_region 'Yugoiztochen' 0.0027354497
run_region 'Saarland' 0.0027150990
run_region 'Nyugat-Dunántúl' 0.0026772487
run_region 'Közép-Dunántúl' 0.0026640212
run_region 'Severoiztochen' 0.0024629630
run_region 'Umbria' 0.0024316723
run_region 'Kýpros' 0.0024285714
run_region 'Sjælland' 0.0023436383
run_region 'Dél-Dunántúl' 0.0022936508
run_region 'Northern and Western' 0.0022486772
run_region 'Vilniaus' 0.0022142857
run_region 'Prešovský' 0.0021798942
run_region 'Ústecký' 0.0021375661
run_region 'Košický' 0.0021243386
run_region 'Vaud' 0.0021015446
run_region 'Limburg' 0.0020548436
run_region 'Grad Zagreb' 0.0020343915
run_region 'Severen tsentralen' 0.0020238095
run_region 'Sjeverna Hrvatska' 0.0019708995
run_region 'Tirol' 0.0019295025
run_region 'Friesland' 0.0019217190
run_region 'Bratislavský' 0.0019100529
run_region 'Alentejo' 0.0018798491
run_region 'Thessalia' 0.0018703704
run_region 'Severozapaden' 0.0018333333
run_region 'Žilinský' 0.0018280423
run_region 'Bremen' 0.0018100660
run_region 'Navarra' 0.0017810792
run_region 'Nordjylland' 0.0017577287
run_region 'Nitriansky' 0.0017539683
run_region 'Oslo' 0.0017392094
run_region 'Aargau' 0.0017279367
run_region 'Dytiki Ellada' 0.0017195767
run_region 'Jihočeský' 0.0017010582
run_region 'Banskobystrický' 0.0016693122
run_region 'Olomoucký' 0.0016507937
run_region 'Kriti' 0.0016507937
run_region 'Põhja-Eesti' 0.0016402116
run_region 'Rīga' 0.0016269841
run_region 'Basilicata' 0.0016211149
run_region 'Plzeňský' 0.0015899471
run_region 'Anatoliki Makedonia, Thraki' 0.0015740741
run_region 'Peloponnisos' 0.0015423280
run_region 'Cantabria' 0.0015266393
run_region 'Zlínský' 0.0015185185
run_region 'Trenčiansky' 0.0015158730
run_region 'Kärnten' 0.0015142931
run_region 'Kauno' 0.0015079365
run_region 'Trnavský' 0.0015052910
run_region 'Osrednjeslovenska' 0.0015026455
run_region 'Vestland' 0.0014928214
run_region 'Namur' 0.0014632977
run_region 'Groningen' 0.0014412892
run_region 'Drenthe' 0.0014412892
run_region 'Salzburg' 0.0014410208
run_region 'Královéhradecký' 0.0014338624
run_region 'Sterea Ellada' 0.0014021164
run_region 'Malta' 0.0013862434
run_region 'Pardubický' 0.0013730159
run_region 'Rogaland' 0.0013623807
run_region 'Vestfold-Telem' 0.0013478872
run_region 'Vysočina' 0.0013359788
run_region 'St. Gallen' 0.0013309783
run_region 'Genève' 0.0013076278
run_region 'Trøndelag' 0.0012754202
run_region 'Östergötland' 0.0012681735
run_region 'Algarve' 0.0011887254
run_region 'Brabant Wallon' 0.0011830918
run_region 'Liberecký' 0.0011666667
run_region 'Uppsala' 0.0011272653
run_region 'Corse' 0.0010966681
run_region 'Luzern' 0.0010741228
run_region 'Vorarlberg' 0.0010502328
run_region 'Innlandet' 0.0010435229
run_region 'Jönköping' 0.0010427204
run_region 'Pierīga' 0.0009788360
run_region 'Zeeland' 0.0009608595
run_region 'Flevoland' 0.0009608595
run_region 'Halland' 0.0009581755
run_region 'Ticino' 0.0009340198
run_region 'Fribourg' 0.0009106693
run_region 'Notio Aigaio' 0.0008994709
run_region 'La Rioja' 0.0008905396
run_region 'Valais' 0.0008873188
run_region 'Agder' 0.0008696047
run_region 'Ipeiros' 0.0008677249
run_region 'Podravska' 0.0008571429
run_region 'Örebro' 0.0008454490
run_region 'Gävleborg' 0.0008454490
run_region 'Lõuna-Eesti' 0.0008333333
run_region 'Møre og Romsd' 0.0008261244
run_region 'Klaipėdos' 0.0008201058
run_region 'Molise' 0.0008105574
run_region 'Burgenland' 0.0008059947
run_region 'Södermanland' 0.0007890857
run_region 'Dalarna' 0.0007890857
run_region 'Västerbotten' 0.0007609041
run_region 'Karlovarský' 0.0007486772
run_region 'Thurgau' 0.0007472159
run_region 'Solothurn' 0.0007472159
run_region 'Luxembourg' 0.0007160819
run_region 'Västernorrland' 0.0007045408
run_region 'Norrbotten' 0.0007045408
run_region 'Kalmar' 0.0007045408
run_region 'Dytiki Makedonia' 0.0007010582
run_region 'Nordland' 0.0006956837
run_region 'Madeira' 0.0006911210
run_region 'Savinjska' 0.0006825397
run_region 'Troms-Finnmark' 0.0006666969
run_region 'Šiaulių' 0.0006666667
run_region 'Latgale' 0.0006560847
run_region 'Höfuðborgarsvæði' 0.0006534392
run_region 'Kurzeme' 0.0006269841
run_region 'Açores' 0.0006081865
run_region 'Zemgale' 0.0005978836
run_region 'Graubünden' 0.0005837624
run_region 'Basel-Stadt' 0.0005837624
run_region 'Panevėžio' 0.0005582011
run_region 'Voreio Aigaio' 0.0005529101
run_region 'Gorenjska' 0.0005529101
run_region 'Ionia Nisia' 0.0005423280
run_region 'Kronoberg' 0.0005354510
run_region 'Neuchâtel' 0.0005137109
run_region 'Vidzeme' 0.0004947090
run_region 'Schwyz' 0.0004670099
run_region 'Lääne-Eesti' 0.0004153439
run_region 'Jugovzhodna Slovenija' 0.0003809524
run_region 'Marijampolės' 0.0003703704
run_region 'Telšių' 0.0003597884
run_region 'Alytaus' 0.0003597884
run_region 'Kirde-Eesti' 0.0003386243
run_region 'Utenos' 0.0003306878
run_region 'Landsbyggð' 0.0003253968
run_region "Valle dAosta" 0.0003242230
run_region 'Kesk-Eesti' 0.0003201058
run_region 'Goriška' 0.0003121693
run_region 'Obalno-kraška' 0.0003095238
run_region 'Pomurska' 0.0002989418
run_region 'Ceuta' 0.0002544399
run_region 'Tauragės' 0.0002486772
run_region 'Schaffhausen' 0.0002335050
run_region 'Posavska' 0.0001984127
run_region 'Koroška' 0.0001851852
run_region 'Zasavska' 0.0001507937
run_region 'Primorsko-notranjska' 0.0001375661
run_region 'Gozo and Comino' 0.0000978836
run_region 'Obwalden' 0.0000934020
run_region 'Åland' 0.0000793651

END=$(date +%s)
echo ""
echo "Generation done in $((END-START))s — ${SUCCESS} OK, ${ERRORS} failed, ${SKIPPED} skipped (no data)"
echo "Merging CSV files..."

mkdir -p "${MERGED_DIR}/csv"

# Collect the UNION of all CSV filenames across ALL region directories
# This handles regions that may be missing some CSV files (e.g. no allergies)
CSV_FILES=$(find "${REGIONS_DIR}" -name "*.csv" -path "*/csv/*" | xargs -I{} basename {} | sort -u)
 
if [ -z "${CSV_FILES}" ]; then
    echo "ERROR: No CSV output found in ${REGIONS_DIR}"
    exit 1
fi

# Merge each CSV type: header from first region that has it, data rows from all
for BASENAME in ${CSV_FILES}; do
    MERGED="${MERGED_DIR}/csv/${BASENAME}"
    echo "  Merging ${BASENAME} ... "
    FIRST=1
    for REGION_DIR in "${REGIONS_DIR}"/*/; do
        SRC="${REGION_DIR}csv/${BASENAME}"
        if [ -f "${SRC}" ]; then
            if [ ${FIRST} -eq 1 ]; then
                cat "${SRC}" > "${MERGED}"
                FIRST=0
            else
                tail -n +2 "${SRC}" >> "${MERGED}"
            fi
        fi
    done
    COUNT=$(tail -n +2 "${MERGED}" | wc -l | tr -d " ")
    echo "    ${COUNT} records"
done

mkdir -p "${MERGED_DIR}/fhir"
find "${REGIONS_DIR}" -name "*.json" -path "*/fhir/*" | while read f; do
    cp "${f}" "${MERGED_DIR}/fhir/"
done
FHIR_COUNT=$(find "${MERGED_DIR}/fhir" -name "*.json" | wc -l | tr -d " ")

echo ""
TOTAL_PATIENTS=$(tail -n +2 "${MERGED_DIR}/csv/patients.csv" | wc -l | tr -d " ")
echo "✅ Done! ${TOTAL_PATIENTS} patients in ${MERGED_DIR}/csv/"
echo "   FHIR bundles: ${FHIR_COUNT} in ${MERGED_DIR}/fhir/"
rm -f "${PROPS_FILE}"
