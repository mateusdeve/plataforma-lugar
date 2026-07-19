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
#
# FrankenPHP e não php-fpm + nginx.
#
# O fpm fala FastCGI, não HTTP — sozinho ele não atende o proxy do EasyPanel.
# O arranjo clássico seria nginx na frente, o que significa um segundo
# processo e um supervisor para cuidar dos dois dentro do mesmo container.
#
# O FrankenPHP junta servidor HTTP e runtime PHP num binário só: um processo,
# sem supervisor, menos memória — o que importa num droplet de 2 GB dividido
# com serviços de produção de terceiros.
FROM dunglas/frankenphp:1-php8.4-alpine AS prod

RUN install-php-extensions pdo_pgsql intl opcache

WORKDIR /app

# Opcache de produção: validate_timestamps desligado porque o código não muda
# dentro de um container em execução. Trocar de versão é subir imagem nova.
RUN { \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'display_errors=Off'; \
        echo 'expose_php=Off'; \
        echo 'memory_limit=96M'; \
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

# `:80` e não um nome de domínio: isso DESLIGA o HTTPS automático do Caddy.
#
# Quem termina TLS é o proxy do EasyPanel, que já provisiona Let's Encrypt.
# Deixar o container também tentar emitir certificado dá dois problemas: ele
# precisa escrever em /data/caddy (e não roda como root) e passaria a
# competir com o proxy pelo desafio ACME.
ENV SERVER_NAME=:80

# Quantos workers PHP o servidor mantém. Quatro cabem com folga em 256 MB e
# dão previsibilidade — importa mais que elasticidade em servidor dividido.
ENV FRANKENPHP_CONFIG="worker ./public/index.php 4"

# O Caddy escreve estado em /data e /config mesmo sem TLS.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p var /data /config \
    && chown -R www-data:www-data /app /data /config

# Processo de aplicação não precisa de root. Um container comprometido
# rodando como root é um problema maior que o mesmo container sem privilégio.
USER www-data

EXPOSE 80

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
