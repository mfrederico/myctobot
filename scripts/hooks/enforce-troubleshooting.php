#!/usr/bin/env php
<?php
/**
 * PreToolUse hook to enforce proper troubleshooting before drastic actions.
 *
 * Blocks overzealous commands like service restarts, deployment assumptions,
 * and server-level fixes that should only happen AFTER checking application logs.
 *
 * Per CLAUDE.md: "When debugging issues (404s, 500s, unexpected behavior),
 * check the application logs FIRST before trying other diagnostic approaches"
 */

// Commands that suggest drastic troubleshooting without proper diagnosis
$blockedPatterns = [
    // Service restarts
    [
        'pattern' => '/\b(systemctl|service)\s+(restart|reload|stop|start)\s+(nginx|apache|httpd|php-fpm|mysql|mariadb|postgresql)/i',
        'reason' => 'Service restart attempted',
        'suggestion' => 'Check log/app-*.log first to identify the actual error before restarting services.'
    ],
    // Nginx specific
    [
        'pattern' => '/\bnginx\s+-s\s+(reload|restart|stop)/i',
        'reason' => 'Nginx restart/reload attempted',
        'suggestion' => 'Check log/app-*.log first. Most 404/500 errors are application-level, not nginx issues.'
    ],
    // Clearing all caches without diagnosis
    [
        'pattern' => '/\brm\s+-rf?\s+.*\/(cache|tmp|var\/cache)\b/i',
        'reason' => 'Manual cache purge attempted',
        'suggestion' => 'Use php scripts/resetcache.php instead of manual rm commands.'
    ],
    // Git pull on production as a "fix"
    [
        'pattern' => '/\bgit\s+pull\b.*--force/i',
        'reason' => 'Forced git pull attempted',
        'suggestion' => 'Check log/app-*.log first. The issue may not be missing code.'
    ],
    // SSH to remote servers for "deployment"
    [
        'pattern' => '/\bssh\s+.*\b(deploy|sync|restart|reload)\b/i',
        'reason' => 'Remote deployment/restart via SSH',
        'suggestion' => 'Check log/app-*.log first. The error message will identify if deployment is actually needed.'
    ],
    // Docker restarts
    [
        'pattern' => '/\bdocker(-compose)?\s+(restart|stop|down|up\s+-d)/i',
        'reason' => 'Docker container restart attempted',
        'suggestion' => 'Check log/app-*.log first to identify the actual error before restarting containers.'
    ],
    // Apache specific
    [
        'pattern' => '/\bapachectl\s+(restart|graceful|stop)/i',
        'reason' => 'Apache restart attempted',
        'suggestion' => 'Check log/app-*.log first. Most errors are application-level, not Apache issues.'
    ],
    // PHP-FPM specific
    [
        'pattern' => '/\bkill\s+.*php-fpm/i',
        'reason' => 'PHP-FPM kill attempted',
        'suggestion' => 'Check log/app-*.log first before killing PHP processes.'
    ],
];

// Read hook input from stdin
$input = file_get_contents('php://stdin');
$hookInput = json_decode($input, true);

if (!$hookInput) {
    echo json_encode(['decision' => 'approve']);
    exit(0);
}

$toolName = $hookInput['tool_name'] ?? '';
$toolInput = $hookInput['tool_input'] ?? [];

// Only check Bash commands
if ($toolName !== 'Bash') {
    echo json_encode(['decision' => 'approve']);
    exit(0);
}

$command = $toolInput['command'] ?? '';

// Check for blocked patterns
foreach ($blockedPatterns as $blocked) {
    if (preg_match($blocked['pattern'], $command)) {
        $message = "TROUBLESHOOTING CHECKLIST REQUIRED\n\n";
        $message .= "Blocked: {$blocked['reason']}\n\n";
        $message .= "Before taking this action, verify:\n";
        $message .= "1. Have you checked log/app-*.log for the actual error?\n";
        $message .= "2. Does the log show a deployment/server issue, or an application bug?\n";
        $message .= "3. What is the EXACT error message from the logs?\n\n";
        $message .= "Standard troubleshooting tools:\n";
        $message .= "- Check logs: tail -50 log/app-*.log | grep -i error\n";
        $message .= "- Reset cache: php scripts/resetcache.php\n\n";
        $message .= "Suggestion: {$blocked['suggestion']}\n\n";
        $message .= "If you've confirmed via logs that this action is necessary, ";
        $message .= "explain the log evidence to the user first.";

        echo json_encode([
            'decision' => 'block',
            'reason' => $message
        ]);
        exit(0);
    }
}

echo json_encode(['decision' => 'approve']);
