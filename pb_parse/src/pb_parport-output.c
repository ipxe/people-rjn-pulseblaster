/* Output data to the parallel port(s), directly (i.e. bit-banging mode) without any form of handshaking.
 * Recent Linuxes (> 2.4) use ioctl, described at:  http://people.redhat.com/twaugh/parport/html/x623.html
 * which is much better than the old way using ioperm() and outb(). Also, this code needn't be run as root.
 * Note that only physical "legacy" parallel ports will necessarily work this way; USB parport adapters usually won't:
 * it depends on the specific chipset, but even those that support bit-banging will be seriously limited in speed.
 * See also: doc/parport-output.txt  */

/* Copyright (C) Richard Neill 2011, <pulseblaster at REMOVE.ME.richardneill.org>. This program is Free Software. You can
 * redistribute and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation,
 * either version 3 of the License, or (at your option) any later version. There is NO WARRANTY, neither express nor implied.
 * For the details, please see: http://www.gnu.org/licenses/gpl.html  */

/*
 * TODO: Performance measured at 400k writes/sec with one physical parport. If more ports are wanted, this could be improved by 
 * triggering the 3 PPWDATA ioctls in parallel (how?). Might also want to use the Strobe lines somehow to improve sync across all ports.
 *
 *  For a pretty display, slowed down for human-readable speed.
 *   1. Have /dev/parport0
 *   2. turn on DEBUG below.
 *   3. gcc -Wall -Wextra -Werror  -o pb_parport-output pb_parport-output.c
 *   4. In one shell:  mkfifo myfifo; { for ((i=0;i<1000;i++)); do echo  $i; done ;} > myfifo
 *   5. In another shell:  ./pb_parport-output myfifo
*/

#include <stdio.h>
#include <stdlib.h>
#include <fcntl.h>
#include <unistd.h>
#include <string.h>
#include <sys/stat.h>
#include <sys/ioctl.h>
#include <linux/ppdev.h>

#define PARPORT_0  "/dev/parport0"	/* The parallel port. (LSB) */
#define PARPORT_1  "/dev/parport1"	/* The 2nd port, if present */
#define PARPORT_2  "/dev/parport2"	/* The 3rd port, if present (MSB) */

#define DEBUG	0			/* 0: normal; 1: verbose (and has delay to be much slower) */

#define MAXLEN 128			/* Input buffer. */

void printhelp(){
	fprintf(stderr, "Usage:   pb_parport-output input_fifo\n"
			"Example: mkfifo myfifo; echo 0x123456 > myfifo &  pb_parport-output myfifo\n"
			"\n"
			"This reads data from a named pipe (or file), and immediately writes the bytes directly to the parallel port(s). \n"
			"Input data should be in ascii-hex format (eg \"0xfe12d3\\n\"), containing 1-3 bytes of data.\n"
			"The parallel ports are autodetected: (MSB) %s:%s:%s (LSB); at least one must be present.\n"
			"There is no handshaking at the parallel port: the output registers are set directly using ioctl(), and the timing is\n"
			"is controlled by the input fifo (reads are blocking). When the fifo reaches EOF, this process will exit.\n"
			"\n"
			"[The other-end of the fifo (the writer) will block on fopen(); also on fwrite() after 64kB buffered, see man (7) pipe.]\n"
			"Performance is ~ 400k writes/second (measured); this will be shared across all ports used.\n"
			"WARNING: if multiple parports are used, they will not be perfectly synchronised: watch out for glitches!\n"
			"\n"
			"The parport device must be available (for PPCLAIM ioctl), but this program need not run as root.\n" 
			"The port must exist as a low-level device (/dev/parportX), not a buffered device (/dev/lpX); only physical (\"legacy\")\n"
			"parallel ports can work this way; USB printer adapters won't work.\n"
			"Copyright Richard Neill, 2011. This is Free Software, licensed under the GNU GPL version 3+.\n"
			" \n",
			PARPORT_2, PARPORT_1, PARPORT_0);
}


int main(int argc, char** argv){

	char parports[][20] = { PARPORT_0, PARPORT_1, PARPORT_2 };
	int pp_fd[3] = {0,0,0};
	FILE * data_fd;
	int port;
	char buf[MAXLEN];
	unsigned char byte;
	unsigned long data;
	struct stat sb;

	if (argc != 2){
		fprintf (stderr, "Error: this takes exactly 1 argument: the name of the fifo from which to read. (-h for help).\n");
		exit (1);
	}

	if ((!strcmp (argv[1], "-h")) || (!strcmp (argv[1], "--help"))){
		printhelp();
		exit (1);
	}

#if DEBUG == 1
	fprintf (stderr, "Debug mode is on, #defined in source.\n");
#endif

	/* For each of the possible parallel ports, 0,1,2, check if they exist, and then open and claim them */
	for (port = 0; port < 3; port++){
		if (stat (parports[port], &sb) == 0){	/* Exists? */

			/* Open the parallel port */
			pp_fd[port] = open (parports[port],  O_RDWR);
			if (pp_fd[port] < 0 ){
				fprintf (stderr, "Failed to open parallel port %s: ", parports[port]); perror (NULL);
				exit (1);
			}

			/* Claim it for use. (might also need PPEXCL too). This is blocking. */
			if (ioctl(pp_fd[port], PPCLAIM) < 0){
				fprintf (stderr, "Failed to claim parallel port %s: ", parports[port]); perror (NULL);
				exit(1);
			}

		}else{
			// fprintf (stderr, "Parallel port %s does not exist\n", parports[port]);
		}
	}

	if ( (pp_fd[0] == 0) && (pp_fd[1] == 0) && (pp_fd[2] == 0) ){
		fprintf (stderr, "Error: no parallel ports found. None of %s, %s, %s exist.\n", parports[0], parports[1], parports[2]);
		exit (0);
	}

	/* Check that the file argument exists and is readable. Open it (open, not fopen) */
	data_fd = fopen (argv[1], "r");
	if (data_fd == NULL){
		fprintf (stderr, "Error opening input file/pipe %s: ", argv[1]); perror(NULL);
		exit (1);
	}

	/* Loop. Read an integer (3 bytes ascii-hex + \n) from the input, and write it to the parport(s).
	 * Use buffered string reads (i.e. fgets, not read), and block as necessary. Exit on EOF. */
	while (fgets (buf, sizeof(buf), data_fd) != NULL) {

		data = strtoul (buf, NULL, 16);		/* Expect an asciihex string, eg "0xfedcba \n" */
#if DEBUG == 1
		fprintf (stderr, "Line (with hex value 0x%lx) is: %s", data, buf);
#endif

		for (port = 0; port < 3; port++){	/* Foreach parport that actually exists... */
			if (pp_fd[port] != 0){

				byte = ( data >> (8 * port )) & 0xff ; 		/* Mask off the relevant byte */
				if (ioctl(pp_fd[port], PPWDATA, &byte) < 0){	/* Write the byte to the port */
					fprintf (stderr, "Error writing to parallel port %s: ", parports[port]); perror (NULL);
				}

#if DEBUG == 1
				if ( (byte >= 0x20) && (byte <= 0x7E)){		/* Show debug info */
					printf ("Output to port %s: byte 0x%x (char '%c')\n", parports[port], byte, byte);
				}else if (byte == 0xa){
					printf ("Output to port %s: byte 0x%x (char '\\n')\n", parports[port], byte);
				}else{
					printf ("Output to port %s: byte 0x%x (char 'NONPRINTING')\n", parports[port], byte);
				}
				usleep (100000);	/* Sleep 0.1s; useful for debugging */
#endif
			}
		}
	}

	printf ("EOF\n");	/* End of File, input of pipe was closed */
	return 0;
}
