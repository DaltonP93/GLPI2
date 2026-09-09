# Entorno local de desarrollo (Docker)

Levanta GLPI **oficial** + MariaDB + cron + MailHog, montando nuestros plugins
**sin tocar el core**. Detalle operativo en `../../infra/docker/README.md`.

## Pasos
```bash
# 1) Variables (secretos fuera de Git)
cd infra/docker
cp .env.example .env          # definir MARIADB_PASSWORD y MARIADB_ROOT_PASSWORD

# 2) Levantar el stack (descarga GLPI 11.0.8 oficial y monta plugins)
docker compose up -d --build

# 3) Instalar GLPI por consola (español)
docker compose exec glpi php bin/console db:install \
  --db-host=db --db-name="$MARIADB_DATABASE" \
  --db-user="$MARIADB_USER" --db-password="$MARIADB_PASSWORD" \
  --default-language=es_ES --no-interaction
docker compose exec glpi rm -f /var/www/glpi/install/install.php

# 4) Instalar/activar el plugin de validación
docker compose exec glpi php bin/console glpi:plugin:install --username=glpi companyqr
docker compose exec glpi php bin/console glpi:plugin:activate companyqr
```

- GLPI: http://localhost:8080  ·  MailHog: http://localhost:8025

## Configuración post-instalación
En **Configuración → General**: idioma **Español**, zona horaria
`America/Asuncion`, moneda **PYG**. Cargar tablas de husos en MariaDB (ver
`../../infra/docker/README.md`, paso 6).

## Verificación
```bash
tests/smoke/run-smoke.sh            # smoke tests
tests/upgrade/verify-core-untouched.sh   # el core no está versionado
```

## Notas para quien está aprendiendo
- Los plugins viven en `plugins/` del repo y se ven dentro de GLPI porque el
  compose los **monta** (no los copia).
- Editás el plugin en tu editor y el cambio se refleja en el contenedor.
- El core de GLPI vive **dentro de la imagen** y no debe editarse.
