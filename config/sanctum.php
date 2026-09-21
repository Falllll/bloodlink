<?php

declare(strict_types=1);

return [
    // Auth di repo ini bearer token stateless. Route SPA csrf-cookie
    // (middleware 'web') sengaja dimatikan supaya repo tetap headless.
    'routes' => false,
];
