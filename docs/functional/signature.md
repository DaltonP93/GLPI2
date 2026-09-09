# Funcional — Firma/Aprobación electrónica (`companysignature`)

## Dos niveles (no confundir)
- **Nivel A — Aprobación/firma electrónica interna:** identidad autenticada +
  acción explícita + auditoría + **hash** del documento/solicitud + **sello de
  tiempo** + evidencia. Para el circuito operativo interno.
- **Nivel B — Firma digital certificada:** integración con proveedor/certificado
  conforme a requisitos jurídicos, cuando se exija mayor fuerza probatoria.

> Una **imagen** de firma **no** equivale a una firma digital.

## PDF de solicitud aprobada
Debe incluir: ID, versión, aprobadores, fecha/hora, resultado, **hash/verificador**,
historial resumido y un **QR/URL de verificación interna**.

## Reglas
- La política legal final se valida con el área **jurídica** de la organización.
- Evidencia **append-oriented** (no borrable silenciosamente).
- Invocado por `companypurchasing` al aprobar una solicitud.

## Estrategia native-first
- **Construir** (nivel A) sobre historial/validación nativos; **Integrar** (nivel B).
- ADR: `../adr/ADR-0007-electronic-signature.md`.
