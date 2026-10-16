<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Models\NotificationTemplate;
use App\Modules\Notification\Application\Exceptions\NotificationTemplateRejected;
use App\Modules\Notification\Domain\NotificationChannel;
use App\Modules\Notification\Domain\RenderedNotification;

/**
 * Merender template secara KETAT: setiap kemungkinan teks yang tidak jujur
 * melempar, bukan menghasilkan pesan berisi "{{donor_name}}" yang diam-diam terkirim.
 *
 * Validasi adalah perbandingan himpunan terhadap kolom variables, dua arah.
 * Penggantian dilakukan SATU lintasan atas placeholder di template, jadi nilai
 * variabel yang kebetulan memuat "{{...}}" tidak ikut dipindai ulang.
 *
 * Nilai disisipkan apa adanya: escape HTML untuk email adalah urusan pengirim (kartu 460).
 */
final class RenderNotification
{
    private const string PLACEHOLDER = '/\{\{([a-z][a-z0-9_]*)\}\}/';

    /** @param  array<string, string|int>  $variables */
    public function handle(
        string $code,
        NotificationChannel $channel,
        array $variables,
        string $locale = 'id',
    ): RenderedNotification {
        $template = NotificationTemplate::query()
            ->where('code', $code)
            ->where('channel', $channel->value)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        if ($template === null) {
            throw NotificationTemplateRejected::notFound($code, $channel, $locale);
        }

        $declared = array_map(strval(...), $template->variables);
        $given = array_map(strval(...), array_keys($variables));

        $missing = array_values(array_diff($declared, $given));
        if ($missing !== []) {
            throw NotificationTemplateRejected::missingVariables($code, $missing);
        }

        // Variabel salah nama adalah bug pemanggil; menelannya membuatnya hidup berbulan-bulan.
        $unexpected = array_values(array_diff($given, $declared));
        if ($unexpected !== []) {
            throw NotificationTemplateRejected::unexpectedVariables($code, $unexpected);
        }

        $texts = array_filter([$template->subject, $template->body], fn (?string $text): bool => $text !== null);
        $used = [];

        foreach ($texts as $text) {
            preg_match_all(self::PLACEHOLDER, $text, $matches);
            $used = [...$used, ...$matches[1]];

            // Sisa "{{" setelah semua placeholder sah dibuang berarti placeholder yang
            // bentuknya salah ("{{ donor_name }}", "{{Donor}}") -- tidak akan pernah diganti.
            if (str_contains((string) preg_replace(self::PLACEHOLDER, '', $text), '{{')) {
                throw NotificationTemplateRejected::malformedPlaceholder($code);
            }
        }

        $undeclared = array_values(array_unique(array_diff($used, $declared)));
        if ($undeclared !== []) {
            throw NotificationTemplateRejected::undeclaredPlaceholders($code, $undeclared);
        }

        // Variabel terdaftar yang tidak pernah dipakai: pemanggil wajib mengirimnya
        // tapi nilainya hilang -- bug seeder yang sama seriusnya.
        $unused = array_values(array_diff($declared, $used));
        if ($unused !== []) {
            throw NotificationTemplateRejected::unusedVariables($code, $unused);
        }

        $render = fn (string $text): string => (string) preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $m): string => (string) $variables[$m[1]],
            $text,
        );

        return new RenderedNotification(
            $template->subject === null ? null : $render($template->subject),
            $render($template->body),
        );
    }
}
