PHP_INI_ARGS ?= -c /etc/php/php.ini

words-download:
	wget https://www.wordfrequency.info/samples/wordFrequency.xlsx

words-convert:
	mkdir -p words-csv/
	soffice --convert-to csv:"Text - txt - csv (StarCalc)":44,34,UTF8,1,,0,false,true,false,false,false,-1 \
		--headless \
		--outdir words-csv/ \
		wordFrequency.xlsx

nouns-filter:
	textql -header -sql 'SELECT lemma WHERE pos = "n" LIMIT 1000' 'words-csv/wordFrequency-1 lemmas.csv' \
		| grep -v '-' \
		> nouns.en.lst

server-start:
	php $(PHP_INI_ARG) -S localhost:8080 index.php
