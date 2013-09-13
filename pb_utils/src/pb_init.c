/* This is pb_init.c  It initialises the PulseBlaster outputs to specific values. 
 * Very old ISA models used to support explicit setting of the Flags output, but this feature isn't present on PCI PulseBlasters
 * Programming a PB doesn't change the output states, so it's OK to pb_init() and then pb_prog().
 * pb_zero is a special case of this, so it #define's pb_zero, then includes this file.
 * Most of the important, shared stuff is in pb_functions.c */

#include "pb_functions.c"

void printhelp(){
#ifdef pb_zero
	fprintf(stderr,"pb_zero sets the PulseBlaster outputs to all zeros.\n"
		       "This is a special case of pb_init, useful to ensure the connected device is powered-down.\n"
		       "pb_zero will implicitly stop a running PulseBlaster and over-write the existing program.\n"
		       "Usage: pb_zero      (it takes no arguments).\n");
#else
	fprintf(stderr,"pb_init sets the PulseBlaster outputs to specified values.\n"
		       "It does this by writing a very short PulseBlaster program, and then executing it.\n"
		       "pb_init will implicitly stop a running PulseBlaster and over-write the existing program.\n"
		       "Experimentally, this takes about 3ms to run.\n\n"
		       "Usage: pb_init	[flags]                (If no argument is given, zeros are assumed)\n"
		       "       pb_init  0x22 0x11 0x00         (3 consecutive Bytes, MSB first)\n"
		       "       pb_init  0xFFFFFF	       (One Long Integer)\n"
		       "\n");
#endif
}

int main(int argc, char *argv[] __attribute__ ((unused)) ){

	int count = 0;
	unsigned char flags[3]; 	/* Flags are optional command-line parameters, (2,1,0 are CBA) */
	unsigned long long_flag = 0; 

	flags[2] = flags[1] = flags[0] = 0; /* Outputs default to zeros */

	#if PB_OUTPUTS_24BIT != 0xFFFFFF	/* This Pulseblaster has 24 I/O channels only, so (the original flags[3] is irrelevant). */
	fprintf(stderr,"ERROR - this code assumes a 24-bit Pulseblaster.\n");   /* Should this assumption change, we have a serious problem, */
	exit(PB_ERROR_BUG);							/* because the VLIW format is defined at 80 bits. Exit with error. */
	#endif


#ifdef pb_zero
	if (argc > 1){   /* Print help, if invoked with -h, or --help */
               	printhelp();
               	exit (PB_ERROR_WRONGARGS);
		long_flag += count;  /* silence compiler warning about unused variables */
	}	
#else
	if (argc == 2){   /* Print help, if invoked with -h, or --help */
	        
		if ((!strcmp (argv[1], "-h")) || (!strcmp (argv[1], "--help"))){
                	printhelp();
                	exit (PB_ERROR_WRONGARGS);
		}
		
				/* One argument was given - assuming a single 3-byte wide value. */
		long_flag=strtoul(argv[1],NULL,0);	/* Convert the string (which may be decimal or hex) to an unsigned long. */
		if (long_flag > PB_OUTPUTS_24BIT){	/* NOTE: if strtol() finds something it can't parse, it returns zero */
			fprintf(stderr,"Error: long_flag should be between 0 and 0x%02X, but it is 0x%02lx.\n",PB_OUTPUTS_24BIT,long_flag);
			exit (PB_ERROR_WRONGARGS);
		}
		flags[2]=(long_flag & 0xFF0000) >> 16;	/* Extract individual flags using bitwise AND. */
		flags[1]=(long_flag & 0x00FF00) >> 8;
		flags[0]=(long_flag & 0x0000FF);

	}else if (argc == 4){	/* 3 arguments were given - each is one byte */
		for (count=0; count < 3; count++){
			flags[count]=(unsigned int)strtoul(argv[3-count],NULL,0); /* Convert the string (which may be decimal or hex) to a long. */
		}
	}else if (argc != 1){
		fprintf (stderr,"Error: wrong number of arguments! Need either 0, 1, or 3.\n"
				"Use -h for help\n");
		exit (PB_ERROR_WRONGARGS);
	}
#endif

	/* Open pulseblaster; check device is present */
	pb_fopen();
	
	/* Write a short program to the device and run it. */
	pb_init(flags);		
	
	fprintf(stderr, "PulseBlaster outputs have been set to: 0x%02x 0x%02x 0x%02x.\n", flags[2],flags[1],flags[0]);
	fprintf(stderr, "Now, (re-)program the PulseBlaster with pb_prog.\n");

       	/* Close the device */
	pb_fclose();

	return PB_EXIT_OK;	/* i.e. zero */
}
