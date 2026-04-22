<?php
/**
 * 90_Cleanup — trash scaffold beans registered via $_defer(), plus legacy
 * named scaffold vars from older seeds.
 *
 * Seeds SHOULD call $_defer($bean) instead of R::trash($bean) directly, so
 * parent beans stay available for child seeders that reference them. This
 * file drains $_deferred in reverse registration order (children first,
 * parents last) so FK constraints hold.
 *
 * The legacy block below trashes specific named variables ($member, $board,
 * $directive, $repo). Those patterns predate the $_defer harness. Keep them
 * working so older seeds don't need to be touched; new seeds should use
 * $_defer exclusively.
 */

// Modern path — drain $_deferred in reverse (children before parents)
if (isset($_deferred) && !empty($_deferred)) {
    foreach (array_reverse($_deferred) as $_bean) {
        try {
            \RedBeanPHP\R::trash($_bean);
        } catch (\Exception $e) {
            // FK constraint may prevent deletion — safe to ignore for
            // scaffold beans (they're placeholders, not real data)
        }
    }
    $_deferred = [];
}

// Legacy path — named scaffold vars set by older seeds before the harness
// existed. Trash order (child → parent) matches the existing FK graph.
if (isset($directive) && $directive->id) {
    try { \RedBeanPHP\R::trash($directive); } catch (\Exception $e) {}
}
if (isset($repo) && $repo->id) {
    try { \RedBeanPHP\R::trash($repo); } catch (\Exception $e) {}
}
if (isset($board) && $board->id) {
    try { \RedBeanPHP\R::trash($board); } catch (\Exception $e) {}
}
if (isset($member) && $member->id) {
    try { \RedBeanPHP\R::trash($member); } catch (\Exception $e) {}
}
