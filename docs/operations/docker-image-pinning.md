# Pinning de imágenes Docker e integridad del artefacto (hardening)

Hardening **no bloqueante** para Fase 0: lineamientos para STAGING/PROD sobre
fijado de versiones e integridad. En DEV se prioriza la comodidad; en
STAGING/PROD, la reproducibilidad y la seguridad de la cadena de suministro.

## 1. Evitar `:latest`
Nunca usar `:latest` en STAGING/PROD: es un tag móvil que rompe la
reproducibilidad y puede introducir cambios no auditados.

| Imagen | DEV (actual) | STAGING/PROD (recomendado) |
|--------|--------------|-----------------------------|
| GLPI (propia) | `Dockerfile.glpi` con `GLPI_VERSION=11.0.8` | build con versión fija + **digest** publicado |
| MariaDB | `mariadb:11.4` | `mariadb:11.4.<patch>@sha256:<digest>` |
| MailHog | `mailhog/mailhog:v1.0.1` (sólo DEV) | **no usar en PROD** (SMTP real) |
| PHP base | `php:8.3-apache` (ARG) | `php:8.3.<patch>-apache@sha256:<digest>` |

**Pin por digest** (máxima reproducibilidad):
```yaml
image: mariadb:11.4@sha256:<digest-exacto>
```
El digest se obtiene con `docker buildx imagetools inspect <imagen:tag>` o
`docker inspect --format='{{index .RepoDigests 0}}' <imagen:tag>`.

## 2. Integridad del artefacto GLPI (checksum)
`Dockerfile.glpi` acepta `--build-arg GLPI_SHA256=<hash>` para verificar el
tarball oficial descargado:
```bash
docker build -f infra/docker/Dockerfile.glpi \
  --build-arg GLPI_VERSION=11.0.8 \
  --build-arg GLPI_SHA256=<sha256-oficial> \
  infra/docker
```
Si se omite, la build **advierte** y continúa (aceptable en DEV). En STAGING/PROD
el `GLPI_SHA256` es **obligatorio**.

> El SHA256 debe tomarse de la publicación oficial del release (GitHub Releases de
> GLPI) y versionarse junto al pipeline de build, no inventarse.

## 3. Futuro (backlog de hardening)
- Publicar y firmar las imágenes propias (cosign) y verificar firmas en el deploy.
- Escaneo de vulnerabilidades de imágenes en CI (por ejemplo, Trivy) antes de PROD.
- Fijar también las `actions/*` de CI por SHA de commit.

## Referencias
- `../../infra/docker/Dockerfile.glpi`, `../../infra/docker/docker-compose.yml`
- Seguridad: `../security/security-baseline.md`
