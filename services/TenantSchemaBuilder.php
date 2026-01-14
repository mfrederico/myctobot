<?php
/**
 * TenantSchemaBuilder - Creates tenant database schema using RedBeanPHP
 *
 * Uses RedBeanPHP associations to create tables with proper foreign keys.
 * Non-FK identifiers use _uid suffix to avoid RedBeanPHP's integer inference.
 *
 * @see https://redbeanphp.com/index.php?p=/association
 */

namespace app\services;

use RedBeanPHP\R as R;

class TenantSchemaBuilder {

    private string $dbName;
    private string $dbUser;
    private string $dbPass;
    private string $dbHost;

    // Temporary beans for associations
    private $member;
    private $board;
    private $repo;

    public function __construct(string $dbName, string $dbUser, string $dbPass, string $dbHost = 'localhost') {
        $this->dbName = $dbName;
        $this->dbUser = $dbUser;
        $this->dbPass = $dbPass;
        $this->dbHost = $dbHost;
    }

    /**
     * Build the complete tenant schema using RedBeanPHP
     */
    public function build(): void {
        // Add tenant database as a secondary connection
        $dsn = "mysql:host={$this->dbHost};dbname={$this->dbName};charset=utf8mb4";
        R::addDatabase('tenant_provision', $dsn, $this->dbUser, $this->dbPass);
        R::selectDatabase('tenant_provision');
        R::freeze(false); // Allow schema modifications

        // Create base tables first (no dependencies)
        $this->member = $this->createMemberTable();
        $this->createAuthControlTable();

        // Create tables with member association
        $this->board = $this->createJiraBoardsTable();
        $this->repo = $this->createRepoConnectionsTable();
        $this->createAtlassianTokenTable();
        $this->createGitHubTokensTable();
        $this->createEnterpriseSettingsTable();
        $this->createAnthropicKeysTable();
        $this->createAIAgentsTable();

        // Create tables with board/repo associations
        $this->createBoardRepoMappingTable();
        $this->createAnalysisResultsTable();
        $this->createDigestHistoryTable();
        $this->createAIDevJobsTable();
        $this->createAIDevJobLogsTable();
        $this->createDirectivesTable();
        $this->createCeoDirectivesTable();
        $this->createCtoProjectsTable();
        $this->createCtoEpicsTable();
        $this->createCtoStoriesTable();
        $this->createProjectsTable();
        $this->createPluginTables();

        // Apply JSON column types (MariaDB needs explicit JSON type)
        $this->applyJsonColumnTypes();

        // Clean up temporary beans
        $this->cleanupTempBeans();

        // Insert default authcontrol permissions
        $this->insertDefaultPermissions();

        // Switch back to default database
        R::selectDatabase('default');
    }

    private function createMemberTable() {
        $bean = R::dispense('member');
        $bean->email = 'schema@example.com';
        $bean->username = 'schemauser';
        $bean->password = str_repeat('x', 255);
        $bean->display_name = 'Schema User';
        $bean->level = 100;
        $bean->status = 'active';
        $bean->first_name = 'Schema';
        $bean->last_name = 'User';
        $bean->bio = 'Schema bio placeholder';
        $bean->avatar_url = 'https://example.com/avatar.png';
        $bean->timezone = 'America/New_York';
        $bean->last_login = date('Y-m-d H:i:s');
        $bean->login_count = 0;
        $bean->failed_logins = 0;
        $bean->lockout_until = null;
        $bean->remember_token = 'schema_remember_token';
        $bean->google_uid = 'schema_google_uid'; // _uid suffix - string not FK
        $bean->email_verified = true;
        $bean->verification_token = 'schema_verification_token';
        $bean->verified_at = date('Y-m-d H:i:s');
        $bean->reset_token = 'schema_reset_token';
        $bean->reset_expires = date('Y-m-d H:i:s');
        $bean->invite_token = 'schema_invite_token';
        $bean->invite_sent_at = date('Y-m-d H:i:s');
        $bean->invite_expires_at = date('Y-m-d H:i:s');
        $bean->invited_by = 0;
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        return $bean;
    }

    private function createAuthControlTable(): void {
        $bean = R::dispense('authcontrol');
        $bean->control = 'schema';
        $bean->method = 'index';
        $bean->level = 101;
        $bean->description = 'Schema placeholder';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->validcount = 0;
        R::store($bean);
        R::trash($bean);
    }

    private function createAtlassianTokenTable(): void {
        $bean = R::dispense('atlassiantoken');
        $bean->member = $this->member; // Creates member_id FK automatically
        $bean->access_token = 'schema_access_token_placeholder';
        $bean->refresh_token = 'schema_refresh_token_placeholder';
        $bean->token_type = 'Bearer';
        $bean->expires_at = date('Y-m-d H:i:s');
        $bean->cloud_uid = 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'; // _uid suffix - string not FK
        $bean->site_url = 'https://example.atlassian.net';
        $bean->site_name = 'Schema Example Site';
        $bean->scopes = 'read:jira-work read:jira-user offline_access';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createJiraBoardsTable() {
        $bean = R::dispense('jiraboards');
        $bean->member = $this->member; // Creates member_id FK
        $bean->cloud_uid = 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'; // _uid suffix - string not FK
        $bean->board_id = 1; // This IS an integer (Jira board ID)
        $bean->board_name = 'Schema Template Board';
        $bean->board_type = 'scrum';
        $bean->project_key = 'SCHEMA';
        $bean->project_name = 'Schema Template Project';
        $bean->is_active = true;
        $bean->digest_enabled = true;
        $bean->digest_frequency = 'daily';
        $bean->digest_time = '09:00';
        $bean->digest_day = 1;
        $bean->last_digest_at = date('Y-m-d H:i:s');
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        return $bean;
    }

    private function createRepoConnectionsTable() {
        $bean = R::dispense('repoconnections');
        $bean->member = $this->member; // Creates member_id FK
        $bean->provider = 'github';
        $bean->repo_owner = 'schema-owner';
        $bean->repo_name = 'schema-repo';
        $bean->repo_full_name = 'schema-owner/schema-repo';
        $bean->default_branch = 'main';
        $bean->webhook_uid = 'schema_webhook_uid'; // _uid suffix - string not FK
        $bean->webhook_secret = 'schema_webhook_secret';
        $bean->is_active = true;
        $bean->last_webhook_at = date('Y-m-d H:i:s');
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        return $bean;
    }

    private function createAIDevJobsTable(): void {
        $bean = R::dispense('aidevjobs');
        $bean->job_uid = 'schema-job-uid-placeholder'; // _uid suffix - string not FK
        $bean->member = $this->member; // Creates member_id FK
        $bean->jiraboards = $this->board; // Creates jiraboards_id FK
        $bean->repoconnections = $this->repo; // Creates repoconnections_id FK
        $bean->issue_key = 'SCHEMA-1';
        $bean->cloud_uid = 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'; // _uid suffix
        $bean->status = 'pending';
        $bean->progress = 0;
        $bean->current_step = 'Initializing';
        $bean->steps_completed = '[]';
        $bean->current_shard_job_uid = 'schema_shard_job_uid'; // _uid suffix
        $bean->branch_name = 'feature/schema-branch';
        $bean->pr_url = 'https://github.com/example/repo/pull/1';
        $bean->pr_number = 0;
        $bean->pr_created_at = date('Y-m-d H:i:s');
        $bean->clarification_comment_uid = 'schema_comment_uid'; // _uid suffix
        $bean->clarification_questions = '[]';
        $bean->error_message = 'Schema placeholder error message';
        $bean->run_count = 0;
        $bean->last_output = 'Schema placeholder output';
        $bean->last_result_json = '{}';
        $bean->files_changed = '[]';
        $bean->commit_sha = 'abc123def456';
        $bean->shopify_theme_id = 0; // This IS an integer
        $bean->shopify_preview_url = 'https://example.myshopify.com';
        $bean->playwright_results = '[]';
        $bean->preserve_branch = true;
        $bean->started_at = date('Y-m-d H:i:s');
        $bean->completed_at = date('Y-m-d H:i:s');
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        $bean->project_type = 'jira';
        $bean->queue_metadata = '{}';
        R::store($bean);
        R::trash($bean);
    }

    private function createAIDevJobLogsTable(): void {
        $bean = R::dispense('aidevjoblogs');
        $bean->issue_key = 'SCHEMA-1';
        $bean->log_level = 'info';
        $bean->message = 'Schema log message';
        $bean->context_json = '{}';
        $bean->created_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createAnalysisResultsTable(): void {
        $bean = R::dispense('analysisresults');
        $bean->jiraboards = $this->board; // Creates jiraboards_id FK
        $bean->analysis_type = 'sprint';
        $bean->content_json = '{}';
        $bean->content_markdown = 'Schema analysis content';
        $bean->issue_count = 0;
        $bean->status_filter = 'all';
        $bean->created_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createDigestHistoryTable(): void {
        $bean = R::dispense('digesthistory');
        $bean->jiraboards = $this->board; // Creates jiraboards_id FK
        $bean->member = $this->member; // Creates member_id FK
        $bean->digest_type = 'daily';
        $bean->content_markdown = 'Schema digest content';
        $bean->email_sent = false;
        $bean->email_to = 'schema@example.com';
        $bean->created_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createEnterpriseSettingsTable(): void {
        $bean = R::dispense('enterprisesettings');
        $bean->setting_key = 'schema_key';
        $bean->setting_value = 'schema_value';
        $bean->setting_type = 'string';
        $bean->is_encrypted = false;
        $bean->description = 'Schema setting';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createGitHubTokensTable(): void {
        $bean = R::dispense('githubtokens');
        $bean->member = $this->member; // Creates member_id FK
        $bean->access_token = 'gho_schema_placeholder_token';
        $bean->token_type = 'bearer';
        $bean->scope = 'repo,user';
        $bean->github_user_uid = 'schema_github_user_uid'; // _uid suffix
        $bean->github_login = 'schema-user';
        $bean->github_name = 'Schema User';
        $bean->github_avatar = 'https://github.com/avatar.png';
        $bean->expires_at = date('Y-m-d H:i:s');
        $bean->refresh_token = 'ghr_schema_placeholder_refresh';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createBoardRepoMappingTable(): void {
        $bean = R::dispense('boardrepomapping');
        $bean->jiraboards = $this->board; // Creates jiraboards_id FK
        $bean->repoconnections = $this->repo; // Creates repoconnections_id FK
        $bean->is_primary = true;
        $bean->created_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createAnthropicKeysTable(): void {
        $bean = R::dispense('anthropickeys');
        $bean->member = $this->member; // created_by_member association
        $bean->created_by_name = 'Schema User';
        $bean->name = 'Schema Key';
        $bean->api_key = 'sk-ant-schema-placeholder';
        $bean->model = 'claude-sonnet-4-20250514';
        $bean->shared = false;
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createAIAgentsTable(): void {
        $bean = R::dispense('aiagents');
        $bean->member = $this->member; // Creates member_id FK
        $bean->created_by_member_id = $this->member->id;
        $bean->created_by_name = 'Schema User';
        $bean->name = 'Default Agent';
        $bean->description = 'Schema agent description';
        $bean->runner_type = 'claude_cli';
        $bean->runner_config = '{}';
        $bean->mcp_servers = '[]';
        $bean->hooks_config = '{}';
        $bean->is_active = true;
        $bean->is_default = false;
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createDirectivesTable(): void {
        $bean = R::dispense('directives');
        $bean->directive_uid = 'schema-directive-uid'; // _uid suffix
        $bean->member = $this->member; // Creates member_id FK
        $bean->directive_type = 'email';
        $bean->subject = 'Schema directive subject';
        $bean->content = 'Schema directive content';
        $bean->source_type = 'email';
        $bean->source_uid = 'schema_source_uid'; // _uid suffix
        $bean->source_email = 'schema@example.com';
        $bean->status = 'pending';
        $bean->processed_content = 'Schema processed content';
        $bean->context_json = '{}';
        $bean->actions_json = '[]';
        $bean->results_json = '{}';
        $bean->error_message = 'Schema error message';
        $bean->retry_count = 0;
        $bean->scheduled_at = date('Y-m-d H:i:s');
        $bean->started_at = date('Y-m-d H:i:s');
        $bean->completed_at = date('Y-m-d H:i:s');
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createCeoDirectivesTable(): void {
        $bean = R::dispense('ceodirectives');
        $bean->directive_uid = 'schema-ceodirective-uid'; // _uid suffix
        $bean->member = $this->member; // Creates member_id FK
        $bean->email_from = 'ceo@example.com';
        $bean->email_subject = 'Schema directive subject';
        $bean->email_body = 'Schema directive body';
        $bean->email_message_uid = 'schema-message-id';
        $bean->parsed_intent = 'project';
        $bean->parsed_summary = 'Schema summary';
        $bean->parsed_requirements = '[]';
        $bean->status = 'received';
        $bean->current_phase = 'initial';
        $bean->error_message = 'Schema error';
        $bean->approval_mode = 'auto';
        $bean->response_sent_at = date('Y-m-d H:i:s');
        $bean->response_content = 'Schema response';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createCtoProjectsTable(): void {
        $bean = R::dispense('ctoprojects');
        $bean->project_uid = 'schema-ctoproject-uid'; // _uid suffix
        $bean->member = $this->member; // Creates member_id FK
        $bean->jiraboards = $this->board; // Creates jiraboards_id FK (board_id equivalent)
        $bean->repoconnections = $this->repo; // Creates repoconnections_id FK (github_repo_id equivalent)
        $bean->name = 'Schema CTO Project';
        $bean->project_type = 'jira';
        $bean->description = 'Schema project description';
        $bean->goals = '[]';
        $bean->estimated_effort = 'medium';
        $bean->risk_assessment = '{}';
        $bean->tech_stack = '[]';
        $bean->jira_project_key = 'SCHEMA';
        $bean->status = 'planning';
        $bean->completion_percentage = 0;
        $bean->milestones = '[]';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createCtoEpicsTable(): void {
        $bean = R::dispense('ctoepics');
        $bean->epic_uid = 'schema-ctoepic-uid'; // _uid suffix
        $bean->ctoprojects = R::findOne('ctoprojects'); // FK placeholder - will be null
        $bean->title = 'Schema CTO Epic';
        $bean->description = 'Schema epic description';
        $bean->acceptance_criteria = '[]';
        $bean->jira_epic_key = 'SCHEMA-1';
        $bean->jira_epic_uid = 'schema-jira-epic-uid';
        $bean->status = 'backlog';
        $bean->story_count = 0;
        $bean->stories_completed = 0;
        $bean->priority = 0;
        $bean->sequence = 0;
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createCtoStoriesTable(): void {
        $bean = R::dispense('ctostories');
        $bean->story_uid = 'schema-ctostory-uid'; // _uid suffix
        $bean->ctoepics = R::findOne('ctoepics'); // FK placeholder - will be null
        $bean->title = 'Schema CTO Story';
        $bean->description = 'Schema story description';
        $bean->acceptance_criteria = '[]';
        $bean->story_points = 0;
        $bean->jira_issue_key = 'SCHEMA-2';
        $bean->jira_issue_uid = 'schema-jira-issue-uid';
        $bean->aidev_job_uid = 'schema-aidev-job-uid';
        $bean->status = 'pending_review';
        $bean->blocker_reason = 'Schema blocker';
        $bean->depends_on = '[]';
        $bean->verified_at = date('Y-m-d H:i:s');
        $bean->verification_result = '{}';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createProjectsTable(): void {
        $bean = R::dispense('projects');
        $bean->project_uid = 'schema-project-uid'; // _uid suffix
        $bean->member = $this->member; // Creates member_id FK
        $bean->jiraboards = $this->board; // Creates jiraboards_id FK
        $bean->repoconnections = $this->repo; // Creates repoconnections_id FK
        $bean->name = 'Schema Project';
        $bean->description = 'Schema project description';
        $bean->project_type = 'feature';
        $bean->status = 'pending';
        $bean->priority = 'medium';
        $bean->epic_key = 'SCHEMA-1';
        $bean->goals_json = '[]';
        $bean->constraints_json = '{}';
        $bean->context_json = '{}';
        $bean->plan_json = '[]';
        $bean->progress_json = '{}';
        $bean->current_phase = 'planning';
        $bean->current_step = 0;
        $bean->total_steps = 0;
        $bean->error_message = 'Schema error';
        $bean->started_at = date('Y-m-d H:i:s');
        $bean->completed_at = date('Y-m-d H:i:s');
        $bean->paused_at = date('Y-m-d H:i:s');
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createPluginTables(): void {
        // Discovered plugins
        $discovered = R::dispense('discoveredplugins');
        $discovered->plugin_uid = 'schema-plugin-uid'; // _uid suffix
        $discovered->repoconnections = $this->repo; // Creates repoconnections_id FK
        $discovered->repo_owner = 'schema-owner';
        $discovered->repo_name = 'schema-plugin';
        $discovered->plugin_name = 'Schema Plugin';
        $discovered->plugin_description = 'Schema plugin description';
        $discovered->plugin_version = '1.0.0';
        $discovered->plugin_author = 'Schema Author';
        $discovered->plugin_main = 'src/Plugin.php';
        $discovered->plugin_requires = '{}';
        $discovered->plugin_json = '{}';
        $discovered->status = 'discovered';
        $discovered->validation_error = 'Schema validation error';
        $discovered->discovered_at = date('Y-m-d H:i:s');
        $discovered->last_scanned_at = date('Y-m-d H:i:s');
        $discovered->created_at = date('Y-m-d H:i:s');
        $discovered->updated_at = date('Y-m-d H:i:s');
        R::store($discovered);

        // Plugin scans
        $scan = R::dispense('pluginscans');
        $scan->scan_uid = 'schema-scan-uid'; // _uid suffix
        $scan->repoconnections = $this->repo; // Creates repoconnections_id FK
        $scan->status = 'running';
        $scan->repos_scanned = 0;
        $scan->plugins_found = 0;
        $scan->errors_encountered = 0;
        $scan->error_log = '[]';
        $scan->started_at = date('Y-m-d H:i:s');
        $scan->completed_at = date('Y-m-d H:i:s');
        $scan->created_at = date('Y-m-d H:i:s');
        R::store($scan);
        R::trash($scan);

        // Installed plugins
        $installed = R::dispense('installedplugins');
        $installed->plugin_uid = 'schema-installed-plugin-uid'; // _uid suffix
        $installed->discoveredplugins = $discovered; // Creates discoveredplugins_id FK
        $installed->member = $this->member; // Creates member_id FK
        $installed->installed_version = '1.0.0';
        $installed->is_active = true;
        $installed->config_json = '{}';
        $installed->installed_at = date('Y-m-d H:i:s');
        $installed->updated_at = date('Y-m-d H:i:s');
        R::store($installed);
        R::trash($installed);

        R::trash($discovered);
    }

    /**
     * Apply JSON column types for MariaDB
     */
    private function applyJsonColumnTypes(): void {
        // JSON columns - MariaDB needs explicit JSON type for proper handling
        R::exec('ALTER TABLE `aidevjobs` MODIFY COLUMN `steps_completed` JSON');
        R::exec('ALTER TABLE `aidevjobs` MODIFY COLUMN `clarification_questions` JSON');
        R::exec('ALTER TABLE `aidevjobs` MODIFY COLUMN `files_changed` JSON');
        R::exec('ALTER TABLE `aidevjobs` MODIFY COLUMN `playwright_results` JSON');

        R::exec('ALTER TABLE `aiagents` MODIFY COLUMN `runner_config` JSON');
        R::exec('ALTER TABLE `aiagents` MODIFY COLUMN `mcp_servers` JSON');
        R::exec('ALTER TABLE `aiagents` MODIFY COLUMN `hooks_config` JSON');

        R::exec('ALTER TABLE `directives` MODIFY COLUMN `context_json` JSON');
        R::exec('ALTER TABLE `directives` MODIFY COLUMN `actions_json` JSON');
        R::exec('ALTER TABLE `directives` MODIFY COLUMN `results_json` JSON');

        R::exec('ALTER TABLE `projects` MODIFY COLUMN `goals_json` JSON');
        R::exec('ALTER TABLE `projects` MODIFY COLUMN `constraints_json` JSON');
        R::exec('ALTER TABLE `projects` MODIFY COLUMN `context_json` JSON');
        R::exec('ALTER TABLE `projects` MODIFY COLUMN `plan_json` JSON');
        R::exec('ALTER TABLE `projects` MODIFY COLUMN `progress_json` JSON');

        R::exec('ALTER TABLE `discoveredplugins` MODIFY COLUMN `plugin_requires` JSON');
        R::exec('ALTER TABLE `discoveredplugins` MODIFY COLUMN `plugin_json` JSON');

        R::exec('ALTER TABLE `pluginscans` MODIFY COLUMN `error_log` JSON');

        R::exec('ALTER TABLE `installedplugins` MODIFY COLUMN `config_json` JSON');

        R::exec('ALTER TABLE `ceodirectives` MODIFY COLUMN `parsed_requirements` JSON');

        R::exec('ALTER TABLE `ctoprojects` MODIFY COLUMN `goals` JSON');
        R::exec('ALTER TABLE `ctoprojects` MODIFY COLUMN `risk_assessment` JSON');
        R::exec('ALTER TABLE `ctoprojects` MODIFY COLUMN `tech_stack` JSON');
        R::exec('ALTER TABLE `ctoprojects` MODIFY COLUMN `milestones` JSON');

        R::exec('ALTER TABLE `ctoepics` MODIFY COLUMN `acceptance_criteria` JSON');

        R::exec('ALTER TABLE `ctostories` MODIFY COLUMN `acceptance_criteria` JSON');
        R::exec('ALTER TABLE `ctostories` MODIFY COLUMN `depends_on` JSON');
        R::exec('ALTER TABLE `ctostories` MODIFY COLUMN `verification_result` JSON');

        // Add unique constraints
        R::exec('ALTER TABLE `member` ADD UNIQUE INDEX IF NOT EXISTS `uk_email` (`email`)');
        R::exec('ALTER TABLE `aidevjobs` ADD UNIQUE INDEX IF NOT EXISTS `uk_job_uid` (`job_uid`)');
        R::exec('ALTER TABLE `atlassiantoken` ADD UNIQUE INDEX IF NOT EXISTS `uk_member_cloud` (`member_id`, `cloud_uid`)');
    }

    /**
     * Clean up temporary beans used for schema creation
     */
    private function cleanupTempBeans(): void {
        if ($this->repo) {
            R::trash($this->repo);
        }
        if ($this->board) {
            R::trash($this->board);
        }
        if ($this->member) {
            R::trash($this->member);
        }
    }

    /**
     * Insert default permission entries for authcontrol
     */
    private function insertDefaultPermissions(): void {
        $permissions = [
            // Public pages (level 101)
            ['index', 'index', 101, 'Landing page'],
            ['auth', 'login', 101, 'Login page'],
            ['auth', 'dologin', 101, 'Process login'],
            ['auth', 'google', 101, 'Start Google OAuth'],
            ['auth', 'googlecallback', 101, 'Google OAuth callback'],
            ['auth', 'forgot', 101, 'Forgot password'],
            ['auth', 'resetpassword', 101, 'Reset password'],
            ['auth', 'register', 101, 'Registration page'],

            // Authenticated user pages (level 100)
            ['auth', 'logout', 100, 'Logout'],
            ['dashboard', 'index', 100, 'Main dashboard'],
            ['member', 'index', 100, 'Member index'],
            ['member', 'profile', 100, 'View profile'],
            ['member', 'edit', 100, 'Edit profile'],
            ['member', 'settings', 100, 'Member settings'],
            ['member', 'password', 100, 'Change password'],
            ['member', 'avatar', 100, 'Update avatar'],
            ['atlassian', 'index', 100, 'Atlassian connections'],
            ['atlassian', 'connect', 100, 'Start Atlassian OAuth'],
            ['atlassian', 'callback', 100, 'Atlassian OAuth callback'],
            ['atlassian', 'disconnect', 100, 'Disconnect Atlassian'],
            ['atlassian', 'refresh', 100, 'Refresh Atlassian tokens'],
            ['boards', 'index', 100, 'List boards'],
            ['boards', 'discover', 100, 'Discover Jira boards'],
            ['boards', 'add', 100, 'Add board'],
            ['boards', 'edit', 100, 'Edit board'],
            ['boards', 'remove', 100, 'Remove board'],
            ['boards', 'toggle', 100, 'Toggle board status'],
            ['analysis', 'index', 100, 'Analysis dashboard'],
            ['analysis', 'run', 100, 'Run analysis'],
            ['analysis', 'view', 100, 'View analysis'],
            ['analysis', 'email', 100, 'Email analysis'],
            ['settings', 'index', 100, 'Settings page'],
            ['settings', 'profile', 100, 'Profile settings'],
            ['settings', 'notifications', 100, 'Notification settings'],
            ['settings', 'subscription', 100, 'Subscription management'],
            ['enterprise', 'index', 100, 'Enterprise settings'],
            ['enterprise', 'github', 100, 'GitHub settings'],
            ['enterprise', 'githubcallback', 100, 'GitHub OAuth callback'],
            ['enterprise', 'repos', 100, 'Repository management'],
            ['enterprise', 'aidev', 100, 'AI Developer settings'],
            ['enterprise', 'api', 100, 'API key management'],
            ['jobs', 'index', 100, 'AI Dev jobs list'],
            ['jobs', 'view', 100, 'View job detail'],
            ['jobs', 'logs', 100, 'View job logs'],
            ['jobs', 'cancel', 100, 'Cancel job'],
            ['jobs', 'retry', 100, 'Retry job'],
            ['directives', 'index', 100, 'CEO directives list'],
            ['directives', 'view', 100, 'View directive detail'],
            ['directives', 'retry', 100, 'Retry failed directive'],
            ['directives', 'cancel', 100, 'Cancel directive'],
            ['projects', 'index', 100, 'CTO Projects dashboard'],
            ['projects', 'view', 100, 'View project detail'],
            ['projects', 'report', 100, 'Generate project report'],
            ['projects', 'pause', 100, 'Pause project execution'],
            ['projects', 'resume', 100, 'Resume project execution'],
            ['plugins', 'index', 100, 'Plugin marketplace listing'],
            ['plugins', 'search', 100, 'Search plugins'],
            ['plugins', 'autocomplete', 100, 'Plugin name autocomplete'],
            ['plugins', 'view', 100, 'View plugin details'],
            ['pluginregistry', 'index', 100, 'List discovered plugins'],

            // Admin pages (level 50)
            ['admin', 'index', 50, 'Admin dashboard'],
            ['admin', 'members', 50, 'Manage members'],

            // System endpoints (level 1 - ROOT only)
            ['api', 'crondigest', 1, 'Cron digest endpoint'],
            ['webhook', 'jira', 1, 'Jira webhook'],
            ['webhook', 'github', 1, 'GitHub webhook'],
            ['webhook', 'mailgun', 101, 'Mailgun incoming email webhook'],
        ];

        foreach ($permissions as $perm) {
            $bean = R::dispense('authcontrol');
            $bean->control = $perm[0];
            $bean->method = $perm[1];
            $bean->level = $perm[2];
            $bean->description = $perm[3];
            $bean->created_at = date('Y-m-d H:i:s');
            R::store($bean);
        }
    }
}
