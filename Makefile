PHP_INI_ARGS ?=
LISTEN_HOST  ?= localhost
LISTEN_PORT  ?= 8080
MEMORY_LIMIT ?= 256M

.PHONY: words-download
words-download:
	wget https://www.wordfrequency.info/samples/wordFrequency.xlsx

.PHONY: words-convert
words-convert:
	@command -v soffice > /dev/null || { echo >&2 "Sorry, you need LibreOffice or OpenOffice for that."; exit 1; }
	mkdir -p words-csv/
	soffice --convert-to csv:"Text - txt - csv (StarCalc)":44,34,UTF8,1,,0,false,true,false,false,false,-1 \
		--headless \
		--outdir words-csv/ \
		wordFrequency.xlsx

.PHONY: words-filter
words-filter:
	@command -v textql > /dev/null || { echo >&2 "Sorry, you need textql for that: https://github.com/dinedal/textql"; exit 1; }
	textql -header -sql 'SELECT lemma WHERE pos = "n" AND lemma NOT LIKE "%-%" LIMIT 1000' 'words-csv/wordFrequency-1 lemmas.csv' \
		> wordslist.txt

.PHONY: server-start
server-start:
	php -c php.ini $(PHP_INI_ARG) \
		-d memory_limit=$(MEMORY_LIMIT) \
		-S $(LISTEN_HOST):$(LISTEN_PORT) \
		index.php

.PHONY: doc-generate-demo-gif
doc-generate-demo-gif:
	cd doc \
		&& LC_ALL=C vhs demo.tape

.PHONY: config
config:
	docker-compose config

.PHONY: build
build:
	docker-compose build $(args)
