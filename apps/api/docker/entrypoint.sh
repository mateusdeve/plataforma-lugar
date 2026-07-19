#!/bin/sh
set -eu

# Espera o banco aceitar conexão. O EasyPanel sobe os serviços em paralelo, e
# sem isso o container morreria na primeira tentativa de migration.
echo "[entrypoint] aguardando o banco…"
until php bin/console dbal:run-sql 'SELECT 1' >/dev/null 2>&1; do
    sleep 2
done

# Migrations sob lock global do Postgres. O porquê está documentado em
# src/UI/Console/MigrarCommand.php — resumo: o segundo container a subir fica
# bloqueado até o primeiro terminar, em vez de rodar a mesma migration junto.
echo "[entrypoint] aplicando migrations…"
php bin/console lugar:migrar

echo "[entrypoint] aquecendo o cache…"
php bin/console cache:warmup

echo "[entrypoint] subindo o servidor."
exec "$@"
