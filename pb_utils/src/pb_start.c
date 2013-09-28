/* This is pb_start.c  It starts the pulseblaster. *
 * Most of the important, shared stuff is in pb_functions.c
 * This is Free Software, released under the GNU GPL, version 3 or later.
 */

#include "pb_functions.c"

void printhelp(){
	fprintf(stderr,"pb_start starts the (already-programmed) PulseBlaster with a software trigger.\n"
		       "If the PulseBlaster is already running, pb_start will implicitly stop it first.\n"
		       "pb_start does not care whether the PulseBlaster has been armed.\n"
		       "Usage: pb_start      (it takes no arguments).\n");
}
		       
int main(int argc, char *argv[] __attribute__ ((unused)) ){
	if (argc > 1){
		printhelp();
		exit (PB_ERROR_WRONGARGS);
	}

	/* Open pulseblaster; check device is present */
	pb_fopen();

	/* Trigger the pulseblaster */
	pb_start();
	fprintf(stderr, "Pulseblaster has been triggered, and is now running the program.\n");

       	/* Close the device */
	pb_fclose();

        return PB_EXIT_OK;      /* i.e. zero */
}
