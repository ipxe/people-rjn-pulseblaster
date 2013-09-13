#!/bin/bash
#This makes the pulseblaster act like a 24-bit binary counter, continuously
#being clocked. 

#It runs at about 50Hz, which is not bad, considering the way this
#is being done. Each increment corresponds to a new instance of pb_init, 
#which means the pulseblaster is programmed, and then started, and stopped.

if [ $# -ge 1 ] ; then
	echo "This uses pb_init to act like a 24-bit binary counter." 
	echo "It takes no arguments."
	exit 1
fi

echo "Now using pb_init to act like a 24-bit binary counter"

while : ; do
	for ((i=0;i<16777215;i++)) ;do 
		if ! pb_init $i ; then
			echo pb_init failed
			exit 1
		fi
	done
done
