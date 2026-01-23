<?php
/**
 * API Controller Template
 *
 * Variables available:
 * - $ctx: Context object with all scaffold data
 */

// Output the template
echo "<?php\n";
?>
/**
 * <?=$ctx->className?>api Controller
 * Stateless API endpoints for <?=$ctx->beanName?> beans
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\ApiAuthService;

class <?=$ctx->className?>api extends BaseControls\Control {

    private ?int $memberId = null;
    private ?string $workspace = null;
    protected $logger;

    public function __construct() {
        // Stateless - no session
        $this->logger = Flight::get('log');
        $this->workspace = $_SERVER['WORKSPACE'] ?? null;
    }

    /**
     * API endpoint router
     */
    public function index() {
        // Authenticate
        $authResult = ApiAuthService::authenticate('<?=$ctx->beanName?>', 'read');
        if (!$authResult['success']) {
            $this->jsonError($authResult['error'], 401);
            return;
        }
        $this->memberId = $authResult['member_id'];

        $method = $_SERVER['REQUEST_METHOD'];

        switch ($method) {
            case 'GET':
                $this->handleGet();
                break;
            case 'POST':
                $this->handlePost();
                break;
            case 'PUT':
                $this->handlePut();
                break;
            case 'DELETE':
                $this->handleDelete();
                break;
            case 'OPTIONS':
                $this->handleOptions();
                break;
            default:
                $this->jsonError('Method not allowed', 405);
        }
    }

    /**
     * GET - Retrieve one or list all
     */
    private function handleGet(): void {
        $id = $this->opId();

        if ($id) {
            // Get single record
            $bean = Bean::load('<?=$ctx->beanName?>', $id);
            if (!$bean->id) {
                $this->jsonError('Not found', 404);
                return;
            }
            $this->jsonSuccess($bean->export());
        } else {
            // List with optional filtering
            $limit = (int) ($this->getParam('limit') ?? 50);
            $offset = (int) ($this->getParam('offset') ?? 0);
            $limit = min($limit, 100); // Cap at 100

            $items = Bean::findAll('<?=$ctx->beanName?>', ' ORDER BY id DESC LIMIT ? OFFSET ? ', [$limit, $offset]);
            $total = Bean::count('<?=$ctx->beanName?>');

            $data = array_map(fn($b) => $b->export(), $items);
            $this->jsonSuccess([
                'items' => $data,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ]);
        }
    }

    /**
     * POST - Create new record
     */
    private function handlePost(): void {
        $input = $this->getJsonInput();
        if (!$input) {
            $this->jsonError('Invalid JSON body', 400);
            return;
        }

        $bean = Bean::dispense('<?=$ctx->beanName?>');
        foreach ($input as $key => $value) {
            if ($key !== 'id' && $key !== 'created_at' && $key !== 'updated_at') {
                $bean->{$key} = $value;
            }
        }

        try {
            $id = Bean::store($bean);
            $this->jsonSuccess(['id' => $id, 'data' => $bean->export()], 'Created', 201);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    /**
     * PUT - Update existing record
     */
    private function handlePut(): void {
        $id = $this->opId();
        if (!$id) {
            $this->jsonError('ID required in URL', 400);
            return;
        }

        $bean = Bean::load('<?=$ctx->beanName?>', $id);
        if (!$bean->id) {
            $this->jsonError('Not found', 404);
            return;
        }

        $input = $this->getJsonInput();
        if (!$input) {
            $this->jsonError('Invalid JSON body', 400);
            return;
        }

        foreach ($input as $key => $value) {
            if ($key !== 'id' && $key !== 'created_at' && $key !== 'updated_at') {
                $bean->{$key} = $value;
            }
        }

        try {
            Bean::store($bean);
            $this->jsonSuccess($bean->export(), 'Updated');
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    /**
     * DELETE - Remove record
     */
    private function handleDelete(): void {
        $id = $this->opId();
        if (!$id) {
            $this->jsonError('ID required in URL', 400);
            return;
        }

        $bean = Bean::load('<?=$ctx->beanName?>', $id);
        if (!$bean->id) {
            $this->jsonError('Not found', 404);
            return;
        }

        try {
            Bean::trash($bean);
            $this->jsonSuccess(['deleted' => $id], 'Deleted');
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    /**
     * OPTIONS - CORS preflight
     */
    private function handleOptions(): void {
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        http_response_code(204);
    }
}
