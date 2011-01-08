Contents:

	flash.bin		A pulseblaster executable to flash all the outputs at 2Hz
				Write this to /sys/class/pulseblaster/pulseblaster0/program
				then echo 1 > /sys/class/pulseblaster/pulseblaster0/start

	flashpad.bin		The same, but padded to 327680 Bytes (32k VLIW Words). Works.

	flashpad.bin		The same, but padded to be too large. PulseBlaster doesn't start.
