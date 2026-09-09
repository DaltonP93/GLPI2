# Workflow de Compras (configurable)

Flujo inicial (parametrizable; se pueden agregar/quitar pasos sin reescribir):

```
Borrador
  → Enviada
    → Pendiente Jefe de Área
      → Aprobada por Jefe
        → En Compras
          → Pendiente Gerencia Financiera
            → Aprobada  ──┐
            → Rechazada ──┘ (fin con motivo)
        → En compra
          → Recibida        (posible alta/vínculo de activo + QR)
            → Entregada
              → Cerrada
```

## En cada transición se registra
actor · rol · fecha/hora · comentario · estado anterior/nuevo · origen/canal ·
evidencia técnica disponible. Auditoría **append-oriented**.

## Puntos de integración
- **Aprobaciones** vía `companyworkflow` (aprobadores por rol, delegación,
  escalamiento).
- **Firma/evidencia** vía `companysignature` (PDF con hash/QR de verificación).
- **Recepción de bien inventariable** → crear/vincular **activo GLPI** nativo y
  generar **QR** (`companyqr`).

## Reglas
- Aprobadores, SLA y umbrales por **configuración**, nunca hardcodeados.
- Importes en **PYG**; fechas en `America/Asuncion`.
- Métricas: tiempo por etapa, tiempo total, rechazos, devoluciones, gasto por
  área/categoría/proveedor (`../functional/metrics.md`).
