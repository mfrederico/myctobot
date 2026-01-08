#!/usr/bin/env php
<?php
/**
 * PreToolUse hook to validate git commit messages.
 * Blocks commits containing emojis or Claude cruft.
 */

function hasEmoji(string $text): bool {
    // Match common emoji ranges
    $emojiPattern = '/[\x{1F600}-\x{1F64F}' .  // emoticons
                    '\x{1F300}-\x{1F5FF}' .    // symbols & pictographs
                    '\x{1F680}-\x{1F6FF}' .    // transport & map symbols
                    '\x{1F1E0}-\x{1F1FF}' .    // flags
                    '\x{2702}-\x{27B0}' .      // dingbats
                    '\x{1F900}-\x{1F9FF}' .    // supplemental symbols
                    '\x{1FA00}-\x{1FA6F}' .    // chess symbols
                    '\x{1FA70}-\x{1FAFF}' .    // symbols extended
                    '\x{2600}-\x{26FF}' .      // misc symbols
                    '\x{1F004}-\x{1F0CF}' .    // playing cards
                    '\x{1F018}-\x{1F270}' .    // various
                    '\x{238C}-\x{2454}' .      // misc technical
                    '\x{2194}-\x{2199}' .      // arrows
                    '\x{21A9}-\x{21AA}' .      // arrows
                    '\x{231A}-\x{231B}' .      // watch/hourglass
                    '\x{23E9}-\x{23F3}' .      // media controls
                    '\x{23F8}-\x{23FA}' .      // media controls
                    '\x{25AA}-\x{25AB}' .      // squares
                    '\x{25B6}' .               // play
                    '\x{25C0}' .               // reverse
                    '\x{25FB}-\x{25FE}' .      // squares
                    '\x{2614}-\x{2615}' .      // umbrella/coffee
                    '\x{2648}-\x{2653}' .      // zodiac
                    '\x{267F}' .               // wheelchair
                    '\x{2693}' .               // anchor
                    '\x{26A1}' .               // lightning
                    '\x{26AA}-\x{26AB}' .      // circles
                    '\x{26BD}-\x{26BE}' .      // sports
                    '\x{26C4}-\x{26C5}' .      // weather
                    '\x{26CE}' .               // ophiuchus
                    '\x{26D4}' .               // no entry
                    '\x{26EA}' .               // church
                    '\x{26F2}-\x{26F3}' .      // fountain/golf
                    '\x{26F5}' .               // sailboat
                    '\x{26FA}' .               // tent
                    '\x{26FD}' .               // fuel pump
                    '\x{2702}' .               // scissors
                    '\x{2705}' .               // check mark
                    '\x{2708}-\x{270D}' .      // airplane-writing hand
                    '\x{270F}' .               // pencil
                    '\x{2712}' .               // black nib
                    '\x{2714}' .               // check mark
                    '\x{2716}' .               // x mark
                    '\x{271D}' .               // cross
                    '\x{2721}' .               // star of david
                    '\x{2728}' .               // sparkles
                    '\x{2733}-\x{2734}' .      // eight spoked
                    '\x{2744}' .               // snowflake
                    '\x{2747}' .               // sparkle
                    '\x{274C}' .               // cross mark
                    '\x{274E}' .               // cross mark
                    '\x{2753}-\x{2755}' .      // question marks
                    '\x{2757}' .               // exclamation
                    '\x{2763}-\x{2764}' .      // heart
                    '\x{2795}-\x{2797}' .      // math
                    '\x{27A1}' .               // arrow
                    '\x{27B0}' .               // curly loop
                    '\x{27BF}' .               // double curly loop
                    '\x{2934}-\x{2935}' .      // arrows
                    '\x{2B05}-\x{2B07}' .      // arrows
                    '\x{2B1B}-\x{2B1C}' .      // squares
                    '\x{2B50}' .               // star
                    '\x{2B55}' .               // circle
                    '\x{3030}' .               // wavy dash
                    '\x{303D}' .               // part alternation mark
                    '\x{3297}' .               // circled ideograph
                    '\x{3299}' .               // circled ideograph secret
                    ']/u';

    return (bool) preg_match($emojiPattern, $text);
}

function hasClaudeCruft(string $text): bool {
    $cruftPatterns = [
        '/Generated with \[?Claude/i',
        '/Co-Authored-By:.*Claude/i',
        '/Co-Authored-By:.*Anthropic/i',
        '/Co-Authored-By:.*noreply@anthropic\.com/i',
        '/claude\.com\/claude-code/i',
        '/\[Claude Code\]/i',
    ];

    foreach ($cruftPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }

    return false;
}

// Read hook input from stdin
$input = file_get_contents('php://stdin');
$hookInput = json_decode($input, true);

if (!$hookInput) {
    // Can't parse input, allow through
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

// Check if this is a git commit with -m
if (!preg_match('/\bgit\b.*\bcommit\b.*-m/', $command)) {
    echo json_encode(['decision' => 'approve']);
    exit(0);
}

// Check for issues
$issues = [];

if (hasEmoji($command)) {
    $issues[] = 'Commit message contains emojis';
}

if (hasClaudeCruft($command)) {
    $issues[] = 'Commit message contains Claude cruft (Generated with Claude, Co-Authored-By: Claude, etc.)';
}

if (!empty($issues)) {
    echo json_encode([
        'decision' => 'block',
        'reason' => 'Git commit blocked: ' . implode('; ', $issues) . '. Please remove before committing.'
    ]);
} else {
    echo json_encode(['decision' => 'approve']);
}
