#!/usr/bin/env php
<?php
/**
 * Unit test for core/Markdown.php. Run: php tests/markdown.php
 */
require_once __DIR__ . '/../core/Markdown.php';

$cases = [
    ['# Title', '<h1>Title</h1>'],
    ['## Sub ##', '<h2>Sub</h2>'],
    ["Para one\nstill one\n\nPara two", "<p>Para one\nstill one</p>\n<p>Para two</p>"],
    ['**bold** and *em* and `code`', '<p><strong>bold</strong> and <em>em</em> and <code>code</code></p>'],
    ['__bold__ _em_ ~~gone~~', '<p><strong>bold</strong> <em>em</em> <s>gone</s></p>'],
    ['[Site](https://example.com "T")', '<p><a href="https://example.com" title="T">Site</a></p>'],
    ['![Alt text](/assets/a.jpg)', '<p><img src="/assets/a.jpg" alt="Alt text" loading="lazy"></p>'],
    ["- one\n- two\n  - nested\n- three", '<ul><li>one</li><li>two<ul><li>nested</li></ul></li><li>three</li></ul>'],
    ["1. a\n2. b", '<ol><li>a</li><li>b</li></ol>'],
    ["> quoted\n> line", "<blockquote><p>quoted\nline</p></blockquote>"],
    ["```php\n<?php echo 1; ?>\n```", "<pre><code class=\"language-php\">&lt;?php echo 1; ?&gt;</code></pre>"],
    ['---', '<hr>'],
    ["| a | b |\n|---|---|\n| 1 | 2 |", '<table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>'],
    ["line one  \nline two", "<p>line one<br>\nline two</p>"],
    ['<script>alert(1)</script> text', '<p>&lt;script&gt;alert(1)&lt;/script&gt; text</p>'],
    ['Keep <strong>html</strong> & <em>em</em>', '<p>Keep <strong>html</strong> &amp; <em>em</em></p>'],
    ["<figure><img src=\"/x.jpg\" alt=\"x\"><figcaption>cap</figcaption></figure>", "<figure><img src=\"/x.jpg\" alt=\"x\"><figcaption>cap</figcaption></figure>"],
    ['see https://example.com/a?b=1.', '<p>see <a href="https://example.com/a?b=1">https://example.com/a?b=1</a>.</p>'],
    ['5 * 3 * 2 = 30', '<p>5 * 3 * 2 = 30</p>'],
    ['snake_case_word stays', '<p>snake_case_word stays</p>'],
];

$fail = 0;
foreach ($cases as [$in, $want]) {
    $got = Markdown::toHtml($in);
    if ($got !== $want) {
        $fail++;
        echo "FAIL\n  in:   " . json_encode($in) . "\n  want: " . json_encode($want) . "\n  got:  " . json_encode($got) . "\n";
    }
}
echo ($fail === 0 ? 'All ' . count($cases) . " markdown cases passed.\n" : "{$fail} case(s) failed.\n");
exit($fail === 0 ? 0 : 1);
