#!/bin/bash
#Copyright Richard Neill, 2011. Free software, licensed	under GNU GPL version 3	or later.

#Quick test script to make the pulseblaster flash a specific outputs at 2Hz.
#Useful to identify which wire connects to what.
#This tests just the pulseblaster driver (not pb_utils, pb_parse etc).
#So it directly interfaces with the /sys interface.
#See doc/pulseblaster/raw.txt for the format of the binary file.

#A very simple pulseblaster program: so trivial we can store it as a string!
#Note that the first 3 bytes must be prepended as the outputs that we set.
BIN_IMAGE_END="00 0000 017d 783d 0000 0000 0006 017d 783d"

#Program and start interface.
PROGRAM=/sys/class/pulseblaster/pulseblaster0/program
START=/sys/class/pulseblaster/pulseblaster0/start
STOP=/sys/class/pulseblaster/pulseblaster0/stop

if [ "$1" == "-h" -o "$1" == "--help" ] ;then
	echo "Identify pulseblaster outputs. Flash one line at 2Hz."
        echo "Usage: `basename $0` BIT_N"
	echo "       where BIT_N is 0 .. 23"
	exit 1
elif [ $# -ne 1 ];then 
	echo "Invoke with -h for usage"
	exit 1
fi

#Which bit? Then work out the output.
BIT=$1
case $BIT in 
	0)	OUT="00 00 01";;
	1)	OUT="00 00 02";;  
	2)	OUT="00 00 04";;  
	3)	OUT="00 00 08";;  
	4)	OUT="00 00 10";;  
	5)	OUT="00 00 20";;  
	6)	OUT="00 00 40";;  
	7)	OUT="00 00 80";;  
	8)	OUT="00 01 00";;  
	9)	OUT="00 02 00";;  
	10)	OUT="00 04 00";;  
	11)	OUT="00 08 00";;  
	12)	OUT="00 10 00";;  
	13)	OUT="00 20 00";;  
	14)	OUT="00 40 00";;  
	15)	OUT="00 80 00";;  
	16)	OUT="01 00 00";;  
	17)	OUT="02 00 00";;  
	18)	OUT="04 00 00";;  
	19)	OUT="08 00 00";;  
	20)	OUT="10 00 00";;  
	21)	OUT="20 00 00";;  
	22)	OUT="40 00 00";;  
	23)	OUT="80 00 00";;  
	*)	echo "Error, bit must be 0 .. 23"; exit 1;;
esac

#Build the program:
BIN_IMAGE=$OUT$BIN_IMAGE_END

#Load pb module and apply permissions (normally automatic, but useful if we installed it and haven't rebooted yet)
lsmod | grep -q pulseblaster || pb_driver-load

#Program it
echo -n "$BIN_IMAGE" | xxd -p -r > $PROGRAM

#Start it.
echo 1 > $START

#Say something
echo "Pulseblaster is now flashing output bit $BIT ($OUT) at 2Hz."
echo "To stop it: 'echo 1 > $STOP'"

