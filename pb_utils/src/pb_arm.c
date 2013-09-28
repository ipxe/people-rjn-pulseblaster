/* This is pb_arm.c  It arms the already-programmed pulseblaster. *
 * Most of the important, shared stuff is in pb_functions.c 
 * This is Free Software, released under the GNU GPL v3+ */

#include "pb_functions.c"

void printhelp(){
	fprintf(stderr,"pb_arm places the (already-programmed) PulseBlaster into the ARMED state, ready for HW_Trigger.\n"
		       "If the PulseBlaster is running, pb_arm will implicitly stop and reset it.\n"
		       "[pb_arm is not required (but harmless), between pb_prog and pb_start.]\n"
		       "Usage: pb_arm      (it takes no arguments).\n");
}
		       
int main(int argc, char *argv[] __attribute__ ((unused)) ){
	if (argc > 1){
		printhelp();
		exit (PB_ERROR_WRONGARGS);
	}

	/* Open pulseblaster; check device is present */
	pb_fopen();

	/* Trigger the pulseblaster */
	pb_arm();
	fprintf(stderr, "Pulseblaster has been armed, and is ready for HW_Trigger.\n");

       	/* Close the device */
	pb_fclose();

        return PB_EXIT_OK;      /* i.e. zero */
}
