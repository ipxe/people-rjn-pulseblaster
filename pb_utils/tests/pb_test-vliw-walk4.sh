#!/bin/bash
#This makes the pulseblaster do a 4-way walking LEDs thing at 1Hz.
#It tests pb_start, pb_stop, and pb_prog for programming vliw files.

if [ $# -ge 1 ] ; then
        echo "This tests vliw programming (pb_prog, pb_start, pb_stop)."
        echo "It takes no arguments."
        exit 1
fi


echo "Now starting 1Hz, 4-way walking LEDs. This tests vliw programming (pb_prog, pb_start, pb_stop)"

#This file could be either in the source directory, or in the installed directory.
VLIWFILE=$(dirname $0)/../vliw_examples/walking_4leds_1Hz.vliw
if [ ! -f "$VLIWFILE" ] ;then
	VLIWFILE=/usr/local/share/doc/pb_utils/vliw_examples/walking_4leds_1Hz.vliw
	if [ ! -f "$VLIWFILE" ] ;then
		echo "Cannot find the .vliw file to load."
		exit 1
	fi
fi

echo -e "\n\npb_init 0x00"
if ! pb_init 0x00 ; then
	echo "pb_init failed"
	exit 1
fi

echo -e "\n\npb_prog $VLIWFILE"
if ! pb_prog $VLIWFILE ;then
	echo pb_prog failed
	exit 1
fi
	
echo -e "\n\npb_start"
if ! pb_start ; then
	echo pb_start failed
	exit 1
fi

echo -e "\n\nsleep 20"
sleep 20

echo -e "\n\npb_stop"
if ! pb_stop; then
	echo pb_stop failed
	exit 1
fi

echo success
exit 0
