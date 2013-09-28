/* This is pb_vliw.c  It prints a short example VLIW program, with comments
 * Most of the important, shared stuff is in pb_functions.c
 * This is Free Software, released under the GNU GPL, version 3 or later.
 */

#include "pb_functions.c"

void printhelp(){
	fprintf(stderr,"pb_vliw prints an example VLIW file, with OpCode summary\n");
}		       

int main(int argc, char *argv[] __attribute__ ((unused)) ){

	if (argc > 1){   /* Print help, if invoked with -h, or --help */
               	printhelp();
               	exit (PB_ERROR_WRONGARGS);
	}else{
		printf ("//This is an example VLIW file. 4 tokens per line, whitespace delimited. Optional comments begin with '//'.\n"
			"//Output (24-bit Hex/Decimal). Opcode (case-insensitive string). Arg (20-bit data, or '-'). Length (32-bit Hex/Decimal).\n"
			"//Length is how long the instruction takes to execute; it isn't a \"delay before the opcode\", nor \"a delay after the opcode\".\n"
			"\n"
			"//PulseBlaster: model %s. Tick: %d ns. Minimum length: %d. Max nested loops: %d. Max nested calls: %d.  Max instructions: %d\n"
			"//The .vliw file represents an idealised PulseBlaster: offsets are automatically calculated, restrictions are removed, constraints are checked.\n"
			"\n"
			"//OUTPUT	OPCODE		ARG		LENGTH		//Comment\n"
			"\n"
			"0x000000	cont		-		10		//Set outputs, continue to next instruction, taking length cycles.\n"
			"0x000001	longdelay	n		20		//Set outputs, continue to next instruction, taking arg * length cycles.\n"
			"\n"
			"0x02		goto		addr		50		//Set outputs, jump to instruction at arg, taking length cycles.\n"
			"\n"
			"0x03		call		addr		60		//Set outputs, call subroutine at arg, taking length cycles.\n"
			"0x04		return		-		70		//Set outputs, return to the address after the caller, taking length cycles.\n"
			"\n"
			"0xffffff	loop		n		30		//Set outputs. Unless already in this loop, start a loop of arg counts. Continue to next instruction, taking length cycles.\n"
			"0x000000	endloop		addr		40		//Set outputs. Either exit the loop or jump-back to address arg [the corresponding loop instruction], taking length cycles.\n"
			"\n"
			"0x05		wait		-		80		//Set outputs, wait for software-continue (pb_cont) or hw_trigger. Then proceed, taking length cycles.\n"
			"-		stop		-		-		//Ignore outputs. Just halt. To restart, need  (hw_reset; hw_trigger), or pb_start.\n"
			"\n",
			PB_VERSION, PB_TICK_NS, PB_MINIMUM_DELAY, PB_LOOP_MAXDEPTH, PB_SUB_MAXDEPTH, PB_MEMORY);
	}

	return PB_EXIT_OK;	/* i.e. zero */
}
