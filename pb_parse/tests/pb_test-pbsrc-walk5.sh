#!/bin/bash
#This makes the pulseblaster do a 5-way paired walking LEDs thing at 5Hz.
#It tests all the utilities and pb_parse. So if this works, the entire suite of utilities is tested as good!

if [ $# -ge 1 ] ; then
        echo "This is a test of the entire suite of pulseblaster utilities and parser."
	echo "It compiles an example 5-way walking LED pbsrc file, and runs it."
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

VLIWFILE=/tmp/walking_5leds_5Hz.vliw

echo "Now starting 5Hz, 5-way paired walking LEDs. This tests everything (pb_parse, pb_init, pb_prog, pb_start, pb_stop)"
echo "If this works OK, the entire pb_* environment is correctly installed and working."

echo -e "\n\n#1. Parsing a .pbsrc file with pb_parse, and writing a .vliw file; also simulating 50 instructions..."
echo -e "$PBPARSE -xsap -u 50 -i $PBSRCFILE -o $VLIWFILE"

if ! $PBPARSE -xsap -u 50 -i $PBSRCFILE -o $VLIWFILE ; then
	echo pb_parse failed
	exit 1
fi

echo -e "\n\n#2. Zeroing the hardware output with pb_init..."
echo -e "pb_init 0x00"
if ! pb_init 0x00 ; then
	echo pb_init failed
	exit 1
fi

echo -e "\n\n#3. Programming the generated .vliw file into the hardware with pb_prog..."
echo -e "pb_prog $VLIWFILE"
if ! pb_prog $VLIWFILE ;then
	echo pb_prog failed
	exit 1
fi

echo -e "\n\n#4. Starting the pulseblaster hardware with pb_start..."
echo -e "pb_start"
if ! pb_start ; then
	echo pb_start failed
	exit 1
fi

echo -e "\n\n#5. Letting it run for 20 seconds..."
echo -e "sleep 20 (running the program on hardware)"
sleep 20

echo -e "\n\n#6. Stopping the hardware with pb_stop..."
echo -e "pb_stop"
if ! pb_stop ; then
	echo pb_stop failed
	exit 1
fi

echo -e "\n\n7. Optional:"
echo -e "Now run it in interactive simulation (at 1/10th speed) if desired: $PBPARSE -xlt -z 0.1 -i $PBSRCFILE -o /dev/null"

rm $VLIWFILE	#clean up
echo -e "\nSuccess!\n"

exit 0
