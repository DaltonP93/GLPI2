# Manejo de secretos

## Reglas
- **Nada de credenciales en Git.** `.env` y variantes están en `.gitignore`;
  sólo se versiona `.env.example` con **placeholders** (`__CHANGE_ME__`).
- Configuración por **variables de entorno** / **secret manager** en cada entorno.
- Tokens de **mínimo privilegio** por integración; sin credenciales compartidas.
- **Rotación** de secretos y revocación ante incidente.
- Los secretos **no** aparecen en logs, métricas, mensajes de error ni PDFs.

## Dónde viven los secretos
| Entorno | Fuente de secretos |
|---------|--------------------|
| Desarrollo | `.env` local (ignorado por Git) |
| Staging/Producción | Secret manager / variables de entorno del orquestador |

## Verificación
- Revisar que ningún commit incluya `.env` o claves (`git status`, escaneo de
  secretos en CI).
- El archivo `.env.example` nunca contiene valores reales.

## Referencia
`CLAUDE.md` (Seguridad, Prohibiciones) y `../api/api-standards.md`.
