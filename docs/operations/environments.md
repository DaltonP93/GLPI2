# Entornos: DEV → STAGING → PRODUCCIÓN

## Principios
- **Staging idéntico a producción** (misma versión de GLPI, misma configuración,
  mismos plugins).
- Producción **sólo** sobre release **estable** de GLPI (11.0.8). **Nunca una RC**.
- Secretos por entorno (secret manager), nunca en Git.

## Entornos
| Entorno | Propósito | GLPI | Datos | Correo |
|---------|-----------|------|-------|--------|
| **DEV** | Desarrollo local | 11.0.8 (Docker) | Sintéticos | MailHog |
| **STAGING** | Validación previa a prod | = producción | Copia anonimizada | SMTP de prueba |
| **PRODUCCIÓN** | Uso real | Estable soportada | Reales | SMTP real |

## Flujo de cambios
```
desarrollo → tests → staging → backup → migración → smoke tests → producción → (rollback si falla)
```

## Promoción
- Un cambio pasa a staging sólo con **tests verdes**.
- Pasa a producción sólo con **smoke tests** OK en staging y **plan de rollback**
  probado (`backup-restore.md`).

## Reglas por entorno
- **Ningún** despliegue directo a producción sin staging.
- Cada plugin declara su **rango de versiones** de GLPI y se valida en staging
  antes de una actualización mayor.
