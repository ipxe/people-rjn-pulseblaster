#!/bin/bash
#Check for whether PulseBlaster PCI card is physically present. NB the PCI_STRING is for our specific PulseBlaster model.

PCI_STRING="Applied Micro Circuits Corp. S5920"
WRONG_EXAMPLE="Applied Micro Circuits Corp. Device 5922"

if [ $# != 0 ]; then
	echo "This checks for the presence of a physical PulseBlaster device in this computer's PCI slot."
	echo "It checks:  'lspci | grep \"$PCI_STRING\"'"
	echo "If found, return 0; else return 1."
	exit 1
fi

echo -n "Checking for PulseBlaster..." 
if lspci | grep -q "$PCI_STRING" ; then
	echo "OK"
	exit 0
else
	echo "ERROR"
	echo "Warning: PulseBlaster PCI device not present! Failed to find PCI device '$PCI_STRING'" >&2
	echo "Turn off this PC, remove lid and wiggle the PCI cards carefully..." >&2
	echo "Note: if a slightly wrong device is detected, e.g., lspci shows '$WRONG_EXAMPLE', this usually indicates the PCI device isn't correctly seated in the slot." >&2
	#zenity --error --text="ERROR: PulseBlaster PCI device not present! Turn off, remove lid and wiggle the PCI cards carefully."
	exit 1
fi

