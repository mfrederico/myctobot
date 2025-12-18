<?php
/**
 * Tier Features
 * Defines feature access and limits per subscription tier
 */

namespace app\services;

class TierFeatures {

    // Feature names
    const FEATURE_PRIORITY_WEIGHTS = 'priority_weights';
    const FEATURE_ENGINEERING_GOALS = 'engineering_goals';
    const FEATURE_CLARITY_ANALYSIS = 'clarity_analysis';
    const FEATURE_STAKEHOLDER_INFO = 'stakeholder_info';
    const FEATURE_IMAGE_ANALYSIS = 'image_analysis';
    const FEATURE_REPO_INTEGRATION = 'repo_integration';
    const FEATURE_AI_CONFIDENCE = 'ai_confidence';
    const FEATURE_CUSTOM_PROMPTS = 'custom_prompts';
    const FEATURE_BATCH_ANALYSIS = 'batch_analysis';

    // Enterprise-only features
    const FEATURE_AI_DEVELOPER = 'ai_developer';
    const FEATURE_GIT_INTEGRATION = 'git_integration';
    const FEATURE_JIRA_WRITE = 'jira_write';
    const FEATURE_WEBHOOKS = 'webhooks';

    // Limit names
    const LIMIT_BOARDS = 'boards';
    const LIMIT_ANALYSES_PER_DAY = 'analyses_per_day';
    const LIMIT_DIGEST_RECIPIENTS = 'digest_recipients';
    const LIMIT_AI_DEV_JOBS_PER_DAY = 'ai_dev_jobs_per_day';
    const LIMIT_REPO_CONNECTIONS = 'repo_connections';

    /**
     * Feature access by tier
     */
    private static array $features = [
        'free' => [
            self::FEATURE_PRIORITY_WEIGHTS => false,
            self::FEATURE_ENGINEERING_GOALS => false,
            self::FEATURE_CLARITY_ANALYSIS => false,
            self::FEATURE_STAKEHOLDER_INFO => false,
            self::FEATURE_IMAGE_ANALYSIS => false,
            self::FEATURE_REPO_INTEGRATION => false,
            self::FEATURE_AI_CONFIDENCE => false,
            self::FEATURE_CUSTOM_PROMPTS => false,
            self::FEATURE_BATCH_ANALYSIS => false,
            self::FEATURE_AI_DEVELOPER => false,
            self::FEATURE_GIT_INTEGRATION => false,
            self::FEATURE_JIRA_WRITE => false,
            self::FEATURE_WEBHOOKS => false,
        ],
        'pro' => [
            self::FEATURE_PRIORITY_WEIGHTS => true,
            self::FEATURE_ENGINEERING_GOALS => true,
            self::FEATURE_CLARITY_ANALYSIS => true,
            self::FEATURE_STAKEHOLDER_INFO => true,
            self::FEATURE_IMAGE_ANALYSIS => true,
            self::FEATURE_REPO_INTEGRATION => false,  // Future phase
            self::FEATURE_AI_CONFIDENCE => false,     // Future phase
            self::FEATURE_CUSTOM_PROMPTS => true,
            self::FEATURE_BATCH_ANALYSIS => true,
            self::FEATURE_AI_DEVELOPER => false,
            self::FEATURE_GIT_INTEGRATION => false,
            self::FEATURE_JIRA_WRITE => false,
            self::FEATURE_WEBHOOKS => false,
        ],
        'enterprise' => [
            self::FEATURE_PRIORITY_WEIGHTS => true,
            self::FEATURE_ENGINEERING_GOALS => true,
            self::FEATURE_CLARITY_ANALYSIS => true,
            self::FEATURE_STAKEHOLDER_INFO => true,
            self::FEATURE_IMAGE_ANALYSIS => true,
            self::FEATURE_REPO_INTEGRATION => true,
            self::FEATURE_AI_CONFIDENCE => true,
            self::FEATURE_CUSTOM_PROMPTS => true,
            self::FEATURE_BATCH_ANALYSIS => true,
            self::FEATURE_AI_DEVELOPER => true,
            self::FEATURE_GIT_INTEGRATION => true,
            self::FEATURE_JIRA_WRITE => true,
            self::FEATURE_WEBHOOKS => true,
        ],
    ];

    /**
     * Usage limits by tier (-1 = unlimited)
     */
    private static array $limits = [
        'free' => [
            self::LIMIT_BOARDS => 2,
            self::LIMIT_ANALYSES_PER_DAY => 3,
            self::LIMIT_DIGEST_RECIPIENTS => 1,
            self::LIMIT_AI_DEV_JOBS_PER_DAY => 0,
            self::LIMIT_REPO_CONNECTIONS => 0,
        ],
        'pro' => [
            self::LIMIT_BOARDS => 10,
            self::LIMIT_ANALYSES_PER_DAY => 50,
            self::LIMIT_DIGEST_RECIPIENTS => 5,
            self::LIMIT_AI_DEV_JOBS_PER_DAY => 0,
            self::LIMIT_REPO_CONNECTIONS => 0,
        ],
        'enterprise' => [
            self::LIMIT_BOARDS => -1,
            self::LIMIT_ANALYSES_PER_DAY => -1,
            self::LIMIT_DIGEST_RECIPIENTS => -1,
            self::LIMIT_AI_DEV_JOBS_PER_DAY => -1,
            self::LIMIT_REPO_CONNECTIONS => -1,
        ],
    ];

    /**
     * Get features for a tier
     *
     * @param string $tier Tier name
     * @return array Feature access map
     */
    public static function getFeatures(string $tier): array {
        return self::$features[$tier] ?? self::$features['free'];
    }

    /**
     * Get limits for a tier
     *
     * @param string $tier Tier name
     * @return array Limit values
     */
    public static function getLimits(string $tier): array {
        return self::$limits[$tier] ?? self::$limits['free'];
    }

    /**
     * Check if a feature is available for a tier
     *
     * @param string $tier Tier name
     * @param string $feature Feature name
     * @return bool
     */
    public static function hasFeature(string $tier, string $feature): bool {
        $features = self::getFeatures($tier);
        return $features[$feature] ?? false;
    }

    /**
     * Get a specific limit for a tier
     *
     * @param string $tier Tier name
     * @param string $limit Limit name
     * @return int Limit value (-1 for unlimited)
     */
    public static function getLimit(string $tier, string $limit): int {
        $limits = self::getLimits($tier);
        return $limits[$limit] ?? 0;
    }

    /**
     * Get all Pro features for display
     *
     * @return array Feature descriptions
     */
    public static function getProFeatureList(): array {
        return [
            [
                'name' => 'Priority Weights',
                'description' => 'Configure custom priority weights for quick wins, task synergy, customer focus, and more',
                'icon' => 'bi-sliders'
            ],
            [
                'name' => 'Engineering Goals',
                'description' => 'Set sprint velocity targets, tech debt allocation, and delivery predictability goals',
                'icon' => 'bi-bullseye'
            ],
            [
                'name' => 'Clarity Analysis',
                'description' => 'Automatically detect tickets that need more detail with AI-powered clarity scoring',
                'icon' => 'bi-search'
            ],
            [
                'name' => 'Stakeholder Info',
                'description' => 'View reporter details and suggested clarification questions for unclear tickets',
                'icon' => 'bi-people'
            ],
            [
                'name' => 'Up to 10 Boards',
                'description' => 'Track and analyze up to 10 Jira boards (vs 2 on Free)',
                'icon' => 'bi-kanban'
            ],
            [
                'name' => '50 Analyses/Day',
                'description' => 'Run up to 50 analyses per day (vs 3 on Free)',
                'icon' => 'bi-graph-up'
            ],
            [
                'name' => 'Image Analysis',
                'description' => 'AI examines attached screenshots, mockups, and diagrams when analyzing ticket clarity',
                'icon' => 'bi-image'
            ],
        ];
    }

    /**
     * Compare tiers for display
     *
     * @return array Comparison data
     */
    public static function getTierComparison(): array {
        return [
            [
                'feature' => 'Tracked Boards',
                'free' => '2',
                'pro' => '10',
                'enterprise' => 'Unlimited'
            ],
            [
                'feature' => 'Analyses per Day',
                'free' => '3',
                'pro' => '50',
                'enterprise' => 'Unlimited'
            ],
            [
                'feature' => 'Priority Weights',
                'free' => false,
                'pro' => true,
                'enterprise' => true
            ],
            [
                'feature' => 'Engineering Goals',
                'free' => false,
                'pro' => true,
                'enterprise' => true
            ],
            [
                'feature' => 'Clarity Analysis',
                'free' => false,
                'pro' => true,
                'enterprise' => true
            ],
            [
                'feature' => 'Stakeholder Info',
                'free' => false,
                'pro' => true,
                'enterprise' => true
            ],
            [
                'feature' => 'Repository Integration',
                'free' => false,
                'pro' => false,
                'enterprise' => true
            ],
            [
                'feature' => 'AI Confidence Scoring',
                'free' => false,
                'pro' => false,
                'enterprise' => true
            ],
            [
                'feature' => 'Image Analysis',
                'free' => false,
                'pro' => true,
                'enterprise' => true
            ],
            [
                'feature' => 'AI Developer Agent',
                'free' => false,
                'pro' => false,
                'enterprise' => true
            ],
            [
                'feature' => 'GitHub/Bitbucket Integration',
                'free' => false,
                'pro' => false,
                'enterprise' => true
            ],
            [
                'feature' => 'Automated Pull Requests',
                'free' => false,
                'pro' => false,
                'enterprise' => true
            ],
            [
                'feature' => 'Jira Write Access',
                'free' => false,
                'pro' => false,
                'enterprise' => true
            ],
            [
                'feature' => 'Webhook Integrations',
                'free' => false,
                'pro' => false,
                'enterprise' => true
            ],
        ];
    }

    /**
     * Get all Enterprise features for display
     *
     * @return array Feature descriptions
     */
    public static function getEnterpriseFeatureList(): array {
        return [
            [
                'name' => 'AI Developer Agent',
                'description' => 'Autonomous AI agent reads Jira tickets, analyzes requirements, and implements code changes using Claude',
                'icon' => 'bi-robot'
            ],
            [
                'name' => 'GitHub Integration',
                'description' => 'Connect GitHub repositories with OAuth, enabling automated branch creation and pull requests',
                'icon' => 'bi-github'
            ],
            [
                'name' => 'Bitbucket Integration',
                'description' => 'Connect Bitbucket repositories through Atlassian OAuth for seamless workflow automation',
                'icon' => 'bi-git'
            ],
            [
                'name' => 'Automated Pull Requests',
                'description' => 'AI automatically creates pull requests with detailed descriptions linking back to Jira tickets',
                'icon' => 'bi-arrow-left-right'
            ],
            [
                'name' => 'Jira Clarification',
                'description' => 'AI posts clarifying questions directly to Jira tickets when requirements are unclear',
                'icon' => 'bi-chat-left-quote'
            ],
            [
                'name' => 'Webhook Triggers',
                'description' => 'Resume AI jobs automatically when Jira tickets are updated or questions are answered',
                'icon' => 'bi-lightning'
            ],
            [
                'name' => 'Custom API Keys',
                'description' => 'Use your own Anthropic API key for AI operations with secure encrypted storage',
                'icon' => 'bi-key'
            ],
            [
                'name' => 'Unlimited Everything',
                'description' => 'No limits on boards, analyses, AI jobs, or repository connections',
                'icon' => 'bi-infinity'
            ],
        ];
    }
}
