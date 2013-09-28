/* This is pb_stop.c  It stops the pulseblaster (and doesn't arm it to restart).
 * Most of the important, shared stuff is in pb_functions.c
 * This is Free Software, released under the GNU GPL, version 3 or later.
 */

#include "pb_functions.c"

void printhelp(){
	fprintf(stderr,"pb_stop places the (already-programmed) PulseBlaster into the STOPped state.\n"
		       "In this state, it is not ARMed, so HW_Trigger will be ignored. However, pb_start will work.\n"
		       "Usage: pb_stop      (it takes no arguments).\n");
}

int main(int argc, char *argv[] __attribute__ ((unused)) ){
	if (argc > 1){
		printhelp();
		exit (PB_ERROR_WRONGARGS);
	}

	/* Open pulseblaster; check device is present */
	pb_fopen();

	/* Stop the pulseblaster. Do not re-arm it (though a pb_start() will still work) */
	pb_stop();
	fprintf(stderr, "PulseBlaster has been stopped. (Not armed; ignoring HW_Trigger).\n");

	/* Close the device */
	pb_fclose();
	
        return PB_EXIT_OK;      /* i.e. zero */
}
