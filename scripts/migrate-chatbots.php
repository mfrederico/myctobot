<?php
/**
 * Migrate existing connection-based widget configs to chatbots table.
 *
 * Finds connections with public_agent_token in metadata_json and creates
 * corresponding chatbot records. Also links existing knowledge bases.
 *
 * Usage:
 *   php scripts/migrate-chatbots.php --workspace=dev [--dry-run]
 */

$opts = getopt('', ['script', 'workspace:', 'dry-run']);

if (!isset($opts['script'])) {
    echo "Error: --script flag required\n";
    exit(1);
}

$workspace = $opts['workspace'] ?? null;
$dryRun = isset($opts['dry-run']);

if (!$workspace) {
    echo "Usage: php scripts/migrate-chatbots.php --script --workspace=dev [--dry-run]\n";
    exit(1);
}

// Bootstrap
$_SERVER['WORKSPACE'] = $workspace;
define('BASE_PATH', dirname(__DIR__));
chdir(BASE_PATH);
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/bootstrap.php';

$configFile = "conf/config.{$workspace}.ini";
$app = new \app\Bootstrap($configFile);

use \app\Bean;
use \RedBeanPHP\R;

R::freeze(false);

echo "Migrating chatbot configs for workspace: {$workspace}" . ($dryRun ? " (DRY RUN)\n" : "\n");

// Find connections with public_agent_token in metadata
$connections = Bean::find('connections', ' enabled = 1 AND metadata_json LIKE ? ', ['%public_agent_token%']);

$migrated = 0;
$skipped = 0;

foreach ($connections as $conn) {
    $metadata = json_decode($conn->metadata_json ?: '{}', true);
    $token = $metadata['public_agent_token'] ?? null;

    if (empty($token)) {
        continue;
    }

    // Check if chatbot already exists for this token
    $existing = Bean::findOne('chatbots', ' widget_token = ? ', [$token]);
    if ($existing) {
        echo "  SKIP: Token {$token} already has chatbot #{$existing->id} ({$existing->name})\n";
        $skipped++;
        continue;
    }

    $name = $conn->connection_name ?: $conn->external_name ?: $conn->external_eid ?: 'Chatbot';
    $slug = \Model_Chatbots::generateSlug($name);

    // Ensure unique slug
    $slugBase = $slug;
    $counter = 1;
    while (Bean::findOne('chatbots', ' slug = ? ', [$slug])) {
        $slug = $slugBase . '-' . $counter;
        $counter++;
    }

    $pipelineId = $metadata['pipelines_id'] ?? null;

    echo "  MIGRATE: {$conn->external_eid} -> chatbot '{$name}' (slug: {$slug}, token: {$token}, pipeline: {$pipelineId})\n";

    if (!$dryRun) {
        $chatbot = Bean::dispense('chatbots');
        $chatbot->name = $name;
        $chatbot->slug = $slug;
        $chatbot->mode = 'advisor';
        $chatbot->connections_id = (int)$conn->id;
        $chatbot->pipelines_id = $pipelineId ? (int)$pipelineId : null;
        $chatbot->status = 'active';
        $chatbot->widget_token = $token;
        $chatbot->member_id = $conn->member_id ?: $conn->created_by_member_id;

        // Default config
        $config = [
            'welcome_message' => 'Hi! How can I help you today?',
            'require_order_verification' => true,
            'session_expiry_seconds' => 3600,
            'max_message_length' => 2000,
        ];
        $chatbot->config_json = json_encode($config, JSON_UNESCAPED_SLASHES);

        // Default triggers (matching previously hardcoded keywords)
        $triggers = [
            ['name' => 'Talk to Human', 'keywords' => ['manager', 'human', 'supervisor', 'complaint'], 'action' => 'escalate', 'pipeline_id' => null, 'enabled' => true],
            ['name' => 'Refund Request', 'keywords' => ['refund', 'cancel'], 'action' => 'escalate', 'pipeline_id' => null, 'enabled' => true],
        ];
        $chatbot->triggers_json = json_encode($triggers, JSON_UNESCAPED_SLASHES);

        Bean::store($chatbot);

        // Link existing knowledge bases
        $kbs = Bean::findAll('knowledgebases');
        foreach ($kbs as $kb) {
            $link = Bean::dispense('chatbotkbs');
            $link->chatbots_id = (int)$chatbot->id;
            $link->knowledgebases_id = (int)$kb->id;
            $link->priority = 0;
            $link->created_at = date('Y-m-d H:i:s');
            Bean::store($link);
            echo "    Linked KB: {$kb->name}\n";
        }

        // Update existing sessions to reference this chatbot
        $sessions = Bean::find('customersessions', ' connections_id = ? ', [(int)$conn->id]);
        $sessionCount = 0;
        foreach ($sessions as $session) {
            $session->chatbots_id = (int)$chatbot->id;
            Bean::store($session);
            $sessionCount++;
        }
        if ($sessionCount) {
            echo "    Updated {$sessionCount} existing sessions\n";
        }
    }

    $migrated++;
}

// Create default builder chatbot if none exists
$builderExists = Bean::findOne('chatbots', ' mode = ? ', ['builder']);
if (!$builderExists) {
    echo "\n  CREATE: Default builder chatbot (myctobot-builder)\n";
    if (!$dryRun) {
        $builder = Bean::dispense('chatbots');
        $builder->name = 'MyCTOBot Assistant';
        $builder->slug = 'myctobot-builder';
        $builder->mode = 'builder';
        $builder->status = 'active';
        $builder->member_id = 1;
        $builder->widget_token = 'wgt_' . bin2hex(random_bytes(16));
        $builder->created_at = date('Y-m-d H:i:s');
        $builder->updated_at = date('Y-m-d H:i:s');

        $config = [
            'welcome_message' => 'Hi! I can help you navigate MyCTOBot. What would you like to do?',
            'require_order_verification' => false,
        ];
        $builder->config_json = json_encode($config, JSON_UNESCAPED_SLASHES);
        $builder->triggers_json = '[]';

        Bean::store($builder);
        echo "    Created builder chatbot #{$builder->id}\n";
    }
}

echo "\nDone. Migrated: {$migrated}, Skipped: {$skipped}\n";
