#!/bin/bash
#This loads the PulseBlaster with a simple program to output a specified frequency on a particular BIT or BITS.

#Copyright (C) Richard Neill 201q, <pulseblaster at REMOVE.ME.richardneill.org>. This program is Free Software. You can
#redistribute and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation,
#either version 3 of the License, or (at your option) any later version. There is NO WARRANTY, neither express nor implied.
#For the details, please see: http://www.gnu.org/licenses/gpl.html

TICK_NS=10			#Number of ns per pulseblaster tick (varies for pulseblaster model).
PB_MIN_TICKS=9			#Pulseblaster minimum instruction length.
PB_INIT=pb_init			#Pulseblaster initialisation program
PB_START=pb_start		#Pulseblaster start program
PB_STOP=pb_stop			#Pulseblaster stop program

if [ $# != 2 ] ;then
	echo "This loads the PulseBlaster with a simple program to run at a certain frequency on particular bits."
	echo "First, all bits are taken high. Then, when triggered, BITS will oscillate with a square wave at FREQ."
	echo "USAGE: `basename $0 .sh`  FREQ_Hz  BITs"
	echo "       #Eg 20kHz on Bit 15 is: '20000 0x8000'."
	echo "       #FREQ may have units: Hz/kHz/MHz  (if omitted, assume Hz)"
	echo "       #BITS may be a Hexadecimal number, or 'bitX' where X=0..15, or 'all'."
	echo ""
	exit 1
fi

if [[ "$1" =~ ^([0-9]+)(|Hz|kHz|MHz)$ ]]; then		#Freq in Hz (if no units), else allow Hz, kHz, MHz
        FREQ_HZ=${BASH_REMATCH[1]}
        SUFFIX=${BASH_REMATCH[2]}

        if [ "$SUFFIX" == 'kHz' ]; then
                FREQ_HZ=$((FREQ_HZ*1000))
        elif [ "$SUFFIX" == 'MHz' ]; then
                FREQ_HZ=$((FREQ_HZ*1000000))
        fi
else
	echo "Unrecognised frequency '$1'."
	exit 1
fi

shopt -s nocasematch
if [ $2 == all ] ; then					#Bit. Either a hex mask, or "bitX" or "all"
	BITS=0xffffff
elif [[ "$2" =~ ^bit_?([0-9]+)$ ]]; then
        BIT=${BASH_REMATCH[1]}
	BITS=$((0x1 << $BIT))
elif [[ "$2" =~ 0x([0-9a-f]+)$ ]]; then
        BITS=$2
else
	echo "Unrecognised bit mask '$2'."
	exit 1
fi


#Calculate the main program values.
printf -v OUTPUT_HIGH 0xffffff
printf -v BITS 0x%06x $BITS
printf -v OUTPUT_LOW 0x%06x $((OUTPUT_HIGH &~ BITS))
HALF_CYCLE_TICKS=$((1000000000/(2*FREQ_HZ*TICK_NS)))

if [[ $HALF_CYCLE_TICKS -lt $PB_MIN_TICKS ]];then	#Check within range
	echo "Error: interval is too short. Signal with frequency $FREQ_HZ has $HALF_CYCLE_TICKS ticks per half-cycle, but minimum ticks is $PB_MIN_TICKS.";
	exit 1
fi

#First, initialise the pulseblaster with the trigger bit high.
echo "Initialising PulseBlaster with bit pattern: $OUTPUT_HIGH";
$PB_INIT $OUTPUT_HIGH
if [ $? != 0 ];then
	echo "Failed to initialise PulseBlaster with: $PB_INIT $OUTPUT_HIGH"
	exit 1
fi

#Generate the VLIW file.
VLIW=$(cat <<-EOT
//OUTPUT  (hex) is a 3-byte value for the outputs
//OPCODE  (string) is one of the 9 allowed opcodes. (Case-insensitive)
//ARG     (hex) is the argument to the opcode.
//LENGTH  (hex) is the 4-byte delay value in ticks (10ns)

//OUTPUT	OPCODE		ARG	LENGTH		//comment

$OUTPUT_HIGH	CONT		-	$HALF_CYCLE_TICKS		//square wave
$OUTPUT_LOW	GOTO		0	$HALF_CYCLE_TICKS

EOT
)

#echo "$VLIW"
echo -e "Programming PulseBlaster to produce a $FREQ_HZ Hz square wave on bits $BITS ..."
echo "$VLIW" | pb_asm - - | pb_prog -		#Assemble and Program.
if [ $? != 0 ]; then
	echo "Error: failed to program PulseBlaster."
	exit 1
fi

echo -e "\nNow run $PB_START (or HW_Trigger). This will start the square-wave; use $PB_STOP (or HW_Reset) to stop it."
exit 0
