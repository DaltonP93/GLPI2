# GLPI2 — Plataforma Interna Modular sobre GLPI

Este repositorio contiene **nuestras extensiones, documentación, infraestructura y
pruebas** para una plataforma empresarial construida **sobre GLPI**.

> **GLPI NO vive en este repositorio.** GLPI es una **dependencia upstream oficial**
> (release estable **11.0.8**) que se descarga y se levanta aparte. Aquí solo viven
> nuestros plugins, servicios, documentación e infraestructura. **El core de GLPI
> nunca se modifica** (ver `CLAUDE.md`, Regla 0).

## Estructura del repositorio

```
docs/            Arquitectura, ADRs, especificación funcional, API, workflows, seguridad, operaciones
plugins/         Plugins propios de GLPI (companyqr implementado; el resto, esqueletos)
services/        Servicios desacoplados (IA, WhatsApp, Integration Hub)
infra/           Docker/Compose, nginx, backup, monitoreo, scripts
tests/           e2e, smoke y suite de actualización (upgrade)
CLAUDE.md        Reglas obligatorias del proyecto
```

## Orden obligatorio de decisión (antes de construir cualquier cosa)

`Configurar (nativo) → Plugin existente → Extender (plugin propio/hook) → Integrar (servicio/API) → Construir`

No se recrea nada que **GLPI 11 ya ofrezca de forma nativa**. Ver
`docs/architecture/glpi11-capability-matrix.md`.

## Entorno de desarrollo

Ver `docs/operations/local-dev-setup.md`. En resumen:

```bash
cp .env.example .env      # completar valores locales (sin credenciales reales en Git)
cd infra/docker
docker compose up -d      # descarga GLPI 11.0.8 oficial y monta nuestros plugins
```

## Licencia

Los plugins de GLPI se distribuyen bajo **GPL-3.0-or-later** (compatible con GLPI).
Ver el encabezado de licencia declarado en cada `plugins/*/setup.php`.

---
Estado del proyecto: **Fase 2 en curso** — motor de workflow e integración de activos
implementados; firma electrónica interna es lo siguiente.

```
Fase 0  — Fundaciones .................... ✅
Fase 1  — companyqr ..................... ✅
Fase 2  — Arquitectura .................. ✅
Fase 2A — companyworkflow ............... ✅
Fase 2B — SI-1 / Snipe integration ...... ✅
Fase 2C — companysignature .............. ⏭️ siguiente
Fase 2D — companypurchasing ............. pendiente
```
