SUMMARY
-------

In one line:
	make && sudo make install && pb_test-flash-2Hz


INTRO
-----

This is a PulseBlaster device driver, /sys interface ( /sys/class/pulseblaster/ ), containing:

   program				- write a pulseblaster binary to this
   start, stop, arm, continue		- echo "1" to these to make this happen.


TO COMPILE
----------

cd kernel
make -C /lib/modules/`uname -r`/build M=`pwd`

(the makefile does this, and installs it)


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

* pbctl and pb_utils provide helpful wrappers. 
  pb_utils can also assemble .vliw format files.

* pb_test-identify-output is useful for identifying channels.


QUIRKS
------

Because the PulseBlaster is essentially an independent device, merely powered and 
programmed from the host, a PulseBlaster program will continue to run even when the
kernel module is removed, or when the host is rebooted!


EXAMPLE
-------

doc/flash.bin is a pulseblaster executable to flash all the outputs at 2Hz

To program it:
   cat doc/flash.bin > /sys/class/pulseblaster/pulseblaster0/program
To start the program:
    echo 1 > /sys/class/pulseblaster/pulseblaster0/start
