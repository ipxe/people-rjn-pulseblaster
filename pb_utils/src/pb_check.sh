#!/bin/bash
#Check for whether PulseBlaster PCI card is physically present.
#This is Free Software, released under the GNU GPL, version 3 or later.

#We have an SP1 board.
SP1_ID=0x5920

#The SP2 board has either of these IDs.
SP2a_ID=0x8879
SP2b_ID=0x8852

#NB the PCI_STRING is for our specific PulseBlaster model.
PCI_STRING="Applied Micro Circuits Corp. S5920"
WRONG_EXAMPLE="Applied Micro Circuits Corp. Device 5922"	#Misreads.

if [ $# != 0 ]; then
	echo "This checks for the presence of a physical PulseBlaster device in this computer's PCI slot."
	echo "It checks lspci for any of $SP1_ID, $SP2a_ID, $SP2b_ID"
	echo "If found, return 0; else return 1."
	exit 1
fi

echo -n "Checking for PulseBlaster..." 
if [ -n "$(lspci -d "*:$SP1_ID")" ]; then
	echo "OK"
	exit 0
elif  [ -n "$(lspci -d "*:$SP2a_ID")" ]; then
	echo "OK"
	exit 0
elif  [ -n "$(lspci -d "*:$SP2b_ID")" ]; then
	echo "OK"
	exit 0
else
	echo "ERROR"
	echo "Warning: PulseBlaster PCI device not present! Failed to find PCI device with ID '$SP1_ID', '$SP2a_ID' or '$SP2b_ID'." >&2
	echo "Turn off this PC, remove lid and wiggle the PCI cards carefully..." >&2
	echo "Note: if a slightly wrong device is detected, e.g., lspci shows '$WRONG_EXAMPLE', rather than '$PCI_STRING' this usually indicates the PCI device isn't correctly seated in the slot." >&2
	#zenity --error --text="ERROR: PulseBlaster PCI device not present! Turn off, remove lid and wiggle the PCI cards carefully."
	exit 1
fi

