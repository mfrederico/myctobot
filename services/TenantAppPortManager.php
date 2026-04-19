<?php
/**
 * TenantAppPortManager - Port allocation for tenant apps
 *
 * Manages port allocation in the range 9600-9699 for tenant app servers.
 * Checks both database allocations and actual system port usage.
 */

namespace app\services;

use app\Bean;

class TenantAppPortManager
{
    /**
     * Port range for tenant apps
     */
    public const PORT_RANGE_START = 9600;
    public const PORT_RANGE_END = 9699;

    /**
     * Reserved ports (used by other services)
     */
    private const RESERVED_PORTS = [
        9502,  // PaddleOCR service
    ];

    /**
     * Allocate the next available port
     *
     * @return int The allocated port number
     * @throws \RuntimeException If no ports are available
     */
    public function allocate(): int
    {
        for ($port = self::PORT_RANGE_START; $port <= self::PORT_RANGE_END; $port++) {
            if (!$this->isPortInUse($port)) {
                return $port;
            }
        }

        throw new \RuntimeException(
            sprintf('No available ports in range %d-%d', self::PORT_RANGE_START, self::PORT_RANGE_END)
        );
    }

    /**
     * Check if a port is in use
     *
     * Checks both:
     * 1. Database allocations (tenantapps table)
     * 2. Actual system port usage via lsof
     *
     * @param int $port Port number to check
     * @return bool True if port is in use
     */
    public function isPortInUse(int $port): bool
    {
        // Check reserved ports
        if (in_array($port, self::RESERVED_PORTS)) {
            return true;
        }

        // Check database for allocated ports (any status - unique constraint)
        $allocated = Bean::findOne(
            'tenantapps',
            ' port = ? ',
            [$port]
        );

        if ($allocated) {
            return true;
        }

        // Check actual system port usage
        return $this->isSystemPortInUse($port);
    }

    /**
     * Check if a port is in use at the system level
     *
     * @param int $port Port number to check
     * @return bool True if port is in use
     */
    public function isSystemPortInUse(int $port): bool
    {
        $output = [];
        $cmd = sprintf('lsof -i :%d -t 2>/dev/null', $port);
        exec($cmd, $output, $returnCode);

        return !empty($output);
    }

    /**
     * Release a port allocation
     *
     * Note: This only clears the database allocation.
     * The actual port is released when the process stops.
     *
     * @param int $port Port number to release
     */
    public function release(int $port): void
    {
        // Update any apps using this port to clear the port
        $apps = Bean::find('tenantapps', ' port = ? ', [$port]);
        foreach ($apps as $app) {
            // Only clear port if app is not running
            if (!in_array($app->status, ['running', 'starting'])) {
                $app->port = null;
                Bean::store($app);
            }
        }
    }

    /**
     * Get all allocated ports
     *
     * @return array Array of [port => app_slug] mappings
     */
    public function getAllocatedPorts(): array
    {
        $allocated = [];
        $apps = Bean::find('tenantapps', ' port IS NOT NULL ');

        foreach ($apps as $app) {
            $allocated[(int) $app->port] = $app->slug;
        }

        return $allocated;
    }

    /**
     * Get port usage statistics
     *
     * @return array Statistics about port usage
     */
    public function getStats(): array
    {
        $totalPorts = self::PORT_RANGE_END - self::PORT_RANGE_START + 1;
        $reservedCount = count(self::RESERVED_PORTS);
        $allocatedPorts = $this->getAllocatedPorts();
        $allocatedCount = count($allocatedPorts);
        $availableCount = $totalPorts - $reservedCount - $allocatedCount;

        return [
            'range_start' => self::PORT_RANGE_START,
            'range_end' => self::PORT_RANGE_END,
            'total_ports' => $totalPorts,
            'reserved_count' => $reservedCount,
            'reserved_ports' => self::RESERVED_PORTS,
            'allocated_count' => $allocatedCount,
            'allocated_ports' => $allocatedPorts,
            'available_count' => $availableCount,
        ];
    }

    /**
     * Find apps using orphaned ports
     *
     * Returns apps that have a port allocated but the port is not
     * actually in use at the system level.
     *
     * @return array Array of app beans with orphaned ports
     */
    public function findOrphanedAllocations(): array
    {
        $orphaned = [];
        $apps = Bean::find('tenantapps', ' port IS NOT NULL AND status = ? ', ['running']);

        foreach ($apps as $app) {
            if (!$this->isSystemPortInUse((int) $app->port)) {
                $orphaned[] = $app;
            }
        }

        return $orphaned;
    }
}
