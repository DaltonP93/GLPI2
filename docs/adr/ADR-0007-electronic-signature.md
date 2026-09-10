# ADR-0007: Firma electrónica interna vs. firma digital certificada

- **Estado:** Aceptado
- **Fecha:** 2026-09-09
- **Decisores:** Producto, Legal, plataforma
- **Módulo/área:** `plugins/companysignature`

## Contexto
Compras y aprobaciones requieren evidencia de decisión. Es imprescindible **no
confundir** una imagen de firma con una firma digital certificada.

## Decisión
Distinguir **dos niveles** y construir `companysignature` para el nivel A, con
punto de integración para el nivel B:

- **Nivel A — Aprobación/firma electrónica interna:** identidad autenticada +
  acción explícita + auditoría + **hash** del documento/solicitud + **sello de
  tiempo** + evidencia técnica. Suficiente para el circuito operativo interno.
- **Nivel B — Firma digital con certificado:** integración con un proveedor/
  certificado conforme a los requisitos jurídicos aplicables, **cuando el negocio
  o lo legal lo exijan**. Se resuelve como **integración externa**, no dentro del core.

El PDF final de una solicitud aprobada incluye: ID, versión, aprobadores,
fecha/hora, resultado, **hash/verificador**, historial resumido y **QR/URL de
verificación interna**. La validez legal se valida con el área jurídica.

## Alternativas consideradas
- **Imagen de firma como "firma"** — descartado: sin valor probatorio.
- **Sólo firma certificada desde el inicio** — descartado: costo/complejidad
  innecesarios para el circuito interno; se habilita cuando se requiera.
- **Dos niveles con integración opcional (elegida)**.

## Consecuencias
- (+) Evidencia sólida y auditable para el flujo interno desde el día uno.
- (+) Camino claro hacia firma certificada sin rehacer el módulo.
- (−) Requiere validación jurídica antes de reemplazar procesos en papel con
  efectos legales externos.

## Cumplimiento de la Regla 0
Plugin propio + integración externa. Sin edición de core.
