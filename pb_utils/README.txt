This is the contents of pb_utils/, and a short explanation of what everything does. All binaries can be invoked with -h.


README.txt
	This readme file with the explanations.

pulseblaster.h
	The header file, with all the definitions. See the manual for more information. In particular, the #defines are here for debugging.

../driver
	Other directory, with the pulseblaster kernel driver in it. Note that you need to run pb_driver-load.sh


src/
	The actual pulseblaster programs themselves.
		pb_functions.c
			Include file: functions containing most of the stuff that actually does things!


		pb_init [FLAGS]
			Initialise the pulseblaster and set the outputs to FLAGS (or zero if unspecified).
			FLAGS is optional and is in either hex or decimal, and may be one long number or split up into bytes. (eg '12 34 56' or '0xFFACD2')
			Note: this will terminate and overwrite any currently-loaded program.

		pb_zero 
			Initialise the pulseblaster and set the outputs to all zeros. This is used to explicitly zero the outputs; useful to remove parasitic 
			power otherwise supplied to the circuit, during a power-on reset. (It's a trivial modification of pb_init).
			Note: this will terminate and overwrite any currently-loaded program.

		pb_prog FILE.vliw
			Program the pulseblaster with the pulse program in FILE.vliw  (as documented in doc/vliw.txt)
			Some basic sanity-checking of the .vliw file is performed, however complex errors (eg stack depth exceeded) will not be caught.
			After this, the pulseblaster is left un-armed.
			[File may also be a pre-compiled .bin file, from pb_asm]
			Programming the PulseBlaster stops it, but leaves the outputs untouched.

		pb_asm FILE.vliw [OUT.bin]
			Assemble the .vliw file to a .bin file. for use subsequently by pb_prog. 
			This doesn't touch the pulseblaster hardware at all.

		pb_start
			Starts the pulseblaster excecuting (from the beginning of the program). 
			This will work whether or not the pulseblaster is armed. Start implicitly stops (and resets) the PB in order to restart it.
			[HW_TRIGGER is not-quite exactly equivalent; it only works when the PB is armed]

		pb_stop
			Stops the pulse program which is currently executing. Does NOT re-arm it. 
			[HW_RESET is slightly different; it leaves the PB armed.]

		pb_arm
			(Re-)Arm the pulseblaster. Only required after programming to make HW_TRIGGER work.
			(pb_arm will implicitly stop a running pulseblaster)

		pb_cont
			Continue the pulseblaster. Required to software trigger after a WAIT. 
			(Unlike pb_start, pb_cont will not stop and reset a running pulseblaster)
			
		pb_check
			Check whether the PulseBlaster is physically installed. (checks `lspci`). pb_driver-load may still be needed.
			
		pb_vliw	
			Print an example VLIW file. (Useful as a template)
			
		pb_print_config
			print the configuration (a simple bridge between pulseblaster.h and pb_parse.php)


	Helper programs:
		pb_freq_gen
			Control the PB to output a square wave of a given frequency on a given set of output bits.
		
		pb_manual
			Manually control the pulseblaster outputs. This is a user-interface wrapper around pb_init, to allow for direct control
			of the output lines. Useful for prototyping and testing.

		pb_serial_trigger
			Trigger the HW_Trigger input. This uses a spare RS232 serial port, with a trivial circuit to allow for external
			triggering (or resetting). It could usefully be adapted.

doc/
	Documentation of file formats etc.
		vliw.txt
			documents my ".vliw" file format. Note: .vliw files are human-editable, but *usually* generated from a .pbsrc file.
		pulseblaster-hardware-trigger-reset.txt
			Explanation of the pulseblaster hardware trigger/reset.
		pulseblaster-opcodes.txt
			Explanation of the pulseblaster opcodes.
		hardware-modifications.txt
			Explanations of how I modified the pulseblaster.

man/
	Brief man pages for the pb_utils binaries, the tests, and the vliw file-format.

vliw_examples/
	Example vliw files, for testing and debugging. Notably:
		good/ 	- some useful and testing files, such as:
 		    example.vliw
		 	simple vliw file
	  	    flash_leds_2Hz.vliw
			flash LEDs at 2Hz
		    walking_leds_2Hz.vliw
			walking LEDs (3s) at 2Hz
		    flash_leds_fastestpossible.vliw
			walking LEDs at fastest possible rate for the hardware.
		walking_4leds_1Hz.vliw
			walking LEDs (4s) at 1Hz

		invalid/ - some deliberately broken vliw files.

tests/	
	Test scripts, to check everything works. 
		pb_test-pbinit-counter.sh
			test that pb_init works, but emulating a binary counter.
		pb_test-vliw-walk4.sh
			test that vliw file programming works.

Makefile
	To compile and install: make; sudo make install.  The executables (all beginning with pb_) will be installed in /usr/local/bin


README.txt, LICENSE.txt
	This readme, and the GPL License.

