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
plugins/         Plugins propios de GLPI (5 implementados; companyportal y companydashboard: esqueletos)
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
## Estado del proyecto — baseline de Fase 2

La Fase 2 está **completa en código** sobre `main` `74dc2c7`. Es una baseline **candidata**:
- sin tag ni GitHub Release;
- pendiente de la UAT en staging.

El detalle, las versiones reales y el orden de instalación están en
[`docs/releases/phase2-baseline.md`](docs/releases/phase2-baseline.md).

```
Fase 0  — Fundaciones ........................ ✅
Fase 1  — companyqr .......................... ✅
Fase 2  — Arquitectura ....................... ✅
Fase 2A — companyworkflow .................... ✅
Fase 2B — SI-1 / Snipe read integration ...... ✅
Fase 2C — companysignature ................... ✅  (firma interna / evidencia; NO firma certificada)
Fase 2D — companypurchasing v1 ............... ✅
SI4-1   — Snipe create/reconcile ............. ✅
SI4-2   — GLPI asset + Infocom + bridge ...... ✅
SI4-3   — QR + label + ACK/finalize .......... ✅  (código listo; worker DESHABILITADO: si4_enabled = 0)
```

| Plugin | Versión (`setup.php`) | Estado |
|--------|-----------------------|--------|
| `companyqr` | 0.3.0 | implementado |
| `companyworkflow` | 0.6.1 | implementado |
| `companysignature` | 0.5.1 | implementado (firma interna / evidencia) |
| `companyintegrations` | 0.6.0 | implementado (SI-1 + SI4-1/2/3; worker SI-4 deshabilitado) |
| `companypurchasing` | 0.5.0 | implementado (v1) |
| `companyportal` | 0.1.0 | **esqueleto / no implementado** |
| `companydashboard` | 0.1.0 | **esqueleto / no implementado** |

**No implementado todavía (sin fase comprometida):** `companyportal`, `companydashboard`,
servicios `ai-assistant` / `whatsapp-adapter` / `integration-hub` (esqueletos), firma digital
certificada. Ninguno de estos figura como completado.

Operación de la baseline:
[instalación limpia](docs/operations/phase2-clean-install.md) ·
[upgrade](docs/operations/phase2-upgrade.md) ·
[estado de SI-4](docs/operations/si4-readiness.md) ·
[UAT](docs/testing/phase2-uat.md).
