# Motor de workflow reutilizable (`companyworkflow`)

## Objetivo
Proveer una **máquina de estados configurable** que otros módulos (empezando por
Compras) puedan usar sin reimplementar transiciones, aprobaciones ni auditoría.

## Conceptos
- **Estados** y **transiciones** definidos por configuración (no en código).
- **Condiciones** por transición (rol, monto, entidad, etc.).
- **Aprobadores por rol** (nunca personas hardcodeadas), con **delegación** y
  **escalamiento**.
- **Evento de transición** que registra: actor, rol, fecha/hora, comentario,
  estado anterior/nuevo, canal y evidencia (auditoría append-oriented).

## Requisitos técnicos
- Tablas propias `glpi_plugin_companyworkflow_*`, migraciones reversibles.
- API/eventos para que otros plugins disparen transiciones.
- i18n de nombres de estado/transición; sin textos hardcodeados.
- Métricas por etapa (tiempo en estado, rechazos, reasignaciones).

## Native-first
- GLPI ofrece validaciones/aprobaciones nativas para **casos simples**; para un
  circuito configurable multi-etapa con delegación/escalamiento se **construye**.
- Antes de implementar, revisar Marketplace por un plugin de workflow aceptable y
  registrar la decisión en el ADR del módulo.

## Reglas
- Nunca modificar el core; sólo hooks/API oficiales.
- Configurable desde administración cuando sea razonable.
