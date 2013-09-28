/* This is pb_functions.c which contains the functions required by pb_init, pb_asm, pb_prog, pb_start, pb_end etc.
 * All the definitions are in pulseblaster.h (and in the manual!)
 * This userspace version pairs with the Linux 2.6 kernel driver, which has /sys/class/pulseblaster.
 * [It differs from the more complex earlier version which used the 2.4 kernel driver, and had to 'bit-bang' the interface]
 * Most functions here will exit with an error-code, rather than return, since any error here is fatal.
 * This is Free Software, released under the GNU GPL, version 3 or later.
 */

#include <stdio.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <fcntl.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <errno.h>
#include "pulseblaster.h"	/* Pulseblaster configuration/hardware info */

/*Helpful macros */
#define eprintf(...)		fprintf (stderr, __VA_ARGS__)

/* Combine the files and directories in pulseblaster.h to get the trigger/control files in sysfs */
#if HAVE_PB == 1	/* Real PB */
    #define PB_SYS_PROGRAM	PB_SYS_DIR"/"PB_PROGRAM		/* Write a bytestream to this file to program the device */
    #define PB_SYS_START  	PB_SYS_DIR"/"PB_START		/* Write a "1" (PB_SYS_TRIGGER_BYTE) to this file to start the device */
    #define PB_SYS_STOP  	PB_SYS_DIR"/"PB_STOP		/* Write a "1" to this file to stop the device */
    #define PB_SYS_ARM  	PB_SYS_DIR"/"PB_ARM		/* Write a "1" to this file to arm the device */
    #define PB_SYS_CONT  	PB_SYS_DIR"/"PB_CONT		/* Write a "1" to this file to continue the device */

#else			/* Test files in /tmp */
    #define PB_SYS_PROGRAM 	DEBUG_PB_TMP_DIR"/"PB_PROGRAM
    #define PB_SYS_START 	DEBUG_PB_TMP_DIR"/"PB_START
    #define PB_SYS_STOP 	DEBUG_PB_TMP_DIR"/"PB_STOP
    #define PB_SYS_ARM 		DEBUG_PB_TMP_DIR"/"PB_ARM
    #define PB_SYS_CONT		DEBUG_PB_TMP_DIR"/"PB_CONT
#endif

int i;					/* counter */
unsigned char vliw_buf[PB_BPW_VLIW];	/* The buffer to hold individual VLIW words (10 bytes at a time) */
FILE *pb_prog_fh;			/* programming -  File handles (Global)  */
FILE *pb_start_fh;			/* start */
FILE *pb_stop_fh;			/* stop  */
FILE *pb_arm_fh;			/* arm */
FILE *pb_cont_fh;			/* continue */
struct stat stat_buf;			/* stat, for directory_exists check */
int loop_depth = 0;			/* Attempt to track number of nested loops. Note: this catches only the most common trivial error */

#define STACKSIZE PB_LOOP_MAXDEPTH * 2	/* Define a stack for the address of the loop instructions, and push/pop functions. (allocate some extra space too) */
typedef struct {
	int count;
	int items[STACKSIZE];
} STACK;

/* Stack push and pop */
void push(STACK *ps, int x){
	if (ps->count == STACKSIZE){
		eprintf("Error: stack overflow\n");
		abort();
	}
	ps->items[ps->count++] = x;
}
int pop(STACK *ps){
	if (ps->count == 0){
		eprintf("Error: stack underflow\n");
		abort();
	}
	return ps->items[--ps->count];
}

/* Open (all) the pulseblaster device files for writing. Else, exit with error PB_ERROR_NODEVICE */
void pb_fopen(){
	#if DEBUG_FUNCTION_CALLS==1
	eprintf("Now in function pb_fopen()\n");
	#endif

	#if HAVE_PB!=1
	eprintf("Dummy run, *WITHOUT* PulseBlaster hardware! ('#define HAVE_PB 0' in pulseblaster.h). "
		"Faking PB sysfs directory: %s \n", DEBUG_PB_TMP_DIR);
        if (stat(DEBUG_PB_TMP_DIR, &stat_buf) < 0){		/* If we can stat it, directory probably already exists. C's mkdir() doesn't have the "-p" option. */
		if (mkdir (DEBUG_PB_TMP_DIR, S_IRWXU) < 0 ){
			perror ("Could not create pulseblaster dummy directory: "DEBUG_PB_TMP_DIR);
			exit(PB_ERROR_NODEVICE);
		}
	}
	#endif

	if ((pb_prog_fh = fopen(PB_SYS_PROGRAM, "w")) == NULL){
		perror ("Could not open device programming file "PB_SYS_PROGRAM". Has 'pb_driver-load' been run? ");
                exit(PB_ERROR_NODEVICE);
	}
	if ((pb_start_fh = fopen(PB_SYS_START, "w")) == NULL){
		perror ("Could not open device programming file "PB_SYS_START". Has 'pb_driver-load' been run? ");
                exit(PB_ERROR_NODEVICE);
	}
	if ((pb_stop_fh = fopen(PB_SYS_STOP, "w")) == NULL){
		perror ("Could not open device programming file "PB_SYS_STOP". Has 'pb_driver-load' been run? ");
                exit(PB_ERROR_NODEVICE);
	}
	if ((pb_arm_fh = fopen(PB_SYS_ARM, "w")) == NULL){
		perror ("Could not open device programming file "PB_SYS_ARM". Has 'pb_driver-load' been run? ");
                exit(PB_ERROR_NODEVICE);
	}
	if ((pb_cont_fh = fopen(PB_SYS_CONT, "w")) == NULL){
		perror ("Could not open device programming file "PB_SYS_CONT". Has 'pb_driver-load' been run? ");
                exit(PB_ERROR_NODEVICE);
	}
}


/* Close the pulseblaster device */
void pb_fclose(){
	#if DEBUG_FUNCTION_CALLS==1
	eprintf("Now in function pb_fclose()\n");
	#endif

        fclose(pb_prog_fh);
        fclose(pb_start_fh);
        fclose(pb_stop_fh);
        fclose(pb_arm_fh);
        fclose(pb_cont_fh);

	/* warn if HAVE_PB is NOT defined to 1, i.e. dummy run. Useful as final status-line, so it doesn't get overlooked. */
	#if HAVE_PB!=1
        eprintf("WARNING: PulseBlaster hardware NOT actually used. This is a dummy run: see: %s\n", DEBUG_PB_TMP_DIR);
	#endif
}


/* pb_write_control() writes the trigger string to the correct control fd */
void pb_write_control(FILE *fh){
	#if DEBUG_WRITES==1
 	eprintf ("pb_write_control(): file-descriptor %d\n", fileno(fh)); /* Filename would be more useful! */
	#endif
	if (fprintf(fh, "%s", PB_SYS_TRIGGER_STRING) < 0){	/* Write, or exit with error */
		perror("Could not write to PulseBlaster device control file.");
		pb_fclose();
		exit(PB_ERROR_NODEVICE);
	}
}


/* pb_write_program writes the (vliw) buffer, vliw_buf[] containing a single VLIW word to the pulseblaster programming device filehandle. */
void pb_write_program(){
	static int vliw_num = 0;  /* how many lines written? */

	#if DEBUG_WRITES==1
 	eprintf ("pb_write_program(): file-descriptor %d, ", fileno(pb_prog_fh));
	for (i = 0; i < abs(sizeof(vliw_buf)); i++){  /* abs() keeps -Wextra happy */
		eprintf ("0x%02x ", vliw_buf[i]);
	}
	eprintf ("\n");
	#endif

	/* This Endianness is correct: VLIW Output MSB is in vliw_buf[0] and must be written to the PB device-file first */
	if (write(fileno(pb_prog_fh), vliw_buf, sizeof(vliw_buf)) < 0) {    /* Write, or exit with error */
		perror("Could not write to PulseBlaster device programming file.");
		pb_fclose();
		exit(PB_ERROR_NODEVICE);
	}

	vliw_num++;

	if (vliw_num > PB_MEMORY){	/* Check we haven't used too much memory. The PulseBlaster driver is unable to do this check, but it will prevent the PB from starting. */
		eprintf ("Error: just written VLIW instruction %d to the PulseBlaster, but it only has %d words available!\n", vliw_num, PB_MEMORY);
		exit (PB_ERROR_OUTOFMEM);
	}
}


/* The pulseblaster control files in /sys are "stop", "start", "arm", "continue". Echoing "1\n" to stop/start/arm puts the Pulseblaster into that
 * state, irrespective of what it is currently doing. Eg starting an already running pulseblaster means "stop, restart", and is
 * not (as one might think) a NO-OP.
 * However, "continue" is used to keep going when in a WAIT state, and is otherwise a NOP when the pulseblaster is running.
 * [The original distinction of pb_stop() and pb_halt() [stop with and without re-arming] is no longer needed, so pb_halt() has been removed to reduce confusion ]*/

/* Trigger the pulseblaster. We will exit (PB_ERROR_NODEVICE) if there is a problem. */
/* If the device is idle, it will be STARTED; if it is currently running, it will be STOPPED AND RESTARTED. */
/* [No need to call pb_arm() before doing pb_start()] */
void pb_start(){
	#if DEBUG_FUNCTION_CALLS==1
	eprintf("Now in function pb_start()\n");
	#endif
	/* Trigger the pulseblaster */
	pb_write_control(pb_start_fh);
}

/* Stop the pulseblaster. Leave it STOPPED and NOT RESPONSIVE to HW_TRIGGER. We will exit (PB_ERROR_NODEVICE) if there is a problem.*/
/* [After stopping, pb_start() will start the PB from software; but HW_TRIGGER won't work until we have done pb_arm() ] */
void pb_stop(){
	#if DEBUG_FUNCTION_CALLS==1
	eprintf("Now in function pb_stop()\n");
	#endif
	/* Stop the pulseblaster */
	pb_write_control(pb_stop_fh);

	/* Don't re-arm it */
}

/* Arm the pulseblaster, ready for it to start. We will exit (PB_ERROR_NODEVICE) if there is a problem. */
/* This will STOP the device if it's running, and leave in the ARMED state, ready for an HW_Trigger (or pb_start()) */
/* [Once ARMED, pb_cont() will also trigger it ]*/
void pb_arm(){
	#if DEBUG_FUNCTION_CALLS==1
	eprintf("Now in function pb_arm()\n");
	#endif
	/* Arm the pulseblaster. */
	pb_write_control(pb_arm_fh);
}

/* Continue the pulseblaster, (without first resetting it) during a WAIT opcode. We will exit (PB_ERROR_NODEVICE) if there is a problem. */
/* This will have no effect on the device if it's running (except in WAIT state). "pb_arm(); pb_cont()" is the same as pb_start(). */
void pb_cont(){
	#if DEBUG_FUNCTION_CALLS==1
	eprintf("Now in function pb_arm()\n");
	#endif
	/* Continue the pulseblaster. */
	pb_write_control(pb_cont_fh);
}



/* pb_make_vliw() takes args in the order (OUTPUT,OPCODE,ARG,LENGTH, NA[4]). It merges them into a very long instruction word (VLIW).		*
 * This VLIW is then written into vliw_buf[10] in Spincore's required order (OUTPUT,DATA,OPCODE,DELAY), merging DATA:0 and OPCODE.		*
 * The values are sanity-checked and compensated for various Misfeatures in the harware. Then, vliw_buf[] can be written to the device.		*
 * Returns 0 on success; else error code. The is_N/A values (na[5] are is used to check "-" is used explicitly instead of 0 as needed. 		*
 *																		*
 *  The arguments are:																*
 *	OUTPUT is a hexadecimal number (3 bytes)  [NOT a string!]										*
 *	OPCODE is a string such as "CONT" (case-insensitive)											*
 *	ARG    is a hexadecimal number (20 bits). [If not applicable, 0]									*
 *	LENGTH is a hexadecimal number (4 bytes). [If not applicable, 0]									*
 *	NA[4]  is an array containing "-  -", depending whether a "-" or 0 was in the source.							*
 *																		*
 *  What this means is:  1)Set the outputs. 2) Do the instruction taking length (Prepare, wait, jump to program_counter)			*
 *      [Note that: "STOP" ignores outputs; "WAIT" has the delay *after* wakeup ]								*
 *																		*
 *  This function also:																*
 *      - Accounts for the PB internal latency (by subtracting PB_INTERNAL_LATENCY) and checks that the delay is larger than PB_MINIMUM_DELAY. 	*
 *		The LENGTH argument to pb_make_vliw should be the ACTUAL delay that the user wants.						*
 * 	- Accounts for the loop count bug (by subtracting PB_BUG_LOOP_OFFSET)									*
 *	- Accounts for the longdelay bug (by subtracting PB_BUG_LONGDELAY_OFFSET).								*
 * 	- Converts a LONGDELAY with ARG = 1 (which is illegal) into the (equivalent) a CONT.							*
 *	- Verifies that the VLIW arguments are all legal and within range.									*
 * 	- Checks for the Bug with PB_BUG_PRESTOP_EXTRADELAY 											*
 * 	- Checks for the Bug with PB_BUG_WAIT_NOTFIRST												*
 * 	- Checks for the Bug with PB_BUG_WAIT_MINFIRSTDELAY											*
 *	- Checks that the number of instructions will fit within the Pulseblaster's RAM. 							*
 *	- Checks for loop depth. and for paired address_of(loop) == arg_of(endloop)								*
 *  It does not check for call depth (stack), as this is impossible (call/return is not nested; numbers of each type not necessarily equal.)    *
 *  It does not check for idiot-proofing, such as branching out of a loop with a GOTO.								*/

int pb_make_vliw (unsigned long output, char opcode[OPCODE_MAXLEN], unsigned long arg, unsigned long length, char na[5]) {
	unsigned char vliw_output[3], vliw_data[3], vliw_delay[4];	/* function args prefaced with pbv_ for clarity. unsigned long for simplicity! */
	unsigned char vliw_opcode, vliw_data0_opcode;			/* note global variable: loop_depth */
	static int vliw_number = 0;					/* Instruction-number/line-number. 0-based, same as pb_parse, and addresses. */
	static long prev_vliw_length = 0;				/* Save previous vliw_instruction length */
	static STACK loop_addr_stack = {.count = 0};			/* Create stack, initialise size = 0. */
	int loop_addr_prev = 0;

	#if DEBUG_FUNCTION_CALLS==1
	eprintf("Now in function pb_make_vliw ( 0x%0lx, %s, 0x%02lx, 0x%02lx)\n", output, opcode, arg, length);
	#endif

	/* Opcodes */					/* Depending on the string value of opcode, assign the correct value to vliw_opcode, and check that arg */
	if (strcasecmp(opcode,"CONT") == 0){   		/* For some opcodes (STOP, WAIT), there are also checks/modifications for output and length. */
		vliw_opcode = PB_OPCODE_CONT; /* 0 */	/* OPCODES are defined in pulseblaster.h, the manual, and pulseblaster-opcodes.txt */
		if (arg != 0){  
			eprintf("Error at line %d: argument to opcode CONT must be zero (and is ignored), but it is 0x%02lx.\n", vliw_number + 1, arg);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (strcmp (na, "  - ")){		/* '-' vs '0'. */
			eprintf("WARNING at line %d: opcode CONT should have exactly one explicit 'N/A' ('-' rather than '0'), as the arg.\n", vliw_number + 1);
		}

	}else if (strcasecmp(opcode,"LONGDELAY") == 0){
		vliw_opcode = PB_OPCODE_LONGDELAY; 	/* 7 */
		if ((arg == 1) && (PB_LONGDELAY_ARG_MIN == 2)){	/* Do what I mean: longdelay(1) is illegal; convert it to  cont(1). */
			vliw_opcode = PB_OPCODE_CONT;
			arg = 0;
			#if DEBUG==1
			eprintf("Debug: DWIM at line %d: demoting opcode LONGDELAY (with ARG %d) to CONT. PB_LONGDELAY_ARG_MIN is %d.\n", vliw_number + 1, (int)arg, PB_LONGDELAY_ARG_MIN);
			#endif
		}else{
			if ((arg < PB_LONGDELAY_ARG_MIN) || (arg > PB_ARG_20BIT)){
				eprintf("Error at line %d: argument to opcode LONGDELAY must be a counter between %d and 0x%02X (inclusive), but it is 0x%02lx.\n", vliw_number + 1,PB_LONGDELAY_ARG_MIN,PB_ARG_20BIT,arg);
				return (PB_ERROR_INVALIDINSTRUCTION);
			}
			if (strcmp (na, "    ")){	/* '-' vs '0'. */
				eprintf("WARNING at line %d: opcode LONGDELAY should have exactly zero explicit 'N/A' ('-' rather than '0').\n", vliw_number + 1);
			}
			arg -= PB_BUG_LONGDELAY_OFFSET;		/* account for pulseblaster longdelay arg being off by two */
		}

	}else if (strcasecmp(opcode,"LOOP") == 0){
		vliw_opcode = PB_OPCODE_LOOP; /* 2 */
		loop_depth++; /* entered loop */
		push (&loop_addr_stack, vliw_number);  /* Push ADDRESS of this loop onto stack */
		if ((arg < PB_LOOP_ARG_MIN) || (arg > PB_ARG_20BIT)){
			eprintf("Error at line %d: argument to opcode LOOP must be a counter between %d and 0x%02X (inclusive), but it is 0x%02lx.\n", vliw_number + 1,PB_LOOP_ARG_MIN,PB_ARG_20BIT,arg);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (strcmp (na, "    ")){	/* '-' vs '0'. */
			eprintf("WARNING at line %d: opcode LOOP should have exactly zero explicit 'N/A' ('-' rather than '0').\n", vliw_number + 1);
		}
		arg -= PB_BUG_LOOP_OFFSET;		/* account for pulseblaster loop arg being off by one */

	}else if (strcasecmp(opcode,"ENDLOOP") == 0){
		vliw_opcode=PB_OPCODE_ENDLOOP;  /* 3 */
		loop_depth--;
		loop_addr_prev = pop (&loop_addr_stack);  /* Pop address of the corresponding (hopefully correctly nested) loop from stack */
		if (arg >= PB_MEMORY -1){
			eprintf("Error at line %d: argument to opcode ENDLOOP must be an address between 0 and 0x%02X, but it is 0x%02lx.\n", vliw_number + 1,PB_MEMORY-1,arg);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (strcmp (na, "    ")){	/* '-' vs '0'. */
			eprintf("WARNING at line %d: opcode ENDLOOP should have exactly zero explicit 'N/A' ('-' rather than '0').\n", vliw_number + 1);
		}
		if ((unsigned long)loop_addr_prev != arg){
			eprintf("Error at line %d: argument to opcode ENDLOOP must be the address of the corresponding nested loop. Arg = %ld, but address of loop was = %d.\n", vliw_number + 1, arg, loop_addr_prev);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}

	}else if (strcasecmp(opcode,"GOTO") == 0){
		vliw_opcode=PB_OPCODE_GOTO;   /* 6 */
		if (arg >= PB_MEMORY -1){
			eprintf("Error at line %d: argument to opcode GOTO must be an address between 0 and 0x%02X, but it is 0x%02lx.\n", vliw_number + 1,PB_MEMORY-1,arg);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (strcmp (na, "    ")){	/* '-' vs '0'. */
			eprintf("WARNING at line %d: opcode GOTO should have exactly zero explicit 'N/A' ('-' rather than '0').\n", vliw_number + 1);
		}

	}else if (strcasecmp(opcode,"CALL") == 0){
		vliw_opcode=PB_OPCODE_CALL;    /* 4 */
		if (arg >= PB_MEMORY - 1){
			eprintf("Error at line %d: argument to opcode CALL must be an address between 0 and 0x%02X, but it is 0x%02lx.\n", vliw_number + 1,PB_MEMORY-1,arg);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (strcmp (na, "    ")){	/* '-' vs '0'. */
			eprintf("WARNING at line %d: opcode CALL should have exactly zero explicit 'N/A' ('-' rather than '0').\n", vliw_number + 1);
		}

	}else if (strcasecmp(opcode,"RETURN") == 0){
		vliw_opcode=PB_OPCODE_RETURN;    /* 5 */
		if (arg != 0){
			eprintf("Error at line %d: argument to opcode RETURN must be zero (and is ignored), but it is 0x%02lx.\n", vliw_number + 1,arg);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (strcmp (na, "  - ")){	/* '-' vs '0'. */
			eprintf("WARNING at line %d: opcode RETURN should have exactly one explicit 'N/A' ('-' rather than '0'), as the arg.\n", vliw_number + 1);
		}

	}else if (strcasecmp(opcode,"WAIT") == 0){    /* Note, this test is *also* performed above, to deal with PB_MINIMUM_WAIT_DELAY */
		vliw_opcode=PB_OPCODE_WAIT;    /* 8 */
		if (arg != 0){
			eprintf("Error at line %d: argument to opcode WAIT must be zero (and is ignored), but it is 0x%02lx.\n", vliw_number + 1,arg);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (strcmp (na, "  - ")){	/* '-' vs '0'. */
			eprintf("WARNING at line %d: opcode WAIT should have exactly one explicit 'N/A' ('-' rather than '0'), as the arg.\n", vliw_number + 1);
		}
		if (length < PB_MINIMUM_WAIT_DELAY) {  /* If opcode is WAIT we must also check for PB_MINIMUM_WAIT_DELAY */
			eprintf("Error: length for a WAIT instruction must be at least PB_MINIMUM_WAIT_DELAY (%d) (i.e. PB_WAIT_LATENCY (%d) - PB_INTERNAL_LATENCY (%d) + PB_MINIMUM_DELAY (%d)), but it is 0x%02lx.\n",PB_MINIMUM_WAIT_DELAY,PB_WAIT_LATENCY,PB_INTERNAL_LATENCY,PB_MINIMUM_WAIT_DELAY,length);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (PB_BUG_WAIT_NOTFIRST > vliw_number){ /* If opcode is WAIT, it may not come first */
			eprintf("Error: The first instruction may NOT be a WAIT.\n");
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if ((vliw_number ==1 ) && (prev_vliw_length < PB_BUG_WAIT_MINFIRSTDELAY)){
			eprintf("Error: If WAIT is the 2nd instruction, the previous delay must be at least PB_BUG_WAIT_MINFIRSTDELAY (%d), but but it was only 0x%02lx.\n",PB_BUG_WAIT_MINFIRSTDELAY,prev_vliw_length);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}

	}else if (strcasecmp(opcode,"STOP") == 0){
		vliw_opcode=PB_OPCODE_STOP;  /* 1 */
		if (arg != 0){
			eprintf("Error at line %d: argument to opcode STOP must be zero (and is ignored), but it is 0x%02lx.\n", vliw_number + 1,arg);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (strcmp (na, "- --")){		/* '-' vs '0'. */
			eprintf("WARNING at line %d: opcode STOP should have exactly three explicit 'N/A' ('-' rather than '0'), as out/arg/len.\n", vliw_number + 1);
		}
		if (output != 0){  /* Stop keeps the previous output values. Explicitly insist upon this. */
			eprintf("Error at line %d: output for opcode STOP must be zero (and is ignored), but it is 0x%02lx.\n", vliw_number + 1,output);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (length != 0){  /* Stop doesn't pay attention to the length. Explicitly insist upon this. */
			eprintf("Error at line %d: length for opcode STOP must be zero (and is ignored), but it is 0x%02lx.\n", vliw_number + 1,length);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		length = PB_MINIMUM_DELAY;  /* now set it to PB_MINIMUM_DELAY, just for safety */

		if (prev_vliw_length < PB_MINIMUM_DELAY + PB_BUG_PRESTOP_EXTRADELAY){  /* Note: the PREVIOUS instruction's minimum length is PB_BUG_PRESTOP_EXTRADELAY ( =2) higher than expected */
			eprintf("Error at line %d: the instruction *preceding* opcode STOP must have length >= PB_MINIMUM_DELAY + PB_BUG_PRESTOP_EXTRADELAY (i.e. %d), but it is 0x%02lx.\n", vliw_number,PB_MINIMUM_DELAY+PB_BUG_PRESTOP_EXTRADELAY,prev_vliw_length);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		
	}else if (strcasecmp(opcode,"DEBUG") == 0){   	/* This is to help the programmer. Treated as if it were "CONT". Raises a notice. */
		vliw_opcode = PB_OPCODE_CONT; /* 0 */	
		eprintf("NOTICE at line %d: found DEBUG instruction. (Treated as CONT).\n", vliw_number + 1);
		if (arg != 0){ 
			eprintf("Error at line %d: argument to opcode DEBUG must be zero (and is ignored), but it is 0x%02lx.\n", vliw_number + 1, arg);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (strcmp (na, "  - ")){		/* '-' vs '0'. */
			eprintf("WARNING at line %d: opcode DEBUG should have exactly one explicit 'N/A' ('-' rather than '0'), as the arg.\n", vliw_number + 1);
		}
	
		
	}else if (strcasecmp(opcode,"MARK") == 0){   	/* This is to help the programmer at simulation time. Treated as if it were "CONT". */
		vliw_opcode = PB_OPCODE_CONT; /* 0 */	
		if (arg != 0){ 
			eprintf("Error at line %d: argument to opcode MARK must be zero (and is ignored), but it is 0x%02lx.\n", vliw_number + 1, arg);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (strcmp (na, "  - ")){		/* '-' vs '0'. */
			eprintf("WARNING at line %d: opcode MARK should have exactly one explicit 'N/A' ('-' rather than '0'), as the arg.\n", vliw_number + 1);
		}
	
	}else if (strcasecmp(opcode,"NEVER") == 0){   	/* Never is dead-code elimination by pb_parse. It means that this instruction has been jumped over, and program flow never reaches it */
		vliw_opcode = PB_OPCODE_CONT; /* 0 */	/* It is treated as cont to ensure that the PB sees a valid assembly instruction */
		if (arg != 0){ 
			eprintf("Error at line %d: argument to opcode NEVER must be zero (and is ignored), but it is 0x%02lx.\n", vliw_number + 1, arg);
			return (PB_ERROR_INVALIDINSTRUCTION);
		}
		if (strcmp (na, "  - ")){		/* '-' vs '0'. */
			eprintf("WARNING at line %d: opcode NEVER should have exactly one explicit 'N/A' ('-' rather than '0'), as the arg.\n", vliw_number + 1);
		}

	}else{
		eprintf("Error at line %d: opcode %s is not valid.\n", vliw_number + 1,opcode);
		return (PB_ERROR_INVALIDINSTRUCTION);
	}

	/* Outputs */
	if ((output > PB_OUTPUTS_24BIT)){			/* Check value of output. */
		eprintf("Error: output must be between 0 and 0x%02X, but it is 0x%02lx.\n",PB_OUTPUTS_24BIT,output);
		return (PB_ERROR_INVALIDINSTRUCTION);
	}else{
		vliw_output[2] = (output & 0xFF0000) >> 16;	/* Extract individual bytes using bitwise AND and shift. */
		vliw_output[1] = (output & 0x00FF00) >> 8;
		vliw_output[0] = (output & 0x0000FF);
	}

	/* Lengths */
	prev_vliw_length = length;		/* Save length of this instruction, for check next time */
	if ((length < PB_MINIMUM_DELAY) || (length > PB_DELAY_32BIT)){  /* Check value of length */
		eprintf("Error: length must be between PB_MINIMUM_DELAY (%d) and PB_DELAY_32BIT (0x%02X), but it is 0x%02lx.\n",PB_MINIMUM_DELAY, PB_DELAY_32BIT, length);
		return (PB_ERROR_INVALIDINSTRUCTION);
	}
	length -= PB_INTERNAL_LATENCY;		/* Account for Pulseblaster latency (3 cycles). */

	vliw_delay[3] = (length & 0xFF000000) >> 24;	/* Extract individual bytes using bitwise AND and shift. */
	vliw_delay[2] = (length & 0x00FF0000) >> 16;
	vliw_delay[1] = (length & 0x0000FF00) >> 8;
	vliw_delay[0] = (length & 0x000000FF);


	/* Arguments */
	vliw_data[2] = (arg & 0x0FF000) >> 12;		/* Extract individual bytes using bitwise AND and shift. */
	vliw_data[1] = (arg & 0x000FF0) >> 4;
	vliw_data[0] = (arg & 0x00000F) << 4;

	vliw_data0_opcode=(vliw_data[0] | vliw_opcode);	/* Merge the vliw_data[0] and vliw_opcode nibbles together. */


	#if DEBUG==1
	/*print some diagnostics. Note: use 0x%02x, rather than %-#4x  so that 01 prints as 0x01 and not 0x1. */
	eprintf("VLIW (line %3d): Out: [2]  [1]  [0]    Data: [2]  [1]  [0:OPCODE]    Delay: [3]  [2]  [1]  [0]  \n"
		"                      0x%02x 0x%02x 0x%02x         0x%02x 0x%02x 0x%02x                 0x%02x 0x%02x 0x%02x 0x%02x\n",
		vliw_number + 1, vliw_output[2],vliw_output[1],vliw_output[0],  vliw_data[2],vliw_data[1],vliw_data0_opcode,  vliw_delay[3],vliw_delay[2],vliw_delay[1],vliw_delay[0]);
	#endif

	vliw_buf[0] = vliw_output[2];	/*Now, assemble the VLIW instruction into the vliw_buffer. This is the order it is written to the hardware */
	vliw_buf[1] = vliw_output[1];	/*First 3 bytes are the OUTPUT (MSByte first) */
	vliw_buf[2] = vliw_output[0];

	vliw_buf[3] = vliw_data[2];	/*20 bit data field... */
	vliw_buf[4] = vliw_data[1];
	vliw_buf[5] = vliw_data0_opcode;/*...and a 4 bit opcode. (vliw_data0_opcode is 2 nibbles: vliw_data[0] and Opcode.) */

	vliw_buf[6] = vliw_delay[3];	/*Last 4 bytes are the vliw_delay length. */
	vliw_buf[7] = vliw_delay[2];
	vliw_buf[8] = vliw_delay[1];
	vliw_buf[9] = vliw_delay[0];

	if (loop_depth < 0 || loop_depth > PB_LOOP_MAXDEPTH){ /* Check that we aren't too deep in loops. Note: this only catches the most obvious case. */
		eprintf ("Errror at line %d: loop_depth is %d, but should be between 0 and %d.\n",vliw_number + 1,loop_depth,PB_LOOP_MAXDEPTH);
		return (PB_ERROR_LOOPDEPTH);
	}

	if (vliw_number >= PB_MEMORY){		/* Check we haven't used too much memory (useful in pb_asm to have this here as well as in pb_write_program)*/
		eprintf ("Error at line %d: this is one line too many to fit in the memory of 0x%02X bytes!\n", vliw_number + 1, PB_MEMORY);
		return (PB_ERROR_OUTOFMEM);
	}

	vliw_number++;				/* Increment the line counter */

	return (0); /* If we get to here without exiting, all is well. Resulting VLIW is in vliw_buf[] */
}


/* At end of vliw parsing. The global variable loop_depth should be zero */
int check_loop_depth(){
	if (loop_depth != 0){
		eprintf ("Error: reached end of file: (number_of_loops - number_of_endloops) isn't zero, but %d.\n", loop_depth);
		return (PB_ERROR_LOOPDEPTH);
	}else{
		return (0);
	}
}


/* Write a trivial program to the PulseBlaster so as to set the output values. Exit (PB_ERROR_NODEVICE) if there is a problem. */
void pb_init(unsigned char flags[3]){
	unsigned long long_flag;
	char na[5] = "    ";

	#if DEBUG_FUNCTION_CALLS==1
	eprintf("Now in function pb_init( 0x%02x, 0x%02x, 0x%02x )\n", flags[2],flags[1],flags[0]);
	#endif
	/* Current boards don't support the internal flag register. Writing a short pulseblaster program instead */

	/* Write out a very short, 2-line program */
	long_flag=((flags[2] << 16) | (flags[1] << 8) | (flags[0]));  				/* Combine flags[2,1,0] into a single output value */
	na[2] = '-';
	pb_make_vliw (long_flag, "cont", 0, PB_MINIMUM_DELAY + PB_BUG_PRESTOP_EXTRADELAY, na); 	/* Output the flags. Delay minimum number of cycles possible */
	pb_write_program();

	na[0] = na[3] = na[2];
	pb_make_vliw (0, "stop", 0, 0, na);								/* Stop. N.B. the instruction before a STOP must be longer by PB_BUG_PRESTOP_EXTRADELAY */
	pb_write_program ();

	/* Run the program */
	pb_start();

	/* The program will execute, and then halt. */
	/* Sleep briefly before returning, to allow time for the program to execute. (Avoids race condition) */
 	usleep(10 * PB_MINIMUM_DELAY);  /* 900 us. This is actually quite a large safety factor, since PB_MINIMUM_DELAY is in TICKS, and usleep is in us. */

}


/* Parse a line of program source, (passed in as the buffer src_line, of size VLIW_MAXLEN). Parse it into VLIW tokens, and put the result into vliw_buf[]; then return 0 */
/* If the line is invalid, exit with error. If the line is BLANK or a COMMENT, return -1. DO NOT then call pb_write_program(), as you would duplicate the last instruction! */
/* This is where the "-" in .vliw files meaning "not applicable" is converted to 0 for use by pb_make_vliw() */
int pb_parse_sourceline (char src_line[VLIWLINE_MAXLEN], int line_num){	/* line_num is for printing the line of source-code affected (so make it 1-based) */

	char buffer[VLIWLINE_MAXLEN];			/* Copy the source-line to a buffer, because strtok chops it up */
	unsigned int column = 0;			/* which column are we on */
	char na[5] = "    ";				/* How many "-" have we seen? */
	char *token;   					/* pointer to string token */
	char *whitespace = "\t\n ";			/* space, newline or tab */
	char *end;					/* pointer to unused part in strtol() */
	unsigned long output = 0, length = 0, arg = 0;	/* Results, to go into pb_make_vliw() */
	char opcode[OPCODE_MAXLEN];

	/*If the actual line length (including the trailing \n) > VLIW_MAXLEN -1, then we are in trouble, since fgets will start to break up lines. */
	/*The amount read into the buffer must either be < (VLIW_MAXLEN -1), or, IFF the last character is a \n,  exactly VLIW_MAXLEN -1 */
	if ((strlen (src_line) > VLIWLINE_MAXLEN - 1) && (src_line[VLIWLINE_MAXLEN - 2] != '\n') ){
		eprintf ("Error in source file at line %d: Line length is too long (> %d characters). Line begins:\n\t%s\n", line_num, VLIWLINE_MAXLEN - 1 ,src_line);
 		return (PB_ERROR_TOKENISING);
	}

	strcpy(buffer, src_line);		/* Safe: we defined these the same size */

	#if DEBUG==1
	eprintf ("Line %d is: %s",line_num, src_line);
	#endif

	token = strtok (buffer, whitespace);	/* Find the first whitespace-delimited token within the string. If the string is an empty line, nothing will be found. */

	while (token != NULL){

		if ((token[0] == '/') && (token[1] == '/')) {
			#if DEBUG==1
			eprintf ("Encountered comment character (//'). Ignoring rest of line\n");  /* Ignore anything after a '//' */
			#endif
			break;
		}

		/* eprintf ("Line: %3d, column: %d, token: %s\n", line_num, column, token); */

		/* Parse the data. strtol() converts the string (which may be decimal or hex) to a long. Catch anything illegal. Note: we rely on "-" being parsed as "-0", i.e. zero. */

		if (token[0] == '-'){
			na[column] = '-';	/* Explicitly signal "-" rather than 0. */
			if (strlen(token) > 1){
				eprintf("Error in source at line %d: column %d, '%s' is negative. Line is:\n\t%s\n", line_num, column +1, token, src_line);
				return (PB_ERROR_TOKENISING);
			}
		}

		if ((token[0] == '0') && (strlen(token) > 1) &&(token[1] != 'x')){ /* Why, why, why is leading zero without 0x interpreted as Octal?  Any accidental use of Octal is a human error. */
			eprintf("Error in source at line %d: column %d, '%s' begins '0' (but not '0x'). Octal is evil! Line is:\n\t%s\n", line_num, column +1, token, src_line);
			return (PB_ERROR_TOKENISING);
		}

		errno = 0;
		if (column == 0){			/* Column 0, is the vliw output value */
			output = strtoul(token,&end,0);

		}else if (column == 1){			/* Column 1, is the vliw opcode (string) */
			strncpy(opcode, token, OPCODE_MAXLEN);
			*end = 0;  			/* Not calling strtoul for this column. Fake success */

		}else if (column == 2){			/* Column 2, is the output arg */
			arg = strtoul(token,&end,0);   /* Note, for human-readable purposes, a '-' here means N/A, and is treated as a zero. strtol() copes just fine */

		}else if (column == 3){  		/* Column 3, is the vliw length value */
			length=strtoul(token,&end,0);

		}else if (column > 3){
			eprintf ("Error in source at line %d: too many arguments Expect 4. Line is:\n\t%s\n", line_num, src_line);
			return (PB_ERROR_TOKENISING);
		}

		if (errno != 0 || *end != 0 || end == token){	/* Were there any unused chars in the string, eg trailing garbage. */
			if (na[column]  != '-'){		/* [Also detects empty string, which doesn't happen thanks to strtok/whitespace and "-" */
				eprintf("Error in source at line %d: strtoul() couldn't parse column %d, '%s'. Line is:\n\t%s\n", line_num, column +1, token, src_line);
				return (PB_ERROR_TOKENISING);
			}
		}

		token = strtok (NULL, whitespace); 	/* Find any subsequent whitespace-delimited token within the string */
		column++;
	}

	if ((column > 0) && (column < 4)){		/* Too few args in this line! */
		eprintf("Error in source at line %d: too few arguments. Expect 4. Line is:\n\t%s\n", line_num, src_line);
		return (PB_ERROR_TOKENISING);
	}

	#if DEBUG==1
	if (column == 0){				/* Result. */
		eprintf (" parsed as:   Blank line (or Comment)\n");
	}else{
		eprintf (" parsed as:   Output: 0x%02lx   |   OPCODE: %s   |   ARG: 0x%02lx   |   DELAY: 0x%02lx\n", output, opcode, arg, length);
	}
	#endif

	if (column == 0){
		return (-1);					/* IMPORTANT: do NOT then call pb_write_program() as the vliw_buf[] will be the previous line's and we don't want to duplicate it! */
	}else{
		return (pb_make_vliw (output, opcode, arg, length, na));/* Convert these into a single VLIW in vliw_buf[]. */
		/* Now, the caller can invoke pb_write_program();  */
	}
}
