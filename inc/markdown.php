<?php
/* =============================================================================
 *  inc/markdown.php — a small Markdown renderer, for the admin help page.
 * -----------------------------------------------------------------------------
 *  WHY NOT A LIBRARY: the only third-party code this project ships is PHPMailer,
 *  and pulling in a full CommonMark implementation to render two documents we
 *  write ourselves would be a large dependency for a small job. This handles the
 *  subset those documents actually use, and nothing else.
 *
 *  ESCAPE FIRST, THEN FORMAT. Every line is passed through e() before any tag is
 *  produced, so nothing in the source can become markup by accident — the input
 *  is trusted (it ships with the app) but the rule costs nothing and means this
 *  stays safe if it is ever pointed at something else. The consequence is that
 *  the pattern matching below works on ALREADY-ESCAPED text, which is why the
 *  link pattern looks for &quot; rather than a plain quote.
 *
 *  SUPPORTED: headings, paragraphs, bullet and numbered lists, task lists,
 *  blockquotes, fenced code blocks, horizontal rules, and inline bold, italic,
 *  code and links. Tables, images, footnotes and reference links are not
 *  supported — the documents do not use them, and silently mangling something
 *  unsupported would be worse than not offering it.
 * ============================================================================= */

/**
 * A GitHub-style anchor for a heading, so a table of contents written as
 * ordinary Markdown links actually works.
 *
 * Lowercase, spaces to hyphens, punctuation dropped — and DIACRITICS KEPT,
 * which is the part that matters here: the Polish document's headings are
 * full of them, and stripping them would break every link in its contents
 * list. mb_strtolower, not strtolower, for the same reason.
 *
 * @param string $text  Raw heading text (before escaping).
 * @return string
 */
function md_anchor($text) {
    $slug = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    // Drop anything that is not a letter, digit, space or hyphen. /u so the
    // Unicode classes apply to Polish letters rather than cutting them up.
    $slug = preg_replace('/[^\p{L}\p{N}\s\-]+/u', '', $slug);
    $slug = preg_replace('/\s+/u', '-', trim($slug));
    return $slug;
}

/**
 * Inline formatting for one already-escaped line.
 *
 * Order matters: code spans are taken out first and put back last, so that a
 * `*` inside `code` is not read as emphasis.
 *
 * @param string $line  A line that has ALREADY been through e().
 * @return string  HTML.
 */
function md_inline($line) {
    // 1. Park code spans behind placeholders that no later pattern can match.
    $codes = [];
    $line = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
        $codes[] = $m[1];
        return "\x00CODE" . (count($codes) - 1) . "\x00";
    }, $line);

    /* 2. Links. The text has been escaped already, so the quotes in the source
     *    are now &quot; — matching a bare " here would never fire. Only http(s)
     *    and in-page anchors are allowed through; anything else (javascript:,
     *    data:) is left as plain text rather than becoming a link. */
    $line = preg_replace_callback(
        '/\[([^\]]+)\]\(((?:https?:\/\/|#|\.\/|[A-Za-z0-9._\-\/]+)[^)\s]*)\)/',
        function ($m) {
            $href = $m[2];
            $safe = (strpos($href, 'http://') === 0)
                 || (strpos($href, 'https://') === 0)
                 || (strpos($href, '#') === 0);
            if (!$safe) return $m[0];      // leave it as text, unchanged
            // External links open in a new tab; an in-page anchor must not.
            $blank = (strpos($href, '#') === 0) ? '' : ' target="_blank" rel="noopener"';
            return '<a href="' . $href . '"' . $blank . '>' . $m[1] . '</a>';
        }, $line);

    // 3. Bold before italic: ** would otherwise be eaten as two singles.
    $line = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $line);
    $line = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $line);

    // 4. Put the code spans back.
    $line = preg_replace_callback('/\x00CODE(\d+)\x00/', function ($m) use ($codes) {
        return '<code>' . $codes[(int)$m[1]] . '</code>';
    }, $line);

    return $line;
}

/**
 * Render a Markdown document as HTML.
 *
 * @param string $md  The document source.
 * @return string  HTML, safe to echo.
 */
function md_render($md) {
    // Normalise line endings so the line loop does not have to think about it.
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$md));

    $out       = [];
    $para      = [];      // lines gathering into the current paragraph
    $listType  = null;    // 'ul' | 'ol' | null
    $inCode    = false;
    $inQuote   = false;

    // Close whatever block is open. Called before starting a different one, so
    // the nesting cannot end up crossed.
    $flushPara = function () use (&$para, &$out) {
        if (!$para) return;
        $out[] = '<p>' . implode("\n", $para) . '</p>';
        $para = [];
    };
    $closeList = function () use (&$listType, &$out) {
        if ($listType === null) return;
        $out[] = '</' . $listType . '>';
        $listType = null;
    };
    $closeQuote = function () use (&$inQuote, &$out) {
        if (!$inQuote) return;
        $out[] = '</blockquote>';
        $inQuote = false;
    };

    foreach ($lines as $raw) {
        $line = rtrim($raw);

        /* FENCED CODE comes first and swallows everything until it closes:
         * inside a fence, a line starting with # is code, not a heading. */
        if (preg_match('/^```/', $line)) {
            if ($inCode) { $out[] = '</code></pre>'; $inCode = false; }
            else { $flushPara(); $closeList(); $closeQuote(); $out[] = '<pre><code>'; $inCode = true; }
            continue;
        }
        if ($inCode) { $out[] = e($line); continue; }

        // Blank line: end the paragraph, the list and the quote.
        if (trim($line) === '') {
            $flushPara(); $closeList(); $closeQuote();
            continue;
        }

        // Horizontal rule.
        if (preg_match('/^\s*(-{3,}|\*{3,})\s*$/', $line)) {
            $flushPara(); $closeList(); $closeQuote();
            $out[] = '<hr>';
            continue;
        }

        // Heading, with an id so the contents list can link to it.
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $flushPara(); $closeList(); $closeQuote();
            $level = strlen($m[1]);
            $text  = trim($m[2]);
            $out[] = '<h' . $level . ' id="' . e(md_anchor($text)) . '">'
                   . md_inline(e($text)) . '</h' . $level . '>';
            continue;
        }

        // Blockquote. Consecutive quoted lines join into one block.
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $flushPara(); $closeList();
            if (!$inQuote) { $out[] = '<blockquote>'; $inQuote = true; }
            // An empty quoted line separates paragraphs INSIDE the quote.
            if (trim($m[1]) === '') { $out[] = '<br>'; }
            else { $out[] = md_inline(e($m[1])); $out[] = ' '; }
            continue;
        }
        $closeQuote();

        /* TASK LIST — the checklist in section 13. Rendered as a real disabled
         * checkbox rather than "[ ]" text, so it reads as a checklist; disabled
         * because this is a document, not a form that remembers anything. */
        if (preg_match('/^\s*[-*]\s+\[( |x|X)\]\s+(.*)$/', $line, $m)) {
            $flushPara();
            if ($listType !== 'ul') { $closeList(); $out[] = '<ul class="md-tasks">'; $listType = 'ul'; }
            $checked = (strtolower($m[1]) === 'x') ? ' checked' : '';
            $out[] = '<li><input type="checkbox" disabled' . $checked . '> '
                   . md_inline(e($m[2])) . '</li>';
            continue;
        }

        // Bullet list.
        if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
            $flushPara();
            if ($listType !== 'ul') { $closeList(); $out[] = '<ul>'; $listType = 'ul'; }
            $out[] = '<li>' . md_inline(e($m[1])) . '</li>';
            continue;
        }

        // Numbered list.
        if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $m)) {
            $flushPara();
            if ($listType !== 'ol') { $closeList(); $out[] = '<ol>'; $listType = 'ol'; }
            $out[] = '<li>' . md_inline(e($m[1])) . '</li>';
            continue;
        }

        /* A CONTINUATION of the list item above — the manual wraps long items
         * across lines, and without this each wrapped line would start its own
         * paragraph in the middle of a list. */
        if ($listType !== null && preg_match('/^\s+\S/', $raw)) {
            $out[count($out) - 1] = preg_replace('/<\/li>$/', ' ' . md_inline(e(trim($line))) . '</li>',
                                                 $out[count($out) - 1]);
            continue;
        }
        $closeList();

        // Anything else is ordinary paragraph text.
        $para[] = md_inline(e($line));
    }

    // End of document: close whatever is still open.
    $flushPara();
    $closeList();
    $closeQuote();
    if ($inCode) $out[] = '</code></pre>';

    return implode("\n", $out);
}
