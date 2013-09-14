#Stub. pb_utils.1 already exists. So just create the html version.
BZIP2_FILE=$(dirname $0)/pb_utils.1.bz2
COMPRESS=bzip2

#Also create the HTML version,fixing spacing, and munging email addresses.
cat $BZIP2_FILE | $COMPRESS -d | man2html -r - | tail -n +3 | sed -e 's/<BODY>/<BODY><STYLE>\*\{font-family:monospace\}<\/STYLE>/' -re 's/\b([a-z0-9_.+-]*)@([a-z0-9_.+-]*)\b/\1#AT(spamblock)#\2/ig' > ${BZIP2_FILE%.bz2}.html
