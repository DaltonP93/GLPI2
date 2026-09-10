# Arquitectura definitiva — Plataforma GLPI Modular

> Base de producción: **GLPI 11.0.8** (estable). **12.0.0-rc1** NO se usa en
> producción. Localización: Paraguay (es / America\_Asuncion / PYG).

Esta es la arquitectura de referencia, ya **incorporando los hallazgos de GLPI 11**
(ver `glpi11-findings.md`) y el resultado del análisis nativo (ver
`glpi11-capability-matrix.md`).

## Principio rector
`Configurar (nativo) → Plugin existente → Extender (plugin propio/hook) →
Integrar (servicio/API) → Construir` — y **nunca** modificar el core (Regla 0,
`../adr/ADR-0002-glpi-core-immutable.md`).

## Capas

```
┌───────────────────────────────────────────────────────────────────────┐
│ Capa 1 — Experiencia                                                    │
│  Portal autoservicio · Portal técnico · Portal aprobadores · Móvil ·    │
│  Canales externos (WhatsApp)                                            │
├───────────────────────────────────────────────────────────────────────┤
│ Capa 2 — GLPI 11 (CORE, INMUTABLE)                                      │
│  Tickets · Activos/CMDB · Asset Definitions · Usuarios/Entidades · SLA ·│
│  Base de conocimiento · Forms nativo · Self-Service Portal · Webhooks · │
│  2FA · API REST v2 · Notificaciones/Correo                              │
├───────────────────────────────────────────────────────────────────────┤
│ Capa 3 — Plugins propios  (plugins/*)                                   │
│  companyportal · companypurchasing · companyworkflow · companyqr ·      │
│  companydashboard · companysignature · companyintegrations              │
├───────────────────────────────────────────────────────────────────────┤
│ Capa 4 — Servicios desacoplados  (services/*)                           │
│  ai-assistant (RAG+ACL) · whatsapp-adapter · integration-hub            │
├───────────────────────────────────────────────────────────────────────┤
│ Capa 5 — Datos / Observabilidad                                         │
│  BD GLPI · tablas glpi_plugin_*_ (prefijo propio) · almacenamiento doc ·│
│  logs estructurados · métricas · auditoría append-oriented              │
└───────────────────────────────────────────────────────────────────────┘
```

## Reglas de desacoplamiento (compatibilidad con upgrades)
1. Cero ediciones al core; GLPI se descarga como release oficial (no se versiona).
2. Cada plugin: prefijo de tabla propio, migraciones reversibles, rango de
   versiones GLPI declarado, README/CHANGELOG, tests.
3. Sólo interfaces soportadas (hooks/clases/API). Nunca SQL directo al core.
4. API-first: REST v2 nativa donde alcance; APIs propias `/api/v1` con OAuth2,
   webhooks, idempotencia y `correlation_id`; OpenAPI.
5. Servicios externos no tocan la BD.
6. i18n ES/EN; nada de negocio hardcodeado.
7. *Definition of Done* por módulo (código, migración reversible, ACL, i18n,
   auditoría, métricas/logs, tests, docs, changelog, core intacto).

## Documentos relacionados
- Hallazgos GLPI 11: `glpi11-findings.md`
- Matriz de capacidades: `glpi11-capability-matrix.md`
- Proceso previo a cada módulo: `native-first-process.md`
- Mapa de módulos: `module-map.md`
- Riesgos: `risks.md`
- Documento maestro (canónico): `master-document.md`
- Operaciones (dev/staging/prod, backup, upgrade): `../operations/`
