# Procedimiento de instalación

## 1. Requisitos
- Docker + Docker Compose (DEV/STAGING). En producción, servidor con PHP 8.2+ y
  MariaDB/MySQL soportados por GLPI 11.
- Variables/secretos por entorno (`.env` en DEV; secret manager en STAGING/PROD).

## 2. Obtener GLPI (upstream, no fork)
La imagen `infra/docker/Dockerfile.glpi` **descarga** el release oficial estable
`GLPI_VERSION` (11.0.8). El core no se versiona ni se modifica.

## 3. Base de datos
- Base **separada** (servicio `db`, MariaDB) con volumen persistente.
- Cargar tablas de husos horarios para habilitar timezones por usuario.

## 4. Instalar GLPI (consola, reproducible)

> **Antes de instalar — clave de seguridad (workaround GLPI 11, ver ADR-0017).**
> GLPI usa `config/glpicrypt.key` (32 bytes binarios) como `kernel.secret` de
> Symfony. Si esa clave aleatoria contiene un byte `%`, Symfony la interpreta como
> `%parameter%` y **cualquier** `bin/console` falla con `ParameterNotFoundException`
> (bug upstream, intermitente). Como GLPI **respeta** una clave ya existente, se
> pre-provisiona una clave criptográfica de 32 bytes **sin `%`** antes de instalar:
> ```bash
> bash infra/docker/glpi-config/provision-security-key.sh
> ```
> No es un cambio del core ni del algoritmo criptográfico; es infraestructura.

```bash
docker compose exec -u www-data glpi php bin/console db:install \
  --db-host=db --db-name=<DB> --db-user=<USER> --db-password=<PASS> \
  --default-language=es_ES --no-interaction
docker compose exec glpi rm -f /var/www/glpi/install/install.php
```

## 5. Localización
Idioma **Español**, zona horaria `America/Asuncion`, moneda **PYG**, fecha
`dd/mm/aaaa`, 24 h.

## 6. Correo y cron
- Correo: SMTP por entorno (DEV usa MailHog).
- Cron: servicio `cron` ejecuta `front/cron.php` periódicamente.

## 7. Plugins propios
```bash
docker compose exec -u www-data glpi php bin/console plugin:install  --username=glpi <plugin>
docker compose exec -u www-data glpi php bin/console plugin:activate <plugin>
```

## 8. Verificación
- `tests/smoke/run-smoke.sh`
- `tests/upgrade/verify-core-untouched.sh`

> Para producción: revisar `../security/security-baseline.md`,
> `../security/secrets-management.md` y usar `nginx/glpi.conf.example` con TLS.
