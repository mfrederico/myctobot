# Cache System - Standardized Field Names

This document defines the standard field names returned by cache statistics methods.

## PermissionCache::getStats()

Returns an array with the following fields:

### Basic Cache Status
- `apcu_available` (bool) - Whether APCu extension is available
- `cache_loaded` (bool) - Whether permissions are loaded in process memory
- `in_apcu` (bool) - Whether cache key exists in APCu shared memory

### Permission Data
- `count` (int) - Number of cached permissions
- `memory` (int) - Memory usage in bytes

### Performance Metrics
- `hits` (int) - Number of cache hits (loaded from APCu)
- `misses` (int) - Number of cache misses (loaded from database)
- `hit_rate` (float) - Cache hit rate as percentage (0-100)

### Cache Version
- `cache_version` (int) - Current cache version timestamp from file

### Optional APCu Details (when in_apcu = true)
- `apcu_ttl` (int|null) - Time to live for APCu entry
- `apcu_hits_on_key` (int|null) - APCu hit count for this specific key

## Usage in Views and Scripts

### CLI Script (scripts/resetcache.php)
```php
$stats = PermissionCache::getStats();
echo $stats['count'];                // Number of permissions
echo $stats['memory'];               // Memory in bytes
echo $stats['cache_loaded'];         // Process memory status
echo $stats['apcu_available'];       // APCu status
echo $stats['in_apcu'];              // Whether cached in APCu
echo $stats['hits'];                 // Cache hits
echo $stats['misses'];               // Cache misses
echo $stats['hit_rate'];             // Hit rate percentage
echo $stats['cache_version'];        // Version timestamp
```

### Admin Dashboard (admin/index.php)
```php
$cache_stats = \app\PermissionCache::getStats();
echo $cache_stats['count'];          // Number of permissions
echo $cache_stats['apcu_available']; // APCu status
echo $cache_stats['in_apcu'];        // Whether cached in APCu
```

### Cache Management Page (admin/cache.php)
```php
$cache_stats = \app\PermissionCache::getStats();
echo $cache_stats['hit_rate'];       // Hit rate percentage
echo $cache_stats['hits'];           // Cache hits
echo $cache_stats['misses'];         // Cache misses
echo $cache_stats['count'];          // Cached permissions
echo $cache_stats['memory'];         // Memory in bytes
```

### Controller (Admin.php)
```php
// Pass stats directly to view - no manual mapping needed
$this->viewData['cache_stats'] = \app\PermissionCache::getStats();
```

## Field Consistency

All views and controllers use these exact field names. No aliases or duplicates exist.

**Previously had duplicates (now removed):**
- ~~`permission_count`~~ → Use `count`
- ~~`cache_memory`~~ → Use `memory`
- ~~`apcu_info['hits']`~~ → Use `hits`
- ~~`apcu_info['db_loads']`~~ → Use `misses`

## CLI Mode

When running scripts from the command line, the bootstrap automatically detects CLI mode and:

- **Skips session initialization** to avoid warnings about cookies/headers
- **Maintains full database and logging** functionality
- **Allows cache operations** without needing web context

### Running Cache Scripts

```bash
# Clean output with no warnings
php scripts/resetcache.php

# Other CLI operations work the same
php index.php --control=cache --method=stats
```

The CLI detection uses `php_sapi_name() === 'cli'` to determine runtime environment.
