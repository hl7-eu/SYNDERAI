# KH 20250512 20250901 20260309
# copy artifacts Bundles from PEX to the associated master copy FSH input
# run sushi
# copy results and
# validate them

case "$1" in
    LAB)
        ARTIFACT=LAB
        MASTERCOPY=LAB-master-copy
        ;;
    EPS)
        ARTIFACT=EPS
        MASTERCOPY=EPS-master-copy
        ;;
    HDR)
        ARTIFACT=HDR
        MASTERCOPY=HDR-master-copy
        ;;
esac
if [ -z "$ARTIFACT" ]
then
   echo "Must specify spec to validate LAB, EPS or HDR";
   exit;
fi

SIC=`pwd`

echo "Post-processing I: Running sushi for FSH to FHIR for artifact type ${ARTIFACT}"
# delete old stuff
rm -rf ../FSH-FHIR-GENERATOR/${MASTERCOPY}/input/fsh/examples/*.fsh
# copy freshly generated JSON files
cp pex/${ARTIFACT}/*.fsh ../FSH-FHIR-GENERATOR/${MASTERCOPY}/input/fsh/examples/
# go to the master copy directory
cd ../FSH-FHIR-GENERATOR/${MASTERCOPY}/
pwd
# run sushi compiler
sushi

echo "Post-processing II: Copying Bundles to results for artifact type ${ARTIFACT}"
# go back to home
cd $SIC
# empty previous stuff in recent results directory for this artifact
rm -rf ../RECENT-RESULTS/${ARTIFACT}/Bundle*.json
rm -rf ../RECENT-RESULTS/${ARTIFACT}/Bundle*.xml
# copy all sushi results to the recent results directory
cp ../FSH-FHIR-GENERATOR/${MASTERCOPY}/fsh-generated/resources/Bundle*.json ../RECENT-RESULTS/${ARTIFACT}/

echo "Post-processing III: Converting JSON Bundles to XML for artifact type ${ARTIFACT} (as background task)"
# home
# this batch will log to a file named _convert_${ARTIFACT}_log.txt as a semaphore
# when finished the batch renames the file to _convert_${ARTIFACT}_done.txt to indicate finish
rm -rf _convert_${ARTIFACT}_done.txt
cd $SIC
nohup sh synderai-convert-j2x.sh ${ARTIFACT} > _convert_${ARTIFACT}_log.txt 2>&1 &

echo "Post-processing IV: Validating all instances for ${ARTIFACT}  (as background task)"
# home
# this batch will produce a file named _validate_${ARTIFACT}_done.txt as a semphore, later wait for it...
rm -rf _validate_${ARTIFACT}_done.txt
cd $SIC
# this batch will log to a file named _validate_${ARTIFACT}_log.txt as a semaphore
# when finished the batch renames the file to _validate_${ARTIFACT}_done.txt to indicate finish
nohup sh synderai-validate.sh ${ARTIFACT} > _validate_${ARTIFACT}_log.txt 2>&1 &

# now wait for all semphore files to appear until finish and exit
V=""
C=""
while [ "$V" = "" ] | [ "$C" = "" ]
do
    echo "... waiting for semaphores to finish..."
    if [ -f _validate_${ARTIFACT}_done.txt ]
    then
        V="V"
        echo "... validation done"
    fi
    if [ -f _convert_${ARTIFACT}_done.txt ]
    then
        C="C"
        echo "... conversion done"
    fi
    sleep 10
done
echo "... semaphores finished..." 
