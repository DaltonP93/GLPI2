<?php

/**
 * Ciclo de vida del código/token: crear, rotar, revocar, suspender y sincronizar
 * con el ciclo de vida del activo.
 *
 * Estrategia de `public_code` (ver ADR-0011, ajuste obligatorio 4):
 *   - usa `otherserial` del activo SI es válido y libre;
 *   - si no, GENERA un `public_code` propio y lo guarda SÓLO en la tabla del plugin;
 *   - NUNCA escribe en `otherserial` (no toca datos maestros del inventario).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

use CommonDBTM;
use Session;
use GlpiPlugin\Companyqr\Model\Code;

final class CodeManager
{
    public function __construct(private TokenGenerator $tokens = new TokenGenerator())
    {
    }

    /** Devuelve el código del activo, creándolo si no existe. */
    public function getOrCreateForItem(CommonDBTM $item): Code
    {
        $existing = $this->findForItem($item);
        return $existing ?? $this->createForItem($item);
    }

    public function findForItem(CommonDBTM $item): ?Code
    {
        $code = new Code();
        if ($code->getFromDBByCrit(['itemtype' => $item->getType(), 'items_id' => $item->getID()])) {
            return $code;
        }
        return null;
    }

    public function createForItem(CommonDBTM $item): Code
    {
        $code = new Code();
        $id = (int) $code->add([
            'itemtype'          => $item->getType(),
            'items_id'          => $item->getID(),
            'entities_id'       => (int) ($item->fields['entities_id'] ?? 0),
            'is_recursive'      => (int) ($item->fields['is_recursive'] ?? 0),
            'token'             => $this->uniqueToken(),
            'public_code'       => $this->resolvePublicCode($item),
            'status'            => Code::STATUS_ACTIVE,
            'users_id_creation' => (int) (Session::getLoginUserID() ?: 0),
        ]);
        $code->getFromDB($id);
        return $code;
    }

    /** Rotación: nuevo token; el anterior deja de resolver de inmediato. */
    public function rotate(Code $code): bool
    {
        return (bool) $code->update([
            'id'    => $code->getID(),
            'token' => $this->uniqueToken(),
        ]);
    }

    /** Revocación: el código deja de resolver; se conserva la fila y el historial. */
    public function revoke(Code $code, string $reason = ''): bool
    {
        return (bool) $code->update([
            'id'                => $code->getID(),
            'status'            => Code::STATUS_REVOKED,
            'revocation_reason' => $reason,
        ]);
    }

    public function setStatus(Code $code, string $status): bool
    {
        return (bool) $code->update(['id' => $code->getID(), 'status' => $status]);
    }

    /** Sincroniza el snapshot de entidad cuando el activo cambia/se mueve. */
    public function syncEntity(Code $code, CommonDBTM $item): bool
    {
        return (bool) $code->update([
            'id'          => $code->getID(),
            'entities_id' => (int) ($item->fields['entities_id'] ?? 0),
            'is_recursive'=> (int) ($item->fields['is_recursive'] ?? 0),
        ]);
    }

    // ---------------------------------------------------------------------
    //  public_code
    // ---------------------------------------------------------------------

    /**
     * Decide de qué FUENTE proviene el código visible. Función PURA (testeable sin BD).
     *
     * @return 'otherserial'|'generate'
     */
    public static function decidePublicCodeSource(string $otherserial, bool $otherserialIsFree): string
    {
        $otherserial = trim($otherserial);
        if ($otherserial !== '' && $otherserialIsFree) {
            return 'otherserial';
        }
        return 'generate';
    }

    /** Resuelve el `public_code` a persistir (usa otherserial si es válido y libre; si no, genera). */
    public function resolvePublicCode(CommonDBTM $item): string
    {
        $otherserial = trim((string) ($item->fields['otherserial'] ?? ''));
        $source = self::decidePublicCodeSource($otherserial, $this->publicCodeIsFree($otherserial));

        if ($source === 'otherserial') {
            return $otherserial;
        }
        return $this->generatePublicCode($item);
    }

    /** ¿El `public_code` candidato está libre (unicidad en la tabla del plugin)? */
    public function publicCodeIsFree(string $candidate): bool
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return false;
        }
        return !(new Code())->getFromDBByCrit(['public_code' => $candidate]);
    }

    /** Genera `<PREFIJO_TIPO>-<secuencia>` único; NO se escribe en otherserial. */
    private function generatePublicCode(CommonDBTM $item): string
    {
        $prefix = PluginConfig::prefixMap()[$item->getType()] ?? 'AC';
        $seq    = $this->nextSequence();

        do {
            $candidate = sprintf('%s-%06d', $prefix, $seq);
            $seq++;
        } while (!$this->publicCodeIsFree($candidate) && $seq < 1_000_000);

        return $candidate;
    }

    private function nextSequence(): int
    {
        global $DB;
        $count = 0;
        foreach ($DB->request(['COUNT' => 'cpt', 'FROM' => Code::getTable()]) as $row) {
            $count = (int) $row['cpt'];
        }
        return $count + 1;
    }

    private function uniqueToken(): string
    {
        do {
            $token = $this->tokens->generate();
            $exists = (new Code())->getFromDBByCrit(['token' => $token]);
        } while ($exists);
        return $token;
    }
}
