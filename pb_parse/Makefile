PREFIX      = $(DESTDIR)/usr/local
BINDIR      = $(PREFIX)/bin
INCLUDEDIR  = $(PREFIX)/include
DATAROOTDIR = $(PREFIX)/share
DOCDIR      = $(DATAROOTDIR)/doc/pb_parse
EXAMPLEDIR  = $(DOCDIR)/pbsrc_examples
MANDIR      = $(DATAROOTDIR)/man
MAN1DIR     = $(MANDIR)/man1
MAN5DIR     = $(MANDIR)/man5
BASHCOMPDIR = /etc/bash_completion.d
KATESYNTAXDIR = /usr/share/kde4/apps/katepart/syntax
KATESYNTAXDIR2 = /usr/share/apps/katepart/syntax

all ::	pbparse manpages
verify :: examples

pbparse:
	gcc -Wall -Wextra -Werror -o src/pb_parport-output src/pb_parport-output.c
	php -l src/pb_parse.php || ./src/pb_parse.php  
	./src/pb_parse.php -me > pbsrc_examples/good/example.pbsrc
	./src/pb_parse.php -QXxm -DnoABC -i pbsrc_examples/good/example.pbsrc && echo 'Test ok'
	@echo "Consider 'make examples' to test further."
	@echo "Now run 'make install' to install."

manpages:
	bash man/pb_parse.1.sh
	bash man/pb_test-pbsrc-walk5.1.sh
	bash man/pb_test-parport.1.sh
	bash man/pb_parport-output.1.sh
	bash man/pbsrc.5.sh
	bash man/pbsim.5.sh

examples :: examples_good examples_bad examples_invalid examples_large examples_simulate examples_realworld
examples_good:
	rm -f pbsrc_examples/good/*.vliw pbsrc_examples/good/*.bin
	@echo "Processing examples/good (correct code that should parse ok)..."
	for file in pbsrc_examples/good/*.pbsrc ; do echo "Parsing $$file..." ; ./src/pb_parse.php -qQmaxX -Dsixk=6000 -Dsix_opt=6 -DUSE_SIX -DnoUSE_SEVEN -DnoABC -i $$file || exit 1 ; done
	@echo "All good examples parsed succesfully."
examples_bad:
	rm -f pbsrc_examples/bad/*.vliw pbsrc_examples/bad/*.bin
	@echo "Processing examples/bad (incorrect code that should still parse ok, though it may not assemble)..."
	for file in pbsrc_examples/bad/*.pbsrc ; do echo "Parsing $$file..." ;./src/pb_parse.php -qQmx -i $$file || exit 1 ; done
	@echo "All bad, valid-syntax examples parsed succesfully."
examples_invalid:
	rm -f pbsrc_examples/invalid/*.vliw pbsrc_examples/invalid/*.bin
	@echo "Processing examples/invalid (syntax errors that should be caught)..."
	for file in pbsrc_examples/invalid/*.pbsrc ; do echo "Parsing $$file..." ; ! ./src/pb_parse.php -qQmax -i $$file && echo " -> **correctly** detected deliberate syntax error in file $$file ." || exit 1; done	
	@echo "All invalid examples succesfully failed to parse."
examples_large:
	rm -f pbsrc_examples/large/*.vliw pbsrc_examples/large/*.bin
	@echo "Processing examples/large (correct code, large file, to see that there are no O(n^2) algorithm problems), can take *10 seconds*..."
	for file in pbsrc_examples/large/*.pbsrc ; do echo "Parsing $$file..." ; time ./src/pb_parse.php -qQmax -i $$file || exit 1 ; done
	@echo "All large examples parsed succesfully."
examples_simulate:
	rm -f pbsrc_examples/simulate/*.vliw pbsrc_examples/simulate/*.bin pbsrc_examples/simulate/*.pbsim pbsrc_examples/simulate/*.vcd
	@echo "Processing examples/simulate (correct code that should also be simulated to get .pbsim and .vcd)..."
	for file in pbsrc_examples/simulate/*.pbsrc ; do echo "Parsing $$file..." ; ./src/pb_parse.php -qQmaxgG -i $$file || exit 1 ; done
	@echo "All simulations succeeded."
examples_realworld:
	rm -f pbsrc_examples/realworld/*.vliw pbsrc_examples/realworld/*.bin
	@echo "Processing examples/realworld (actually useful code for physics)..."
	./src/pb_parse.php -xqmX -i pbsrc_examples/realworld/pixel_characterise.pbsrc -DQ0_X=0 -DQ0_Y=1 -DQ1_X=2 -DQ1_Y=3 -DQ2_X=255 -DQ2_Y=256  -DQ3_X=510 -DQ3_Y=511 -DT_INIT=0.1s -DT_RENABLED=0.1s -DT_RESET=0.5s -DT_EXPOSE=1s
	@echo "Real-world example parsed succesfully."
examples_clean:
	rm -f pbsrc_examples/*/*.vliw pbsrc_examples/*/*.bin pbsrc_examples/*/*.pbsim pbsrc_examples/*/*.vcd  pbsrc_examples/*/*~

clean:	examples_clean
	rm -f src/pb_parport-output
	rm -f man/*.bz2 man/*.html

install: examples_clean			#Don't install the .vliw files as examples.
	@[ `whoami` = root ] || (echo "Error, please be root"; exit 1)

	@[ -x /usr/bin/pb_print_config -o -x /usr/local/bin/pb_print_config ] || (echo "ERROR: pb_print_config is not present. Please install pb_utils first"; exit 1)
	@[ -x /usr/bin/php ] || (echo "ERROR:  /usr/bin/php is not present. Please install php-cli first"; exit 1)
	php -l src/pb_parse.php	|| ./src/pb_parse.php	#Extra check to be sure.

	mkdir -p $(BINDIR) $(MAN1DIR) $(MAN5DIR) $(DOCDIR) $(EXAMPLEDIR)

	install        src/pb_parport-output         $(BINDIR)
	install        src/pb_parse.php              $(BINDIR)/pb_parse
	install        tests/pb_test-pbsrc-walk5.sh  $(BINDIR)/pb_test-pbsrc-walk5
	install        tests/pb_test-parport.sh      $(BINDIR)/pb_test-parport
	install  -m644 src/pb_parse.bashcompletion   $(BASHCOMPDIR)/pb_parse
	install  -m644 doc/* README.txt LICENSE.txt  $(DOCDIR)
	cp -r          pbsrc_examples/*              $(EXAMPLEDIR)
	install  -m644 tests/walking_5leds_5Hz.pbsrc $(EXAMPLEDIR)/good
	install  -m644 tests/flash_leds_250Hz.pbsrc  $(EXAMPLEDIR)/good
	install  -m644 man/*.1.bz2  $(MAN1DIR)
	install  -m644 man/*.5.bz2  $(MAN5DIR)

	#Install pbsrc rule for kate/kwrite if we can; don't fail if we can't.
	[ -f $(KATESYNTAXDIR)/c.xml ]  &&  install -m644 src/pbsrc_kate.xml $(KATESYNTAXDIR)/pbsrc.xml  || true
	[ -f $(KATESYNTAXDIR2)/c.xml ] &&  install -m644 src/pbsrc_kate.xml $(KATESYNTAXDIR2)/pbsrc.xml  || true

	@/bin/echo -e "\nSuccess. pb_parse is now installed."

uninstall:
	@[ `whoami` = root ] || (echo "Error, please be root"; exit 1)

	rm -rf $(DOCDIR)
	rm -f  $(BINDIR)/pb_parse
	rm -f  $(BINDIR)/pb_parport-output
	rm -f  $(BASHCOMPDIR)/pb_parse
	rm -f  $(BINDIR)/pb_test-pbsrc-walk5
	rm -f  $(BINDIR)/pb_test-parport
	rm -f  $(MAN1DIR)/pb_parse.1.bz2
	rm -f  $(MAN1DIR)/pb_test-pbsrc-walk5.1.bz2
	rm -f  $(MAN1DIR)/pb_test-parport.1.bz2
	rm -f  $(MAN1DIR)/pb_parport-output.1.bz2
	rm -f  $(MAN5DIR)/pbsrc.5.bz2
	rm -f  $(MAN5DIR)/pbsim.5.bz2
	rm -f  $(KATESYNTAXDIR)/pbsrc.xml
	rm -f  $(KATESYNTAXDIR2)/pbsrc.xml
