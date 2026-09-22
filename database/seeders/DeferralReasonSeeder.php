<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DeferralReason;
use App\Modules\Donor\Domain\DeferralDurationUnit;
use App\Modules\Donor\Domain\DeferralType;
use Illuminate\Database\Seeder;

final class DeferralReasonSeeder extends Seeder
{
    private const string JURISDICTION = 'WHO';

    public function run(): void
    {
        $rows = $this->rows();

        DeferralReason::query()->upsert(
            $rows,
            ['jurisdiction', 'code'],
            ['type', 'default_duration_value', 'default_duration_unit', 'label', 'anchor_note', 'source_reference', 'is_active']
        );

        $codes = array_column($rows, 'code');

        DeferralReason::query()
            ->where('jurisdiction', self::JURISDICTION)
            ->whereNotIn('code', $codes)
            ->update(['is_active' => false]);
    }

    /**
     * @return list<array<string, string|int|bool|null>>
     */
    private function rows(): array
    {
        return [
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'PREGNANT_OR_LACTATING',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => null,
                'default_duration_unit' => null,
                'label' => 'Hamil atau menyusui',
                'anchor_note' => 'Berlaku selama kondisi berlangsung; tanggal akhir diisi petugas.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Pregnancy, lactation and menstruation (§4.8.1)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'POST_DELIVERY_OR_TERMINATION',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 6,
                'default_duration_unit' => DeferralDurationUnit::MONTHS->value,
                'label' => 'Pasca persalinan atau terminasi kehamilan',
                'anchor_note' => 'Dihitung dari tanggal persalinan atau terminasi.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Pregnancy, lactation and menstruation (§4.8.1)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'TATTOO_PIERCING_ACUPUNCTURE',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 12,
                'default_duration_unit' => DeferralDurationUnit::MONTHS->value,
                'label' => 'Tato, tindik, atau akupunktur',
                'anchor_note' => 'Dihitung dari tanggal prosedur terakhir.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Cosmetic treatments and rituals (§7.9.5)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'BLOOD_TRANSFUSION_RECEIVED',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 12,
                'default_duration_unit' => DeferralDurationUnit::MONTHS->value,
                'label' => 'Menerima transfusi darah',
                'anchor_note' => 'Dihitung dari tanggal transfusi.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Blood transfusion (§6.3.1)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'MAJOR_SURGERY',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 12,
                'default_duration_unit' => DeferralDurationUnit::MONTHS->value,
                'label' => 'Operasi besar',
                'anchor_note' => 'Dihitung dari tanggal operasi.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Surgery (§6.4)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'FLEXIBLE_ENDOSCOPY',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 12,
                'default_duration_unit' => DeferralDurationUnit::MONTHS->value,
                'label' => 'Endoskopi fleksibel invasif',
                'anchor_note' => 'Dihitung dari tanggal prosedur.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Diagnostic and surgical procedures (§6.4)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'ALLOGENEIC_TISSUE_TRANSPLANT',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 12,
                'default_duration_unit' => DeferralDurationUnit::MONTHS->value,
                'label' => 'Transplantasi jaringan alogenik',
                'anchor_note' => 'Dihitung dari tanggal transplantasi.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Organ, stem cell and tissue transplantation (§6.3.2)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'HEPATITIS_A_E_OR_UNKNOWN',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 12,
                'default_duration_unit' => DeferralDurationUnit::MONTHS->value,
                'label' => 'Hepatitis A, hepatitis E, atau hepatitis penyebab tidak diketahui',
                'anchor_note' => 'Dihitung dari kesembuhan penuh.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Hepatitis A, hepatitis E and hepatitis of unknown origin (§7.3.1)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'TUBERCULOSIS',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 2,
                'default_duration_unit' => DeferralDurationUnit::YEARS->value,
                'label' => 'Tuberkulosis',
                'anchor_note' => 'Dihitung dari konfirmasi sembuh.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Tuberculosis (§7.5.6)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'DENTAL_SIMPLE',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 24,
                'default_duration_unit' => DeferralDurationUnit::HOURS->value,
                'label' => 'Tindakan gigi sederhana',
                'anchor_note' => 'Dihitung dari tanggal tindakan.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Diagnostic and surgical procedures (§6.4)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'DENTAL_EXTRACTION_ENDODONTIC',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 7,
                'default_duration_unit' => DeferralDurationUnit::DAYS->value,
                'label' => 'Cabut gigi atau perawatan endodontik',
                'anchor_note' => 'Dihitung dari tanggal tindakan.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Diagnostic and surgical procedures (§6.4)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'FEVER_NONSPECIFIC',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 14,
                'default_duration_unit' => DeferralDurationUnit::DAYS->value,
                'label' => 'Demam tanpa sebab spesifik',
                'anchor_note' => 'Dihitung dari kesembuhan penuh.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Minor illnesses (§4.3)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'ANTIBIOTIC_COURSE',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 14,
                'default_duration_unit' => DeferralDurationUnit::DAYS->value,
                'label' => 'Menjalani pengobatan antibiotik',
                'anchor_note' => 'Dihitung dari akhir pengobatan.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Medications (§6.2)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'MINOR_ILLNESS',
                'type' => DeferralType::TEMPORARY->value,
                'default_duration_value' => 14,
                'default_duration_unit' => DeferralDurationUnit::DAYS->value,
                'label' => 'Penyakit ringan',
                'anchor_note' => 'Dihitung dari kesembuhan penuh dan terapi berhenti.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Minor illnesses (§4.3)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'INJECTING_DRUG_USE',
                'type' => DeferralType::PERMANENT->value,
                'default_duration_value' => null,
                'default_duration_unit' => null,
                'label' => 'Riwayat penggunaan narkoba suntik',
                'anchor_note' => null,
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Injecting drug use (§7.9.2)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'SYPHILIS_EVER_DIAGNOSED',
                'type' => DeferralType::PERMANENT->value,
                'default_duration_value' => null,
                'default_duration_unit' => null,
                'label' => 'Pernah didiagnosis sifilis',
                'anchor_note' => null,
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Syphilis, yaws and gonorrhoea (§7.5.1)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'SEX_WORKER',
                'type' => DeferralType::PERMANENT->value,
                'default_duration_value' => null,
                'default_duration_unit' => null,
                'label' => 'Pekerja seks',
                'anchor_note' => null,
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: High-risk sexual behaviours (§7.9.1)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'NEUROSURGERY_DURA_CORNEA',
                'type' => DeferralType::PERMANENT->value,
                'default_duration_value' => null,
                'default_duration_unit' => null,
                'label' => 'Bedah saraf, cangkok dura mater, atau transplantasi kornea',
                'anchor_note' => null,
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Diagnostic and surgical procedures; Prion diseases (§6.4, §7.7)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'ORGAN_OR_STEM_CELL_TRANSPLANT',
                'type' => DeferralType::PERMANENT->value,
                'default_duration_value' => null,
                'default_duration_unit' => null,
                'label' => 'Transplantasi organ atau sel punca',
                'anchor_note' => null,
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: Organ, stem cell and tissue transplantation (§6.3.2)',
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => 'TTI_CONFIRMED_REACTIVE',
                'type' => DeferralType::PERMANENT->value,
                'default_duration_value' => null,
                'default_duration_unit' => null,
                'label' => 'Konfirmasi reaktif infeksi menular transfusi (TTI)',
                'anchor_note' => 'Dipakai Kartu 250.',
                'source_reference' => 'WHO 2012 Blood Donor Selection, Technical recommendations: TTI and donor risk assessment (§7.1, §7.2)',
                'is_active' => true,
            ],
        ];
    }
}
