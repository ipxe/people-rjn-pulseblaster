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

