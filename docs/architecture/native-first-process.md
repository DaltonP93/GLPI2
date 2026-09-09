# Proceso "Native-First" (obligatorio antes de cada módulo)

Antes de escribir código de cualquier módulo funcional se ejecuta este análisis
y se registra el resultado en un **ADR** (`../adr/`). Objetivo: **no recrear** lo
que GLPI 11 ya ofrece.

## Orden de decisión
```
1. Configure   ¿GLPI 11 ya lo hace de forma nativa? → sólo configurar.
2. Existing    ¿Existe un plugin oficial/comunitario evaluado y compatible?
3. Extend      ¿Se cubre extendiendo con un plugin propio vía hooks/API?
4. Integrate   ¿Lo resuelve mejor un servicio externo por API/webhook?
5. Build       Construir propio, desacoplado, sólo si 1–4 no alcanzan.
   (Core: sólo con ADR de excepción aprobado por un humano.)
```

## Checklist por requisito
1. **Buscar capacidad nativa** en GLPI 11 (Help Center, changelog de la versión).
2. **Revisar hooks/API** de la versión objetivo (documentación de desarrollador,
   API REST v2, webhooks nativos).
3. **Evaluar plugins existentes** (Marketplace) y su compatibilidad de versión.
4. **Escribir mini-ADR**: Configure / Existing / Extend / Integrate / Build.
5. **Diseñar permisos (ACL), auditoría y métricas** del requisito.
6. **Definir i18n** (ES/EN) y parámetros configurables (nada hardcodeado).
7. **Recién entonces programar**, cumpliendo el *Definition of Done*.

## Salida esperada
- Una fila (o varias) en `glpi11-capability-matrix.md`.
- Un ADR con la decisión y las referencias oficiales consultadas.
- Confirmación explícita de cumplimiento de la Regla 0.

## Señal de alto
Si el análisis concluye que "haría falta tocar el core": **DETENER**, documentar
el bloqueo (archivos afectados, motivo, alternativas, riesgo de upgrade) y proponer
una alternativa por plugin/hook/API. Sólo continuar con aprobación humana y ADR de
excepción.
