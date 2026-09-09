# ADR-0009: WhatsApp y omnicanalidad

- **Estado:** Aceptado
- **Fecha:** 2026-09-09
- **Decisores:** Producto, plataforma, seguridad
- **Módulo/área:** `services/whatsapp-adapter`

## Contexto
WhatsApp debe ser un **canal**, no un repositorio paralelo. Todo caso debe
converger en el mismo modelo de usuario, conversación, ticket/solicitud y
auditoría de la plataforma.

## Decisión
Construir `whatsapp-adapter` como **servicio con proveedor intercambiable**:

- Diseñar un **adaptador** que permita sustituir proveedor/API sin cambiar la
  lógica de negocio.
- **Identificar al usuario de forma segura** antes de revelar datos internos.
- Permitir consulta de estado, creación de ticket, respuestas y notificaciones.
- **Escalar del bot al técnico conservando contexto.**
- Aplicar límites, plantillas, consentimiento y retención según políticas del canal.
- Converger todo en GLPI/IA vía **API/webhooks** (nunca acceso directo a BD).

## Alternativas consideradas
- **Integración directa acoplada a un proveedor** — descartado: dependencia y
  riesgo ante cambios de API.
- **WhatsApp como almacén de conversaciones** — descartado: rompe la fuente única
  de verdad y la auditoría.
- **Adaptador desacoplado con proveedor intercambiable (elegida)**.

## Consecuencias
- (+) Canal sustituible sin reescribir negocio.
- (+) Trazabilidad y auditoría homogéneas.
- (−) Complejidad de identidad/consentimiento y gestión de plantillas.

## Cumplimiento de la Regla 0
Servicio externo vía API/webhooks. Sin edición de core.
