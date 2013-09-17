INTRODUCTION 
------------

This is a GPL'd Linux driver for the SpinCore PulseBlaster PCI Digital Timing card:

kernel/
	The driver. This builds on kernel 3.8. 
  	It creates entries in /sys, under /sys/class/pulseblaster/

pb_ctl/
	A simple control script and some low-level debugging/diagnostic utilities.


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


Identify specific outputs with pb_test-identify-output.sh  
(this generates simple PB programs).

	  
DISTRIBUTION
------------

Once the driver becomes part of the kernel, there's no need to install pb_ctl/*.
Just use pb_utils.


SEE ALSO
--------

* pb_utils  - PulseBlaster control and assembler.

* pb_parse  - High-level compiler.

* GIT tree at: http://git.ipxe.org/people/rjn/pulseblaster.git

