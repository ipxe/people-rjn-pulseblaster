/* This is pb_cont.c  It continues the already-running pulseblaster. during a WAIT *
 * Most of the important, shared stuff is in pb_functions.c 
 * This is Free Software, released under the GNU GPL, version 3 or later.
 */

#include "pb_functions.c"

void printhelp(){
	fprintf(stderr,"pb_cont re-triggers a PulseBlaster that is WAITing.\n"
		       "If the PulseBlaster is running, pb_cont will have no effect.\n"
		       "Unlike pb_start, pb_cont does not RESET the PulseBlaster to the start of program.\n"
		       "[pb_start is equivalent to \"pb_arm; pb_cont\", i.e. arm and cont are the primitives.]\n"
		       "Usage: pb_cont      (it takes no arguments).\n");
}
		       
int main(int argc, char *argv[] __attribute__ ((unused)) ){
	if (argc > 1){
		printhelp();
		exit (PB_ERROR_WRONGARGS);
	}

	/* Open pulseblaster; check device is present */
	pb_fopen();

	/* Continue the pulseblaster */
	pb_cont();
	fprintf(stderr, "Pulseblaster has been continued.\n");

       	/* Close the device */
	pb_fclose();

        return PB_EXIT_OK;      /* i.e. zero */
}
