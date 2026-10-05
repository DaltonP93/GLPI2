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

> **Implementación v1 (baseline de Fase 2):** estados reales del motor (ADR-0018, ADR-0019 y ADR-0023).
>
> | Funcional | Motor (`companyworkflow`) | Notas |
> |---|---|---|
> | BORRADOR | `DRAFT` | |
> | ENVIADA | — | No es un estado del motor. Es la proyección local `domain_state = PENDING` ("Enviada"), transitoria hasta que el motor crea la instancia. Una instancia que no se crea la reporta el reconcile (`sin_instancia`) |
> | PENDIENTE_JEFE_AREA | `PENDING_AREA_HEAD` | |
> | EN_COMPRAS | `PURCHASING` | |
> | PENDIENTE_GERENCIA_FINANCIERA | `PENDING_FINANCE` | |
> | APROBADA / RECHAZADA / DEVUELTA | `APPROVED` / `REJECTED` / `RETURNED` | |
> | EN_COMPRA | `IN_PURCHASE` | |
> | — | `PARTIALLY_RECEIVED` | agregado en P2D-3 |
> | RECIBIDA / ENTREGADA / CERRADA | `RECEIVED` / `DELIVERED` / `CLOSED` | La entrega parcial vive en las unidades, no en el motor |
> | CANCELADA | `CANCELLED` | La transición `cancel` existe en la definición; la UI v1 no la expone |

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
