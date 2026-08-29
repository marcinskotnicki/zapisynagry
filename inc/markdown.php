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
 *  INLINE FORMATTING IS APPLIED TO A WHOLE BLOCK, NOT LINE BY LINE. The first
 *  version of this did it per line, which quietly broke every piece of emphasis
 *  that a wrapped paragraph split across two lines: **Public event archive**
 *  with the newline in the middle left the asterisks showing. Worse, the two
 *  halves then paired up with the NEXT stray pair further down, so bold turned
 *  on and off in the wrong places. Blocks are therefore buffered raw and
 *  formatted once, when the block closes.
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
    /* Italic may span a line break, exactly like bold above — the excluded \n
     * here was the same fault, and showed up as a stray pair of asterisks
     * whenever a wrapped line split an italic phrase. Blocks arrive here joined,
     * so a newline inside the match is a wrap, never a paragraph boundary. */
    $line = preg_replace('/(?<![\w*])\*([^*]+)\*(?![\w*])/', '<em>$1</em>', $line);

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
    /* Joined THEN formatted, so emphasis that spans a line break still works —
     * see the note in this file's header. */
    $flushPara = function () use (&$para, &$out) {
        if (!$para) return;
        $out[] = '<p>' . md_inline(implode("\n", $para)) . '</p>';
        $para = [];
    };
    /* The item currently being gathered. A list item wraps across lines just as
     * a paragraph does, so it is buffered and formatted whole for exactly the
     * same reason. */
    $liBuf  = null;   // escaped text of the open <li>, or null if none
    $liPre  = '';     // markup that goes before that text (the task checkbox)
    $flushLi = function () use (&$liBuf, &$liPre, &$out) {
        if ($liBuf === null) return;
        $out[] = '<li>' . $liPre . md_inline($liBuf) . '</li>';
        $liBuf = null;
        $liPre = '';
    };
    $closeList = function () use (&$listType, &$out, &$flushLi) {
        $flushLi();
        if ($listType === null) return;
        $out[] = '</' . $listType . '>';
        $listType = null;
    };
    $quoteBuf = [];   // raw escaped lines of the open blockquote
    $closeQuote = function () use (&$inQuote, &$out, &$quoteBuf) {
        if (!$inQuote) return;
        // Same rule again: format the whole quote at once, not line by line.
        $out[] = md_inline(implode("\n", $quoteBuf));
        $out[] = '</blockquote>';
        $quoteBuf = [];
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
            // An empty quoted line separates paragraphs INSIDE the quote. Kept
            // as a marker in the buffer so the break survives the join.
            $quoteBuf[] = (trim($m[1]) === '') ? '<br>' : e($m[1]);
            continue;
        }
        $closeQuote();

        /* TASK LIST — the checklist in section 13. Rendered as a real disabled
         * checkbox rather than "[ ]" text, so it reads as a checklist; disabled
         * because this is a document, not a form that remembers anything. */
        if (preg_match('/^\s*[-*]\s+\[( |x|X)\]\s+(.*)$/', $line, $m)) {
            $flushPara();
            if ($listType !== 'ul') { $closeList(); $out[] = '<ul class="md-tasks">'; $listType = 'ul'; }
            $flushLi();
            $checked = (strtolower($m[1]) === 'x') ? ' checked' : '';
            $liPre = '<input type="checkbox" disabled' . $checked . '> ';
            $liBuf = e($m[2]);
            continue;
        }

        // Bullet list.
        if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
            $flushPara();
            if ($listType !== 'ul') { $closeList(); $out[] = '<ul>'; $listType = 'ul'; }
            $flushLi();
            $liBuf = e($m[1]);
            continue;
        }

        // Numbered list.
        if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $m)) {
            $flushPara();
            if ($listType !== 'ol') { $closeList(); $out[] = '<ol>'; $listType = 'ol'; }
            $flushLi();
            $liBuf = e($m[1]);
            continue;
        }

        /* A CONTINUATION of the list item above — the manual wraps long items
         * across lines, and without this each wrapped line would start its own
         * paragraph in the middle of a list. */
        if ($listType !== null && $liBuf !== null && preg_match('/^\s+\S/', $raw)) {
            // Appended to the buffer, so the finished item is formatted in one
            // piece: emphasis opened on the first line and closed on this one
            // still becomes emphasis.
            $liBuf .= "\n" . e(trim($line));
            continue;
        }
        $closeList();

        // Anything else is ordinary paragraph text.
        $para[] = e($line);   // formatted later, as one block
    }

    // End of document: close whatever is still open.
    $flushPara();
    $closeList();
    $closeQuote();
    if ($inCode) $out[] = '</code></pre>';

    return implode("\n", $out);
}
