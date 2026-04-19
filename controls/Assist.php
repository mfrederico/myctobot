<?php
/**
 * Assist Controller - Detached Chat Window
 *
 * Serves the standalone assistant chat for popup/detached mode.
 * Uses same WebSocket connection as the inline pageassist widget.
 */

namespace app;

use \Flight as Flight;

class Assist extends BaseControls\Control {

    /**
     * Standalone chat page for detached/popup mode
     */
    public function chat() {
        // Load assistant configuration
        $assistConfigFile = dirname(__DIR__) . '/conf/assistant.ini';
        $assistConfig = file_exists($assistConfigFile) ? parse_ini_file($assistConfigFile, true) : [];

        $assistName = $assistConfig['general']['name'] ?? 'MyCTOBOT Assistant';
        $assistWsPort = $assistConfig['websocket']['port'] ?? 9510;

        $isLocalhost = isset($_SERVER['HTTP_HOST']) && (
            strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
            strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false ||
            strpos($_SERVER['HTTP_HOST'], 'dev.') === 0
        );

        $assistWorkspace = $_SESSION['workspace_slug'] ?? null;
        if (!$assistWorkspace) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $parts = explode('.', $host);
            if (count($parts) >= 3) {
                $assistWorkspace = $parts[0];
            }
        }

        $this->render('assist/chat', [
            'title' => $assistName,
            'assistName' => $assistName,
            'assistWsPort' => $assistWsPort,
            'isLocalhost' => $isLocalhost,
            'assistWorkspace' => $assistWorkspace,
        ], false);
    }
}
