#!/bin/bash
#This is a demo version of pb_test-pbsrc-walk5.sh, to create static output for the web.

if [ $# -ge 1 ] ; then
        echo "This is a demo, designed to produce static output for a webpage."
        echo "It takes no arguments."
        exit 1
fi

#The source and pb_parse files could be either in the source directory, or in the installed directory.
PBSRCFILE=$(dirname $0)/walking_5leds_5Hz.pbsrc
PBPARSE=$(dirname $0)/../src/pb_parse.php
if [ ! -f "$PBSRCFILE" ] ;then
	PBSRCFILE=/usr/local/share/doc/pb_parse/pbsrc_examples/good/walking_5leds_5Hz.pbsrc
	PBPARSE=pb_parse
	if [ ! -f "$PBSRCFILE" ] ;then
		echo "Cannot find the .pbsrc file to load."
		exit 1
	fi
fi

#Send just the simulation output to stdout. NB it has terminal colour codes in it. Keep stderr and trim off with head/tail so that we can have the headings.
SIM_OUT=$( $PBPARSE -xsp -u 20 -i $PBSRCFILE -o /dev/null 2>&1 | tail -n +10 | head -n -10 ) 

#ANSI fixes. See the colour list defined in pb_parse.php. Or Wikipedia -> Ansi Escape Code. Then escape as necessary.
a_red=$'\033\[1;31m';		f_red="<font color='#FF5454'>"
a_green=$'\033\[1;32m';		f_green="<font color='#54FF54'>"
a_blue=$'\033\[1;34m';		f_blue="<font color='#5454FF'>"
a_dblue=$'\033\[34m';		f_dblue="<font color='#1818B2'>"
a_magenta=$'\033\[35m';		f_magenta="<font color='#B218B2'>"
a_amber=$'\033\[33m';		f_amber="<font color='#B26818'>"
a_cyan=$'\033\[36m';		f_cyan="<font color='#18B2B2'>"
a_dred=$'\033\[0;31m';		f_dred="<font color='#B21818'>"
a_bmagenta=$'\033\[1;35m';	f_bmagenta="<font color='#FF54FF'>"
a_grey=$'\033\[1;30m';		f_grey="<font color='#686868'>"
a_normal=$'\033\[0m';		f_normal="<\/font>"

HTML_OUT=$(echo "$SIM_OUT" | sed -e "s/$a_red/$f_red/g" -e "s/$a_green/$f_green/g" -e "s/$a_blue/$f_blue/g" -e "s/$a_dblue/$f_dblue/g" -e "s/$a_magenta/$f_magenta/g" -e "s/$a_amber/$f_amber/g" -e "s/$a_cyan/$f_cyan/g" -e "s/$a_dred/$f_dred/g" -e "s/$a_bmagenta/$f_bmagenta/g" -e "s/$a_grey/$f_grey/g" -e "s/$a_normal/$f_normal/g" )

#HTML output
echo '<!DOCTYPE HTML><html><head><meta charset="utf-8"><link rel="stylesheet" type="text/css" href="../style.css"><title>PulseBlaster Simulation Demo</title></head><body class=program text="#fff" bgcolor="#000"><pre>'
echo "$HTML_OUT"
echo '</pre></html></body>'


