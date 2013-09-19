#!/bin/bash
#This sends 250Hz pulses to the physical parallel port; it doesn't use the actual PulseBlaster at all!
#It tests pb_parse, and pb_parport-output.

if [ $# -ge 1 ] ; then
        echo "This is a test of pb_parse, and pb_parport-output."
	echo "It compiles an example 250Hz flashing LED pbsrc file, and runs it via the physical parallel port(s)."
	echo "This uses the physical parports, /dev/parport0 etc, to form a 'poor-man's pulseblaster'; it doesn't use the real PulseBlaster!"
	echo "It's useful to evaluate the performance and jitter of the simulation: try measuring it with an oscilloscope."
        echo "USAGE: `basename $0`	#No arguments are needed, except -h for help)."
        exit 1
fi

#The source and pb_parse files could be either in the source directory, or in the installed directory.
PBSRCFILE=$(dirname $0)/flash_leds_250Hz.pbsrc
PBPARSE=$(dirname $0)/../src/pb_parse.php
PBPOUT=$(dirname $0)/../src/pb_parport-output
if [ ! -f "$PBSRCFILE" ] ;then
	PBSRCFILE=/usr/local/share/doc/pb_parse/pbsrc_examples/good/flash_leds_250Hz.pbsrc
	PBPARSE=pb_parse
	PBPOUT=pb_parport-output
	if [ ! -f "$PBSRCFILE" ] ;then
		echo "Cannot find the .pbsrc file to load."
		exit 1
	fi
fi

FIFO=/tmp/pbppo_fifo
PARPORT=/dev/parport0


echo "Now parsing and simulating a program to flash the LEDs on all bits at 250Hz. This uses the parallel-port(s), not the pulseblaster."
echo "This tests pb_parse and pb_parport-output."
echo "If anything gets stuck, it may require kill -9, because of blocking fwrite()/fread()/fopen(). [This process has PID $$ .]"

#check parport exists
if [ ! -c "$PARPORT" ] ;then
	echo "Error: parallel-port device $PARPORT not found."
	exit 1
fi

echo -e "\n\n#1. Creating fifo to connect pb_parse with pb_parport-output..."
echo -e "mkfifo $FIFO"
mkfifo $FIFO


echo -e "\n\n#2. Launching pb_parport-output in the background. This will print EOF when the pipe closes..."
echo -e "$PBPOUT $FIFO &"
$PBPOUT $FIFO &
PBPOUT_PID=$!
#Check if it worked. 
sleep 0.3
if ! kill -s 0 $PBPOUT_PID 2> /dev/null; then
	echo "pb_parport-output failed. Giving up."
	rm $FIFO
	exit 1
fi

echo -e "\n\n#3. Parsing, running, and simulating to the display and to the parport at 1/50 speed for 10 seconds..."
echo -e "$PBPARSE -xi $PBSRCFILE -o /dev/null -j $FIFO  -tz 0.02  -u 100"
$PBPARSE -xi $PBSRCFILE -o /dev/null -j $FIFO  -tz 0.02  -u 100


echo -e "\n\n#4. Re-starting pb_parport-output in the background. This will print EOF when the pipe closes..."
echo -e "$PBPOUT $FIFO &"
$PBPOUT $FIFO &
PBPOUT_PID=$!


echo -e "\n\n#5. Running simulation at full speed (250Hz) with minimal interface. Use osciloscope to probe the LEDs and observe timing/jitter accuracy. Ctrl-C (or kill -9) to quit..."
echo -e "$PBPARSE -xi $PBSRCFILE -o /dev/null -j $FIFO -t -myq"
$PBPARSE -xi $PBSRCFILE -o /dev/null -j $FIFO  -t -myq


rm  $FIFO	#clean up
echo -e "\nSuccess!\n"
exit 0
