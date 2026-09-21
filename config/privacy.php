<?php

declare(strict_types=1);

$retentionYears = env('PRIVACY_RETENTION_HEALTH_YEARS');

return [
    'consent' => [
        'questionnaire_version' => env('CONSENT_QUESTIONNAIRE_VERSION', '2026.1'),
    ],
    'retention' => [
        // BELUM DIVERIFIKASI. Perintah privacy:purge-expired menolak jalan selama ini null.
        // Baris kosong di .env (PRIVACY_RETENTION_HEALTH_YEARS=) dibaca sebagai string kosong,
        // bukan null, jadi harus diperlakukan sebagai "belum diisi".
        'health_years' => $retentionYears === null || $retentionYears === ''
            ? null
            : (int) $retentionYears,
    ],
];
