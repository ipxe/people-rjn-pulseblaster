all ::	compile

compile:
	cd kernel; make -C /lib/modules/`uname -r`/build M=`pwd` ; cd -
	pod2man pbctl/pbctl -c "User Commands" > man/pbctl.1
	bzip2 -kf man/pbctl.1
	bzip2 -kf man/pb_driver-load.1
	bzip2 -kf man/pb_test-flash-2Hz.1 

install:
	@[ `whoami` = root ] || (echo "Error, please be root"; exit 1)
	@#Kernel module
	mkdir -p /lib/modules/`uname -r`/kernel/3rdparty/pulseblaster
	cp kernel/pulseblaster.ko  /lib/modules/`uname -r`/kernel/3rdparty/pulseblaster
	gzip -f /lib/modules/`uname -r`/kernel/3rdparty/pulseblaster/pulseblaster.ko
	depmod -A
	@#PAM permissions.
	echo '<console>  0660 /sys/class/pulseblaster/pulseblaster*/* 660 root.usb' > /etc/security/console.perms.d/90-pulseblaster.perms
	@#Do it
	modprobe pulseblaster; pam_console_apply
	mkdir -p /usr/local/bin/
	cp pbctl/pbctl /usr/local/bin/
	cp pbctl/pb_driver-load.sh /usr/local/bin/pb_driver-load
	cp pbctl/pb_driver-unload.sh /usr/local/bin/pb_driver-unload
	cp pbctl/pb_test-flash-2Hz.sh /usr/local/bin/pb_test-flash-2Hz
	cp pbctl/pb_test-flash-fastest-5.55MHz.sh /usr/local/bin/pb_test-flash-fastest-5.55MHz
	cp pbctl/pb_test-identify-output.sh /usr/local/bin/pb_test-identify-output
	mkdir -p /usr/local/share/doc/pulseblaster
	cp doc/* README.txt LICENSE.txt /usr/local/share/doc/pulseblaster
	mkdir -p /usr/local/man/man1
	cp man/pbctl.1.bz2 /usr/local/man/man1
	cp man/pb_test-flash-2Hz.1.bz2 /usr/local/man/man1
	ln -sf /usr/local/man/man1/pb_test-flash-2Hz.1.bz2 /usr/local/man/man1/pb_test-flash-fastest-5.55MHz.1.bz2
	ln -sf /usr/local/man/man1/pb_test-flash-2Hz.1.bz2 /usr/local/man/man1/pb_test-identify-output.1.bz2
	cp man/pb_driver-load.1.bz2 /usr/local/man/man1
	ln -sf /usr/local/man/man1/pb_driver-load.1.bz2 /usr/local/man/man1/pb_driver-unload.1.bz2

clean:
	cd kernel; rm -f *.o *.ko; cd -
	rm -f man/*.bz2 

uninstall:
	@[ `whoami` = root ] || (echo "Error, please be root"; exit 1)
	rm -rf /lib/modules/`uname -r`/kernel/3rdparty/pulseblaster
	depmod -A
	rm -f /etc/security/console.perms.d/90-pulseblaster.perms
	rm -f /usr/local/bin/pbctl
	rm -f /usr/local/bin/pb_driver-load
	rm -f /usr/local/bin/pb_driver-unload
	rm -f /usr/local/bin/pb_test-flash-2Hz
	rm -f /usr/local/bin/pb_test-flash-fastest-5.55MHz 
	rm -f /usr/local/bin/pb_test-identify-output
	rm -rf /usr/local/share/doc/pulseblaster
	rm -f /usr/local/man/man1/pbctl.1.bz2
	rm -f /usr/local/man/man1/pb_driver-load.1.bz2	/usr/local/man/man1/pb_driver-unload.1.bz2
	rm -f /usr/local/man/man1/pb_test-flash-2Hz.1.bz2
	rm -f /usr/local/man/man1/pb_test-flash-fastest-5.55MHz.1.bz2
	rm -f /usr/local/man/man1/pb_test_identify_output.1.bz2
