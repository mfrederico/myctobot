<?php
/**
 * ServicePortManager - Unified port allocation for apps and engines
 *
 * Manages port allocation in the range 9600-9699 for all service types
 * (tenant apps and pipeline engines). Checks both database allocations
 * and actual system port usage.
 */

namespace app\services;

use app\Bean;

class ServicePortManager
{
    public const PORT_RANGE_START = 9600;
    public const PORT_RANGE_END = 9699;

    private const RESERVED_PORTS = [
        9502,  // PaddleOCR service
    ];

    /**
     * Allocate the next available port
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
     * Check if a port is in use (database + system level)
     */
    public function isPortInUse(int $port): bool
    {
        if (in_array($port, self::RESERVED_PORTS)) {
            return true;
        }

        // Check database for allocated ports (any service type, any status)
        $allocated = Bean::findOne('tenantapps', ' port = ? ', [$port]);
        if ($allocated) {
            return true;
        }

        return $this->isSystemPortInUse($port);
    }

    /**
     * Check if a port is in use at the system level
     */
    public function isSystemPortInUse(int $port): bool
    {
        $output = [];
        $cmd = sprintf('lsof -i :%d -t 2>/dev/null', $port);
        exec($cmd, $output, $returnCode);

        return !empty($output);
    }

    /**
     * Release a port allocation (only if service is not running)
     */
    public function release(int $port): void
    {
        $services = Bean::find('tenantapps', ' port = ? ', [$port]);
        foreach ($services as $service) {
            if (!in_array($service->status, ['running', 'starting'])) {
                $service->port = null;
                Bean::store($service);
            }
        }
    }

    /**
     * Get all allocated ports with service type
     */
    public function getAllocatedPorts(): array
    {
        $allocated = [];
        $services = Bean::find('tenantapps', ' port IS NOT NULL ');

        foreach ($services as $service) {
            $allocated[(int) $service->port] = [
                'slug' => $service->slug,
                'service_type' => $service->service_type ?? 'app',
                'status' => $service->status,
            ];
        }

        return $allocated;
    }

    /**
     * Get port usage statistics
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
            'allocated_count' => $allocatedCount,
            'allocated_ports' => $allocatedPorts,
            'available_count' => $availableCount,
        ];
    }
}
