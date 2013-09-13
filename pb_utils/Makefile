PREFIX      = $(DESTDIR)/usr/local
BINDIR      = $(PREFIX)/bin
INCLUDEDIR  = $(PREFIX)/include
DATAROOTDIR = $(PREFIX)/share
DOCDIR      = $(DATAROOTDIR)/doc/pb_utils
MANDIR      = $(DATAROOTDIR)/man
MAN1DIR     = $(MANDIR)/man1
MAN5DIR     = $(MANDIR)/man5
BASHCOMPDIR = /etc/bash_completion.d

CFLAGS      = -Wall -Wextra -Werror -O3 -march=native -std=gnu99

all ::	pbutils manpages

pbutils:
	$(CC) $(CFLAGS) -o src/pb_start    src/pb_start.c
	$(CC) $(CFLAGS) -o src/pb_stop     src/pb_stop.c
	$(CC) $(CFLAGS) -o src/pb_arm      src/pb_arm.c
	$(CC) $(CFLAGS) -o src/pb_cont     src/pb_cont.c
	$(CC) $(CFLAGS) -o src/pb_init     src/pb_init.c
	$(CC) $(CFLAGS) -o src/pb_zero     src/pb_zero.c
	$(CC) $(CFLAGS) -o src/pb_prog     src/pb_prog.c
	$(CC) $(CFLAGS) -o src/pb_asm      src/pb_asm.c
	$(CC) $(CFLAGS) -o src/pb_vliw     src/pb_vliw.c
	$(CC) $(CFLAGS) -o src/pb_stop-arm src/pb_stop-arm.c
	$(CC) $(CFLAGS) -o src/pb_serial_trigger  src/pb_serial_trigger.c

	strip src/pb_start
	strip src/pb_stop
	strip src/pb_arm
	strip src/pb_cont
	strip src/pb_init
	strip src/pb_zero
	strip src/pb_prog
	strip src/pb_asm
	strip src/pb_vliw
	strip src/pb_stop-arm
	strip src/pb_serial_trigger

	@grep -Eq '#define\s*HAVE_PB\s*1' src/pulseblaster.h || echo "Warning: Built with #define HAVE_PB 0."

manpages:
	bzip2 -kf man/pb_utils.1
	ln -sf  pb_utils.1.bz2  man/pb_start.1.bz2
	ln -sf  pb_utils.1.bz2  man/pb_stop.1.bz2
	ln -sf  pb_utils.1.bz2  man/pb_arm.1.bz2
	ln -sf  pb_utils.1.bz2  man/pb_stop-arm.1.bz2
	ln -sf  pb_utils.1.bz2  man/pb_cont.1.bz2
	ln -sf  pb_utils.1.bz2  man/pb_init.1.bz2
	ln -sf  pb_utils.1.bz2  man/pb_zero.1.bz2
	ln -sf  pb_utils.1.bz2  man/pb_prog.1.bz2
	ln -sf  pb_utils.1.bz2  man/pb_asm.1.bz2
	ln -sf  pb_utils.1.bz2  man/pb_vliw.1.bz2
	ln -sf  pb_utils.1.bz2  man/pb_check.1.bz2
	bzip2 -kf man/pb_test-pbinit-counter.1
	ln -sf  pb_test-pbinit-counter.1.bz2  man/pb_test-vliw-walk4.1.bz2
	bash man/vliw.5.sh
	bash man/pb_freq_gen.1.sh
	bash man/pb_manual.1.sh
	bash man/pb_serial_trigger.1.sh
	bash man/pb_serial_trigger_check.1.sh

examples :: examples_good examples_invalid
examples_good:
	rm -f vliw_examples/good/*.bin*
	@echo "Processing vliw_examples/good (correct code that should assemble ok)..."
	for file in vliw_examples/good/*.vliw ; do echo "Assembling $$file..."; ./src/pb_asm $$file || exit 1; done
	@echo "All good examples assembled succesfully."
examples_invalid:
	rm -f vliw_examples/invalid/*.bin*
	@echo "Processing vliw_examples/invalid (invalid code that should fail to assemble)..."
	for file in vliw_examples/invalid/*.vliw ; do echo "Assembling $$file..."; ! ./src/pb_asm $$file && echo "**CORRECTLY** detected deliberate syntax error in file $$file ." || exit 1; done
	@echo "All invalid examples succesfully failed to assemble."

clean:
	rm -f src/pb_start
	rm -f src/pb_stop
	rm -f src/pb_arm 
	rm -f src/pb_cont
	rm -f src/pb_init
	rm -f src/pb_zero
	rm -f src/pb_prog
	rm -f src/pb_asm
	rm -f src/pb_vliw
	rm -f src/pb_serial_trigger
	rm -f vliw_examples/good/*.bin*
	rm -f vliw_examples/invalid/*.bin*
	rm -f man/*.bz2 man/*.html

install:
	@[ `whoami` = root ] || (echo "Error, please be root"; exit 1)

	mkdir -p $(BINDIR) $(INCLUDEDIR) $(MAN1DIR) $(MAN5DIR) $(DOCDIR)

	install        src/pb_start               $(BINDIR)
	install        src/pb_stop                $(BINDIR)
	install        src/pb_arm                 $(BINDIR)
	install        src/pb_cont                $(BINDIR)
	install        src/pb_init                $(BINDIR)
	install        src/pb_zero                $(BINDIR)
	install        src/pb_prog                $(BINDIR)
	install        src/pb_asm                 $(BINDIR)
	install        src/pb_vliw                $(BINDIR)
	install        src/pb_check.sh            $(BINDIR)/pb_check
	install        src/pb_freq_gen.sh         $(BINDIR)/pb_freq_gen
	install        src/pb_manual.sh           $(BINDIR)/pb_manual
	install        src/pb_serial_trigger      $(BINDIR)
	install        src/pb_serial_trigger_check.sh $(BINDIR)/pb_serial_trigger_check

	install        tests/pb_test-pbinit-counter.sh   $(BINDIR)/pb_test-pbinit-counter
	install        tests/pb_test-vliw-walk4.sh       $(BINDIR)/pb_test-vliw-walk4

	install  -m644 src/pulseblaster.h  $(INCLUDEDIR)

	install  -m644 src/pb_utils.bashcompletion  $(BASHCOMPDIR)/pb_utils
	install  -m644 doc/* README.txt LICENSE.txt $(DOCDIR)
	install  -m644 man/*.1.bz2  $(MAN1DIR)
	install  -m644 man/*.5.bz2  $(MAN5DIR)
	cp -r          vliw_examples/               $(DOCDIR)

	@grep -qE '#define\s*HAVE_PB\s*1' /usr/local/include/pulseblaster.h || echo "Warning: Installed with #define HAVE_PB 0."
	@/bin/echo -e "Success: pb_utils is now installed."


uninstall:
	@[ `whoami` = root ] || (echo "Error, please be root"; exit 1)

	rm -rf $(DOCDIR)

	rm -f $(BINDIR)/pb_start
	rm -f $(BINDIR)/pb_stop
	rm -f $(BINDIR)/pb_arm 
	rm -f $(BINDIR)/pb_cont 
	rm -f $(BINDIR)/pb_init
	rm -f $(BINDIR)/pb_zero
	rm -f $(BINDIR)/pb_prog 
	rm -f $(BINDIR)/pb_asm 
	rm -f $(BINDIR)/pb_vliw
	rm -f $(BINDIR)/pb_check
	rm -f $(BINDIR)/pb_serial_trigger
	rm -f $(BINDIR)/pb_serial_trigger_check

	rm -f $(BINDIR)/pb_test-pbinit-counter
	rm -f $(BINDIR)/pb_test-vliw-walk4
	rm -f $(BINDIR)/pb_freq_gen
	rm -f $(BINDIR)/pb_manual

	rm -f $(INCLUDEDIR)/pulseblaster.h 

	rm -f $(BASHCOMPDIR)/pb_utils

	rm -f $(MAN1DIR)/pb_utils.1.bz2
	rm -f $(MAN1DIR)/pb_start.1.bz2
	rm -f $(MAN1DIR)/pb_stop.1.bz2
	rm -f $(MAN1DIR)/pb_arm.1.bz2
	rm -f $(MAN1DIR)/pb_stop-arm.1.bz2
	rm -f $(MAN1DIR)/pb_cont.1.bz2
	rm -f $(MAN1DIR)/pb_init.1.bz2
	rm -f $(MAN1DIR)/pb_zero.1.bz2
	rm -f $(MAN1DIR)/pb_prog.1.bz2
	rm -f $(MAN1DIR)/pb_asm.1.bz2
	rm -f $(MAN1DIR)/pb_vliw.1.bz2
	rm -f $(MAN1DIR)/pb_check.1.bz2
	rm -f $(MAN1DIR)/pb_freq_gen.1.bz2
	rm -f $(MAN1DIR)/pb_manual.1.bz2
	rm -f $(MAN1DIR)/pb_serial_trigger.1.bz2
	rm -f $(MAN1DIR)/pb_serial_trigger_check.1.bz2
	rm -f $(MAN1DIR)/pb_test-pbinit-counter.1.bz2
	rm -f $(MAN1DIR)/pb_test-vliw-walk4.1.bz2
	rm -f $(MAN5DIR)/vliw.5.bz2
