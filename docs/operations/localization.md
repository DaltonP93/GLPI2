# Localización Paraguay — configuración y verificación

Objetivo: una instalación **reproducible** donde GLPI queda con **Español** por
defecto, **America/Asuncion** como zona horaria y **PYG/Guaraní** como moneda de
presentación. Se usan **mecanismos oficiales** de GLPI 11 (consola/configuración
soportada); **nunca SQL directo** al core.

> Comandos verificados contra **GLPI 11.0.8** (en v11 se quitó el prefijo `glpi:`):
> `database:install`, `database:enable_timezones` (alias `db:*`).

## Qué es global y qué es preferencia por usuario

| Ajuste | Alcance | Mecanismo | Reproducible |
|--------|---------|-----------|:------------:|
| **Idioma por defecto = es_ES** | Global (instancia) | `database:install --default-language=es_ES` (`-L`) | ✅ CLI |
| Idioma de cada persona | Preferencia por usuario (hereda el global) | Preferencias del usuario / admin | por usuario |
| **Soporte de timezones** | Global (capacidad de BD) | `database:enable_timezones` + tablas tz en MariaDB | ✅ CLI |
| Zona horaria del **servidor** (PHP) | Global (contenedor) | `date.timezone=America/Asuncion` en `php/glpi.ini` | ✅ imagen |
| **Zona horaria por defecto de la instancia** | Global | **Admin**: Configuración → General → Valores por defecto | ⚠️ admin |
| Zona horaria de cada persona | Preferencia por usuario | Preferencias del usuario | por usuario |
| **Formato numérico (PYG: 0 decimales)** | Global + preferencia | **Admin**: Configuración → General (y preferencia por usuario) | ⚠️ admin |
| **Moneda PYG (símbolo/formato de negocio)** | Nuestros plugins | GLPI core no tiene "moneda"; lo aplican nuestros plugins vía i18n/config | plugin |

**Nota importante sobre moneda:** GLPI core **no** tiene un concepto de moneda de
primera clase (los montos son decimales). El formato **PYG** (guaraní, 0 decimales,
separador de miles) se aplica en **nuestros plugins** (p. ej. `companypurchasing`)
mediante configuración/i18n, sin valores hardcodeados. A nivel GLPI sólo se ajusta
el **formato numérico** para que coincida con la convención local.

## Instalación reproducible (parte CLI)
```bash
# Con el stack levantado (infra/docker):
bash infra/docker/glpi-config/install-and-localize.sh
```
Este script es la fase de **configuración** (mutación). Hace, con comandos
oficiales y **fail-closed** en cada prerequisito (ver ADR-0016):
0. Espera a que MariaDB acepte conexiones.
1. `database:install --default-language=es_ES` → idioma por defecto Español.
2. Carga de tablas de husos horarios en MariaDB (`mariadb-tzinfo-to-sql`) **sin
   enmascarar errores**, y **verifica** que `mysql.time_zone_name` quedó poblada.
3. `GRANT SELECT ON mysql.time_zone_name` al usuario de GLPI.
4. `database:enable_timezones` → habilita timezones (paso de configuración, **una
   sola vez**).
5. **Verifica** que el usuario de GLPI resuelve una zona nombrada
   (`CONVERT_TZ(...,'America/Asuncion')`).

> **Importante:** `database:enable_timezones` es un comando de **configuración**,
> no una sonda de estado. Se ejecuta aquí una vez; **nunca** debe reejecutarse
> como "test" (produce fallos intermitentes "faltan requisitos"). Ver ADR-0016.

## Pasos administrativos (parte no cubierta por consola en GLPI 11)
En **Configuración → General** (requiere sesión de administrador):
1. **Zona horaria por defecto:** `America/Asuncion`.
2. **Formato de números:** el que corresponda a PYG (0 decimales por defecto,
   separador de miles). Ajustar también, si se desea, como preferencia por usuario.
3. **Idioma:** confirmar **Español** como predeterminado (ya fijado por la CLI).

> En GLPI 11 no existe un comando de consola para fijar estos valores; la vía
> soportada es la interfaz de administración (o, a futuro, un comando provisto por
> un plugin de infraestructura propio). Por eso se documentan aquí y se verifican
> automáticamente abajo.

## Verificación automática (fail-closed, sólo lectura)
```bash
bash tests/localization/verify-localization.sh
```
Comprueba, **sin reconfigurar** (comprobaciones de sólo lectura e idempotentes):
1. La interfaz anónima se sirve en español.
2. La zona horaria del servidor PHP es `America/Asuncion`.
3. `mysql.time_zone_name` está poblada y es **accesible por el usuario de GLPI**.
4. El usuario de GLPI resuelve la zona nombrada `America/Asuncion` (`CONVERT_TZ`).

Sale con error si algo no cumple. Se ejecuta en CI (job *integration*), y además
**se repite N veces** sobre la misma instalación como **regresión de
idempotencia** (garantiza que la verificación es estable y no reintroduce el
anti-patrón de reejecutar `database:enable_timezones`; ver ADR-0016).

## Referencias
- ADR: `../adr/ADR-0005-localization-paraguay.md`
- ADR: `../adr/ADR-0016-timezone-init-verification.md` (configuración vs. verificación de timezones)
- Comandos GLPI 11 verificados: `database:install`, `database:enable_timezones`.
