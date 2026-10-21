<?php
/** Small, safe Markdown subset: # headings, **bold**, `code`, [text](https://url), lists, - [ ] checklists, > quotes. Input is escaped first. */
function md_inline(string $s): string
{
    $s = e($s);
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
    $s = preg_replace('/`(.+?)`/', '<code>$1</code>', $s);
    $s = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', function ($m) {
        return '<a href="' . $m[2] . '" rel="noopener nofollow" target="_blank">' . $m[1] . '</a>';
    }, $s);
    return $s;
}

function md(?string $text): string
{
    $lines = explode("\n", str_replace("\r", '', trim((string) $text)));
    $out = '';
    $list = null;   // 'ul' | 'ol' | null
    $para = [];
    $closeList = function () use (&$out, &$list) { if ($list) { $out .= "</$list>"; $list = null; } };
    $flush = function () use (&$out, &$para) { if ($para) { $out .= '<p>' . md_inline(implode(' ', $para)) . '</p>'; $para = []; } };

    foreach ($lines as $line) {
        $t = rtrim($line);
        if ($t === '') { $flush(); $closeList(); continue; }
        if (preg_match('/^(#{1,3})\s+(.+)$/', $t, $m)) {
            $flush(); $closeList();
            $lvl = strlen($m[1]) + 1;
            $out .= "<h$lvl>" . md_inline($m[2]) . "</h$lvl>";
        } elseif (preg_match('/^\s*[-*]\s+\[( |x|X)\]\s+(.+)$/', $t, $m)) {
            $flush();
            if ($list !== 'ul') { $closeList(); $out .= '<ul class="check">'; $list = 'ul'; }
            $out .= '<li><input type="checkbox" disabled' . (strtolower($m[1]) === 'x' ? ' checked' : '') . '> ' . md_inline($m[2]) . '</li>';
        } elseif (preg_match('/^\s*[-*]\s+(.+)$/', $t, $m)) {
            $flush();
            if ($list !== 'ul') { $closeList(); $out .= '<ul>'; $list = 'ul'; }
            $out .= '<li>' . md_inline($m[1]) . '</li>';
        } elseif (preg_match('/^\s*\d+[.)]\s+(.+)$/', $t, $m)) {
            $flush();
            if ($list !== 'ol') { $closeList(); $out .= '<ol>'; $list = 'ol'; }
            $out .= '<li>' . md_inline($m[1]) . '</li>';
        } elseif (preg_match('/^>\s?(.*)$/', $t, $m)) {
            $flush(); $closeList();
            $out .= '<blockquote>' . md_inline($m[1]) . '</blockquote>';
        } else {
            $closeList();
            $para[] = $t;
        }
    }
    $flush(); $closeList();
    return '<div class="md">' . $out . '</div>';
}
