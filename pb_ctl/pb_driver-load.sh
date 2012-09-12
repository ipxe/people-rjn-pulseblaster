#!/bin/bash
#Copyright Richard Neill, 2011. Free software, licensed under GNU GPL version 3 or later.

#For backward compatibility only.
 
#This script explicitly loads the pulseblaster module, and grants the permissions. 
#"make install" should already have set this up, such that:
# 	- the pulseblaster module is automatically loaded at boot
#	- the permissions for the device in /sys are granted to the user at login
#		(see /etc/security/console.perms.d/90-pulseblaster.perms and pam_console_apply)

module=pulseblaster
device=/sys/class/pulseblaster/pulseblaster0

if [ "$1" == "-h" -o "$1" == "--help" ];then
        echo -e "This script loads the pulseblaster module ($module) and grants the permissions on ($device/*) to the logged in user."
	echo -e "It shouldn't normally be necessary: the module should auto-load at boot, and get permissions at login."
	echo -e "See also /etc/security/console.perms.d/90-pulseblaster.perms and pam_console_apply."
        echo -e "USAGE:\t `basename $0`\n"
        exit 1
fi


# Root or die
if [ "$(id -u)" != "0" ]; then
	echo "You must be root to load or unload kernel modules"
	exit 1
fi

#Do it
modprobe $module
pam_console_apply

#Say so
echo pulseblaster driver loaded. 
