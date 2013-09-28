#!/bin/bash
#Check for whether the PB serial trigger adapter is physically present, as expected.
#This is a trivial wrapper around pb_serial_trigger, used for consistency with pb_check etc.
#This is Free Software, released under the GNU GPL, version 3 or later.

CMD="pb_serial_trigger -c"

if [ $# != 0 ]; then
	echo "This checks for the presence of the Pulseblaster Serial port trigger adapter (probably on /dev/ttyS0)."
	echo "It checks: '$CMD'"
	echo "If found, return 0; else return 1."
	exit 1
fi

echo -n "Checking for PB Serial Trigger Adapter..."
if $CMD 2> /dev/null ; then
	echo "OK"
	exit 0
else
	echo "ERROR"
	echo "Warning: PB Serial Trigger Adapter not present as expected. It should be connected to the serial port." >&2
	echo "Run $CMD for more details." >&2
	#zenity --error --text="ERROR: PB Serial Trigger adapter not present! Check ttyS*."
	exit 1
fi

