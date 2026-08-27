<?php
// includes/role_switcher.php

require_once __DIR__ . '/auth_helpers.php';

/**
 * Switch user role safely.
 */
function switchRole(string $newRole): bool {
    $newRole = strtolower(trim($newRole));
    return setActiveRole($newRole);
}

