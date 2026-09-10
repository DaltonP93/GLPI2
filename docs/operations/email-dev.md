# Correo en DEV (MailHog) — configuración y verificación

En DEV el correo saliente se captura con **MailHog** (no se envía a Internet).
La vía de transporte es **reproducible**; habilitar los seguimientos por correo
dentro de GLPI es un paso administrativo documentado aquí, con **prueba**.

## Transporte reproducible (contenedor → MailHog)
La imagen instala `msmtp` y `php/glpi.ini` fija:
```
sendmail_path = "/usr/bin/msmtp -t --read-envelope-from"
```
`entrypoint.sh` genera `/etc/msmtprc` desde las variables `SMTP_HOST`/`SMTP_PORT`
(`mailhog:1025` por defecto; **sin secretos**, MailHog no usa auth/TLS). Así, todo
correo que GLPI envíe por `mail()` (modo por defecto) llega a MailHog, tanto desde
el contenedor `glpi` como desde `cron` (que también usa el entrypoint de la imagen).

- MailHog UI: http://localhost:8025

## Paso administrativo: habilitar seguimientos por correo en GLPI
En GLPI 11 esto se configura en la interfaz (no hay comando de consola):
1. **Configuración → Notificaciones → Notificaciones**: activar
   **"Habilitar el seguimiento por correo electrónico"**.
2. **Configuración → Notificaciones → Configuración de seguimientos por correo**:
   - **Modo de envío:** *PHP* (usa `sendmail_path` → msmtp → MailHog), **o** *SMTP*
     apuntando a `mailhog` puerto `1025` sin autenticación ni TLS.
   - **Correo del remitente / administrador:** p. ej. `no-reply@glpi.local`.
3. Botón **"Enviar un correo de prueba"**: debe aparecer en la UI de MailHog.

> Sólo para DEV. En STAGING/PROD se usa un SMTP real gestionado por secret manager
> (`../security/secrets-management.md`); nunca MailHog.

## Verificación automática (fail-closed)
```bash
bash tests/mail/verify-mail.sh
```
Envía un correo por **la misma vía que usa GLPI** (`msmtp` dentro del contenedor)
y comprueba, vía la **API de MailHog** (`/api/v2/messages`), que el mensaje llegó.
Falla con código != 0 si MailHog no lo recibe. Se ejecuta también en CI.

**Alcance de la prueba:** valida el **transporte** GLPI→MailHog. La activación de
los seguimientos (paso administrativo) se comprueba manualmente con el botón
"Enviar un correo de prueba" de GLPI, que usa exactamente esta misma vía.

## Referencias
- ADR de integraciones/observabilidad: `../adr/ADR-0004-*`, `../adr/ADR-0010-*`
- Infra: `../../infra/docker/README.md`
