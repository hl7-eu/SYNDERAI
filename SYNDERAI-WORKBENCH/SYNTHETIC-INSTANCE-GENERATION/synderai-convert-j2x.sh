# convert generated FHIR JSON to FHIR XML
# KH 20260314

case "$1" in
    LAB)
        ARTIFACT=LAB
        ;;
    EPS)
        ARTIFACT=EPS
        ;;
    HDR)
        ARTIFACT=HDR
        ;;
esac
if [ -z "$ARTIFACT" ]
then
   echo "Must specify artifact: LAB, EPS or HDR";
   exit;
fi

# store HOME directory
HOME=`pwd`

RRDIR=../RECENT-RESULTS

# go to recent results foles
cd $RRDIR

php convertall-json2xml.php ${ARTIFACT}
php create-fhir-package.php ${ARTIFACT}

# color presets
RED='\033[0;31m'   # Red
GREEN='\033[0;32m' # Green
BLUE='\033[0;34m'  # Blue
WHITE='\033[0;37m' # White
REDBG='\033[41m'   # Red bg
GREENBG='\033[42m' # Green bg
BLUEBG='\033[44m'  # Blue bg
NC='\033[m'        # No Color

cd $HOME
# rename your own log file to _convert_${ARTIFACT}_done.txt as a semphore
mv _convert_${ARTIFACT}_log.txt _convert_${ARTIFACT}_done.txt