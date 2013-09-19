INTRODUCTION
------------

This is a GPL'd Linux driver and utilities for the SpinCore PulseBlaster PCI Digital Timing card:
See: http://spincore.com/products/PulseBlaster/PulseBlaster-Programmable-Pulse-Generator.shtml
It consists of:


driver/
  kernel/
	The driver. This builds on kernel 3.8. 
  	It creates entries in /sys, under /sys/class/pulseblaster/
  pb_ctl/
	A simple control script and some debugging/diagnostic utilities.

pb_utils/
	C programs to control the pulseblaster, assemble VLIW files, and load the binary file.
	Also contains some examples of the VLIW format.

pb_parse/
	A high-level parser/compiler, which converts PBSRC to VLIW. It can also emulate missing features,
	simulate the hardware, and prove whether programs are valid.


SUPPORTED DEVICES
-----------------

The driver supports the PulseBlaster SP1 PB24-100-32k board, with PCI vendor/device id:  0x10e8:0x5920.

The newer SP2 boards have the same vendor id (0x10e8) and device IDs of (0x8879 or 0x8852)  - both being functionally identical
It is probably sufficient to add them to pulseblaster.c -> pb_pci_tbl and the driver will then work, if the protocol is the same.


TO COMPILE
----------

make && sudo make install

[Aside: it's possible to build pb_utils to work in dummy mode without a real pulseblaster: #define HAVE_PB 0]


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
	Eg pb_utils/vliw_examples/good/flash_leds_2Hz.vliw  or pb_freq_gen.sh

.bin
	Binary file, ready to load into the pulseblaster.
	E.g doc/flash.bin,  see also pb_test-identify-output.sh


TOOLS
-----

pb_asm converts .vliw to .bin

pb_prog loads the bin file to the device (it will automatically assemble .vliw if needed)

pb_parse compiles .pbsrc to .vliw


UTILITIES
---------

pb_utils/ comprises:
	  pb_asm, pb_prog					- assemble and program
	  pb_stop, pb_start, pb_cont, pb_arm, pb_stop-arm	- stop/start/arm the pulseblaster.
	  pb_init, pb_zero					- set the pb outputs directly.			
	  pb_vliw						- generates an example/demo vliw file.
	  pb_check						- check lspci for the pulseblaster hardware being present.
	  
For debugging, use:
	  pb_identify_output.sh					- identify a particular output in the hardware by flashing its bit.
	  pb_freq_gen.sh					- generate a square wave of a desired frequency on selected bits.
	  pb_manual						- manually, interactively, control the pulseblaster outputs. This is actually quite useful!
	  

pb_parse/ is the parser/simulator/compiler. It allows a much higher-level language, and is described in its own README.txt.


HARDWARE TWEAKS
---------------

One way to generate a trigger pulse (on HW_TRIGGER) is a software-controlled hardware trigger. Rather than using pb_start, use an NPN-transistor across the HW_Trigger input.
The easiest way to interface with this is a DB-9 RS-232 serial port, by creative (mis)-use of the control lines. For a ciruit diagram and interface, see pb_serial_trigger.c


DOCUMENTATION
-------------

see doc/ directory. This describes pb_utils and the pulseblaster itself, as well as the VLIW format. 

Notably,  doc/hardware-modifications.txt describes some aditions we made to the board, for our own purposes: external clock input, and synchronisation with an NI 4462 board.


QUIRKS
------

Because the PulseBlaster is essentially an independent device, merely powered and 
programmed from the host, a PulseBlaster program will continue to run even when the
kernel module is removed, or when the host is rebooted!


SEE ALSO
--------

This was written as part of my PhD InfraRed Camera system: http://www.richardneill.org/phd
There is a GIT tree at: http://git.ipxe.org/people/rjn/pulseblaster.git

