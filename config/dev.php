<?php

declare(strict_types=1);

return [
    // Password akun seeder dev. Tidak pernah dipakai di produksi — DevUserSeeder
    // menolak jalan di sana.
    'seed_password' => env('DEV_SEED_PASSWORD', 'dev-only-not-a-real-password'),
];
