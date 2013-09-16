PREFIX      = $(DESTDIR)/usr/local
BINDIR      = $(PREFIX)/bin
DATAROOTDIR = $(PREFIX)/share
DOCDIR      = $(DATAROOTDIR)/doc/pulseblaster
MANDIR      = $(DATAROOTDIR)/man
MAN1DIR     = $(MANDIR)/man1

WWW_DIR     = pulseblaster

all ::	compile

compile:
	@[ -d /usr/src/*`uname -r` ] || (echo "Error: please install the kernel sources"; exit 1)
	cd kernel; make -C /lib/modules/`uname -r`/build M=`pwd` ; cd -
	pod2man pb_ctl/pb_ctl -c "User Commands" | bzip2 > man/pb_ctl.1.bz2
	bzip2 -kf man/pulseblaster.1
	bzip2 -kf man/pb_driver-load.1
	bzip2 -kf man/pb_test-flash-2Hz.1 
	ln -sf    pb_test-flash-2Hz.1.bz2 man/pb_test-flash-fastest-5.55MHz.1.bz2
	ln -sf    pb_test-flash-2Hz.1.bz2 man/pb_test-identify-output.1.bz2
	ln -sf    pb_driver-load.1.bz2    man/pb_driver-unload.1.bz2
	make -C pb_utils

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

	mkdir -p $(BINDIR) $(MAN1DIR) $(DOCDIR)
	install        pb_ctl/pb_ctl                           $(BINDIR)
	install        pb_ctl/pb_driver-load.sh                $(BINDIR)/pb_driver-load
	install        pb_ctl/pb_driver-unload.sh              $(BINDIR)/pb_driver-unload
	install        pb_ctl/pb_test-flash-2Hz.sh             $(BINDIR)/pb_test-flash-2Hz
	install        pb_ctl/pb_test-flash-fastest-5.55MHz.sh $(BINDIR)/pb_test-flash-fastest-5.55MHz
	install        pb_ctl/pb_test-identify-output.sh       $(BINDIR)/pb_test-identify-output
	install  -m644 README.txt LICENSE.txt doc/*            $(DOCDIR)
	install  -m644 man/*.1.bz2                             $(MAN1DIR)
	make -C pb_utils install

.PHONY: www
www:
	rm -rf   www .www
	mkdir -p .www/$(WWW_DIR)/$(WWW_DIR)
	cp -r *  .www/$(WWW_DIR)/$(WWW_DIR)
	mv       .www www
	make -C  www/$(WWW_DIR)/$(WWW_DIR) clean
	tar -czf www/$(WWW_DIR)/$(WWW_DIR).tgz -C www/$(WWW_DIR) $(WWW_DIR)
	make -C  www/$(WWW_DIR)/$(WWW_DIR)/pb_utils
	cp       www/$(WWW_DIR)/$(WWW_DIR)/pb_utils/man/*.html  www/$(WWW_DIR)
	cp       www/$(WWW_DIR)/$(WWW_DIR)/pb_utils/doc/*.txt  www/$(WWW_DIR)
	rm -rf   www/$(WWW_DIR)/$(WWW_DIR)
	cp       index.html README.txt www/$(WWW_DIR)/
	@echo "Now, upload www/$(WWW_DIR)/ and link to www/$(WWW_DIR)/index.html"

clean:
	rm -f kernel/*.o kernel/*.ko
	rm -f man/*.bz2 man/*.html
	make -C pb_utils clean
	rm -rf www

uninstall:
	@[ `whoami` = root ] || (echo "Error, please be root"; exit 1)
	rm -rf /lib/modules/`uname -r`/kernel/3rdparty/pulseblaster
	depmod -A
	rm -f /etc/security/console.perms.d/90-pulseblaster.perms
	rm -f  $(BINDIR)/pb_ctl
	rm -f  $(BINDIR)/pb_driver-load
	rm -f  $(BINDIR)/pb_driver-unload
	rm -f  $(BINDIR)/pb_test-flash-2Hz
	rm -f  $(BINDIR)/pb_test-flash-fastest-5.55MHz 
	rm -f  $(BINDIR)/pb_test-identify-output
	rm -f  $(MAN1DIR)/pulseblaster.1.bz2
	rm -f  $(MAN1DIR)/pb_ctl.1.bz2
	rm -f  $(MAN1DIR)/pb_driver-load.1.bz2
	rm -f  $(MAN1DIR)/pb_driver-unload.1.bz2
	rm -f  $(MAN1DIR)/pb_test-flash-2Hz.1.bz2
	rm -f  $(MAN1DIR)/pb_test-flash-fastest-5.55MHz.1.bz2
	rm -f  $(MAN1DIR)/pb_test-identify_output.1.bz2
	rm -rf $(DOCDIR)
	make -C pb_utils uninstall
