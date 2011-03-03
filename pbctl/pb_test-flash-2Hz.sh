#!/bin/bash
#Copyright Richard Neill, 2011. Free software, licensed	under GNU GPL version 3	or later.

#Quick test script to make the pulseblaster flash all outputs at 2Hz.
#This tests just the pulseblaster driver (not pb_utils, pb_parse etc).
#So it directly interfaces with the /sys interface.
#See doc/pulseblaster/raw.txt for the format of the binary file.

#A very simple pulseblaster program: so trivial we can store it as a string!
#Note that the first 3 bytes are the outputs that we set.
BIN_IMAGE="ffff ff00 0000 017d 783d 0000 0000 0006 017d 783d"

#Program and start interface.
PROGRAM=/sys/class/pulseblaster/pulseblaster0/program
START=/sys/class/pulseblaster/pulseblaster0/start
STOP=/sys/class/pulseblaster/pulseblaster0/stop

if [ $# -ge 1 ] ;then
	echo "Simple test of the pulseblaster hardware and driver (without using pb_utils or pb_parse)."
	echo "Flash all the outputs at 2Hz"
	echo "This writes directly to the program interface: $PROGRAM"
	echo "The program itself (via xxd -p -r) is: $BIN_IMAGE"
        echo "Usage: `basename $0`"
	exit 1
fi

#Load pb module and apply permissions (normally automatic, but useful if we installed it and haven't rebooted yet)
lsmod | grep -q pulseblaster || pb_driver-load

#Program it
echo -n "$BIN_IMAGE" | xxd -p -r > $PROGRAM

#Start it.
echo 1 > $START

#Say something
echo "Pulseblaster is now flashing all outputs at 2Hz."
echo "To stop it: 'echo 1 > $STOP'"

