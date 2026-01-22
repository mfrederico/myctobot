<?php
$bean = \RedBeanPHP\R::dispense('sshkeys');
$bean->member = $member;
$bean->name = 'Schema SSH Key';
$bean->description = 'Schema key description';
$bean->key_type = 'ecdsa';
$bean->public_key = str_repeat('x', 500);
$bean->private_key_encrypted = str_repeat('x', 2000);
$bean->fingerprint = 'SHA256:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
$bean->is_shared = false;
$bean->created_at = date('Y-m-d H:i:s');
$bean->last_used_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);
