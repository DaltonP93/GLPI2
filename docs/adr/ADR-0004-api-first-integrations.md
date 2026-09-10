# ADR-0004: Integraciones API-first

- **Estado:** Aceptado
- **Fecha:** 2026-09-09
- **Decisores:** Plataforma, integraciones
- **Módulo/área:** `services/*`, `plugins/companyintegrations`

## Contexto
El sistema debe integrarse con canales y sistemas externos (WhatsApp, correo,
otros) sin volverlos fuentes paralelas de verdad. GLPI 11 incorpora **API REST v2**
y **webhooks nativos**, lo que reduce el alcance de desarrollo propio.

## Decisión
Toda integración es **API-first**:

- Usar la **API REST v2 de GLPI** para capacidades soportadas; APIs propias
  versionadas bajo **`/api/v1`** para lo que sea nuestro.
- **OAuth2 / tokens con mínimo privilegio**; sin credenciales compartidas entre
  integraciones.
- **Webhooks** para eventos relevantes (ticket creado/aprobado, activo asignado,
  compra aprobada, recepción…), preferentemente los **webhooks nativos** de GLPI 11.
- **Idempotencia** en escrituras externas y **`correlation_id`** para trazabilidad
  distribuida.
- **Contratos OpenAPI** documentados para cada servicio propio.
- Los servicios **no** acceden a la base de datos de GLPI directamente.

## Alternativas consideradas
- **Acceso directo a la BD de GLPI** — descartado: rompe reglas de negocio,
  permisos y compatibilidad de esquema entre versiones.
- **Integraciones ad-hoc por servicio** — descartado: sin idempotencia ni
  trazabilidad homogénea.
- **API-first con hub central (elegida)**: `integration-hub` normaliza y observa.

## Consecuencias
- (+) Integraciones observables, reintentables y seguras.
- (+) Menor acoplamiento al esquema interno de GLPI.
- (−) Overhead inicial de contratos y autenticación.

## Cumplimiento de la Regla 0
Interfaces soportadas (API/webhooks). Sin edición de core.
