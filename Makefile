all:

.PHONY: docs
docs:
	mkdir -p docs
	phpdoc -d . -t ./docs/lib

clean: clean-docs
	rm -r docs
