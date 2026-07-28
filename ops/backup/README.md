# Backup e restore — PLAN.md 8.3 e 8.4

O script ao lado faz o `pg_dump` diário do banco de produção e o versiona no
repositório **privado** `mateusdeve/lugar-backups`, com retenção de 30 dias
nos arquivos (o histórico do git fica como camada extra). Custo zero, adequado
a esta escala: o dump comprimido tem poucos KB. Se um dia passar de alguns MB,
o upgrade é DigitalOcean Spaces + s3cmd — o histórico deste diretório no git
guarda a versão do script que fazia exatamente isso.

## Como está montado (já instalado no droplet)

- `/opt/lugar/backup-lugar.sh` — o script;
- `/root/.ssh/lugar_backup` — deploy key **exclusiva** do repositório de
  backups, com escrita, registrada via `gh repo deploy-key add`. Ela não abre
  mais nada: `IdentitiesOnly=yes` no script;
- `/opt/lugar/backups/` — clone local onde os dumps são commitados;
- cron: `17 3 * * *` (03h17 — horário quebrado de propósito: hora cheia é
  quando todo mundo agenda tudo, inclusive quem disputa o mesmo droplet), com
  log em `/var/log/lugar-backup.log`.

## O teste de restore (8.4)

> Backup nunca testado não é backup. Este teste roda em QUALQUER máquina com
> o repositório — é a razão de o backup ser dump lógico e não snapshot.

```bash
# 1. Baixar o dump mais recente
gh repo clone mateusdeve/lugar-backups /tmp/lugar-backups
DUMP=$(/bin/ls -1 /tmp/lugar-backups/lugar-*.sql.gz | sort | tail -1)

# 2. Restaurar num Postgres descartável (o do docker compose local serve)
docker compose up -d banco
gunzip -c "$DUMP" | docker compose exec -T banco psql -q -U lugar -d lugar

# 3. Provar que restaurou DADOS, não só esquema
docker compose exec -T banco psql -U lugar -d lugar -c \
  "SELECT (SELECT COUNT(*) FROM evento)  AS eventos,
          (SELECT COUNT(*) FROM reserva) AS reservas,
          (SELECT COUNT(*) FROM ingresso) AS ingressos;"

# 4. A prova final: a aplicação sobe em cima do restore
docker compose up -d api && sleep 5 && curl -s localhost:8000/health
```

Registre a data do teste aqui embaixo. Um teste de mais de 6 meses atrás
conta como nenhum.

| data | quem | resultado |
| ---- | ---- | --------- |
| 2026-07-28 | Mateus + Claude | ✅ dump de produção restaurado no compose local; 4 eventos, 4 reservas, 3 ingressos; `/health` ok em cima do restore |

## Snapshot do droplet (semanal)

O item 8.3 também pede snapshot do droplet — é o recurso pago de Backups da
DigitalOcean e ficou **de fora por decisão de custo**. O que ele cobriria
("perdi o droplet inteiro") fica coberto de outro jeito: a infraestrutura é
reconstruível do zero (imagem no GHCR, migrations no boot, EasyPanel) e os
dados estão no dump diário. O tempo de reconstrução é maior que o de um
snapshot; aceito conscientemente num projeto de demonstração.
