# Entorno Docker — Plataforma GLPI Modular (DEV/STAGING)

Levanta **GLPI oficial estable** + **MariaDB** + **cron** + **MailHog**, montando
nuestros plugins **sin tocar el core**.

> **GLPI es upstream.** La imagen descarga el release oficial `GLPI_VERSION`.
> Nuestros plugins se montan desde `../../plugins`. El core nunca se edita.

## 1. Requisitos
- Docker Engine + Docker Compose v2.

## 2. Preparar variables (secretos fuera de Git)
```bash
cd infra/docker
cp .env.example .env
# Editar .env y definir MARIADB_PASSWORD y MARIADB_ROOT_PASSWORD reales.
```

## 3. Levantar el stack
```bash
docker compose up -d --build
# GLPI:     http://localhost:8080
# MailHog:  http://localhost:8025
```

## 4. Instalar GLPI por CLI (español, sin asistente web)
La consola oficial de GLPI evita el instalador web y es reproducible:
```bash
docker compose exec glpi php bin/console db:install \
  --db-host=db --db-name="$MARIADB_DATABASE" \
  --db-user="$MARIADB_USER" --db-password="$MARIADB_PASSWORD" \
  --default-language=es_ES --no-interaction
# Eliminar el directorio de instalación (recomendado por GLPI):
docker compose exec glpi rm -f /var/www/glpi/install/install.php
```
Luego, en **Configuración → General**, fijar zona horaria `America/Asuncion`,
idioma **Español** y moneda **PYG** (ver `docs/operations/installation.md`).

## 5. Instalar/activar nuestros plugins (montados, no copiados)
```bash
docker compose exec glpi php bin/console glpi:plugin:install --username=glpi companyqr
docker compose exec glpi php bin/console glpi:plugin:activate companyqr
```

## 6. Zona horaria de MySQL (timezones por usuario)
Para habilitar husos horarios en GLPI:
```bash
docker compose exec db sh -c \
 'mariadb-tzinfo-to-sql /usr/share/zoneinfo | mariadb -u root -p"$MARIADB_ROOT_PASSWORD" mysql'
docker compose exec db mariadb -u root -p"$MARIADB_ROOT_PASSWORD" \
 -e "GRANT SELECT ON mysql.time_zone_name TO '${MARIADB_USER}'@'%'; FLUSH PRIVILEGES;"
```

## 7. Logs, backup y apagado
```bash
docker compose logs -f glpi          # logs de aplicación/apache
../backup/backup.sh                  # backup DB + files (ver script)
docker compose down                  # detener (los volúmenes persisten)
docker compose down -v               # detener y BORRAR datos (¡cuidado!)
```

## Notas
- `nginx/glpi.conf.example` es una alternativa de reverse-proxy para producción.
- Para **producción** no se usa MailHog: se configura SMTP real por secret manager.
