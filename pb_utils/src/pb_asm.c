/* This is pb_asm.c  It is similar to pb_prog, but outputs a .bin file, rather than writing to the PulseBlaster hardware. Special case of pb_prog.c
 * Most of the important, shared stuff is in pb_functions.c */

#include "pb_functions.c"

void printhelp(){
	fprintf(stderr, "pb_asm is a special case of pb_prog. It reads in a VLIW file, and parses/checks/compensates it.\n"
		"It outputs the binary PulseBlaster executable to a file, rather than programming it into the hardware.\n"
		"If output filename isn't specified given, the input will be renamed from file.vliw to file.bin\n"
		"(If input or output filename is '-', data will be read from/written to stdin/stdout respectively.)\n"
		"USAGE:    pb_asm INPUT.vliw [ OUTPUT.bin ]\n"
		"          pb_asm - - \n");
}

int main(int argc, char *argv[]){

	unsigned int line_num;		/* Line number in file. one-based, for human-readability */
	unsigned int prog_lines = 0;	/* Lines of actual code "programmed" into the bin file */
	int ret, len;
	int is_stdin = 0, is_stdout = 0;
	int fatal_error = 0;
	int error_exit = PB_ERROR_GENERIC;
	char buffer[VLIWLINE_MAXLEN];   /* 1024 */
	char outfile[255];		/* Output filename */
	char backup[255];		/* Backup filename.*/
	FILE *source_fh, *dest_fh;
	struct stat stat_p;		/* pointer to stat structure */

	if ((argc < 2) || (argc > 3)){
		fprintf(stderr,"Error, wrong number of arguments. Use -h for help.\n");
		exit (PB_ERROR_WRONGARGS);
	}
	if ((!strcmp (argv[1], "-h")) || (!strcmp (argv[1], "--help"))){
		printhelp();
		exit (PB_ERROR_WRONGARGS);
	}

	if (!strcmp (argv[1], "-" ) ){	/* Use stdin? */
		source_fh = stdin;
		is_stdin = 1;

	}else{				/* Normal file */

		if (strcmp ( (argv[1] + strlen(argv[1]) - 5), ".vliw") ){	/* Check that the first file extension is ".vliw"  */
			fprintf(stderr,"Error, input file %s is not a .vliw file. (Wrong extension)\n", argv[1]);
			exit (PB_ERROR_WRONGARGS);
		}

		if (stat (argv[1], &stat_p) == -1){  				/* Get stats for file and place them in the structure */
			fprintf(stderr,"Error: program-file %s does not exist\n", argv[1]);
			exit(PB_ERROR_GENERIC);
		}

		if (stat_p.st_size == 0){	/* Error, if the .vliw file is empty.  Empty .vliw files get created if the .pbsrc file contains a fatal error */
			fprintf(stderr,"Error: file %s is empty! This usually occurs if pb_parse found a fatal error in the .pbsrc file.\n", argv[1]);
			exit (PB_ERROR_EMPTYVLIWFILE);
		}

		if ((source_fh = fopen(argv[1],"r")) == NULL){			/* Check that the file exists and is readable; Open it. */
			fprintf(stderr,"Could not open program-file %s.\n",argv[1]);
			exit (PB_ERROR_WRONGARGS);
		}
	}

	if (argc == 2 && is_stdin){		/* Use stdout (no filename given, infile is stdin)  ? */
		dest_fh = stdout;
		is_stdout = 1;
		strcpy (outfile, "-");
		
	}else if (argc == 3 && !strcmp (argv[2], "-" ) ){	/* Use stdout (explicitly '-') ? */
		dest_fh = stdout;
		is_stdout = 1;
		strcpy (outfile, "-");
	}else{					/* Normal file */
		if (argc == 2){							/* No output filename specified; must rename input to .bin */
			len = strlen (argv[1]) - 5 ;  /* 5 is ".vliw" */
			if (abs(sizeof(outfile)) -1 < len + 5){
				fprintf(stderr, "Filename too long: %s\n",argv[1]);
				exit (PB_ERROR_WRONGARGS);
			}
			strncpy(outfile, argv[1], len );
			outfile[len] = 0;
			strncat(outfile, ".bin", (sizeof(outfile) - 1));
		}else{								/* output filename is specified */
			strncpy( outfile, argv[2], (sizeof(outfile) - 1));
		}

		if  (strcmp ( (outfile + strlen(outfile) - 4), ".bin") ){	/* Check that the second file extension is ".bin" */
			fprintf(stderr,"Error, output file %s is not a .bin file. (Wrong extension)\n", outfile);
			exit (PB_ERROR_WRONGARGS);
		}

		if (stat (outfile, &stat_p) != -1){  				/* Check that the dest file CAN'T be stat()ed. If it exists, back it up to "file.bin~" */
			strncpy( backup, outfile, (sizeof(backup) - 1));
			strncat( backup, "~", (sizeof(backup) - 1));
			fprintf(stderr,"WARNING: output file %s already exists. Moving to %s .\n", outfile, backup);
			if (rename ( outfile, backup ) < 0){
				fprintf(stderr, "Error renaming output file %s to saved copy %s.\n", outfile, backup);
				exit(PB_ERROR_GENERIC);
			}
		}

		if ((dest_fh = fopen(outfile,"w")) == NULL){			/* Open (and truncate) the dest file for writing */
			fprintf(stderr,"Could not open destination-file %s for writing.\n", argv[1]);
			exit (PB_ERROR_WRONGARGS);
		}
	}

	/* Iterate over the source file, one line at a time. Note: don't use feof(), or the last line will be duplicated - ugh! */
	line_num = 1; 		/* Line is one-based */

	while (fgets(buffer, VLIWLINE_MAXLEN, source_fh) != NULL){

		/* Parse a line of program source code. Parse it into VLIW tokens, compensate, and put the result into vliw_buf[]; then return 0 */
		/* If the line is invalid, exit with error. If the line is BLANK or a COMMENT, return -1; DO NOT then re-use the buffer, as you would duplicate the last instruction! */

		ret = pb_parse_sourceline (buffer, line_num);
		if (ret > 0){				/* Error encountered during parsing. Clean up and exit */
			error_exit = ret;
			fatal_error = 1;
			break;

		}else if (ret == 0){			/* Don't duplicate vliw_buf[] if we parsed a comment (and returned -1). */

			ret = fwrite(vliw_buf, sizeof(char), sizeof(vliw_buf), dest_fh);  /* Write the buffer to the output stream (rather than call pb_write_program() ) */

			if (ret == PB_BPW_VLIW){ 	/* Did we succeed in writing the entire buffer? */
				prog_lines++;
			}else{
				fatal_error = 1;		/* Trigger error (and deletion of outfile) below */
				fprintf (stderr, "Error: could not write the whole %d bytes of the vliw_buffer to output file %s\n", PB_BPW_VLIW, outfile);
				break;
			}
		}
		line_num++;
	}

	/* Last check on loop depth */
	if ((!fatal_error) && (check_loop_depth() != 0)){
		fatal_error = 1;
	}

	/* close file descriptors */
	fclose(source_fh);
	fclose(dest_fh);

	/* If we don't get here, we already exited with error msg and errorcode */

	if ((prog_lines == 0) && (!fatal_error)){
		fprintf(stderr, "Error: program file contains no instructions.\n");
		fatal_error = 1;
		error_exit = PB_ERROR_BADVLIWFILE;
	}

	if (fatal_error){
		if (! is_stdout){		/* Delete the dest file. */
			unlink (outfile);  
		}
		fprintf(stderr, "Output file %s has been deleted (to prevent accidentally using it).\n", outfile);
		exit (error_exit);
	}

	fprintf(stderr, "Source file %s (with %d instructions) has been assembled into binary file %s.\n", argv[1], prog_lines, outfile);
	fprintf(stderr, "This executable may be loaded with pb_prog, or directly written to PB: %s\n", PB_SYS_PROGRAM);

	return PB_EXIT_OK;	/* i.e. zero */
}
