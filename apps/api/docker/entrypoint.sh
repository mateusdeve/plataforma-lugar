#!/bin/sh
set -eu

# Espera o banco aceitar conexão. O EasyPanel sobe os serviços em paralelo, e
# sem isso o container morreria na primeira tentativa de migration.
echo "[entrypoint] aguardando o banco…"
until php bin/console dbal:run-sql 'SELECT 1' >/dev/null 2>&1; do
    sleep 2
done

# As chaves JWT não vêm na imagem — estão no .gitignore e no .dockerignore,
# e é assim que deve ser: chave privada não se versiona nem se publica em
# registry. Elas são geradas no primeiro boot e vivem num volume, para
# sobreviver a deploys.
#
# Se fossem regeneradas a cada deploy, todo token emitido antes viraria
# inválido e todo mundo seria deslogado a cada publicação.
if [ ! -f config/jwt/private.pem ]; then
    echo "[entrypoint] gerando par de chaves JWT…"
    php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
fi

# Migrations sob lock global do Postgres. O porquê está documentado em
# src/UI/Console/MigrarCommand.php — resumo: o segundo container a subir fica
# bloqueado até o primeiro terminar, em vez de rodar a mesma migration junto.
echo "[entrypoint] aplicando migrations…"
php bin/console lugar:migrar

echo "[entrypoint] aquecendo o cache…"
php bin/console cache:warmup

echo "[entrypoint] subindo o servidor."
exec "$@"
