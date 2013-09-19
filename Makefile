PREFIX      = $(DESTDIR)/usr/local
BINDIR      = $(PREFIX)/bin
DATAROOTDIR = $(PREFIX)/share
DOCDIR      = $(DATAROOTDIR)/doc/pulseblaster
MANDIR      = $(DATAROOTDIR)/man
MAN1DIR     = $(MANDIR)/man1

WWW_DIR     = pulseblaster

all ::	compile

compile:
	make -C driver
	make -C pb_utils
	make -C pb_parse
	bzip2 -kf man/pulseblaster.1

install:
	@[ `whoami` = root ] || (echo "Error, please be root"; exit 1)
	make -C driver install
	make -C pb_utils install
	make -C pb_parse install
	mkdir -p $(BINDIR) $(MAN1DIR) $(DOCDIR)
	install  -m644 README.txt LICENSE.txt index.html $(DOCDIR)
	install  -m644 man/*.1.bz2                       $(MAN1DIR)

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
	cp       www/$(WWW_DIR)/$(WWW_DIR)/pb_utils/vliw_examples/good/example.vliw  www/$(WWW_DIR)/example.vliw.txt
	make -C  www/$(WWW_DIR)/$(WWW_DIR)/pb_parse
	cp       www/$(WWW_DIR)/$(WWW_DIR)/pb_parse/man/*.html  www/$(WWW_DIR)
	cp       www/$(WWW_DIR)/$(WWW_DIR)/pb_parse/doc/*.txt  www/$(WWW_DIR)
	cp       www/$(WWW_DIR)/$(WWW_DIR)/pb_parse/pbsrc_examples/good/example.pbsrc www/$(WWW_DIR)/example.pbsrc.txt
	cp       www/$(WWW_DIR)/$(WWW_DIR)/pb_parse/pbsrc_examples/realworld/pixel_characterise.pbsrc www/$(WWW_DIR)/pixel_characterise.pbsrc.txt
	cp       www/$(WWW_DIR)/$(WWW_DIR)/pb_parse/pbsrc_examples/realworld/hawaii.h www/$(WWW_DIR)/hawaii.h.txt
	cp       www/$(WWW_DIR)/$(WWW_DIR)/pb_parse/pbsrc_examples/realworld/macros.pbsrc www/$(WWW_DIR)/macros.pbsrc.txt
	cp       www/$(WWW_DIR)/$(WWW_DIR)/pb_parse/pbsrc_examples/realworld/clock_calc.php www/$(WWW_DIR)/clock_calc.php.txt
	cp       www/$(WWW_DIR)/$(WWW_DIR)/pb_parse/pbsrc_examples/realworld/README.txt www/$(WWW_DIR)/README2.txt
	cp       www/$(WWW_DIR)/$(WWW_DIR)/pb_parse/tests/walking_5leds_5Hz.pbsrc www/$(WWW_DIR)/walking_5leds_5Hz.pbsrc.txt
	         www/$(WWW_DIR)/$(WWW_DIR)/pb_parse/tests/pb_test-pbsrc-walk5.demo_html.sh > www/$(WWW_DIR)/walking_5leds_5Hz.demo.html
	rm -rf   www/$(WWW_DIR)/$(WWW_DIR)
	cp       index.html README.txt www/$(WWW_DIR)/
	@echo "Now, upload www/$(WWW_DIR)/ and link to www/$(WWW_DIR)/index.html"

clean:
	make -C driver clean
	make -C pb_utils clean
	make -C pb_parse clean
	rm -f man/*.bz2 man/*.html
	rm -rf www

uninstall:
	@[ `whoami` = root ] || (echo "Error, please be root"; exit 1)
	rm -f  $(MAN1DIR)/pulseblaster.1.bz2
	rm -rf $(DOCDIR)
	make -C driver uninstall
	make -C pb_utils uninstall
	make -C pb_parse uninstall
