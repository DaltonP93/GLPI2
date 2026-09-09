# Documento Maestro de Arquitectura — Plataforma Interna Modular basada en GLPI

> Versión viva y canónica. Base: **GLPI 11.x** (estable 11.0.8). Localización:
> Paraguay. Idioma principal: Español. Incorpora los **hallazgos de GLPI 11**
> (§19). Fuente original: documento maestro provisto por el negocio.

## 0. Decisiones rectoras
- GLPI es el núcleo estándar para ITSM, inventario/CMDB, activos, tickets,
  usuarios, entidades, contratos y base de conocimiento.
- **REGLA ABSOLUTA:** no modificar el core. Orden: configuración nativa → plugin
  oficial/comunitario → plugin propio → API/integración externa → core sólo por
  excepción formal aprobada (`../adr/ADR-0002-glpi-core-immutable.md`).
- Todo desarrollo propio: modular, versionado, actualizable, auditable, desacoplado.
- Español por defecto, inglés opcional, `America/Asuncion`, moneda **PYG**.
- Todo proceso importante produce métricas y auditoría.
- Integraciones **API-first**; los canales (WhatsApp, correo) son adaptadores.
- La IA respeta permisos, cita la fuente y escala cuando no tiene evidencia.

## 1. Objetivo del producto
Portal interno que unifique soporte, activos, solicitudes, compras, aprobaciones,
conocimiento, automatización, integraciones y analítica, manteniendo GLPI
actualizable y los diferenciadores en plugins/servicios propios.

## 2. Matriz reutilizar/configurar/extender/desarrollar
Ver `glpi11-capability-matrix.md` (versión actualizada con GLPI 11) y `module-map.md`.

## 3. Localización Paraguay
`America/Asuncion`, PYG (miles sin decimales por defecto), español por defecto con
i18n para inglés, fecha `dd/mm/aaaa`, 24 h, nada de textos hardcodeados
(`../adr/ADR-0005-localization-paraguay.md`).

## 4. Arquitectura lógica (5 capas)
Experiencia · GLPI (core inmutable) · Plugins propios · Servicios · Datos/
observabilidad. Detalle en `overview.md`.

## 5. Módulo de Compras
Formulario configurable (intake con **Forms nativo**). Estados iniciales:
`Borrador → Enviada → Pendiente Jefe de Área → Aprobada por Jefe → En Compras →
Pendiente Gerencia Financiera → Aprobada/Rechazada → En compra → Recibida →
Entregada → Cerrada`. Motor configurable (agregar/quitar pasos sin reescribir).
Cada decisión registra usuario, rol, fecha/hora, comentario, estado anterior/nuevo,
canal y evidencia. Cotizaciones versionadas. Recepción de bien inventariable →
crear/vincular activo GLPI + QR. Detalle: `../functional/purchasing.md`,
`../workflows/purchasing-workflow.md`, `../adr/ADR-0006-purchasing-workflow.md`.

## 6. Firma electrónica y firma digital
Nivel A (aprobación electrónica interna: identidad + acción + auditoría + hash +
timestamp + evidencia) y Nivel B (firma digital certificada por integración).
PDF final con ID, versión, aprobadores, fecha/hora, resultado, hash/verificador,
historial y QR/URL de verificación. Validación jurídica requerida.
(`../adr/ADR-0007-electronic-signature.md`, `../functional/signature.md`).

## 7. Portal tipo Jira Service Management
Catálogo de servicios por tarjetas, formularios condicionales (**Forms nativo**),
seguimiento simple para el usuario y colas para técnicos, tickets ligados a
activos/usuarios/ubicaciones/KB, experiencia responsive. Extiende el
**Self-Service Portal** nativo (`../functional/service-portal.md`).

## 8. Asistente IA y conocimiento
RAG con ACL sobre fuentes autorizadas, cita de fuente y nivel de confianza,
escalado con creación de ticket, curación de conocimiento, sin entrenamiento
automático (`../adr/ADR-0008-ai-architecture.md`).

## 9. WhatsApp y omnicanal
Canal, no repositorio. Adaptador con proveedor intercambiable, identificación
segura, escalado con contexto (`../adr/ADR-0009-whatsapp-omnichannel.md`).

## 10. API e integraciones
API REST v2 nativa + APIs propias `/api/v1`, OAuth2/mínimo privilegio, **webhooks
nativos**, idempotencia, `correlation_id`, OpenAPI
(`../adr/ADR-0004-api-first-integrations.md`, `../api/`).

## 11. Auditoría y seguridad
RBAC por perfil/entidad/departamento/rol, mínimo privilegio, auditoría
append-oriented, validación de archivos/MIME, protección CSRF/XSS/SQLi, secretos
fuera del repo, backups probados, sólo releases estables/seguridad
(`../security/`).

## 12. Métricas obligatorias
Soporte, Compras, Activos, Conocimiento, IA, Integraciones (`../functional/metrics.md`).

## 13. Reglas para Claude (obligatorias)
Resumidas en `../../CLAUDE.md` y en `native-first-process.md`. En síntesis:
inspeccionar la versión objetivo antes de programar; prohibido editar core; cada
módulo aislado con README/CHANGELOG/migraciones/tests/versión; no SQL directo al
core; nada hardcodeado; endpoints con authn/authz/validación/logging; pruebas y
prueba de actualización; documentar en ADR.

## 14. Estructura de repositorios
Ver árbol real en `../../README.md` y `phase-0-summary.md`.

## 15. Estrategia de actualización de GLPI
`../operations/glpi-upgrade-test.md` (staging idéntico, backup, migraciones,
compatibilidad de plugins, smoke tests, rollback). Producción sólo sobre estable.

## 16. Roadmap
Fase 0 Fundaciones → Fase 1 ITSM/Activos → Fase 2 Compras → Fase 3 Portal/
Dashboards → Fase 4 Integraciones → Fase 5 IA/Conocimiento → Fase 6 Madurez.

## 17. Criterios de aceptación globales
Upgrade no sobrescribe personalizaciones; toda acción sensible es trazable; el
usuario final opera sin conocer GLPI; es/Paraguay por defecto e inglés habilitable;
sin dependencia obligatoria de Microsoft 365; compras sin papel (sujeto a validación
jurídica); todos los módulos exponen métricas; integraciones fallidas visibles y
reintentables; la IA no salta permisos ni inventa procedimientos.

## 18. Referencias técnicas oficiales
- Repositorio GLPI — https://github.com/glpi-project/glpi
- Releases — https://github.com/glpi-project/glpi/releases
- Documentación de plugins/hooks — https://glpi-developer-documentation.readthedocs.io/
- API REST v2 — https://help.glpi-project.org/documentation/modules/configuration/general/api/restful-api-v2

## 19. Hallazgos GLPI 11 incorporados (nuevo)
GLPI 11 volvió **nativas** funciones antes provistas por plugins. Se adoptan por el
principio "primero nativo" (detalle y fuentes en `glpi11-findings.md`):
- **Forms nativo** (reemplaza Formcreator, EOL) → catálogo de servicios y formularios
  condicionales del portal y del intake de Compras.
- **Asset Definitions / activos personalizados** (reemplaza Generic Object + Fields)
  → tipos de activo propios sin plugin.
- **Self-Service Portal** renovado → `companyportal` lo extiende, no lo recrea.
- **Webhooks nativos** → base de `companyintegrations`/`integration-hub`.
- **2FA nativo** → identidad/seguridad por configuración.
- **API REST v2** → preferida sobre la v1 (`apirest.php`).

Efecto: varios módulos "propios" del plan original se reclasifican a
**Configurar/Extender**, reduciendo código propio y superficie de mantenimiento.
