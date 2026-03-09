# manual validation
# java -jar validator_cli.jar -version 4.0 ./validation/*.json -ig hl7.fhir.eu.hdr#dev -profile http://hl7.eu/fhir/hdr/StructureDefinition/bundle-eu-hdr -html-output ./validation/validation.html
# KH 20250512 20250901 20260309

# validates all Bundle*json in the corresponding directory, we move to that source otherwise the validator fails
# output to HTML in HOME directory

case "$1" in
    LAB)
        OUT=validation-LAB.html
        SDIR=../RECENT-RESULTS/LAB
        FILES=Bundle-*.json
        IG='hl7.fhir.eu.laboratory#current'
        PROFILE=http://hl7.eu/fhir/laboratory/StructureDefinition/Bundle-eu-lab	
        ;;
    EPS)
        OUT=validation-EPS.html
        SDIR=../RECENT-RESULTS/EPS
        FILES=Bundle-*.json
        IG='hl7.fhir.eu.eps#current'
        PROFILE=http://hl7.eu/fhir/eps/StructureDefinition/bundle-eu-eps
        ;;
    HDR)
        OUT=validation-HDR.html
        SDIR=../RECENT-RESULTS/HDR
        FILES=Bundle-*.json
        IG='hl7.fhir.eu.hdr#current'
        PROFILE=http://hl7.eu/fhir/hdr/StructureDefinition/bundle-eu-hdr
        ;;
esac
if [ -z "$OUT" ]
then
   echo "Must specify spec to validate LAB, EPS or HDR";
   exit;
fi

# store HOME directory
HOME=`pwd`

# the validator in HOME
VALIDATOR=$HOME/validator_cli.jar

# color presets
RED='\033[0;31m'   # Red
GREEN='\033[0;32m' # Green
BLUE='\033[0;34m'  # Blue
WHITE='\033[0;37m' # White
REDBG='\033[41m'   # Red bg
GREENBG='\033[42m' # Green bg
BLUEBG='\033[44m'  # Blue bg
NC='\033[m'        # No Color

# now go to the source folder
cd $SDIR

echo "${WHITE}${BLUEBG}VALIDATING INSTANCES OF IG $IG$NC"
echo "---------------------------$BLUE"
ls $FILES
echo "$NC---------------------------"
java -jar $VALIDATOR -version 4.0.1 -locale en-US $FILES -ig $IG -profile $PROFILE -html-output $HOME/$OUT
if [ $? -eq 0 ]; then
    echo ${WHITE}${GREENBG}VALIDATION SUCCEEDED $NC
else
    echo ${WHITE}${REDBG}VALIDATION FAILED $NC
fi