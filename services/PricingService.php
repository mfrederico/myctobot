<?php
/**
 * Pricing Service
 * Centralized pricing management for all MyCTOBot products
 *
 * Reads pricing from conf/pricing.ini and provides calculated values
 * like yearly pricing with discounts.
 */

namespace app\services;

class PricingService {

    private static ?array $config = null;
    private static string $configFile = 'conf/pricing.ini';

    /**
     * Load pricing configuration
     */
    private static function loadConfig(): array {
        if (self::$config === null) {
            $path = defined('BASE_PATH') ? BASE_PATH . '/' . self::$configFile : self::$configFile;

            if (!file_exists($path)) {
                throw new \RuntimeException("Pricing config not found: {$path}");
            }

            self::$config = parse_ini_file($path, true);

            if (self::$config === false) {
                throw new \RuntimeException("Failed to parse pricing config: {$path}");
            }
        }

        return self::$config;
    }

    /**
     * Get all pricing for a specific product
     *
     * @param string $product Product key (e.g., 'ai-developer', 'pipelines')
     * @return array Product pricing data
     */
    public static function getProduct(string $product): array {
        $config = self::loadConfig();

        if (!isset($config[$product])) {
            throw new \InvalidArgumentException("Unknown product: {$product}");
        }

        return $config[$product];
    }

    /**
     * Get a specific pricing value
     *
     * @param string $product Product key
     * @param string $key Pricing key (e.g., 'starter_monthly', 'pro_monthly')
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public static function get(string $product, string $key, $default = null) {
        $config = self::loadConfig();

        return $config[$product][$key] ?? $default;
    }

    /**
     * Get global settings
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    public static function getGlobal(string $key, $default = null) {
        $config = self::loadConfig();

        return $config['global'][$key] ?? $default;
    }

    /**
     * Get currency symbol
     */
    public static function getCurrencySymbol(): string {
        return self::getGlobal('currency_symbol', '$');
    }

    /**
     * Calculate yearly price from monthly with discount
     *
     * @param float $monthly Monthly price
     * @param float $discount Discount percentage (e.g., 0.20 for 20% off)
     * @return float Yearly price
     */
    public static function calculateYearly(float $monthly, float $discount = 0.20): float {
        return $monthly * 12 * (1 - $discount);
    }

    /**
     * Get yearly price for a product tier
     *
     * @param string $product Product key
     * @param string $tier Tier name (starter, pro, enterprise)
     * @return float|null Yearly price or null if not applicable
     */
    public static function getYearlyPrice(string $product, string $tier): ?float {
        $monthlyKey = $tier . '_monthly';
        $monthly = self::get($product, $monthlyKey);

        if ($monthly === null) {
            return null;
        }

        $discount = self::get($product, 'yearly_discount', 0.20);

        return self::calculateYearly((float) $monthly, (float) $discount);
    }

    /**
     * Get monthly price for a product tier
     *
     * @param string $product Product key
     * @param string $tier Tier name (starter, pro, enterprise)
     * @return float|null Monthly price or null if not found
     */
    public static function getMonthlyPrice(string $product, string $tier): ?float {
        $monthly = self::get($product, $tier . '_monthly');
        return $monthly !== null ? (float) $monthly : null;
    }

    /**
     * Format price for display
     *
     * @param float $price Price value
     * @param bool $showCents Whether to show cents
     * @return string Formatted price (e.g., "$150" or "$758.40")
     */
    public static function format(float $price, bool $showCents = false): string {
        $symbol = self::getCurrencySymbol();

        if ($showCents || fmod($price, 1) !== 0.0) {
            return $symbol . number_format($price, 2);
        }

        return $symbol . number_format($price, 0);
    }

    /**
     * Get complete pricing data for a product (for views)
     *
     * @param string $product Product key
     * @return array Pricing data with calculated values
     */
    public static function getPricingData(string $product): array {
        $data = self::getProduct($product);
        $symbol = self::getCurrencySymbol();

        // Add calculated yearly prices for subscription products
        $discount = $data['yearly_discount'] ?? 0.20;

        foreach (['starter', 'pro', 'enterprise'] as $tier) {
            $monthlyKey = $tier . '_monthly';
            if (isset($data[$monthlyKey])) {
                $monthly = (float) $data[$monthlyKey];
                $yearly = self::calculateYearly($monthly, (float) $discount);

                $data[$tier . '_yearly'] = $yearly;
                $data[$tier . '_monthly_formatted'] = $symbol . number_format($monthly, 0);
                $data[$tier . '_yearly_formatted'] = $symbol . number_format($yearly, 0);
                $data[$tier . '_yearly_monthly'] = $yearly / 12; // monthly equivalent when paid yearly
                $data[$tier . '_yearly_monthly_formatted'] = $symbol . number_format($yearly / 12, 0);
                $data[$tier . '_savings'] = ($monthly * 12) - $yearly;
                $data[$tier . '_savings_formatted'] = $symbol . number_format(($monthly * 12) - $yearly, 0);
            }
        }

        // Format one-time prices if present
        foreach (['small', 'medium', 'large', 'enterprise', 'starter', 'pro', 'base'] as $tier) {
            foreach (['project', 'theme', 'price'] as $suffix) {
                $key = $tier . '_' . $suffix;
                if (isset($data[$key]) && is_numeric($data[$key])) {
                    $data[$key . '_formatted'] = $symbol . number_format((float) $data[$key], 0);
                }
            }
        }

        return $data;
    }

    /**
     * Get all products pricing data (for main page)
     *
     * @return array All products with pricing
     */
    public static function getAllProducts(): array {
        $products = [
            'ai-developer',
            'pipelines',
            'code-review',
            'php-modernization',
            'shopify-themes',
            'enterprise'
        ];

        $data = [];
        foreach ($products as $product) {
            $data[$product] = self::getPricingData($product);
        }

        return $data;
    }

    /**
     * Get trial days
     */
    public static function getTrialDays(): int {
        return (int) self::getGlobal('trial_days', 14);
    }

    /**
     * Clear cached config (useful for testing)
     */
    public static function clearCache(): void {
        self::$config = null;
    }
}
