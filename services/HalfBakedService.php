<?php

namespace app\services;

use HalfBaked\Pipeline\BakedStepInterface;
use HalfBaked\Pipeline\Pipeline;

/**
 * HalfBaked integration for myctobot.
 *
 * Uses voidlux-coder (our specialist PHP model) to prototype code generation
 * pipelines, then bakes them into deterministic PHP once patterns stabilize.
 *
 * Usage from any myctobot service/controller:
 *
 *   $hb = new HalfBakedService('http://localhost:11434', 'voidlux-coder');
 *
 *   // Generate a FlightPHP endpoint (dough mode - LLM does the work)
 *   $result = $hb->endpointPipeline()->run([
 *       'method' => 'GET',
 *       'path' => '/api/v1/products',
 *       'description' => 'List all products with pagination',
 *       'auth_required' => 'yes',
 *   ]);
 *
 *   // After 10+ runs, bake the pipeline steps into PHP
 *   $hb->endpointPipeline()->bake('generate_endpoint');
 */
class HalfBakedService
{
    private string $ollamaHost;
    private string $model;
    private string $logDir;

    public function __construct(
        string $ollamaHost = 'http://localhost:11434',
        string $model = 'voidlux-coder',
        ?string $logDir = null,
    ) {
        $this->ollamaHost = $ollamaHost;
        $this->model = $model;
        $this->logDir = $logDir ?? sys_get_temp_dir() . '/halfbaked-myctobot';
    }

    /**
     * Pipeline for generating FlightPHP + RedBeanPHP endpoints.
     *
     * Steps:
     *  1. design_schema — Determine RedBean model + fields from description
     *  2. generate_endpoint — Generate the PHP controller method
     *  3. generate_test — Generate a PHPUnit test for the endpoint
     */
    public function endpointPipeline(): Pipeline
    {
        return Pipeline::create('endpoint_generator', logDir: $this->logDir, ollamaHost: $this->ollamaHost)
            ->step('design_schema')
                ->using($this->model)
                ->systemPrompt('You are a PHP database schema designer specializing in RedBeanPHP. Design clean, normalized schemas.')
                ->prompt(<<<'PROMPT'
                    Design a RedBeanPHP schema for this endpoint:

                    Method: {{method}}
                    Path: {{path}}
                    Description: {{description}}

                    Return the bean type name, required fields with types, and any relationships.
                    Use RedBeanPHP conventions (snake_case bean names, _id for foreign keys).
                    PROMPT)
                ->output([
                    'bean_type' => 'string',
                    'fields' => 'array',
                    'relationships' => 'array',
                    'indexes' => 'array',
                ])
            ->step('generate_endpoint')
                ->using($this->model)
                ->systemPrompt('You are a PHP code generator specializing in FlightPHP 3.x + RedBeanPHP. Generate clean, production-ready endpoint code. Use R:: for RedBeanPHP operations. Use Flight::json() for responses. Include proper error handling and input validation.')
                ->prompt(<<<'PROMPT'
                    Generate a FlightPHP endpoint method:

                    Method: {{method}}
                    Path: {{path}}
                    Description: {{description}}
                    Auth Required: {{auth_required}}
                    Bean Type: {{bean_type}}
                    Fields: {{fields}}

                    Return the complete PHP method body as a string.
                    Include input validation, RedBeanPHP queries, and Flight::json() responses.
                    Follow PSR-12 coding standards.
                    PROMPT)
                ->output([
                    'code' => 'string',
                    'route_registration' => 'string',
                    'dependencies' => 'array',
                ])
            ->step('generate_test')
                ->using($this->model)
                ->systemPrompt('You are a PHP test generator. Write concise PHPUnit tests for FlightPHP endpoints.')
                ->prompt(<<<'PROMPT'
                    Generate a PHPUnit test for this endpoint:

                    Method: {{method}}
                    Path: {{path}}
                    Code: {{code}}

                    Write 2-3 test methods covering: success case, validation failure, not-found (if applicable).
                    PROMPT)
                ->output([
                    'test_code' => 'string',
                    'test_class_name' => 'string',
                ])
            ->build();
    }

    /**
     * Pipeline for analyzing and refactoring existing code.
     *
     * Takes existing PHP code and produces improved version with explanation.
     */
    public function refactorPipeline(): Pipeline
    {
        return Pipeline::create('code_refactor', logDir: $this->logDir, ollamaHost: $this->ollamaHost)
            ->step('analyze')
                ->using($this->model)
                ->systemPrompt('You are a PHP code reviewer. Identify issues and improvement opportunities.')
                ->prompt(<<<'PROMPT'
                    Analyze this PHP code for issues and improvements:

                    ```php
                    {{code}}
                    ```

                    Context: {{context}}

                    Identify: security issues, performance problems, code smells, and improvement opportunities.
                    PROMPT)
                ->output([
                    'issues' => 'array',
                    'severity' => 'string',
                    'suggestions' => 'array',
                ])
            ->step('refactor')
                ->using($this->model)
                ->systemPrompt('You are a PHP code refactoring expert. Apply the suggested improvements while maintaining backward compatibility.')
                ->prompt(<<<'PROMPT'
                    Refactor this code based on the analysis:

                    Original code:
                    ```php
                    {{code}}
                    ```

                    Issues found: {{issues}}
                    Suggestions: {{suggestions}}

                    Return the refactored code and a summary of changes made.
                    PROMPT)
                ->output([
                    'refactored_code' => 'string',
                    'changes_summary' => 'array',
                ])
            ->build();
    }

    /**
     * Get stats for all pipelines.
     */
    public function getStats(): array
    {
        $stats = [];
        $pipelines = ['endpoint_generator', 'code_refactor'];

        foreach ($pipelines as $name) {
            $logDir = $this->logDir;
            if (is_dir($logDir)) {
                $files = glob($logDir . '/*.jsonl');
                $stats[$name] = [
                    'log_files' => count($files),
                    'log_dir' => $logDir,
                ];
            }
        }

        return $stats;
    }
}
