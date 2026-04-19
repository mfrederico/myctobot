<?php
/**
 * Model_Chatbots - FUSE model for chatbot instances
 *
 * Each chatbot is a configurable AI chat agent with:
 * - mode: 'advisor' (external customer support) or 'builder' (internal MyCTOBot assistant)
 * - Configurable pipeline triggers (keyword → action mappings)
 * - Linked knowledge bases for RAG context
 * - Widget token for embedding
 */

use \app\Bean;

class Model_Chatbots extends \RedBeanPHP\SimpleModel {

    private const VALID_MODES = ['advisor', 'builder'];
    private const VALID_STATUSES = ['active', 'inactive', 'archived'];

    public function dispense(): void {
        $this->bean->status = 'active';
        $this->bean->mode = 'advisor';
        $this->bean->config_json = '{}';
        $this->bean->triggers_json = '[]';
        $this->bean->created_at = date('Y-m-d H:i:s');
        $this->bean->updated_at = date('Y-m-d H:i:s');
    }

    public function update(): void {
        $this->bean->updated_at = date('Y-m-d H:i:s');

        if ($this->bean->mode && !in_array($this->bean->mode, self::VALID_MODES)) {
            $this->bean->mode = 'advisor';
        }

        if ($this->bean->status && !in_array($this->bean->status, self::VALID_STATUSES)) {
            $this->bean->status = 'active';
        }

        // Validate JSON columns
        if ($this->bean->config_json) {
            $decoded = json_decode($this->bean->config_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->bean->config_json = '{}';
            }
        }
        if ($this->bean->triggers_json) {
            $decoded = json_decode($this->bean->triggers_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->bean->triggers_json = '[]';
            }
        }

        // Auto-generate slug from name if missing
        if (empty($this->bean->slug) && !empty($this->bean->name)) {
            $this->bean->slug = self::generateSlug($this->bean->name);
        }
    }

    // ── Config accessors ────────────────────────────────────────────────

    public function getConfig(): array {
        return json_decode($this->bean->config_json ?: '{}', true) ?: [];
    }

    public function setConfig(array $config): void {
        $this->bean->config_json = json_encode($config, JSON_UNESCAPED_SLASHES);
    }

    public function getConfigValue(string $key, $default = null) {
        $config = $this->getConfig();
        return $config[$key] ?? $default;
    }

    // ── Trigger system ──────────────────────────────────────────────────

    public function getTriggers(): array {
        return json_decode($this->bean->triggers_json ?: '[]', true) ?: [];
    }

    public function setTriggers(array $triggers): void {
        $this->bean->triggers_json = json_encode($triggers, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Match a customer message against trigger rules.
     * Returns the first matching trigger or null.
     */
    public function matchTrigger(string $message): ?array {
        $triggers = $this->getTriggers();
        $lowerMessage = strtolower($message);

        foreach ($triggers as $trigger) {
            if (!($trigger['enabled'] ?? true)) {
                continue;
            }
            $keywords = $trigger['keywords'] ?? [];
            foreach ($keywords as $keyword) {
                if (strpos($lowerMessage, strtolower(trim($keyword))) !== false) {
                    return $trigger;
                }
            }
        }

        return null;
    }

    // ── Widget token ────────────────────────────────────────────────────

    public function generateWidgetToken(): string {
        $token = 'wgt_' . bin2hex(random_bytes(16));
        $this->bean->widget_token = $token;
        return $token;
    }

    // ── Knowledge base links ────────────────────────────────────────────

    public function getLinkedKnowledgeBases(): array {
        $links = Bean::find('chatbotkbs', ' chatbots_id = ? ORDER BY priority DESC, id ASC ', [(int)$this->bean->id]);
        $result = [];
        foreach ($links as $link) {
            $kb = Bean::load('knowledgebases', (int)$link->knowledgebases_id);
            if ($kb && $kb->id) {
                $result[] = [
                    'link_id' => (int)$link->id,
                    'kb_id' => (int)$kb->id,
                    'name' => $kb->name,
                    'slug' => $kb->slug,
                    'priority' => (int)$link->priority,
                ];
            }
        }
        return $result;
    }

    public function linkKnowledgeBase(int $kbId, int $priority = 0): void {
        $existing = Bean::findOne('chatbotkbs', ' chatbots_id = ? AND knowledgebases_id = ? ', [(int)$this->bean->id, $kbId]);
        if ($existing) {
            $existing->priority = $priority;
            Bean::store($existing);
            return;
        }
        $link = Bean::dispense('chatbotkbs');
        $link->chatbots_id = (int)$this->bean->id;
        $link->knowledgebases_id = $kbId;
        $link->priority = $priority;
        $link->created_at = date('Y-m-d H:i:s');
        Bean::store($link);
    }

    public function unlinkKnowledgeBase(int $kbId): void {
        $link = Bean::findOne('chatbotkbs', ' chatbots_id = ? AND knowledgebases_id = ? ', [(int)$this->bean->id, $kbId]);
        if ($link) {
            Bean::trash($link);
        }
    }

    // ── Mode helpers ────────────────────────────────────────────────────

    public function isAdvisor(): bool {
        return $this->bean->mode === 'advisor';
    }

    public function isBuilder(): bool {
        return $this->bean->mode === 'builder';
    }

    public function isActive(): bool {
        return $this->bean->status === 'active';
    }

    // ── Static finders ──────────────────────────────────────────────────

    public static function findByToken(string $token): ?\RedBeanPHP\OODBBean {
        return Bean::findOne('chatbots', ' widget_token = ? AND status = ? ', [$token, 'active']);
    }

    public static function findBySlug(string $slug): ?\RedBeanPHP\OODBBean {
        return Bean::findOne('chatbots', ' slug = ? ', [$slug]);
    }

    // ── Slug generation ─────────────────────────────────────────────────

    public static function generateSlug(string $name): string {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'chatbot';
    }

    // ── Serialization ───────────────────────────────────────────────────

    public function toArray(): array {
        $connection = null;
        if ($this->bean->connections_id) {
            $conn = Bean::load('connections', (int)$this->bean->connections_id);
            if ($conn && $conn->id) {
                $connection = [
                    'id' => (int)$conn->id,
                    'name' => $conn->connection_name ?: $conn->external_name,
                    'domain' => $conn->external_eid,
                    'type' => $conn->connector_type,
                ];
            }
        }

        $pipeline = null;
        if ($this->bean->pipelines_id) {
            $p = Bean::load('pipelines', (int)$this->bean->pipelines_id);
            if ($p && $p->id) {
                $pipeline = [
                    'id' => (int)$p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                ];
            }
        }

        return [
            'id' => (int)$this->bean->id,
            'name' => $this->bean->name,
            'slug' => $this->bean->slug,
            'mode' => $this->bean->mode,
            'status' => $this->bean->status,
            'widget_token' => $this->bean->widget_token,
            'connections_id' => $this->bean->connections_id ? (int)$this->bean->connections_id : null,
            'pipelines_id' => $this->bean->pipelines_id ? (int)$this->bean->pipelines_id : null,
            'connection' => $connection,
            'pipeline' => $pipeline,
            'config' => $this->getConfig(),
            'triggers' => $this->getTriggers(),
            'knowledge_bases' => $this->getLinkedKnowledgeBases(),
            'created_at' => $this->bean->created_at,
            'updated_at' => $this->bean->updated_at,
        ];
    }
}
