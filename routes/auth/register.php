<?php
declare(strict_types=1);

// Public self-registration has been disabled.
// User creation must go through /users/createUsers, which requires an authenticated Admin.
require_once 'includes/bootstrap.php';

jsonResponse([
    'status' => 'Failed',
    'message' => 'Route not found.'
], 404);
