#!/bin/bash
#Copyright Richard Neill, 2011. Free software, licensed	under GNU GPL version 3	or later.

#For backward compatibility only.
#This script explicitly unloads the pulseblaster module

module=pulseblaster

if [ "$1" == "-h" -o "$1" == "--help" ];then
        echo -e "This script unloads the pulseblaster module ($module)."
        echo -e "USAGE:\t `basename $0`\n"
        exit 1
fi

# Root or die
if [ "$(id -u)" != "0" ]; then
	echo "You must be root to load or unload kernel modules"
	exit 1
fi

#Do it
modprobe -r $module

#Say so
echo pulseblaster driver removed. 
