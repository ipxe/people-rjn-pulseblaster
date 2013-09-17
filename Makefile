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
	bzip2 -kf man/pulseblaster.1

install:
	@[ `whoami` = root ] || (echo "Error, please be root"; exit 1)
	make -C driver install
	make -C pb_utils install
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
	rm -rf   www/$(WWW_DIR)/$(WWW_DIR)
	cp       index.html README.txt www/$(WWW_DIR)/
	@echo "Now, upload www/$(WWW_DIR)/ and link to www/$(WWW_DIR)/index.html"

clean:
	make -C driver clean
	make -C pb_utils clean
	rm -f man/*.bz2 man/*.html
	rm -rf www

uninstall:
	@[ `whoami` = root ] || (echo "Error, please be root"; exit 1)
	rm -f  $(MAN1DIR)/pulseblaster.1.bz2
	rm -rf $(DOCDIR)
	make -C driver uninstall
	make -C pb_utils uninstall
