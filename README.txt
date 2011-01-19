INTRO
-----
PulseBlaster device driver, /sys interface ( /sys/class/pulseblaster/ ), containing:

   program				- write a pulseblaster binary to this
   start, stop, arm, continue		- echo "1" to these to make this happen.


COMPILE
-------

cd kernel
make -C /lib/modules/`uname -r`/build M=`pwd`


MODULE
------

insmod ./pulseblaster.ko 
rmmod pulseblaster 

Loading the module will create entries within /sys/class/pulseblaster,
typically /sys/class/pulseblaster/pulseblaster0


USERSPACE
---------

Use either pbctl, or pb_utils


QUIRKS
------

Because the PulseBlaster is essentially an independent device, merely powered and 
programmed from the host, a PulseBlaster program will continue to run even when the
kernel module is removed, or when the host is rebooted!


EXAMPLE
-------

doc/flash.bin is a pulseblaster executable to flash all the outputs at 2Hz

To program it, copy this to /sys/class/pulseblaster/pulseblaster0/program
Then echo 1 > /sys/class/pulseblaster/pulseblaster0/start
