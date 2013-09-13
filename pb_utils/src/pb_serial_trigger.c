/* Serial port remote trigger for pulseblaster: Use the serial port DTR line to trigger the PB's hardware trigger input.
 * This is better than the software trigger if there is a gate (D-type) in the signal path way, as it allows for synchronisation 
 * Copyright Richard Neill, 2012; This is Free Software, licensed under the GNU GPL v3+.  
 * This is inspired by the "sled" serial led program by Guido Socher which is itself GPL.
 * For hardware circuit, see the help text.
 */

#include <stdio.h>
#include <stdlib.h>
#include <sys/ioctl.h>
#include <sys/types.h>
#include <sys/types.h>
#include <sys/stat.h>
#include <fcntl.h>
#include <unistd.h>
#include <signal.h>
#include <string.h>
#include <libgen.h>
#include <errno.h>

/* Macros */
#define eprintf(...)	fprintf(stderr, __VA_ARGS__)				/* Error printf: send to stderr  */
#define feprintf(...)	fprintf(stderr, __VA_ARGS__); exit (EXIT_FAILURE)	/* Fatal error printf: send to stderr and exit */

/* Serial port pins. See "man tty_ioctl" or "statserial" for info. *
 * The output pulse is on RTS, (checked on CTS). DTR/RI are simply looped back in the DB9, used to check that our adapter is present: DTR deliberately mis-paired with RI rather than DSR *
 * [ Alternative unused inputs are: DCD: TIOCM_CAR;  DSR: TIOCM_DSR] */
#define SERIAL_PORT_DEFAULT		"/dev/ttyS0"	/* Default serial port */	
#define TRIGGER_OUT_RTS_BITMASK		TIOCM_RTS	/* Trigger Output, on the RTS line. */
#define TRIGGER_OUT_RTS_STR 		"RTS"
#define READBACK_IN_CTS_BITMASK		TIOCM_CTS	/* Readback Input, on the CTS line. */
#define READBACK_IN_CTS_STR 		"CTS"
#define CHECK_OUT_DTR_BITMASK		TIOCM_DTR	/* Check output, on the DTR line. */
#define CHECK_OUT_DTR_STR 		"DTR"
#define DETECT_IN_RI_BITMASK		TIOCM_RNG	/* Detect input, on the RI line.  */  
#define DETECT_IN__RI_STR		"RI"

#define PULSE_LENGTH_MS			0.1		/* Length of the PB trigger pulse --_--.  Note that this can't be too short, because of the NI RTSI6 clocking the D-type. */
#define RETRIGGER_INTERVAL_MS		1000		/* Retrigger sleep period */
#define BLINK_ON_S			1		/* Blink time: 1 s on; 2 s off */
#define BLINK_OFF_S			2
#define EXIT_NOCIRCUIT			2		/* Exit status if circuit not detected */

/* Globals */
int	serial_fd = 0;					/* serial port file descriptor */
int	quiet = 0;					/* quiet => don't exit 2 even if no loopback */

/* Show help */
void print_help(char *argv0){
	argv0 = basename(argv0);
	#define RSISTR "/\\/\\/\\/\\"  
	eprintf("\nINTRO:  %s is used to trigger the PulseBlaster's HW_Trigger via one of the control pins of a serial port. This is\n"
		"        useful compared to software triggering, because other hardware (eg a D-flipflop for sunchronisation) can be inserted.\n"
		"        It uses a specially wired serial-port adapter, and a legacy RS-232 port (USB-serial adapters do also work).\n"
		"\n"
		"USAGE:  %s  OPTIONS\n"
		"   -s   DEVICE    use serial port DEVICE. [default: %s].\n"
		"   -i             initialise to the quiescent state (HW_Trigger inactive, high;  i.e. %s low).\n"
		"   -t             trigger the PulseBlaster. Send %.2g ms pulse: --_-- to HW_Trigger.\n"
		"   -n   N         trigger N times, repetition interval given by -r. Normally, just once.\n"
		"   -r   TIME_ms   repetition interval (default: %d ms).\n"
		"   -c             detect adapter on serial port (check loopback: %s->%s). This doesn't trigger the Pulseblaster.\n"
		"   -b             blink the LED for diagnostics: %d second on; %d seconds off.\n"
		"   -q             fail quietly: even if the loopback %s->%s fails on -t/-i, exit %d anyway.\n"
		"   -h             show this help.\n"
		"\n"
		"EXIT STATUS:\n"
		"    %d             on success.\n"
		"    %d             on error (e.g. can't open the port).\n"
		"    %d             if circuit not found, or loopback fails (unless -q).\n"
		"\n"
		"WIRING:  This is the circuit used, assembled inside the DE9-F connector. %s is the trigger; %s is for readback.\n"
		"         The unusual pairing of %s/%s is used to detect whether this custom circuit is plugged into the port.\n"
		"         The pullup to +5V is part of the driven circuit; this is shown for clarity only.\n"
		"         Serial port signals typically swing +/- 10V. The control lines have logic 1 positive [Tx/Rx are inverted].\n"
		"\n"
		"    DTR -->---+                                                   ........"RSISTR"..... +5V\n"
		"              |                                                   .                        \n"
		"    RI  --<---+                                                   .                         \n"
		"                                    RED            +--------------+----------------o  )  BNC\n"
		"    RTS -->---+                     LED            c                                  |\n"
		"              |------"RSISTR"-------|>|--------- b    BC109                           |\n"
		"    CTS --<---+         2k         a   k           e  NPN                             |\n"
		"                                                   |                                  |\n"
		"    GND -------------------------------------------+----------------------------------+\n"
		"\n"
		"TROUBLESHOOTING:  if the PulseBlaster doesn't respond to the serial trigger, check the following:\n"
		"        * the clock source is set to Ext: 'filterctl -c Ext'.\n"
		"        * the PB is armed (and ready for HW_Trigger). Use 'pb_arm', NOT 'pb_stop'.\n"
		"        * the external clock is being conveyed: (NI4462 is driving RTSI6):\n"
		"            operate the NI4462 in 'reference trigger' mode, (or use 'pb_convey_hwtrigger').\n"
		"        * the trigger isn't sent before the NI is ready. (Use ni4462_test with -T and inotifywait). \n"
		"\n"
		"SEE ALSO: pb_convey_hwtrigger, 'setserial -g /dev/ttyS*', statserial, tty_ioctl.\n"
		"\n"
		,argv0, argv0, SERIAL_PORT_DEFAULT, TRIGGER_OUT_RTS_STR, PULSE_LENGTH_MS, RETRIGGER_INTERVAL_MS, CHECK_OUT_DTR_STR, DETECT_IN__RI_STR, BLINK_ON_S, BLINK_OFF_S,
		TRIGGER_OUT_RTS_STR, READBACK_IN_CTS_STR, EXIT_SUCCESS, EXIT_SUCCESS, EXIT_FAILURE, EXIT_NOCIRCUIT, TRIGGER_OUT_RTS_STR, READBACK_IN_CTS_STR, CHECK_OUT_DTR_STR, DETECT_IN__RI_STR );
}


/* Fail because loopback not connected */
void no_loopback_fail(char *msg){
	if (quiet){
		eprintf ("Warning: no loopback detected: serial-port trigger-adapter probably missing. %s\n", msg);
	}else{
		eprintf ("ERROR: no loopback detected: serial-port trigger-adapter probably missing. %s\n", msg);
		exit (EXIT_NOCIRCUIT);
	}
}

/* Set the RTS bit to on/off */
void set_rts (int onoff){
	int pins = TRIGGER_OUT_RTS_BITMASK;
	if (onoff){
		ioctl (serial_fd, TIOCMBIS, &pins);
	}else{
		ioctl (serial_fd, TIOCMBIC, &pins);
	}
}

/* Set the DTR bit to on/off */
void set_dtr (int onoff){
	int pins = CHECK_OUT_DTR_BITMASK;
	if (onoff){
		ioctl (serial_fd, TIOCMBIS, &pins);
	}else{
		ioctl (serial_fd, TIOCMBIC, &pins);
	}
}

/* Get the state of the CTS bit */
int get_cts (){
	int state;
	ioctl(serial_fd, TIOCMGET, &state);
	if ((state & READBACK_IN_CTS_BITMASK) == 0){
		return (0);
	}else{
		return (1);
	}
}

/* Get the state of the RI bit */
int get_ri (){
	int state;
	ioctl(serial_fd, TIOCMGET, &state);
	if ((state & DETECT_IN_RI_BITMASK) == 0){
		return (0);
	}else{
		return (1);
	}
}


/* Blink (never exits) */
void blink(){
	eprintf ("Now blinking the RTS LED. Ctrl-C to exit.\n");
	while (1){
		set_rts (1);
		eprintf ("RTS: On\n");
		if (get_cts() != 1){
			no_loopback_fail("RTS=1; CTS=0; expect CTS=RTS.");
		}
		sleep (BLINK_ON_S);
		set_rts (0);
		eprintf ("RTS: Off\n");
		if (get_cts() != 0){
			no_loopback_fail("RTS=0; CTS=1; expect CTS=RTS.");
		}
		sleep (BLINK_OFF_S);
	}
}

/* Initialise. Set RTS to low (allow trigger to float high). Check loopback. */
void initialise(){
	set_rts (0);
	eprintf ("HW_Trigger initialised: floating high.\n");
	if (get_cts() != 0){
		no_loopback_fail("RTS=0; CTS=1; expect CTS=RTS.");
	}
}	

/* Trigger the pulseblaster: send -_-- to trigger (i.e.  _-__  to RTS. Check loopback. */
void trigger(){
	set_rts(1);
	eprintf ("HW_Trigger: sent -_-- pulse (%g ms)\n", PULSE_LENGTH_MS);
	if (get_cts() != 1){
		no_loopback_fail("RTS=1; CTS=0; expect CTS=RTS.");
	}
	usleep (PULSE_LENGTH_MS * 1000);
	set_rts(0);
	if (get_cts() != 0){
		no_loopback_fail("RTS=0; CTS=1; expect CTS=RTS.");
	}
	usleep (PULSE_LENGTH_MS * 1000);
}

/* Check/detect the adapter is present on the serial port. Toggle DTR and check RI status. */
void check(){
	int error = 0;
	eprintf ("Checking for serial port adapter...");
	set_dtr(1);
	if (get_ri() != 1){
		error = 1;
	}
	usleep (PULSE_LENGTH_MS * 1000);
	set_dtr(0);
	if (get_ri() != 0){
		error = 1;
	}
	usleep (PULSE_LENGTH_MS * 1000);
	if (error){
		eprintf ("FAILED.\n");
		no_loopback_fail("DTR=1,0; RI=0,1; expect RI=DTR.");
	}else{
		eprintf ("OK.\n");
	}
}


int main(int argc, char **argv) {
	int	opt; extern char *optarg; extern int optind, opterr, optopt;       	/* getopt */
	char   *serial_devname = SERIAL_PORT_DEFAULT;   				/* serial port device name */
	int     do_initialise = 0, do_trigger = 0, do_blink = 0, do_check = 0;
	int     num_triggers = 1, retrigger_ms = RETRIGGER_INTERVAL_MS;
	
  	/* Parse options and check for validity */
        if ((argc > 1) && (!strcmp (argv[1], "--help"))) {      /* Support --help, without the full getopt_long */
                print_help(argv[0]);
                exit (EXIT_SUCCESS);
        }

	while ((opt = getopt(argc, argv, "bchitqs:n:r:")) != -1) {  /* Getopt */
                switch (opt) {
			case 'b':				/* Blink */
				do_blink = 1;
				break;

			case 'c':				/* Check */
				do_check = 1;
				break;

			case 'h':				/* Show help */
				print_help(argv[0]);
				exit (EXIT_SUCCESS);
				break;
				break;

			case 'i':				/* Initialise */
				do_initialise = 1;
				break;
				
			case 'n':				/* Number of trigger pulses to send */
				num_triggers = atoi(optarg);
				if (num_triggers < 0){
					feprintf("Number of trigger pulses (-n) must be >= 0.\n");
				}
				break;
			
			case 'r':				/* Retrigger interval (ms) */
				retrigger_ms = atoi(optarg);
				if (retrigger_ms < 0){
					feprintf("Retrigger_interval (-r) must be >= 0.\n");
				}
				break;

			case 't':				/* Trigger */
				do_trigger = 1;
				break;

			case 'q':				/* Quiet if not detected */
				quiet = 1;
				break;				

			case 's':				/* Serial port: override default */
				serial_devname = optarg;;
				break;

			default:
				feprintf ("Unrecognised argument %c. Use -h for help.\n", opt);
				break;
		}
	}

	if (argc == 1){
		feprintf ("Wrong arguments: use -h for help.\n");
	}

	/* Open the serial port */
	serial_fd = open (serial_devname, O_RDWR | O_NDELAY);
	if (serial_fd < 0) {
		feprintf("ERROR: can not open serial port \"%s\": %s\n", serial_devname, strerror(errno));
		
	}

	/* Action */
	if (do_initialise){     /* initialise, float high, check */
		initialise();
	}

	if (do_trigger){	/* Trigger the PB HW_Trigger. --_- , check */
		for (int i = 0; i< num_triggers; i++){
			trigger();
			if (num_triggers > 1){
				usleep (retrigger_ms*1e3);  
			}
		}
	}

	if (do_check){		/* Check for the adapter: toggle DTR, check for response on RI. */
		check();
	}
		
	if (do_blink){
		blink();	/* Never returns */
	}
	
	/* Close the port and exit happily */
	close (serial_fd);
	exit (EXIT_SUCCESS);
}
