#!/usr/bin/env bash
#
# Backup diário do Postgres do lugar — PLAN.md 8.3.
#
# Roda NO DROPLET, via cron do host (instalação no README ao lado). O desenho
# respeita o ADR-003: nada é compilado aqui — o pg_dump usado é o de dentro do
# próprio container do banco (mesma versão do servidor, sempre), e o upload é
# um s3cmd instalado pelo apt.
#
# Por que dump lógico e não snapshot de volume: o dump restaura em qualquer
# Postgres 16, inclusive no docker compose local — que é exatamente como o
# teste de restore (8.4) é feito. Snapshot de volume só restaura no mesmo
# ambiente, e um backup que só restaura onde o desastre aconteceu não ajuda.
#
set -euo pipefail

# ── configuração ─────────────────────────────────────────────────────────────
# O nome do container do banco no droplet (docker ps | grep db).
CONTAINER="${LUGAR_DB_CONTAINER:-lugar_lugar-db.1}"
USUARIO="${LUGAR_DB_USER:-lugar}"
BANCO="${LUGAR_DB_NAME:-lugar}"

# Bucket no DigitalOcean Spaces. O s3cmd já deve estar configurado
# (~/.s3cfg) com a chave do Spaces — ver README.
DESTINO="${LUGAR_BACKUP_BUCKET:-s3://lugar-backups}"
RETENCAO_DIAS=30

# ── dump ─────────────────────────────────────────────────────────────────────
DATA="$(date +%Y%m%d-%H%M%S)"
ARQUIVO="lugar-${DATA}.sql.gz"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# --clean --if-exists: o restore pode rodar por cima de um banco sujo.
# O pg_dump roda DENTRO do container: mesma versão do servidor, sem instalar
# cliente no host.
docker exec "$CONTAINER" pg_dump -U "$USUARIO" -d "$BANCO" --clean --if-exists \
  | gzip > "${TMP}/${ARQUIVO}"

# Um dump vazio "funciona" e não protege nada. 1 KB de gzip é menos que o
# esquema sozinho — se ficou menor que isso, algo deu errado em silêncio.
TAMANHO=$(stat -c%s "${TMP}/${ARQUIVO}")
if [ "$TAMANHO" -lt 1024 ]; then
  echo "ERRO: dump suspeito de vazio (${TAMANHO} bytes). Nada foi enviado." >&2
  exit 1
fi

# ── upload e retenção ────────────────────────────────────────────────────────
s3cmd put "${TMP}/${ARQUIVO}" "${DESTINO}/${ARQUIVO}"

# Apaga o que passou da retenção. `LC_ALL=C` para a data do s3cmd ser estável.
LIMITE=$(date -d "-${RETENCAO_DIAS} days" +%s)
s3cmd ls "${DESTINO}/" | while read -r linha; do
  DATA_ARQ=$(echo "$linha" | awk '{print $1}')
  CAMINHO=$(echo "$linha" | awk '{print $4}')
  [ -z "$CAMINHO" ] && continue
  if [ "$(date -d "$DATA_ARQ" +%s)" -lt "$LIMITE" ]; then
    s3cmd del "$CAMINHO"
    echo "retenção: removido $CAMINHO"
  fi
done

echo "OK: ${ARQUIVO} (${TAMANHO} bytes) em ${DESTINO}"
