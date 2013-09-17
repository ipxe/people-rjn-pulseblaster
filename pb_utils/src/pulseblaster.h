/* This is pulseblaster.h the definitions by pb_functions.c etc. See also the manual.		*
 * It is used indirectly, via pb_print_config, by pb_parse.php					*
 * Copright by Richard Neill <pulseblaster at REMOVEME.richardneill.org> 2004-2013.		*
 * This is Free Software, released under the GNU GPL, version 3 or later: http://gnu.org/  	*/

/* Important, global definitions */

#define HAVE_PB 			1		/* HAVE_PB is 1 if pulseblaster hardware is installed; 0 otherwise (for testing software without the hardware, via DEBUG_PB_TMP_DIR). */
#define DEBUG 				0		/* DEBUG is 1 to enable verbose diagnostics (in pb_utils); 0 otherwise. */
#define DEBUG_FUNCTION_CALLS 		0		/* Print out function calls and args. (Except pb_write_byte() ) */
#define DEBUG_WRITES			0		/* Prints out bytes/buffers as written. Extremely verbose; shows raw-hardware writes. */
#define DEBUG_PB_TMP_DIR		"/tmp/pulseblaster_dummy_sysfs" /* Dummy directory, used instead, if HAVE_PB != 1 */

#define PB_SYS_DIR			"/sys/class/pulseblaster/pulseblaster0" /* The pulseblaster directory in /sys for this device */
#define PB_PROGRAM 			"program" 				/* Write a program (as a bytestream) to this to program the device */
#define PB_START 			"start"					/* Write a "1" to this file to start the device */
#define PB_STOP  			"stop"					/* Write a "1" to this file to stop the device */
#define PB_ARM  			"arm"					/* Write a "1" to this file to arm the device */
#define PB_CONT  			"continue"				/* Write a "1" to this file to continue the device */
#define PB_SYS_TRIGGER_STRING		"1\n"					/* The "1\n" to be written to sysfs control files */

#define PB_VERSION			"PBD02PC" /* This is the version I have: a PCI PBD02PC, 100MHz, 32k model */

#define PB_CLOCK_MHZ 			100	/* The pulseblaster clock, in MHz. May be 100, or 125. I have a 100MHz model */
#define PB_TICK_NS 			10	/* Pulseblaster clock tick in ns. Reciprocal of PB_CLOCK_MHZ. 10, or 8. I have 10. MAY be a decimal (eg 4.2) */

#define PB_MEMORY 			32768	/* The amount of onboard memory. Either 512 Words, or 32 kWords. I have the 32 kWord version */
#define PB_LOOP_MAXDEPTH 		8	/* Max loop depth (number of nested loops) for pulseblaster. Normally 8 */
#define PB_SUB_MAXDEPTH 		8	/* Max number of nested subroutines. Normally 8 */

#define PB_INTERNAL_LATENCY 		3	/* The internal latency (in clock cycles) of the PB controller. 3 for all models. */
#define PB_MINIMUM_DELAY 		9	/* The shortest delay (in clock cycles) actually achievable. 9 for 32k models, 5 for 512 byte models. */
						/* Calculation of the delay length is complex:
						 *	1) Assume the user WANTS a delay of N cycles.
						 *	2) This delay is then INCREMENTED by 3 cycles [=PB_INTERNAL_LATENCY], to give an ACTUAL delay of N + 3.
						 *	3) Furthermore, the minimum ACTUAL delay is 5 or 9 cycles [=PB_MINIMUM_DELAY].
						 *      4) So, for 32k model, the user must request N-3, where N>=9. This goes in the VLIW instruction
						 *
						 *    PBMemory	Min.ACTUALdelay  InternalLatency   Minimum value of Delay_count
						 * 	512		5		3		2
						 *      32kB		9		3		6
						 *
						 *      5) My pb_write_vliw() takes account of all this: its "length" argument is N.
						 *  See doc/latencies.txt for more.
						 */

#define PB_WAIT_LATENCY 		6	/* A WAIT instruction has a 6-cycle latency on WAKEUP. (This is on top of PB_MINIMUM_DELAY) */
#define PB_MINIMUM_WAIT_DELAY   	12	/* PB_MINIMUM_WAIT_DELAY is shortest possible wait instruction. I *think* this is correct. See
						 * http://www.pulseblaster.com/CD/PulseBlaster/ISA/WAIT_Op_Code_rev1.pdf
					 	 * Calculation: PB_MINIMUM_WAIT_DELAY = PB_WAIT_LATENCY+PB_MINIMUM_DELAY-PB_INTERNAL_LATENCY
						 */

#define PB_BUG_PRESTOP_EXTRADELAY 	2	/* This is a hardware bug/misfeature: the instruction which precedes a STOP has a minimum length requirement 1 or sometimes 2 ticks
						 * longer than normal. Otherwise, the (previous) outputs don't get set. The documentation is wrong! */

#define PB_LONGDELAY_ARG_MIN 		2	/* Arg for the long delay instruction >= 2 */
#define PB_BUG_LONGDELAY_OFFSET		2  	/* This is a hardware bug/misfeature. Longdelay is supposed (according to documentation) to delay for (length*arg), subject to the requirement that arg >=2.
						 * In fact, the actual delay (as measured experimentally) is length*(arg+2). So, subtract PB_BUG_LONGDELAY_OFFSET from the ARG, in pb_write_vliw(),
						 * and it will do what we want. The hardware is happy to accept an arg of 0 or 1. See doc/longdelay.txt for more. Thus:
						 *        USER REQUESTED ARG		ARG WRITTEN TO DEVICE		ACTUAL DELAY			COMMENT
						 *		0				illegal			-				This is obviously daft.
						 *		1				cont -			length				DWIM demotes this to cont.
						 *		2				0			(length * 2)			The hardware is happy with arg=0
						 *		3				1			(length * 3)			The hardware is happy with arg=1
						 * 		n				n-1			(length * n)			normal.
						 */

#define PB_LOOP_ARG_MIN 		1	/* Arg for the loop instruction >= 1. This is the min number of  ACTUAL loops allowed (Default = 1). Compare:  for(i=0;i<n;i++){}.  We require n>=PB_LOOP_ARG_MIN */
#define PB_BUG_LOOP_OFFSET		1	/* The pulseblaster hardware has a "design bug", such that the internal value of ARG for a LOOP VLIW is off by one. Hence, this value is subtracted. (Default = 1)
						 * Note: "1 loop" means "do the loop body 1 time", NOT "do the body, then repeat it 1 time". The former is correct (and the same as C,bash,PHP etc). The latter
						 * is unfortunately how the pulseblaster hardware behaves! Thus, consider that the user actually wants to repeat the loop n times.
						 * 1) The parser checks that n is legal by checking that n >= PB_LOOP_ARG_MIN.  [Unless the hardware changes, this means that n=1 is allowed, but n=0 is not]
						 * 2) The ARG to the VLIW instruction is then modified by subtracting PB_BUG_LOOP_OFFSET. This is done by pb_write_vliw().
						 * Corollary: the actual hardware is capable of dealing with ARG=0 for loops.
						 * See doc/loops.txt for more.
						 */

#define PB_BUG_WAIT_NOTFIRST		1	/* This is a hardware bug/misfeature: WAIT may not be the first instruction */
#define PB_BUG_WAIT_MINFIRSTDELAY 	11 	/* This is a hardware bug/misfeature: If WAIT is the second instruction, then the first instruction must be 11 or more ticks */



/* Pulseblaster definitions which are unlikely to change */
#define PB_OUTPUTS_24BIT 		0xFFFFFF	/* Output range from 0 to FF,FF,FF.  Hardcoded, by virtue of VLIW format. Be very careful if you modify this!! */
#define PB_DELAY_32BIT	 		0xFFFFFFFF	/* Delay range, from 0 to FF,FF,FF,FF.  Hardcoded, in VLIW format */
#define PB_ARG_20BIT     		0xFFFFF		/* Argument max size. Hardcoded in VLIW format */
#define PB_BPW_VLIW    			10		/* Bytes per word = 10 for VLIW */

#define PB_OPCODE_CONT			0	/* Definition of Opcode CONT = 0      */
#define PB_OPCODE_STOP  		1	/* Definition of Opcode STOP = 1      */
#define PB_OPCODE_LOOP			2	/* Definition of Opcode LOOP = 2      */
#define PB_OPCODE_ENDLOOP		3	/* Definition of Opcode ENDLOOP = 3   */
#define PB_OPCODE_CALL			4	/* Definition of Opcode CALL = 4      */
#define PB_OPCODE_RETURN		5	/* Definition of Opcode RETURN = 5    */
#define PB_OPCODE_GOTO			6	/* Definition of Opcode GOTO = 6      */
#define PB_OPCODE_LONGDELAY		7	/* Definition of Opcode LONGDELAY = 7 */
#define PB_OPCODE_WAIT			8	/* Definition of Opcode WAIT = 8      */

/* Exit codes */
#define PB_EXIT_OK			0	/* Exit with success */
#define PB_ERROR_GENERIC		1	/* Error code 1 is reserved for general purpose use */
#define PB_ERROR_WRONGARGS     		2	/* Wrong arguments to a command */
#define PB_ERROR_NODEVICE		3	/* PB failed to open device, or couldn't write to it. */
#define PB_ERROR_BUG			4	/* A program bug exists (or has been created by some incompatible change in a #define). See the comments. */
#define PB_ERROR_INVALIDINSTRUCTION	5	/* Invalid instruction or opcode */
#define PB_ERROR_OUTOFMEM		6	/* Too many instructions to fit in memory! */
#define PB_ERROR_LOOPDEPTH		7	/* Too deeply nested in loops. [Note: pb_prog only catches the simplest-case errors; pb_parse catches them all.] */
#define PB_ERROR_TOKENISING		8	/* Some problem in tokenising a line. Eg a vliw instruction with the wrong number of args. or a garbage string for strtoul(). */
#define PB_ERROR_EMPTYVLIWFILE		9	/* The .vliw (or .bin) file is empty. (This used to occur as a result of any fatal error during pb_parse.) */
#define PB_ERROR_BADVLIWFILE		10	/* The .vliw (or .bin) file contains no program, or an invalid one. */

/* String lengths, in C programs */
#define OPCODE_MAXLEN 			20	/* Max length of an opcode (string), +1 for the terminating NUL */
#define VLIWLINE_MAXLEN 		1024	/* length of a line in a .vliw file (including the trailing NEWLINE), +1 for the terminating NUL */
