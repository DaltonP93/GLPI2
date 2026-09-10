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

# 3) Instalar GLPI + localización de forma REPRODUCIBLE (idioma es_ES,
#    tablas de husos y database:enable_timezones). Comandos oficiales GLPI 11.
cd ../..                      # volver a la raíz del repo
bash infra/docker/glpi-config/install-and-localize.sh

# 4) Instalar/activar el plugin de validación
cd infra/docker
docker compose exec glpi php bin/console plugin:install --username=glpi companyqr
docker compose exec glpi php bin/console plugin:activate companyqr
```

- GLPI: http://localhost:8080  ·  MailHog: http://localhost:8025

## Configuración post-instalación (pasos administrativos)
Parte de la localización y el correo se configuran en la interfaz de admin
(GLPI 11 no ofrece comando de consola para ello):
- **Localización:** zona horaria de instancia `America/Asuncion` y formato numérico
  PYG → ver `localization.md` (qué es global vs preferencia por usuario).
- **Correo (DEV):** habilitar seguimientos por correo hacia MailHog → ver `email-dev.md`.

## Verificación (fail-closed)
```bash
bash tests/smoke/run-smoke.sh                  # smoke tests
bash tests/upgrade/verify-core-untouched.sh    # el core no está versionado
bash tests/localization/verify-localization.sh # idioma es, tz, timezones
bash tests/mail/verify-mail.sh                 # transporte GLPI -> MailHog
```

## Notas para quien está aprendiendo
- Los plugins viven en `plugins/` del repo y se ven dentro de GLPI porque el
  compose los **monta** (no los copia).
- Editás el plugin en tu editor y el cambio se refleja en el contenedor.
- El core de GLPI vive **dentro de la imagen** y no debe editarse.
