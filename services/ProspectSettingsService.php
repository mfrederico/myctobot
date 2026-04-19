<?php
/**
 * Prospect Pipeline Settings Service
 *
 * Central config for prospect discovery, analysis, enrichment, and outreach.
 * Reads from enterprisesettings (DB) first, falls back to INI, then defaults.
 * Used by both the admin UI and cron scripts.
 */

namespace app\services;

use \app\Bean;

class ProspectSettingsService {

    private array $iniConfig;
    private static array $defaults = [
        // Discovery
        'search_provider'        => 'serper',
        'max_daily_queries'      => '10',
        'fetch_delay'            => '2',
        'skip_domains'           => 'youtube.com,facebook.com,instagram.com,twitter.com,x.com,reddit.com,pinterest.com,linkedin.com,tiktok.com,shopify.com,apps.shopify.com,amazon.com,ebay.com,etsy.com,walmart.com,wikipedia.org,github.com,medium.com,quora.com',

        // Analysis
        'ai_model'               => 'kimi-k2.5:cloud',
        'ai_fallback'            => 'claude',
        'batch_size'             => '20',
        'qualify_threshold'      => '0.6',
        'disqualify_threshold'   => '0.3',

        // CRM Push
        'push_batch_limit'       => '50',
        'push_default_stage'     => 'cold',
        'lead_score_email'       => '20',
        'lead_score_phone'       => '10',
        'lead_score_name'        => '20',
        'lead_score_size'        => '10',
        'lead_score_signals'     => '15',
        'lead_score_confidence_max' => '25',

        // Enrichment
        'enrichment_batch_size'  => '20',
        'enrichment_ai_model'    => 'kimi-k2.5:cloud',
        'enrichment_fetch_delay' => '2',

        // Outreach
        'outreach_min_lead_score' => '40',
        'outreach_gap_days'       => '3',
        'outreach_max_steps'      => '4',
    ];

    /**
     * @param array $iniConfig Parsed INI config array (from parse_ini_file with sections)
     */
    public function __construct(array $iniConfig = []) {
        $this->iniConfig = $iniConfig;
    }

    /**
     * Load from workspace INI file
     */
    public static function fromWorkspace(string $workspace): self {
        $configFile = dirname(__DIR__) . "/conf/config.{$workspace}.ini";
        $config = file_exists($configFile) ? parse_ini_file($configFile, true) : [];
        return new self($config ?: []);
    }

    /**
     * Get a setting value. Checks: DB -> INI -> default.
     */
    public function get(string $key, $default = null) {
        // 1. Check enterprisesettings DB
        $dbValue = UserDatabaseService::getSetting("prospect.{$key}");
        if ($dbValue !== null) {
            return $dbValue;
        }

        // 2. Check INI sections
        $iniValue = $this->getFromIni($key);
        if ($iniValue !== null) {
            return $iniValue;
        }

        // 3. Fall back to hardcoded default, then caller default
        return self::$defaults[$key] ?? $default;
    }

    /**
     * Set a setting value in enterprisesettings.
     */
    public function set(string $key, $value): void {
        UserDatabaseService::setSetting("prospect.{$key}", (string)$value, true);
    }

    /**
     * Get all settings with defaults merged.
     */
    public function getAll(): array {
        $result = [];
        foreach (self::$defaults as $key => $default) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * Get search queries text. DB first, then file seed.
     */
    public function getSearchQueries(): string {
        $dbValue = UserDatabaseService::getSetting('prospect.search_queries');
        if ($dbValue !== null && $dbValue !== '') {
            return $dbValue;
        }

        // Seed from file
        return $this->loadQueriesFromFile();
    }

    /**
     * Save search queries text to DB.
     */
    public function setSearchQueries(string $text): void {
        UserDatabaseService::setSetting('prospect.search_queries', $text, true);
    }

    /**
     * Load queries from the default file.
     */
    public function loadQueriesFromFile(): string {
        $file = $this->iniConfig['prospecting']['queries_file'] ?? 'conf/prospect-queries.txt';
        if (!str_starts_with($file, '/')) {
            $file = dirname(__DIR__) . '/' . $file;
        }
        if (file_exists($file)) {
            return file_get_contents($file);
        }
        return '';
    }

    /**
     * Get API key configuration status (configured yes/no, never exposes values).
     */
    public function getApiKeyStatus(): array {
        return [
            'serper' => !empty($this->iniConfig['serper']['api_key'] ?? ''),
            'google_cse' => !empty($this->iniConfig['google']['api_key'] ?? '') && !empty($this->iniConfig['google']['cse_id'] ?? ''),
            'anthropic' => !empty($this->iniConfig['anthropic']['api_key'] ?? ''),
        ];
    }

    /**
     * Get the list of all setting keys grouped by section.
     */
    public static function getSections(): array {
        return [
            'discovery' => ['search_provider', 'max_daily_queries', 'fetch_delay', 'skip_domains'],
            'queries'   => [], // special handling
            'analysis'  => ['ai_model', 'ai_fallback', 'batch_size', 'qualify_threshold', 'disqualify_threshold'],
            'push'      => ['push_batch_limit', 'push_default_stage', 'lead_score_email', 'lead_score_phone', 'lead_score_name', 'lead_score_size', 'lead_score_signals', 'lead_score_confidence_max'],
            'enrichment' => ['enrichment_batch_size', 'enrichment_ai_model', 'enrichment_fetch_delay'],
            'outreach'  => ['outreach_min_lead_score', 'outreach_gap_days', 'outreach_max_steps'],
        ];
    }

    /**
     * Map INI keys to our setting keys.
     */
    private function getFromIni(string $key): ?string {
        $prospecting = $this->iniConfig['prospecting'] ?? [];
        $outreach = $this->iniConfig['outreach'] ?? [];

        // Map our keys to INI section keys
        $iniMap = [
            'search_provider'    => $prospecting['search_provider'] ?? null,
            'max_daily_queries'  => $prospecting['max_daily_queries'] ?? null,
            'fetch_delay'        => $prospecting['fetch_delay'] ?? null,
            'ai_model'           => $prospecting['ai_model'] ?? null,
            'ai_fallback'        => $prospecting['ai_fallback'] ?? null,
            'batch_size'         => $prospecting['batch_size'] ?? null,
            'qualify_threshold'  => $prospecting['qualify_threshold'] ?? null,
            'disqualify_threshold' => $prospecting['disqualify_threshold'] ?? null,
            'outreach_min_lead_score' => $outreach['min_lead_score'] ?? null,
            'outreach_gap_days'       => $outreach['gap_days'] ?? null,
        ];

        return $iniMap[$key] ?? null;
    }
}
