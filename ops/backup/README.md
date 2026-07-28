# Backup e restore — PLAN.md 8.3 e 8.4

O script ao lado faz o `pg_dump` diário do banco de produção para o
DigitalOcean Spaces, com retenção de 30 dias. Este README é a instalação —
três passos no droplet — e o **teste de restore**, que é o que transforma o
backup em backup.

## Instalação (uma vez, no droplet)

```bash
# 1. s3cmd (upload para o Spaces; nada é compilado — ADR-003)
apt-get update && apt-get install -y s3cmd

# 2. Credencial do Spaces (criar em DigitalOcean → API → Spaces Keys)
s3cmd --configure   # endpoint: nyc3.digitaloceanspaces.com (ou a região do bucket)

# 3. O script e o cron
mkdir -p /opt/lugar && cp backup-lugar.sh /opt/lugar/ && chmod +x /opt/lugar/backup-lugar.sh
crontab -e
# 03h17 da manhã, todo dia. Horário quebrado de propósito: hora cheia é
# quando todo mundo agenda tudo, inclusive quem disputa o mesmo droplet.
# 17 3 * * * /opt/lugar/backup-lugar.sh >> /var/log/lugar-backup.log 2>&1
```

Confira o nome do container antes (`docker ps | grep db`) e ajuste
`LUGAR_DB_CONTAINER` no crontab se for diferente do padrão do script.

## O teste de restore (8.4)

> Backup nunca testado não é backup. Este teste roda em QUALQUER máquina com
> o repositório — é a razão de o backup ser dump lógico e não snapshot.

```bash
# 1. Baixar o dump mais recente
s3cmd get "$(s3cmd ls s3://lugar-backups/ | sort | tail -1 | awk '{print $4}')" dump.sql.gz

# 2. Restaurar num Postgres descartável (o do docker compose local serve)
docker compose up -d banco
gunzip -c dump.sql.gz | docker compose exec -T banco psql -U lugar -d lugar

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
| _pendente_ | | |

## Snapshot do droplet (semanal)

Ativar em DigitalOcean → Droplet → **Backups** (semanal, gerenciado por
eles). O snapshot cobre o desastre "perdi o droplet inteiro" — o dump cobre
"perdi os dados". São coisas diferentes e as duas precisam existir.
