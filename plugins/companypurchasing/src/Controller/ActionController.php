<?php

/**
 * ACCIONES (POST) de la UI de Compras (P2D-4; ADR-0023 §8). Toda mutación:
 *   - es POST (ninguna ruta GET muta) y pasa por el CSRF NATIVO de GLPI 11 (`CheckCsrfListener`; no se revalida aquí
 *     para no consumir el token dos veces);
 *   - exige sesión autenticada (`Firewall::STRATEGY_AUTHENTICATED`);
 *   - delega TODA regla (ACL, entidad, estado, idempotencia, concurrencia) al servicio de dominio existente
 *     (`RequestManager`, `ApprovalOrchestrator`, `QuoteManager`, `ReceivingService`, `DeliveryService`);
 *   - responde con PRG: mensaje nativo + redirect a una URL INTERNA construida en el servidor.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Controller;

use Session;
use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Companypurchasing\Model\Quote;
use GlpiPlugin\Companypurchasing\Model\Request as PurchaseRequest;
use GlpiPlugin\Companypurchasing\Service\ApprovalOrchestrator;
use GlpiPlugin\Companypurchasing\Service\ConfigForm;
use GlpiPlugin\Companypurchasing\Service\DeliveryService;
use GlpiPlugin\Companypurchasing\Service\PluginConfig;
use GlpiPlugin\Companypurchasing\Service\ReceivingService;
use GlpiPlugin\Companypurchasing\Service\RequestManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ActionController extends AbstractController
{
    use UiSupport;

    /**
     * Rutas de ESTE controlador (relativas al prefijo del plugin), todas POST-only. Las usa `MethodGuardController`
     * para responder 405 a un GET/HEAD (el router de plugins de GLPI 11.0.8 no traduce `MethodNotAllowedException`
     * y respondería 500). Un test estático exige que cubra TODAS las rutas de abajo y ninguna página GET.
     */
    public const POST_ONLY_PATHS = 'request/create'
        . '|request/\d+/(?:update|line/add|line/\d+/(?:update|remove)|submit|decide|quote/create'
        . '|quote/\d+/(?:update|select|attach)|purchase/start|receive|deliver|close|pdf/retry)'
        . '|config/(?:save|publish)';

    /** Campos de cabecera que la UI envía (el servicio decide cuáles acepta y los valida). */
    private const HEADER_FIELDS = ['groups_id_department', 'category', 'destination', 'reason', 'observations', 'suppliers_id_suggested', 'budgets_id'];

    /** Tamaño máximo de un adjunto de cotización (bytes); además GLPI valida la extensión contra `DocumentType`. */
    private const MAX_UPLOAD = 20 * 1024 * 1024;

    // ---------------------------------------------------------------- borrador

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/create', name: 'cpur_request_create', methods: ['POST'])]
    public function create(Request $http): Response
    {
        return $this->act('/requests', function () use ($http): array {
            $in = $this->header($http);
            $in['entities_id'] = (int) $http->request->get('entities_id', (int) ($_SESSION['glpiactive_entity'] ?? 0));
            $in['currency_code'] = (string) $http->request->get('currency_code', PluginConfig::defaultCurrency());
            $id = (new RequestManager())->createDraft($in);
            return [__('Draft created', 'companypurchasing'), '/request/' . $id . '/edit'];
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/update', name: 'cpur_request_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $http): Response
    {
        return $this->act('/request/' . $id . '/edit', function () use ($id, $http): string {
            (new RequestManager())->updateDraft($id, $this->header($http));
            return __('Request updated', 'companypurchasing');
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/line/add', name: 'cpur_line_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addLine(int $id, Request $http): Response
    {
        return $this->act('/request/' . $id . '/edit', function () use ($id, $http): string {
            (new RequestManager())->addLine($id, $this->line($http));
            return __('Line added', 'companypurchasing');
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/line/{lineId}/update', name: 'cpur_line_update', methods: ['POST'], requirements: ['id' => '\d+', 'lineId' => '\d+'])]
    public function updateLine(int $id, int $lineId, Request $http): Response
    {
        return $this->act('/request/' . $id . '/edit', function () use ($lineId, $http): string {
            (new RequestManager())->updateLine($lineId, $this->line($http));
            return __('Line updated', 'companypurchasing');
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/line/{lineId}/remove', name: 'cpur_line_remove', methods: ['POST'], requirements: ['id' => '\d+', 'lineId' => '\d+'])]
    public function removeLine(int $id, int $lineId): Response
    {
        return $this->act('/request/' . $id . '/edit', function () use ($lineId): string {
            (new RequestManager())->removeLine($lineId);
            return __('Line removed', 'companypurchasing');
        });
    }

    // ---------------------------------------------------------------- workflow (motor)

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/submit', name: 'cpur_request_submit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function submit(int $id, Request $http): Response
    {
        return $this->act('/request/' . $id, function () use ($id, $http): string {
            (new ApprovalOrchestrator())->submit($id, $this->text($http, 'comment'));
            return __('Request submitted', 'companypurchasing');
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/decide', name: 'cpur_request_decide', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decide(int $id, Request $http): Response
    {
        return $this->act('/request/' . $id, function () use ($id, $http): string {
            // `expected_state` = etapa que el usuario VIO al renderizar: si cambió, el orquestador no decide en otra.
            $r = (new ApprovalOrchestrator())->decide($id, (string) $http->request->get('decision', ''),
                (string) $http->request->get('expected_state', ''), $this->text($http, 'comment'));
            return ($r['status'] ?? '') === 'stage_changed'
                ? __('The request changed stage meanwhile; nothing was decided', 'companypurchasing')
                : __('Decision recorded', 'companypurchasing');
        });
    }

    // ---------------------------------------------------------------- cotizaciones / compra

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/quote/create', name: 'cpur_quote_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createQuote(int $id, Request $http): Response
    {
        return $this->act('/request/' . $id, function () use ($id, $http): string {
            (new ApprovalOrchestrator())->createQuote($id, $this->quote($http) + ['suppliers_id' => (int) $http->request->get('suppliers_id', 0)]);
            return __('Quote created', 'companypurchasing');
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/quote/{quoteId}/update', name: 'cpur_quote_update', methods: ['POST'], requirements: ['id' => '\d+', 'quoteId' => '\d+'])]
    public function updateQuote(int $id, int $quoteId, Request $http): Response
    {
        return $this->act('/request/' . $id, function () use ($id, $quoteId, $http): string {
            $this->assertQuoteOf($quoteId, $id);
            (new ApprovalOrchestrator())->updateQuote($quoteId, $this->quote($http));
            return __('Quote updated', 'companypurchasing');
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/quote/{quoteId}/select', name: 'cpur_quote_select', methods: ['POST'], requirements: ['id' => '\d+', 'quoteId' => '\d+'])]
    public function selectQuote(int $id, int $quoteId, Request $http): Response
    {
        return $this->act('/request/' . $id, function () use ($id, $quoteId, $http): string {
            $expected = $http->request->get('lock_version');
            (new ApprovalOrchestrator())->selectQuote($id, $quoteId, $expected === null || $expected === '' ? null : (int) $expected);
            return __('Quote selected', 'companypurchasing');
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/quote/{quoteId}/attach', name: 'cpur_quote_attach', methods: ['POST'], requirements: ['id' => '\d+', 'quoteId' => '\d+'])]
    public function attachQuoteDocument(int $id, int $quoteId, Request $http): Response
    {
        return $this->act('/request/' . $id, function () use ($id, $quoteId, $http): string {
            $this->assertQuoteOf($quoteId, $id);
            if (!Session::haveRight(PurchaseRequest::$rightname, PurchaseRequest::RIGHT_MANAGE_PURCHASING)) {
                throw new \RuntimeException('permiso denegado (MANAGE_PURCHASING)');
            }
            $docId = $this->storeUpload($http->files->get('document'), $id);
            (new ApprovalOrchestrator())->attachQuoteDocument($quoteId, $docId);
            return __('Document attached', 'companypurchasing');
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/purchase/start', name: 'cpur_purchase_start', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function startPurchase(int $id, Request $http): Response
    {
        return $this->act('/request/' . $id, function () use ($id, $http): string {
            (new ReceivingService())->startPurchase($id, $this->text($http, 'comment'));
            return __('Purchase started', 'companypurchasing');
        });
    }

    // ---------------------------------------------------------------- recepción / entrega / cierre

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/receive', name: 'cpur_receive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function receive(int $id, Request $http): Response
    {
        return $this->act('/request/' . $id, function () use ($id, $http): string {
            $lines = [];
            foreach ((array) $http->request->all('qty') as $itemsId => $qty) {
                $qty = trim((string) $qty);
                if ($qty === '' || $qty === '0') {
                    continue;
                }
                $serials = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($http->request->all('serials')[$itemsId] ?? '')) ?: []), static fn (string $s): bool => $s !== ''));
                $lines[] = ['items_id' => (int) $itemsId, 'quantity' => $qty, 'serials' => $serials];
            }
            // UUID de las unidades: los genera el DOMINIO; la UI sólo aporta la clave del submit (replay seguro).
            $r = (new ReceivingService())->receive($id, (string) $http->request->get('idempotency_key', ''), $lines, ['notes' => $this->text($http, 'notes')]);
            return $r['status'] === 'replayed' ? __('This reception was already recorded', 'companypurchasing') : __('Reception recorded', 'companypurchasing');
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/deliver', name: 'cpur_deliver', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deliver(int $id, Request $http): Response
    {
        return $this->act('/request/' . $id, function () use ($id, $http): string {
            $r = (new DeliveryService())->deliver($id, array_values(array_map('strval', (array) $http->request->all('units'))),
                (int) $http->request->get('recipient_users_id', 0), (string) $http->request->get('idempotency_key', ''), $this->text($http, 'notes'));
            return $r['status'] === 'replayed' ? __('This delivery was already recorded', 'companypurchasing') : __('Delivery recorded', 'companypurchasing');
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/close', name: 'cpur_close', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function close(int $id, Request $http): Response
    {
        return $this->act('/request/' . $id, function () use ($id, $http): string {
            $r = (new DeliveryService())->closeRequest($id, $this->text($http, 'comment'));
            return $r['status'] === 'already_closed' ? __('The request was already closed', 'companypurchasing') : __('Request closed', 'companypurchasing');
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/pdf/retry', name: 'cpur_pdf_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retryPdf(int $id): Response
    {
        return $this->act('/request/' . $id, function () use ($id): string {
            (new ApprovalOrchestrator())->retryPdf($id);
            return __('Approved PDF generation retried', 'companypurchasing');
        });
    }

    // ---------------------------------------------------------------- configuración

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/config/save', name: 'cpur_config_save', methods: ['POST'])]
    public function saveConfig(Request $http): Response
    {
        return $this->act('/config', function () use ($http): string {
            if (!Session::haveRight(PurchaseRequest::$rightname, PurchaseRequest::RIGHT_MANAGE_CONFIG)) {
                throw new \RuntimeException('permiso denegado (MANAGE_CONFIG)');
            }
            $values = ConfigForm::validate($http->request->all());
            foreach ($values as $key => $v) {
                if (ConfigForm::KEYS[$key] === 'group' && (int) $v > 0 && !(new \Group())->getFromDB((int) $v)) {
                    throw new \InvalidArgumentException("{$key}: grupo inexistente");
                }
            }
            \Config::setConfigurationValues(PluginConfig::CONTEXT, $values);
            return __('Configuration saved', 'companypurchasing');
        });
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/config/publish', name: 'cpur_config_publish', methods: ['POST'])]
    public function publish(): Response
    {
        return $this->act('/config', function (): string {
            (new ApprovalOrchestrator())->publishDefinition(); // MANAGE_CONFIG + fail-closed en el orquestador
            return __('A new workflow definition version was published', 'companypurchasing');
        });
    }

    // ---------------------------------------------------------------- traducción HTTP → entrada de servicio

    /** @return array<string,mixed> */
    private function header(Request $http): array
    {
        $in = [];
        foreach (self::HEADER_FIELDS as $k) {
            if ($http->request->has($k)) {
                $in[$k] = $http->request->get($k);
            }
        }
        return $in;
    }

    /** @return array<string,mixed> */
    private function line(Request $http): array
    {
        $in = [];
        foreach (['description', 'category', 'quantity', 'unit', 'estimated_unit_price', 'notes'] as $k) {
            if ($http->request->has($k)) {
                $in[$k] = (string) $http->request->get($k);
            }
        }
        $in['is_inventoriable'] = $http->request->get('is_inventoriable') ? 1 : 0;
        return $in;
    }

    /** @return array<string,mixed> */
    private function quote(Request $http): array
    {
        $in = [];
        foreach (['reference', 'discounts', 'taxes', 'freight', 'valid_until', 'notes'] as $k) {
            if ($http->request->has($k) && (string) $http->request->get($k) !== '') {
                $in[$k] = (string) $http->request->get($k);
            }
        }
        $lines = [];
        foreach ((array) $http->request->all('price') as $itemsId => $price) {
            if (trim((string) $price) !== '') {
                $lines[] = ['items_id' => (int) $itemsId, 'final_unit_price' => trim((string) $price)];
            }
        }
        $in['lines'] = $lines;
        return $in;
    }

    private function text(Request $http, string $key): string
    {
        return mb_substr(trim((string) $http->request->get($key, '')), 0, 4000);
    }

    private function assertQuoteOf(int $quoteId, int $requestId): void
    {
        $q = new Quote();
        if (!$q->getFromDB($quoteId) || (int) $q->fields['requests_id'] !== $requestId) {
            throw new \RuntimeException('la cotización no pertenece a la solicitud (fail-closed)');
        }
    }

    /**
     * Archivo subido ⇒ `Document` NATIVO de la entidad de la solicitud (GLPI valida extensión/MIME con `DocumentType` y
     * lo mueve a su almacén). Nunca se guarda en tablas propias.
     */
    private function storeUpload(mixed $file, int $requestId): int
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new \InvalidArgumentException('archivo inválido o ausente');
        }
        if ((int) $file->getSize() <= 0 || (int) $file->getSize() > self::MAX_UPLOAD) {
            throw new \InvalidArgumentException('tamaño de archivo inválido');
        }
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $file->getClientOriginalName())) ?: 'document';
        if (\Document::isValidDoc($name) === '') {
            throw new \InvalidArgumentException('tipo de archivo no permitido');
        }
        $req = (new RequestManager())->getViewable($requestId);
        $prefix = bin2hex(random_bytes(8)) . '_';
        $file->move(GLPI_TMP_DIR, $prefix . $name);
        $docId = (int) (new \Document())->add([
            'name'              => $name,
            'entities_id'       => (int) $req->fields['entities_id'],
            'is_recursive'      => 0,
            '_filename'         => [$prefix . $name],
            '_prefix_filename'  => [$prefix],
        ]);
        if ($docId <= 0) {
            throw new \InvalidArgumentException('el documento no pudo registrarse');
        }
        return $docId;
    }
}
