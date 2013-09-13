#!/bin/bash
#Manual control of the PulseBlaster from the terminal.
#Copyright 2013 Richard Neill <pulseblaster at richardneill dot org>. This is Free Software, Licensed under the GNU GPL v3+.
#Todo: once we are sure we no longer need to support Bash version 3, use "read -i initial_text" for the prompt, and use ${x,,} and ${x^^} instead of lots of 'tr'

#Configuration.
PB_INIT=pb_init			#binary to write to the pulseblaster. (Remarkably fast, measured at 3.1ms)
INITIAL_OUTPUT=0x00		#Initial output value. We have to have some defined start state.
LOG_TITLE="pb_manual `date`" 	#default title.
PB_INIT_RUNTIME_MS=3		#measure pb_init takes about 3ms to execute. Compensate if we can.

#Which bits do we care about? Name each bit with a lower-case letter. - means this output line isn't "interesting". (ignored in the binary shortcut)
#Duplicate letters are OK (more than one line shares the same name). Sspaces are ignored, if this length isn't 24 characters, it's right-aligned.
BITMASK="---- ---- aflr ---e iiii pppp"					#Be careful!
BIT_MNEMONIC="Adc, Fsync, Lsync, Reset, rEad, lInes, Pixels"		#For the help text		
	
#Parse args.
while getopts dhnxb:i:l:m:t: OPTION; do
        case "$OPTION" in
		d) DEBUG=true;;
		h) SHOW_HELP=true;;
		n) NO_HARDWARE=true;;		
		x) OVERWRITE=true;;
		b) BITMASK="$OPTARG";;
		i) INITIAL_OUTPUT="$OPTARG";;
		l) LOGFILE="$OPTARG";;
		m) BIT_MNEMONIC="$OPTARG";;
		t) LOG_TITLE="$OPTARG";;
	esac
done

#Help
if [ -n "$SHOW_HELP" ] ;then
	cat <<-EOT

	`basename $0`:  manual, interactive control of the PulseBlaster outputs.
	
	INTRO:
	  This wraps $PB_INIT in a loop, with prompts. it is intended to make it easy to quickly 'type' some data into
	  the PulseBlaster. The prompt displays the current outputs, and allows for literal or bitwise inputs.
	  
	CONFIGURATION:
	   The PulseBlaster is 24-bits wide, but not all bits are necessarily connected to the target circuit. Of the 
	   bits that are connected, perhaps not all of them need to be controlled manually at this time. This 
	   configuration is controlled by the bitmask string. [default: '$BITMASK'].
	   
	   Each output bit that is of interest for the experiment is denoted by a lower-case letter, selected by the 
	   experimenter, or "-" for uninteresting bits. The bitmask is right-aligned, up to 24-bits long, spaces are 
	   ignored. Repeated letters affect multiple bits. 

	PROMPT:
	   * Commands are prefixed with '#'. They are: #list, #help, #quit, #init, #zeros, #ones, #flip, #flash N (T/2)ms:
	       #list               list the configured bitmask and the mnemonic.
	       #help               show brief help.
	       #init               set the outputs to their initial values i.e. $INITIAL_OUTPUT (unless changed with -i).
	       #zeros              set all outputs to zero.
	       #ones               set all outputs to one.
	       #flip               toggle all outputs.
	       #left               left-shift by one.  (LSB=0).
	       #right              right-shift by one. (MSB=0).
	       #flash  N*T         flash the currently non-zero bits, N cycles, with each cycle taking T ms.
	       #quit               quit. (or use Ctrl-C).
	   
	   * Data formats are either LiteralHex, TruncatedBinary, or StringDiff:
	       LiteralHex:       This value is literal, and copied to the outputs (including the "uninteresting" bits)
	                         Format:   0x[0-9a-f]{1,6}
	       
	       StringDiff:       The most useful shortcut. Uppercase letters set the relevant bit(s); lowercase letters 
	                         clear them. Ordering doesn't matter; omitted letters remain unchanged. For example, 
	                         with a mask of "--ab -c-d", the input "dCa" sets bit2, and clears bits 0 and 5.
	                         Format:   [a-zA-Z]{1,n}
	                         
	       TruncatedBinary:  This is a helpful shortcut. Only the bits considered "interesting" in the mask should 
	                         be typed. They are then unpacked. For example, with a mask of "--ab -c-d", the input of
	                         "0010" would be interpreted as "--00 -1-0".   "-" are unchanged.
	                         Format:   [01]{n}

	USAGE: `basename $0`  [OPTIONS]      
	
	OPTIONS:
	  -l    LOGFILE        also log to logfile. the output can be played back as a shell-script.
	  -t    TITLE          logfile title. [default: "pb_manual \`date\`"].
	  -x                   ok to overwrite an existing logfile. [default: no].
	 
	  -i    INIT_VALUE     the initial state to output. [default: $INITIAL_OUTPUT].
	  -b    BITMASK        the bitmask to use. [default: $BITMASK].
	  -m    MNEMONIC       mnemonic string. [default: $BIT_MNEMONIC]. 
	  
	  -n                   no hardware: don't write to the physical PulseBlaster device.
	  -h                   show this help
	  -d                   enable verbose debugging

	NOTES:
	   * The underlying program is $PB_INIT; this takes about $PB_INIT_RUNTIME_MS ms to write data to the PB and trigger it.
	   * The prompt starts with instruction 1, because the 0th was to initialise the PulseBlaster.
	   * The prompt shows the value in hex for all bits, but the masked value is only for the "interesting" ones.
	   * The prompt uses readline for line-editing, including history.
	   * Between invocations, this program *doesn't* make the PB output bits glitch (it relies on $PB_INIT). 
	   * Flash should have T >= 10ms, or timing is unreliable.
	  
	EOT
	exit 1
fi

#Sanity check args, and initialise.
if [ -n "$LOGFILE" ]; then
	if [ -f "$LOGFILE" -a -z "$OVERWRITE" ]; then
		echo "Error: logfile '$LOGFILE' already exists. Use -x to overwrite."
		exit 1
	else
		echo -e "#$LOG_TITLE\n#$BITMASK\n#$BIT_MNEMONIC" > $LOGFILE
	fi
fi

if [[ ! "$INITIAL_OUTPUT" =~ ^0x[0-9a-fA-F]{1,6}$ ]]; then 
	echo "Invalid value '$INITIAL_OUTPUT' given for initial output (-i). Expect a 24-bit hex string."
	exit 1
elif [[ ! "${BITMASK//[[:space:]]/}" =~ ^[a-z-]{1,24}$ ]]; then 
	echo "Invalid value '$BITMASK' given for bitmask (-b). Expect a string containing only [a-z -], at most 24 chars long."
	exit 1
fi

BITMASK=${BITMASK//[[:space:]]/}; BITMASK=$(echo $BITMASK | tr A-Z a-z); while (( ${#BITMASK} < 24 )) ; do BITMASK="-$BITMASK"; done #Remove spaces, lower-case, and left-pad to 24.
VALID_CHARS=${BITMASK//-/};
UNIGNORED=${#VALID_CHARS} 	#count the un-ignored bits (non '-' characters)
CHARS_LIST=''; for ((i=0; i < $UNIGNORED; i++)); do CHAR=${VALID_CHARS:$i:1} ; if [[ ! $CHARS_LIST =~ $CHAR ]]; then CHARS_LIST+=$CHAR ; fi ; done  #list legal (unique) chars.

START_TIMESTAMP=$(date '+%s.%N')
PREV_TIMESTAMP=$START_TIMESTAMP
FLASH_CYCLES=0 

#Debug
STDOUT=/dev/null; STDERR=/dev/null
[ -n "$DEBUG" ] && STDOUT=/dev/stdout && STDERR=/dev/stderr

#Output the data to the Pulseblaster, and to the logfile. Calculate how long we waited. $1 is the data to write, as an integer eg 0xfedcb1.
function do_output(){	
	printf -v OUTPUT 0x%06x $1	#format for pb_init.
	TIMESTAMP=$(date '+%s.%N')	#float, sss.nnnnnnnnn
	DELAY=$(echo "scale=4; ($TIMESTAMP - $PREV_TIMESTAMP)/1" | bc)  #4 d.p.
	ELAPSED=$(echo "scale=4; ($TIMESTAMP - $START_TIMESTAMP)/1" | bc) 
	[ ${DELAY:0:1} == "." ] && DELAY="0$DELAY"  #restore leading 0 to decimal.
	PREV_TIMESTAMP=$TIMESTAMP	#for next time.
	
	if [ -z "$NO_HARDWARE" ]; then 		#Write to the hardware, if appropriate.
		if ! $PB_INIT $OUTPUT > $STDOUT 2>$STDERR ; then
			echo -n "ERROR: command '$PB_INIT $OUTPUT' failed."; [ -z "$DEBUG" ] && echo -n " (Retry with -d to debug, showing stdout/stderr)."; echo -e "\n"
			exit 1
		fi
	fi
	if [ -n "$LOGFILE" ]; then
		echo "sleep $DELAY" >> $LOGFILE
		echo "$PB_INIT $OUTPUT" >> $LOGFILE
	fi
	if [ -n "$DEBUG" ];then
		echo "Delay was: $DELAY, New output: $OUTPUT"
	fi
	let INSTR_COUNTER++;
	LAST_VALUE=$OUTPUT
}

#Format a value in the bitmask format. Add spaces every 4.
function format_mask() {   #$1 is value
	VALUE=$1
	for ((i=0; i< ${#BITMASK}; i++)); do	
		CHAR=${BITMASK:$i:1}   #already lc.
		if [ $CHAR != "-" ] ; then
		
			if (( $VALUE & (1 << (${#BITMASK}-1-$i) ) )); then 
				CHAR=$(echo $CHAR | tr a-z A-Z)
			fi
		fi
		LIST=$LIST$CHAR
		(($i%4 == 3)) && LIST="$LIST "
	done
	echo "${LIST% }"
}

#Initial output to the device and logfile
INSTR_COUNTER=0;
do_output $INITIAL_OUTPUT

#Now, loop, prompting for data, writing it out and waiting.
while : ; do
	while [ $FLASH_CYCLES -gt 0 ]; do		#flash, if we need to, from before.
		echo "Instruction: $(printf %3d $INSTR_COUNTER). Time: $(printf %5.1f $ELAPSED). Current: $LAST_VALUE. Masked: $(format_mask $LAST_VALUE). Flashing ($FLASH_CYCLES, off)..." 
		do_output 0x0
		sleep $FLASH_HALFPERIOD_S
		echo "Instruction: $(printf %3d $INSTR_COUNTER). Time: $(printf %5.1f $ELAPSED). Current: $LAST_VALUE. Masked: $(format_mask $LAST_VALUE). Flashing ($FLASH_CYCLES, on)..." 
		do_output $FLASH_OUTPUT
		sleep $FLASH_HALFPERIOD_S
		let FLASH_CYCLES--
	done

	(( $INSTR_COUNTER %10 == 0 )) && echo ""	#blank line every 10th.
	PROMPT="Instruction: $(printf %3d $INSTR_COUNTER). Time: $(printf %5.1f $ELAPSED). Current: $LAST_VALUE. Masked: $(format_mask $LAST_VALUE). Next: " 
	read -e -p "$PROMPT" 				
	
	INPUT=${REPLY//[[:space:]]/}			#remove all spaces, even internal ones. 
	INPUT_LC=$(echo $INPUT | tr A-Z a-z)		#lowercase
	
	if [[ "$INPUT" =~ ^#(.*) ]] ;then			#Command. prefixed #.  #quit, #init, #list, #help., #zeros, #ones, #flip
		if [ "${BASH_REMATCH[1]}" == "quit" ] ;then
			echo Quit; exit 0
		elif [ "${BASH_REMATCH[1]}" == "help" ] ;then
			echo "  COMMANDS: #list, #help, #quit, #init, #zeros, #ones, #flip, #left, #right, #same, #flash N*T(ms)"
			echo "  FORMATS:  LiteralHex: 0x[0-9a-f]{1,6} , TruncatedBinary: [01]{$UNIGNORED} ,  StringDiff: [$CHARS_LIST]{1,${#CHARS_LIST}}/i"
			continue
		elif [ "${BASH_REMATCH[1]}" == "list" ] ;then
			echo "  BITMASK:  $(format_mask 0)        #NAMES:  $BIT_MNEMONIC"
			continue
		elif [ "${BASH_REMATCH[1]}" == "init" ] ;then
			OUTPUT=$INITIAL_OUTPUT
		elif [ "${BASH_REMATCH[1]}" == "zeros" ] ;then
			OUTPUT=0x000000
		elif [ "${BASH_REMATCH[1]}" == "ones" ] ;then
			OUTPUT=0xffffff
		elif [ "${BASH_REMATCH[1]}" == "flip" -o "${BASH_REMATCH[1]}" == "invert" ] ;then  #allow synonym
			OUTPUT=$(($LAST_VALUE ^ 0xffffff))
		elif [ "${BASH_REMATCH[1]}" == "same" ] ;then 
			OUTPUT=$(($LAST_VALUE))
		elif [ "${BASH_REMATCH[1]}" == "left" ] ;then
			OUTPUT=$(( ($LAST_VALUE << 1) & 0xffffff))
		elif [ "${BASH_REMATCH[1]}" == "right" ] ;then
			OUTPUT=$(($LAST_VALUE >> 1))
		elif [[ "${BASH_REMATCH[1]}" =~ ^flash([0-9]+)[*x]([0-9]+)$ ]] ;then
			FLASH_CYCLES=${BASH_REMATCH[1]} 
			FLASH_HALFPERIOD_S=$(echo "scale=3; (${BASH_REMATCH[2]} - (2*$PB_INIT_RUNTIME_MS))/2000" | bc) 		#compensate if we can.
			[ ${FLASH_HALFPERIOD_S:0:1} == "-" ] && FLASH_HALFPERIOD_S=0  #best we can do.
			OUTPUT=$(($LAST_VALUE))
			FLASH_OUTPUT=$OUTPUT
	echo "FHPO 	$FLASH_HALFPERIOD_S"	
			
		else
			echo "  Unrecognised command: '$INPUT'. Try: '#help'."
			continue
		fi
		
	elif [[ $INPUT_LC =~ ^0x([0-9a-f]{1,6})$ ]] ;then  #Hex data. 
		OUTPUT=$INPUT_LC  #Use it literally
		
	elif [[ $INPUT =~ ^([01]{1,24})$ ]] ;then	#Binary data. Read from the right, looking ONLY at the bits we care about (the non "-" entries in BITMASK)
		BINSTR=${BASH_REMATCH[1]}
		if [ "${#BINSTR}" != "$UNIGNORED" ]; then
			echo "  Invalid truncated binary string '$REPLY'. Must be exactly $UNIGNORED digits, to match the interesting set in bitmask."
			continue
		fi
		j=0; OUTPUT=$LAST_VALUE
		for ((i=0; i< ${#BITMASK}; i++)); do	#Build up the output value, bit at a time, looking from the right. 
			CHAR=${BITMASK:$((${#BITMASK}-1-$i)):1}
			if [ "$CHAR" != "-" ] ;then			  #unchanged
				BIN=${BINSTR:$((${#BINSTR}-1-$j)):1}
				if [ "$BIN" == 0 ];then
					OUTPUT=$(($OUTPUT & ~ (1<<$j) ))  #clear
				else
					OUTPUT=$(($OUTPUT | (1<<$j) ))	  #set
				fi
			fi
			let j++
		done

		
	elif [[ $INPUT_LC =~ ^([a-z]){1,24}$ ]] ;then	#Letters. Uppercase letters set bits, lowercase letters clear them.
		OUTPUT=$LAST_VALUE
		for ((i=0; i < ${#INPUT_LC}; i++)); do
			CHAR=${INPUT_LC:$i:1}; 
			if [[ ! $CHARS_LIST =~ $CHAR ]]; then
				echo "  Invalid string differential: illegal character '$CHAR', which is not one of '[$CHARS_LIST]/i'. Try '#help'."
				continue 2;
			fi
		done
		for ((i=0; i< ${#BITMASK}; i++)); do	#Change the prev value bits at a time, depending on whether the upper-case or lower-case letter appears.
			CHAR=${BITMASK:$((${#BITMASK}-1-$i)):1}
			CHAR_UC=$(echo $CHAR | tr a-z A-Z)
			if [[ $INPUT =~ $CHAR ]]; then	
				OUTPUT=$(($OUTPUT &~ (1<<$i) ))
			elif [[ $INPUT =~ $CHAR_UC ]]; then
				OUTPUT=$(($OUTPUT | (1<<$i) ))
			fi
		done	

	elif [ -z "$INPUT_LC" ] ;then			#Blank line. Treat as "same as last time"
		OUTPUT=$(($LAST_VALUE))
		
	else						#Error. Do nothing.
		echo "  Invalid data entered: '$REPLY'. For help, type '#help'"
		continue
	fi

	history -s "$REPLY"	#Put valid commands into our local history.
	do_output $OUTPUT	#Write to the device and the log.
done

exit 0
