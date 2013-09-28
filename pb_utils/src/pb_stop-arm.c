/* This is pb_stop-arm.c  It stops the pulseblaster and re-arms it to restart
 * It's exactly the same as pb_stop;pb_arm,  indeed, it's the same as just doing pb_arm (which implicitly stops the PB first). Pure syntactic sugar ;-)
 * Most of the important, shared stuff is in pb_functions.c
 * This is Free Software, released under the GNU GPL, version 3 or later.
 */

#include "pb_functions.c"

void printhelp(){
	fprintf(stderr,"pb_stop-arm places the (already-programmed) PulseBlaster into the STOPPED,ARMED state.\n"
		       "In this state, it will start with either pb_start or HW_Trigger.\n"
		       "This is exactly equivalent to \"pb_stop; pb_arm\". \n"
		       "Usage: pb_stop-arm      (it takes no arguments).\n");
}

int main(int argc, char *argv[] __attribute__ ((unused)) ){
	if (argc > 1){
		printhelp();
		exit (PB_ERROR_WRONGARGS);
	}

	/* Open pulseblaster; check device is present */
	pb_fopen();

	/* Stop the pulseblaster. Then re-arm it. */
	pb_stop();
	pb_arm();
	fprintf(stderr, "PulseBlaster has been stopped and re-armed (ready for HW_Trigger or pb_start).\n");

	/* Close the device */
	pb_fclose();

        return PB_EXIT_OK;      /* i.e. zero */
}
