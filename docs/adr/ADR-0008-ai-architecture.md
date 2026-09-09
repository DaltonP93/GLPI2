# ADR-0008: Arquitectura del asistente de IA (RAG con control de acceso)

- **Estado:** Aceptado
- **Fecha:** 2026-09-09
- **Decisores:** Producto, seguridad, plataforma
- **Módulo/área:** `services/ai-assistant`

## Contexto
Se quiere un asistente que resuelva consultas usando conocimiento interno, sin
filtrar información que el usuario no podría ver y sin inventar procedimientos.

## Decisión
Construir `ai-assistant` como **servicio desacoplado** con patrón **RAG**:

- **Fuentes autorizadas:** artículos aprobados, procedimientos, manuales, FAQs,
  tickets resueltos seleccionados y metadatos autorizados de activos.
- **ACL antes de recuperar:** el usuario nunca obtiene contenido que no podría
  consultar por medios normales (se aplican permisos de GLPI vía API).
- **Citar la fuente interna** y el nivel de confianza/evidencia en cada respuesta.
- **Escalar a persona / crear ticket** cuando no hay evidencia suficiente, con
  resumen, conversación, diagnóstico, activo, categoría y prioridad sugerida.
- **No entrenar automáticamente** con cualquier conversación; separar conocimiento
  aprobado de datos operativos. Las soluciones nuevas pasan por curación/aprobación.
- **Registrar métricas y acciones** de IA (consultas, resolución sin ticket,
  escalamiento, aceptación, costo/latencia, fuentes usadas, errores).

El servicio consume la **API REST v2** de GLPI; **no** accede a la BD directamente.
El proveedor de modelo (LLM) se define en un ADR posterior de implementación.

## Alternativas consideradas
- **IA con acceso total al dato sin ACL** — descartado: fuga de información.
- **Fine-tuning con todos los tickets** — descartado: mezcla datos no curados y
  arriesga privacidad.
- **RAG con ACL sobre fuentes curadas (elegida)**.

## Consecuencias
- (+) Respuestas trazables, seguras y auditables.
- (+) Conocimiento gobernado por curación.
- (−) Requiere pipeline de curación y control de costos/latencia.

## Cumplimiento de la Regla 0
Servicio externo vía API. Sin edición de core.
