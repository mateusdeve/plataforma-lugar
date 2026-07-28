#!/usr/bin/env bash
#
# Backup diário do Postgres do lugar — PLAN.md 8.3.
#
# Roda NO DROPLET, via cron do host. O desenho respeita o ADR-003: nada é
# compilado aqui — o pg_dump usado é o de dentro do próprio container do banco
# (mesma versão do servidor, sempre).
#
# O destino é um REPOSITÓRIO PRIVADO no GitHub (mateusdeve/lugar-backups),
# via deploy key exclusiva. Decisão de custo zero, adequada A ESTA escala: o
# dump comprimido tem poucos KB. Dump de banco em git é má ideia quando os
# arquivos crescem — se um dia o projeto passar de alguns MB por dump, o
# caminho de upgrade é DigitalOcean Spaces + s3cmd (o histórico deste arquivo
# no git guarda a versão que fazia isso).
#
# Por que dump lógico e não snapshot de volume: o dump restaura em qualquer
# Postgres 16, inclusive no docker compose local — que é exatamente como o
# teste de restore (8.4) é feito. Snapshot de volume só restaura no mesmo
# ambiente, e um backup que só restaura onde o desastre aconteceu não ajuda.
#
set -euo pipefail

# ── configuração ─────────────────────────────────────────────────────────────
REPO_DIR="/opt/lugar/backups"
REMOTO="git@github.com:mateusdeve/lugar-backups.git"
RETENCAO_DIAS=30

# A deploy key SÓ deste repositório. IdentitiesOnly impede o ssh de oferecer
# outras chaves do host — o backup não deve ter acesso a mais nada.
export GIT_SSH_COMMAND="ssh -i /root/.ssh/lugar_backup -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"

# O container do banco é RESOLVIDO a cada execução, não fixado: no Swarm o
# nome da task muda a todo redeploy (lugar_lugar-db.1.<id-da-task>), e um nome
# congelado aqui viraria backup silenciosamente quebrado no primeiro deploy.
CONTAINER="$(docker ps --filter name=lugar_lugar-db --format '{{.Names}}' | head -1)"
if [ -z "$CONTAINER" ]; then
  echo "ERRO: nenhum container lugar_lugar-db em execução." >&2
  exit 1
fi
USUARIO="${LUGAR_DB_USER:-lugar}"
BANCO="${LUGAR_DB_NAME:-lugar}"

# ── clone na primeira execução ───────────────────────────────────────────────
if [ ! -d "${REPO_DIR}/.git" ]; then
  git clone "$REMOTO" "$REPO_DIR"
  git -C "$REPO_DIR" config user.name "backup-lugar"
  git -C "$REPO_DIR" config user.email "backup@comprarbem.store"
  # Repositório recém-criado não tem commit; garante o nome do branch.
  git -C "$REPO_DIR" symbolic-ref HEAD refs/heads/main 2>/dev/null || true
fi

# Remoto vazio (primeira execução) não tem o que puxar — segue.
git -C "$REPO_DIR" pull --ff-only 2>/dev/null || true

# ── dump ─────────────────────────────────────────────────────────────────────
DATA="$(date +%Y%m%d-%H%M%S)"
ARQUIVO="lugar-${DATA}.sql.gz"

# --clean --if-exists: o restore pode rodar por cima de um banco sujo.
# gzip -n: sem timestamp no cabeçalho — dois dumps idênticos geram bytes
# idênticos, e o git não guarda um "novo" arquivo igual ao anterior.
docker exec "$CONTAINER" pg_dump -U "$USUARIO" -d "$BANCO" --clean --if-exists \
  | gzip -n > "${REPO_DIR}/${ARQUIVO}"

# Um dump vazio "funciona" e não protege nada. 1 KB de gzip é menos que o
# esquema sozinho — se ficou menor que isso, algo deu errado em silêncio.
TAMANHO=$(stat -c%s "${REPO_DIR}/${ARQUIVO}")
if [ "$TAMANHO" -lt 1024 ]; then
  rm -f "${REPO_DIR}/${ARQUIVO}"
  echo "ERRO: dump suspeito de vazio (${TAMANHO} bytes). Nada foi enviado." >&2
  exit 1
fi

# ── retenção e push ──────────────────────────────────────────────────────────
# 30 dias de arquivos visíveis; o histórico do git fica como camada extra.
find "$REPO_DIR" -maxdepth 1 -name 'lugar-*.sql.gz' -mtime +"$RETENCAO_DIAS" -delete

git -C "$REPO_DIR" add -A
git -C "$REPO_DIR" commit -m "backup ${DATA} (${TAMANHO} bytes)" --quiet
git -C "$REPO_DIR" push origin HEAD --quiet

echo "OK: ${ARQUIVO} (${TAMANHO} bytes) versionado em ${REMOTO}"
