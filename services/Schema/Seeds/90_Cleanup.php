<?php
// Clean up temporary beans used for FK creation
// Only delete the ones that need to persist for FK relationships

if (isset($directive) && $directive->id) {
    \RedBeanPHP\R::trash($directive);
}
if (isset($repo) && $repo->id) {
    \RedBeanPHP\R::trash($repo);
}
if (isset($board) && $board->id) {
    \RedBeanPHP\R::trash($board);
}
if (isset($member) && $member->id) {
    \RedBeanPHP\R::trash($member);
}
