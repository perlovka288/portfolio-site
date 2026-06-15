<?php

/**
 * ContactExtractor — парсит контакты из текста описания канала.
 */
class ContactExtractor
{
    /**
     * Извлечь контакты из текста.
     *
     * @param string $text
     * @return array ['tg' => string|null, 'email' => string|null, 'other' => string|null]
     */
    public static function extract(string $text): array
    {
        $result = [
            'tg'    => null,
            'email' => null,
            'other' => [],
        ];

        // ── Telegram ──────────────────────────────────────────────────────────
        // t.me/username  или  @username  или  telegram.me/username
        if (preg_match_all(
            '#(?:https?://)?(?:t(?:elegram)?\.me|telegram\.org)/([A-Za-z0-9_]{4,32})#i',
            $text,
            $m
        )) {
            $result['tg'] = '@' . $m[1][0];
        } elseif (preg_match_all('/@([A-Za-z0-9_]{4,32})/', $text, $m)) {
            $result['tg'] = '@' . $m[1][0];
        }

        // ── Email ─────────────────────────────────────────────────────────────
        if (preg_match_all(
            '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
            $text,
            $m
        )) {
            $result['email'] = $m[0][0];
        }

        // ── Другие контакты (Instagram, VK, WhatsApp) ─────────────────────────
        $other = [];

        if (preg_match_all(
            '#(?:https?://)?(?:www\.)?instagram\.com/([A-Za-z0-9_.]{1,30})/?#i',
            $text,
            $m
        )) {
            $other[] = 'instagram: @' . $m[1][0];
        }

        if (preg_match_all(
            '#(?:https?://)?(?:www\.)?vk\.com/([A-Za-z0-9_.]{1,50})/?#i',
            $text,
            $m
        )) {
            $other[] = 'vk: vk.com/' . $m[1][0];
        }

        if (preg_match_all(
            '#(?:https?://)?(?:wa\.me|whatsapp\.com)/([0-9]{7,15})#i',
            $text,
            $m
        )) {
            $other[] = 'whatsapp: +' . $m[1][0];
        }

        $result['other'] = $other ? implode(', ', $other) : null;

        return $result;
    }
}
