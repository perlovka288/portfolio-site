<?php
/**
 * Анимированные иконки закрытого раздела (стиль «Animated State Icons» с 21st.dev).
 * Чистый SVG + CSS (assets/ppk-redesign.css), без библиотек.
 *
 *   require_once __DIR__ . '/ppk_icons.php';
 *   echo ppkIcon('lock');            // анимируется при наведении на родителя (a/button)
 *   echo ppkIcon('bell', 'ai--loop'); // анимируется всегда (по кругу)
 */
function ppkIcon(string $name, string $extraClass = ''): string
{
    static $paths = [
        'success'  => '<circle class="a-ring" cx="12" cy="12" r="9" stroke-dasharray="40 17"/><circle class="a-done" cx="12" cy="12" r="9"/><path class="a-check" d="M7.8 12.6l2.9 2.9 5.6-6"/>',
        'menu'     => '<path class="a-l1" d="M4 7h16"/><path class="a-l2" d="M4 12h16"/><path class="a-l3" d="M4 17h16"/>',
        'play'     => '<path class="a-play" d="M8 5.5v13l10.5-6.5z" fill="currentColor"/><g class="a-pause" fill="currentColor" stroke="none"><rect x="6.5" y="5" width="3.8" height="14" rx="1.2"/><rect x="13.7" y="5" width="3.8" height="14" rx="1.2"/></g>',
        'lock'     => '<rect x="5" y="11" width="14" height="10" rx="2.5"/><path class="a-shackle" d="M8 11V8a4 4 0 0 1 8 0v3"/><circle cx="12" cy="16" r="1.1" fill="currentColor" stroke="none"/>',
        'copy'     => '<g class="a-copy"><rect x="9" y="3" width="11" height="14" rx="2"/><path d="M5 8v11a2 2 0 0 0 2 2h9"/><path d="M12.5 8h4M12.5 11.5h4"/></g><path class="a-ok" d="M7 12.6l3.4 3.4 7-7.6"/>',
        'bell'     => '<g class="a-bell"><path d="M6 16v-5a6 6 0 0 1 12 0v5l1.6 2H4.4z"/><path d="M10 21a2 2 0 0 0 4 0"/></g>',
        'heart'    => '<path class="a-heart" d="M12 20.5s-7.5-4.6-9-9.4C1.9 7.6 4 4.5 7.3 4.5c1.9 0 3.3 1 4.7 2.8 1.4-1.8 2.8-2.8 4.7-2.8 3.3 0 5.4 3.1 4.3 6.6-1.5 4.8-9 9.4-9 9.4z"/>',
        'download' => '<path class="a-arrow" d="M12 4v11M8 11l4 4 4-4"/><path d="M4 20h16"/>',
        'send'     => '<path class="a-plane" d="M21 3 10 14M21 3l-7 18-4-7-7-4z"/>',
        'toggle'   => '<rect class="a-track" x="2" y="7.5" width="20" height="9" rx="4.5"/><circle class="a-knob" cx="7" cy="12" r="2.6" fill="currentColor" stroke="none"/>',
        'eye'      => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle class="a-pupil" cx="12" cy="12" r="3"/>',
        'volume'   => '<path d="M4 9.5v5h4l5 4V5.5l-5 4z"/><path class="a-w1" d="M16.3 9.2a4 4 0 0 1 0 5.6"/><path class="a-w2" d="M19 6.6a8 8 0 0 1 0 10.8"/>',
        'bookmark' => '<path class="a-mark" d="M6.5 4h11v16.5L12 16.8 6.5 20.5z"/>',
        'book'     => '<path d="M12 6.5C10.5 5 8 4.5 4 4.5v13c4 0 6.5.5 8 2 1.5-1.5 4-2 8-2v-13c-4 0-6.5.5-8 2zM12 6.5v13"/>',
        'arrow'    => '<path class="a-nudge" d="M5 12h14M13 6l6 6-6 6"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'chat'     => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
        'bolt'     => '<path class="a-bolt" d="M13 2 4 14h6l-1 8 9-12h-6z" fill="currentColor"/>',
        'spark'    => '<path d="M12 3l2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5z" fill="currentColor"/>',
        'dashed'   => '<circle cx="12" cy="12" r="8.5" stroke-dasharray="2.2 3.2"/>',
        'tile'     => '<rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.6"/>',
        'plus'     => '<path class="a-plus" d="M12 5v14M5 12h14"/>',
        'edit'     => '<path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z"/><path d="M14 7l3 3"/>',
        'trash'    => '<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
        'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
        'upload'   => '<path class="a-arrow" d="M12 16V5M8 9l4-4 4 4"/><path d="M4 20h16"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="M21 16l-5-5-8 9"/>',
        'link'     => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3A4 4 0 0 0 11 18.7l1-1"/>',
        'file'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M12 2.8v2.4M12 18.8v2.4M2.8 12h2.4M18.8 12h2.4M5.5 5.5l1.7 1.7M16.8 16.8l1.7 1.7M5.5 18.5l1.7-1.7M16.8 7.2l1.7-1.7"/>',
        'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
    ];
    $p = $paths[$name] ?? $paths['dashed'];
    return '<svg class="ai ai-' . $name . ($extraClass !== '' ? ' ' . $extraClass : '') . '" viewBox="0 0 24 24" aria-hidden="true">' . $p . '</svg>';
}
