/* This is pb_start.c  It starts the pulseblaster. *
 *  Most of the important, shared stuff is in pb_functions.c */

#include "pb_functions.c"

void printhelp(){
	fprintf(stderr,"pb_print_config prints out the configuration settings compiled into pb_utils.\n"
		       "It's a trivial dump of specific lines from pulseblaster.h, used by pb_parse.\n"
		       "The format is: \"NAME: Value\\n\".\n"
		       "Usage: pb_print_config      (it takes no arguments).\n");
}
		       
int main(int argc, char *argv[] __attribute__ ((unused)) ){
	if (argc > 1){
		printhelp();
		exit (PB_ERROR_WRONGARGS);
	}

	/* Print config dump. Format is:  NAME colon space value newline. */
	printf ("DEBUG: %d\n"
		"PB_VERSION: %s\n"
		"PB_CLOCK_MHZ: %d\n"
		"PB_TICK_NS: %d\n"
		"PB_MEMORY: %d\n"
		"PB_LOOP_MAXDEPTH: %d\n"
		"PB_SUB_MAXDEPTH: %d\n"
		"PB_MINIMUM_DELAY: %d\n"
		"PB_WAIT_LATENCY: %d\n"
		"PB_MINIMUM_WAIT_DELAY: %d\n"
		"PB_BUG_PRESTOP_EXTRADELAY: %d\n"
		"PB_BUG_WAIT_NOTFIRST: %d\n"
		"PB_BUG_WAIT_MINFIRSTDELAY: %d\n"
		"PB_OUTPUTS_24BIT: %u\n"
		"PB_DELAY_32BIT: %u\n"
		"PB_ARG_20BIT: %u\n"
		"PB_LOOP_ARG_MIN: %d\n"
		"PB_BUG_LOOP_OFFSET: %d\n"
		"PB_LONGDELAY_ARG_MIN: %d\n"
		"PB_BUG_LONGDELAY_OFFSET: %d\n"
		"VLIWLINE_MAXLEN: %d\n"
		,
		DEBUG, 
		PB_VERSION, 
		PB_CLOCK_MHZ, 
		PB_TICK_NS, 
		PB_MEMORY,
		PB_LOOP_MAXDEPTH,
		PB_SUB_MAXDEPTH,
		PB_MINIMUM_DELAY,
		PB_WAIT_LATENCY,
		PB_MINIMUM_WAIT_DELAY,
		PB_BUG_PRESTOP_EXTRADELAY,
		PB_BUG_WAIT_NOTFIRST,
		PB_BUG_WAIT_MINFIRSTDELAY,
		PB_OUTPUTS_24BIT,
		PB_DELAY_32BIT,
		PB_ARG_20BIT,
		PB_LOOP_ARG_MIN,
		PB_BUG_LOOP_OFFSET,
		PB_LONGDELAY_ARG_MIN,
		PB_BUG_LONGDELAY_OFFSET,
		VLIWLINE_MAXLEN);

        return PB_EXIT_OK;      /* i.e. zero */
}
