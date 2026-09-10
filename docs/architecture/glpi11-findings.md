# Hallazgos GLPI 11 incorporados a la arquitectura

GLPI 11 absorbió como **nativas** varias funciones que en GLPI 10 exigían
plugins. Esto **reduce el código propio** y es de aplicación obligatoria por el
principio "primero nativo". Verificado contra fuentes oficiales (ver `../../README.md`
y el pie de este documento).

| Hallazgo GLPI 11 | Antes (GLPI 10) | Impacto en nuestros módulos |
|------------------|-----------------|-----------------------------|
| **Forms nativo** (formularios con lógica condicional, catálogo de servicios) | plugin *Formcreator* (ahora EOL) | Portal tipo Jira (`companyportal`) e intake de Compras (`companypurchasing`) usan Forms nativo; no recrear un motor de formularios. |
| **Asset Definitions / activos personalizados** | plugins *Generic Object* + *Fields* | Tipos de activo propios se definen nativamente; `companyqr` y el alta en recepción de Compras se apoyan en activos nativos. |
| **Self-Service Portal** renovado | portal limitado | `companyportal` **extiende** el portal nativo; no se construye un portal desde cero. |
| **Webhooks nativos** | desarrollo propio | `companyintegrations` / `integration-hub` consumen webhooks nativos; sólo se agrega mapeo, idempotencia, `correlation_id` y reintentos. |
| **2FA / MFA nativo** | plugin/externo | Identidad y seguridad se **configuran**; no se desarrolla. |
| **API REST v2** | API v1 (`apirest.php`) | Preferir v2 para capacidades soportadas; APIs propias sólo para lo no cubierto. |

## Consecuencias de diseño
- Varios módulos que el documento maestro marcaba como "propio" pasan a
  **Configure/Extend** apoyándose en lo nativo (ver `glpi11-capability-matrix.md`).
- Se evita duplicar Formcreator/Generic Object/Fields: **quedan cubiertos por el core**.
- Si en el futuro hay que migrar formularios antiguos de Formcreator, existe la
  herramienta oficial *Formcreator Migration Tool* (sólo GLPI 11.0+); Formcreator
  queda **EOL**.

## Versión y política
- **Producción:** GLPI 11.0.8 (estable). **12.0.0-rc1 NO** en producción.
- Cada plugin declara su **rango de versiones** y se prueba antes de actualizar
  (ver `../operations/glpi-upgrade-test.md`).

## Fuentes oficiales
- GLPI Releases — https://github.com/glpi-project/glpi/releases
- GLPI 11 novedades — https://www.glpi-project.org/en/glpi-11-is-out/
- Forms (Help Center) — https://help.glpi-project.org/documentation/modules/administration/forms
- Asset Definitions (Help Center) — https://help.glpi-project.org/documentation/modules/configuration/asset-definitions
- API REST v2 — https://help.glpi-project.org/documentation/modules/configuration/general/api/restful-api-v2
