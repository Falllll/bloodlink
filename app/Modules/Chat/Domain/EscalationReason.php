<?php

declare(strict_types=1);

namespace App\Modules\Chat\Domain;

/**
 * Kenapa percakapan dipindahkan ke petugas (kartu 495). Nilai harus sama dengan
 * enum kolom conversations.escalation_reason. Enum, bukan kalimat bebas: kotak
 * masuk menampilkannya sebagai label dan laporan kelak menghitungnya.
 */
enum EscalationReason: string
{
    case PERSONAL_MEDICAL_QUESTION = 'personal_medical_question';
    case LAB_RESULT_QUESTION = 'lab_result_question';
    case COMPLAINT = 'complaint';
    case OTHER = 'other';
}
