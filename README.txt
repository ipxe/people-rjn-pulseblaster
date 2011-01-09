INTRO
-----
PulseBlaster device driver. /sys interface ( /sys/class/pulseblaster/ ), containing:

   program				- write a pulseblaster binary to this
   start, stop, arm, continue		- echo "1" to these to make this happen.


USERSPACE
---------

pbctl, or pb_utils


COMPILE
-------
make -C /lib/modules/`uname -r`/build M=`pwd`


QUIRKS
------

Because the PulseBlaster is essentially an independent device, merely powered and 
programmed from the host, a PulseBlaster program will continue to run even when the
kernel module is removed, or when the host is rebooted!
