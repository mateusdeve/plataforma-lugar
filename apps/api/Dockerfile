# ─────────────────────────────────────────────────────────────────────────────
# Imagem da API. Multi-stage: `dev` para o docker-compose local, `prod` para o
# que o GitHub Actions publica no GHCR e o EasyPanel puxa (ADR-003).
#
# O droplet nunca compila nada — build no CI é reprodutível e o rollback vira
# troca de tag de imagem, não rebuild (PRD §7.3).
# ─────────────────────────────────────────────────────────────────────────────

# ── base comum ───────────────────────────────────────────────────────────────
FROM php:8.4-cli-alpine AS base

# pdo_pgsql: o driver do Postgres.
# intl: formatação e comparação de string sensível a locale.
# opcache: sem ele, o PHP recompila cada arquivo a cada requisição.
RUN apk add --no-cache postgresql-dev icu-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl opcache \
    && apk del linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ── dependências ─────────────────────────────────────────────────────────────
# Estágio separado só para o vendor. Enquanto composer.json/lock não mudarem,
# o Docker reaproveita esta camada e o build não baixa nada.
FROM base AS dependencias

COPY composer.json composer.lock* ./
RUN composer install \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --no-progress

# ── desenvolvimento ──────────────────────────────────────────────────────────
FROM base AS dev

# Xdebug fica de fora de propósito: pesa no boot e ninguém pediu. Quando
# precisar, é uma linha aqui — e só neste estágio.
COPY --from=dependencias /app/vendor ./vendor
COPY . .

EXPOSE 8000

# Servidor embutido do PHP. É adequado para desenvolvimento e NUNCA para
# produção: single-threaded, sem gestão de processo, sem resistência a carga.
# Produção usa o estágio `prod`, com FPM.
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]

# ── produção ─────────────────────────────────────────────────────────────────
FROM php:8.4-fpm-alpine AS prod

RUN apk add --no-cache postgresql-dev icu-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl opcache \
    && apk del linux-headers

WORKDIR /app

# Configuração de opcache para produção: valida timestamp desligado, porque o
# código não muda dentro de um container em execução. Trocar de versão é subir
# uma imagem nova, não editar arquivo.
RUN { \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

RUN { \
        echo 'display_errors=Off'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/prod.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install \
        --no-interaction \
        --no-dev \
        --optimize-autoloader \
        --no-scripts \
        --prefer-dist \
        --no-progress \
    && rm /usr/bin/composer

COPY . .

ENV APP_ENV=prod APP_DEBUG=0

# Roda como usuário sem privilégio. Processo de aplicação não precisa de root,
# e um container comprometido rodando como root é um problema maior.
RUN chown -R www-data:www-data /app
USER www-data

EXPOSE 9000
CMD ["php-fpm"]
