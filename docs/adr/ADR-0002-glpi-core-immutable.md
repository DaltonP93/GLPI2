# ADR-0002: El core de GLPI es inmutable (Regla 0)

- **Estado:** Aceptado
- **Fecha:** 2026-09-09
- **Decisores:** Propietario del producto, seguridad, plataforma
- **Módulo/área:** Transversal (regla de gobierno)

## Contexto
La prioridad #1 del producto es **actualizable > modular > auditable > seguro >
configurable > rápido**. Modificar el core de GLPI (`src/`, `front/`, `ajax/`,
`templates/`, `vendor/`, migraciones core) rompe la capacidad de aplicar
releases estables y de seguridad, y elimina las garantías del fabricante.

## Decisión
**Prohibido modificar archivos del core de GLPI.** Toda necesidad se resuelve en
este orden estricto:

1. Configuración nativa.
2. Plugin oficial/comunitario evaluado.
3. Plugin propio usando hooks/API oficiales.
4. Servicio externo integrado por API/webhook.
5. Core **sólo** mediante **excepción formal**: un ADR de excepción con
   diagnóstico, archivos afectados, motivo, alternativas descartadas y riesgo de
   upgrade, **aprobado explícitamente por un humano** antes de tocar nada.

El core **no se versiona** en este repositorio: se descarga como release oficial.
Una verificación automatizada (`tests/upgrade/verify-core-untouched.sh`) falla si
aparece código de core versionado.

## Alternativas consideradas
- **Parches mínimos "temporales" al core** — descartado: los parches temporales
  se vuelven permanentes y bloquean upgrades.
- **Monkey-patching en runtime** — descartado: frágil y difícil de auditar.
- **Extensión sólo por mecanismos oficiales (elegida)**.

## Consecuencias
- (+) Upgrades de GLPI de bajo riesgo.
- (+) Superficie de auditoría acotada a nuestros módulos.
- (−) Algunas necesidades exigen más trabajo (plugin/servicio) que un parche
  directo; es un costo aceptado.

## Cumplimiento de la Regla 0
Este ADR **es** la Regla 0. Si se cree necesario editar el core: **DETENER**,
documentar el bloqueo y proponer alternativa vía plugin/hook/API. Sólo continuar
con aprobación humana y ADR de excepción.
