<?php

namespace App\Rendering\Html;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * The inline Markdown a master CV actually uses, as HTML.
 *
 * Bullets carry bold lead-ins by the format's own convention — `**Dispatch API
 * (designed and built).** …` — so a template that printed the text as it stands
 * would put asterisks on a CV. A full Markdown parser is not wanted: the input
 * is one line of a bullet, and block structure comes from the document model.
 *
 * **The text is escaped before any of it is read as Markdown.** Everything here
 * then produces its own tags, so no part of the master CV or of the model's
 * output can contribute one.
 */
final class InlineMarkdown
{
    /**
     * Returning an `Htmlable` is what lets a template write `{{ … }}` and still
     * get tags: Blade's escaper passes an `Htmlable` through, so the escaping
     * is this class's job alone and there is no `{!! … !!}` anywhere to audit.
     */
    public function line(?string $text): Htmlable
    {
        if ($text === null || trim($text) === '') {
            return new HtmlString('');
        }

        $html = e(trim($text));

        $html = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/(?<![\w*])[*_](?=\S)(.+?)(?<=\S)[*_](?![\w*])/u', '<em>$1</em>', $html);
        $html = (string) preg_replace('/`(?=\S)(.+?)(?<=\S)`/u', '<code>$1</code>', $html);

        // Links keep their text and lose the URL: a CV is read on paper as
        // often as on a screen, and a bare URL mid-sentence reads badly.
        $html = (string) preg_replace('/\[(?=\S)(.+?)(?<=\S)\]\(\S+?\)/u', '$1', $html);

        return new HtmlString($html);
    }

    /**
     * A block of text whose line breaks are meaningful, such as a role's
     * context paragraph.
     */
    public function block(?string $text): Htmlable
    {
        if ($text === null || trim($text) === '') {
            return new HtmlString('');
        }

        $lines = [];

        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            if (trim($line) !== '') {
                $lines[] = $this->line($line)->toHtml();
            }
        }

        return new HtmlString(implode('<br>', $lines));
    }
}
