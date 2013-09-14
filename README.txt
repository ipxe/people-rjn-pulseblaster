INTRODUCTION
------------

This is a GPL'd Linux driver and utilities for the SpinCore PulseBlaster PCI Digital Timing card:
See: http://spincore.com/products/PulseBlaster/PulseBlaster-Programmable-Pulse-Generator.shtml
It consists of:


kernel/
	The driver. This builds on kernel 3.8. 
  	It creates entries in /sys, under /sys/class/pulseblaster/

pb_ctl/
	A simple control script and some debugging/diagnostic utilities.

pb_utils/
	C programs to control the pulseblaster, assemble VLIW files, and load the binary file.
	Also contains some examples of the VLIW format.

See also pb_parse: a higher-level parser/compiler, distributed separately.


TO COMPILE
----------

make && sudo make install


MODULE LOADING
--------------

insmod ./pulseblaster.ko ;  rmmod pulseblaster 

Loading the module will create entries within /sys/class/pulseblaster,
  typically /sys/class/pulseblaster/pulseblaster0/*

After running make install, this will happen automatically on reboot, and pam_console_apply 
will automatically grant permissions the the logged-in user. Or use pb_driver-load.


USERSPACE
---------

It's possible to just write directly to the /sys interface.  

Eg:  doc/flash.bin is a pulseblaster executable to flash all the outputs at 2Hz

To program it:
   cat doc/flash.bin > /sys/class/pulseblaster/pulseblaster0/program
To start the program:
    echo 1 > /sys/class/pulseblaster/pulseblaster0/start



FILE TYPES
----------

.pbsrc
	This is the high-level source file, read by pb_parse. 

.vliw
	Very long instruction word file, like assembler. It's human-readable, and possible to write this directly.
	Eg pb_utils/vliw_examples/good/flash_leds_2Hz.vliw

.bin
	Binary file, ready to load into the pulseblaster.
	E.g doc/flash.bin


TOOLS
-----

pb_asm converts .vliw to .bin

pb_prog loads the bin file to the device (it will automatically assemble .vliw if needed)

pb_parse compiles .pbsrc to .vliw   [this is packaged and distributed separately]


UTILITIES
---------


pb_utils/ comprises:
	  pb_asm, pb_prog					- assemble and program
	  pb_stop, pb_start, pb_cont, pb_arm, pb_stop-arm	- stop/start/arm the pulseblaster.
	  pb_init, pb_zero					- set the pb outputs directly.			
	  pb_vliw						- generates an example/demo vliw file.
	  
	  , pb_init,  pb_prog, 
	  
	  
	  make[1]: Leaving directory `/home/rjn/PhD/src/pulseblaster/pb_utils'
[rjn@chocolate pulseblaster]$ ls pb_utils/src/
  pb_check.sh*  pb_freq_gen.sh*  pb_functions.c  pb_manual.sh*  pb_serial_trigger.c      pb_utils.bashcompletion  pb_zero.c
    pb_freq_out.sh*  pb_init.c          pb_serial_trigger_check.sh*  





pb_test-identify-output is useful for channel identification in the hardware.


todo: comprises WHAT

diff fre-gen and fre-out

explain hacks

update pb_check for new id.


QUIRKS
------

Because the PulseBlaster is essentially an independent device, merely powered and 
programmed from the host, a PulseBlaster program will continue to run even when the
kernel module is removed, or when the host is rebooted!

