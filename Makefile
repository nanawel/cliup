PHP_INI_ARGS ?= -c /etc/php/php.ini
LISTEN_HOST  ?= localhost
LISTEN_PORT  ?= 8080

.PHONY: words-download
words-download:
	wget https://www.wordfrequency.info/samples/wordFrequency.xlsx

.PHONY: words-convert
words-convert:
	mkdir -p words-csv/
	soffice --convert-to csv:"Text - txt - csv (StarCalc)":44,34,UTF8,1,,0,false,true,false,false,false,-1 \
		--headless \
		--outdir words-csv/ \
		wordFrequency.xlsx

.PHONY: words-filter
words-filter:
	textql -header -sql 'SELECT lemma WHERE pos = "n" AND lemma NOT LIKE "%-%" LIMIT 1000' 'words-csv/wordFrequency-1 lemmas.csv' \
		> wordslist.txt

.PHONY: server-start
server-start:
	php $(PHP_INI_ARG) -S $(LISTEN_HOST):$(LISTEN_PORT) index.php

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