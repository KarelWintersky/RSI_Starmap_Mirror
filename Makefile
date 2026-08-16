#!/usr/bin/make

help:
	@perl -e '$(HELP_ACTION)' $(MAKEFILE_LIST)

# ------------------------------------------------
# Add the following 'help' target to your makefile, add help text after each target name starting with '\#\#'
# A category can be added with @category
GREEN  := $(shell tput -Txterm setaf 2)
YELLOW := $(shell tput -Txterm setaf 3)
WHITE  := $(shell tput -Txterm setaf 7)
RESET  := $(shell tput -Txterm sgr0)
HELP_ACTION = \
	%help; while(<>) { push @{$$help{$$2 // 'options'}}, [$$1, $$3] if /^([a-zA-Z\-_]+)\s*:.*\#\#(?:@([a-zA-Z\-]+))?\s(.*)$$/ }; \
	print "usage: make [target]\n\n"; for (sort keys %help) { print "${WHITE}$$_:${RESET}\n"; \
	for (@{$$help{$$_}}) { $$sep = " " x (32 - length $$_->[0]); print "  ${YELLOW}$$_->[0]${RESET}$$sep${GREEN}$$_->[1]${RESET}\n"; }; \
	print "\n"; }

# -eof-

PHP  ?= php
PORT ?= 8080

.PHONY: help fetch index assets media media-all build all serve lint

##@ Сборка данных (data/)
fetch:  ##@setup Скачать JSON с API: bootup + системы + объекты (повторный запуск пропускает уже скачанные; --force сбрасывает кэш)
	$(PHP) grab.php fetch

index:  ##@setup Собрать список media-URL из data/ в data/urlindex.json
	$(PHP) grab.php index

assets: ##@setup Зеркалировать статику CDN (модели .dae, звуки, css/js, шрифты, ui-images)
	$(PHP) grab.php assets

media:  ##@setup Скачать основное медиа: текстуры планет, 3D-модели, превью, галерею post
	$(PHP) grab.php media --texture --model --thumbnail --media_post

media-all: ##@setup Скачать ВСЁ медиа, включая все размеры галереи (медленно, большой объём)
	$(PHP) grab.php media

build:  ##@setup Собрать web/: index.html, патченный bundle, локальный API, поисковый индекс
	$(PHP) grab.php build

all: fetch index assets media build ##@setup Полная сборка: данные + статика + медиа + зеркало

##@ Запуск
serve:  ##@run Поднять локальный сервер офлайн-зеркала. Порт: make serve PORT=9000
	$(PHP) grab.php serve $(PORT)

demo:   ##@run Поднять демо-песочницу на данных своего сеттинга (web_demo/). Порт: make demo PORT=8099
	$(PHP) -S 0.0.0.0:$(PORT) web_demo/server.php

demo-data: ##@setup Перегенерировать данные демо (web_demo/api/starmap) из web_demo/make_data.php
	$(PHP) web_demo/make_data.php

##@ Проверки
lint:   ##@check Проверить синтаксис всех PHP-файлов проекта
	find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 $(PHP) -l
