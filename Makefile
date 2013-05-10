COMMIT=`git rev-parse HEAD`

.PHONY: zip zipball tarball archive

zip zipball:
	git archive --format=zip -9 -o ga.$(COMMIT).zip $(COMMIT) lib

archive tarball:
	git archive --format=tgz -o ga.$(COMMIT).tar.gz $(COMMIT) lib


#  vim: set nowrap tabstop=2 shiftwidth=2 softtabstop=0 noexpandtab textwidth=0 filetype=make foldmethod=syntax foldcolumn=4
