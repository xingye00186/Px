<?php

use Px\Dom\VNode;
use Px\Css\CssMappings;

/**
 * Recursive Descent Template Parser for PUI SFC
 *
 * Produces VNode trees directly (全链路 VNode 架构).
 *
 * Architecture:
 *   1. Tokenize:  template string → Token[] (lexer)
 *   2. Parse:     Token[] → VNode tree (recursive descent)
 *   3. Output:    VNode('#root', props, [children...])
 *
 * Tag name mapping (old → new):
 *   <app>       → VNode('#root', ...)
 *   <rect>      → VNode('div', ...)
 *   <text>      → VNode('span', ...)
 *   <btn>       → VNode('button', ...)
 *   <textbox>   → VNode('input', ...)
 *   <grid>      → VNode('div', ['style'=>'display:grid;...'])
 *   <flex>      → VNode('div', ['style'=>'display:flex;...'])
 *   <scroll-container> → VNode('div', ['style'=>'overflow:auto;...'])
 *   <template>  → VNode('template', ['v-for'=>'...'])
 *   <component> → VNode(tagName, props, children)
 *
 * PHP 8.4: 使用 match 表达式分发标签类型。
 */

$frameworkDir = dirname(__DIR__, 2);
require_once $frameworkDir . '/tests/bootstrap/autoload.php';
require_once __DIR__ . '/ComponentRegistry.php';

// ============================================================
// Token types
// ============================================================

define('TOK_EOF',         0);
define('TOK_TAG_OPEN',    1);  // <tagname ...>
define('TOK_TAG_CLOSE',   2);  // </tagname>
define('TOK_TAG_SELF',    3);  // <tagname ... />
define('TOK_TEXT',        4);  // whitespace text between tags
define('TOK_COMMENT',     5);  // <!-- ... -->
define('TOK_TEXT_CONTENT', 6); // non-whitespace text content (may contain {{ }})

class Token
{
    public int $type;
    public string $content;
    public int $line;

    public function __construct(int $type, string $content, int $line)
    {
        $this->type    = $type;
        $this->content = $content;
        $this->line    = $line;
    }
}

class TemplateParseError
{
    public string $message;
    public int $line;

    public function __construct(string $message, int $line)
    {
        $this->message = $message;
        $this->line    = $line;
    }

    public function __toString(): string
    {
        return "Line {$this->line}: {$this->message}";
    }
}

class TemplateParser
{
    /** @var Token[] */
    private array $tokens = [];
    private int $pos = 0;

    /** @var TemplateParseError[] */
    private array $errors = [];

    /** Component registry for resolving custom tags */
    private ?ComponentRegistry $componentRegistry = null;

    public function __construct(?ComponentRegistry $registry = null)
    {
        $this->componentRegistry = $registry;
    }

    // ============================================================
    // Public API
    // ============================================================

    /**
     * Parse a template string into a VNode tree.
     *
     * @param string $template Content of <template>...</template> block
     * @return VNode Root node (#root type)
     */
    public function parse(string $template): VNode
    {
        $this->errors = [];
        $this->tokens = $this->tokenize($template);
        $this->pos    = 0;

        $root = $this->parseDocument();

        if ($this->pos < count($this->tokens)) {
            $tok = $this->tokens[$this->pos];
            if ($tok->type !== TOK_EOF && trim($tok->content) !== '') {
                $this->error("Unexpected content after root element", $tok->line);
            }
        }

        return $root;
    }

    /**
     * @return TemplateParseError[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Pretty-print the VNode tree for debugging (--dump-ast mode).
     */
    public function dumpVNode(VNode $vnode): string
    {
        return json_encode($this->vnodeToArray($vnode), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    // ============================================================
    // Tokenizer (Lexer)
    // ============================================================

    /**
     * Split template text into tokens, tracking line numbers.
     * Preserves text content between tags (for slot text and {{ }} interpolation).
     */
    private function tokenize(string $template): array
    {
        $tokens = [];
        $line   = 1;
        $len    = strlen($template);
        $i      = 0;
        $textBuf = '';

        while ($i < $len) {
            // Handle newlines for line tracking
            if ($template[$i] === "\n") {
                if ($textBuf !== '') {
                    $textBuf .= "\n";
                }
                $line++;
                $i++;
                continue;
            }
            if ($template[$i] === "\r") {
                $i++;
                if ($i < $len && $template[$i] === "\n") {
                    if ($textBuf !== '') $textBuf .= "\r\n";
                    $line++;
                    $i++;
                } else {
                    // Standalone \r — preserve in buffer, count as line
                    if ($textBuf !== '') $textBuf .= "\r";
                    $line++;
                }
                continue;
            }

            // Check for comment <!-- ... -->
            if ($i + 3 < $len && substr($template, $i, 4) === '<!--') {
                // Flush any buffered text first
                if (trim($textBuf) !== '') {
                    $tokens[] = new Token(TOK_TEXT_CONTENT, $textBuf, $line - substr_count($textBuf, "\n"));
                    $textBuf = '';
                }
                $end = strpos($template, '-->', $i + 4);
                if ($end === false) {
                    $this->error('Unclosed comment', $line);
                    break;
                }
                $comment = substr($template, $i, $end + 3 - $i);
                $newlines = substr_count($comment, "\n");
                $tokens[] = new Token(TOK_COMMENT, $comment, $line);
                $line += $newlines;
                $i = $end + 3;
                continue;
            }

            // Tag: starts with '<' — validate next char is valid tag starter
            if ($template[$i] === '<') {
                // Check next character is a valid tag starter: / (close), ! (comment/doctype), or a-z/A-Z
                $next = $i + 1 < strlen($template) ? $template[$i + 1] : '';
                $isValidTag = ($next === '/') || ($next === '!') ||
                    (($next >= 'a' && $next <= 'z') || ($next >= 'A' && $next <= 'Z'));
                if (!$isValidTag) {
                    // Not a valid tag — treat '<' as regular text
                    $textBuf .= $template[$i];
                    $i++;
                    continue;
                }
                // Flush any buffered text before the tag
                $flushedText = $textBuf;
                $textBuf = '';
                if (trim($flushedText) !== '') {
                    $textLine = $line - substr_count($flushedText, "\n");
                    $tokens[] = new Token(TOK_TEXT_CONTENT, $flushedText, $textLine);
                }

                $end = $this->findTagEnd($template, $i);
                if ($end === false) {
                    $this->error('Unclosed tag starting with "<"', $line);
                    break;
                }
                $tagText = substr($template, $i, $end + 1 - $i);

                // Determine tag type
                $trimmedTag = rtrim($tagText, " \t\n\r\0\x0B");
                if (strlen($tagText) >= 3 && $tagText[1] === '/') {
                    // Closing tag: </tagname>
                    $tokens[] = new Token(TOK_TAG_CLOSE, $tagText, $line);
                } elseif (strlen($trimmedTag) >= 2 && $trimmedTag[strlen($trimmedTag) - 2] === '/') {
                    // Self-closing tag: <tagname ... /> (with optional whitespace before />)
                    $tokens[] = new Token(TOK_TAG_SELF, $tagText, $line);
                } else {
                    // Opening tag: <tagname ...>
                    $tokens[] = new Token(TOK_TAG_OPEN, $tagText, $line);
                }

                $newlines = substr_count($tagText, "\n");
                $line += $newlines;
                $i = $end + 1;
                continue;
            }

            // Accumulate text content
            $textBuf .= $template[$i];
            $i++;
        }

        // Flush remaining text buffer
        if (trim($textBuf) !== '') {
            $tokens[] = new Token(TOK_TEXT_CONTENT, $textBuf, $line - substr_count($textBuf, "\n"));
        }

        $tokens[] = new Token(TOK_EOF, '', $line);
        return $tokens;
    }

    /**
     * Quote-aware tag end finder.
     *
     * 从 $start（首个 '<' 位置）扫描到相匹配的 '>'，
     * 但跳过属性值内引号包围的字符串（一直试 v-if="depth > 0" 里的 '>' 会被误语为标签闭合符）。
     *
     * 支持：
     *   - 双引号 "..." 包围（最常见的 HTML 属性值形式）
     *   - 单引号 '...' 包围（也合法，典型例：`:label="label + '.child'"` 内层）
     *   - 引号可跨行
     *
     * @return int|false '>' 的位置，或 false（未找到閄合）
     */
    private function findTagEnd(string $template, int $start): int|false
    {
        $len = strlen($template);
        $inQuote = '';  // '' 未进引号 / '"' 双引号 / "'" 单引号
        for ($i = $start; $i < $len; $i++) {
            $ch = $template[$i];
            if ($inQuote !== '') {
                // 已在引号内部 — 只关心匹配的闭合引号
                if ($ch === $inQuote) {
                    $inQuote = '';
                }
                continue;
            }
            // 不在引号内——遇到引号开启、遇到 '>' 闭合
            if ($ch === '"' || $ch === "'") {
                $inQuote = $ch;
                continue;
            }
            if ($ch === '>') {
                return $i;
            }
        }
        return false;
    }

    // ============================================================
    // Parser: Recursive Descent → VNode tree
    // ============================================================

    /**
     * Parse the full document. Expects <app> (or similar root element).
     * Returns a #root VNode with all children.
     */
    private function parseDocument(): VNode
    {
        $this->skipUntilContent();

        $tok = $this->current();
        if ($tok->type === TOK_EOF) {
            $this->error('Empty template: missing root element', $tok->line);
            return VNode::h('#root', [], []);
        }

        if ($tok->type !== TOK_TAG_OPEN) {
            $this->error("Expected root element, got non-open tag", $tok->line);
            return VNode::h('#root', [], []);
        }

        return $this->parseRoot($tok);
    }

    /**
     * Parse the root element.
     * - <app>: legacy mode, attributes absorbed into #root
     * - <div>, <main>, etc: parsed as regular element, wrapped in #root
     */
    private function parseRoot(Token $openTok): VNode
    {
        $rawAttrs = $this->parseAttrs($openTok->content);
        $tagName  = $this->getTagName($openTok->content);
        $isLegacyApp = ($tagName === 'app');

        // Extract width/height
        if ($isLegacyApp) {
            $width  = (int)($rawAttrs['width'] ?? $rawAttrs['w'] ?? 336);
            $height = (int)($rawAttrs['height'] ?? $rawAttrs['h'] ?? 430);
            $title  = $rawAttrs['title'] ?? 'Untitled';

            if (!isset($rawAttrs['width']) && !isset($rawAttrs['w'])) {
                $this->error('<app> missing required attribute: width (or w)', $openTok->line);
            }
            if (!isset($rawAttrs['height']) && !isset($rawAttrs['h'])) {
                $this->error('<app> missing required attribute: height (or h)', $openTok->line);
            }
        } else {
            // HTML root: ONLY extract explicit pixel values from inline style
            $width = 0;
            $height = 0;
            $styleStr = $rawAttrs['style'] ?? '';
            if ($styleStr !== '') {
                if (preg_match('/\bwidth\s*:\s*(\d+)\s*px\b/i', $styleStr, $m)) {
                    $width = (int)$m[1];
                }
                if (preg_match('/\bheight\s*:\s*(\d+)\s*px\b/i', $styleStr, $m)) {
                    $height = (int)$m[1];
                }
            }
            if ($width === 0) $width = (int)($rawAttrs['width'] ?? $rawAttrs['w'] ?? 0);
            if ($height === 0) $height = (int)($rawAttrs['height'] ?? $rawAttrs['h'] ?? 0);
            $title  = $rawAttrs['title'] ?? 'Untitled';
        }

        // Build #root props
        $rootProps = [];
        if ($isLegacyApp) {
            foreach ($rawAttrs as $k => $v) {
                if ($k !== 'title' && $k !== 'width' && $k !== 'w' && $k !== 'height' && $k !== 'h') {
                    if ($k === 'class' || $k === 'style' || $k[0] === ':' || $k[0] === '@' || ($k[0] === 'v' && $k[1] === '-')) {
                        $rootProps[$k] = $v;
                    }
                }
            }
        }
        $rootProps['title'] = $title;
        $styleParts = [];
        if ($width > 0) $styleParts[] = "width:{$width}px";
        if ($height > 0) $styleParts[] = "height:{$height}px";
        $styleStr = implode(';', $styleParts);
        if ($styleStr !== '') {
            $rootProps['style'] = $styleStr;
        }

        $root = VNode::h('#root', $rootProps, []);
        $root->w = $width ?: 0;
        $root->h = $height ?: 0;

        if ($isLegacyApp) {
            // <app>: children go directly into #root
            $this->advance(); // consume <app> opening tag
            $root->children = $this->parseChildrenUntil($tagName);
            return $root;
        }

        // HTML root: parse the root element as a normal element
        // (parseRoot didn't advance past the opening tag, so parseElement reads it directly)
        $rootEl = $this->parseElement();
        if ($rootEl !== null) {
            $root->children[] = $rootEl;
        }

        return $root;
    }

    /**
     * Parse children elements until a closing tag is encountered.
     *
     * @param string $closeTag Expected closing tag name
     * @return array VNode[]
     */
    private function parseChildrenUntil(string $closeTag): array
    {
        $children = [];

        while (true) {
            $tok = $this->current();

            if ($tok->type === TOK_EOF) {
                $this->error("Unclosed element: missing </{$closeTag}>", $tok->line);
                break;
            }

            if ($tok->type === TOK_TAG_CLOSE) {
                $closeName = $this->getTagName($tok->content);
                if ($closeName === $closeTag) {
                    $this->advance(); // consume closing tag
                    break;
                }
                $this->error("Unexpected closing tag </{$closeName}> (expected </{$closeTag}>)", $tok->line);
                $this->advance();
                continue;
            }

            if ($tok->type === TOK_TAG_OPEN || $tok->type === TOK_TAG_SELF) {
                $child = $this->parseElement();
                if ($child !== null) {
                    $children[] = $child;
                }
                continue;
            }

            // Text content between tags
            if ($tok->type === TOK_TEXT_CONTENT) {
                $child = $this->parseTextNode($tok->content, $tok->line);
                if ($child !== null) {
                    $children[] = $child;
                }
                $this->advance();
                continue;
            }

            // Skip comments
            $this->advance();
        }

        return $children;
    }

    /**
     * Parse a text node from text content between tags.
     * Handles {{ }} interpolation markers.
     */
    private function parseTextNode(string $text, int $line): ?VNode
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        // Check if the entire content is a {{ }} interpolation
        if (preg_match('/^\{\{\s*(\S+)\s*\}\}$/', $trimmed, $m)) {
            // Pure interpolation: store the expression in props
            return new VNode('#text', ['bind' => $m[1]], $trimmed, null);
        }

        // Contains {{ }} mixed with plain text
        if (preg_match('/\{\{/', $trimmed)) {
            // Extract all interpolation expressions
            $parts = [];
            if (preg_match_all('/\{\{\s*(\S+)\s*\}\}|((?:(?!\{\{).)+)/s', $trimmed, $m, PREG_SET_ORDER)) {
                foreach ($m as $part) {
                    if (!empty($part[1])) {
                        $parts[] = ['type' => 'bind', 'expr' => $part[1]];
                    } elseif (!empty($part[2])) {
                        $parts[] = ['type' => 'text', 'value' => $part[2]];
                    }
                }
            }
            return new VNode('#text', ['parts' => $parts], $trimmed, null);
        }

        // Plain text
        return new VNode('#text', [], $trimmed, null);
    }

    /**
     * Unified element parser for all HTML tags.
     * Uses match expression (PHP 8.4) for tag dispatch.
     */
    private function parseElement(): ?VNode
    {
        $tok = $this->current();
        $tagName = $this->getTagName($tok->content);
        $tagType = $tok->type;

        // Dispatch by tag name using match
        $result = match ($tagName) {
            // ===== Text nodes via text content =====
            // (handled in parseChildrenUntil as TOK_TEXT_CONTENT)

            // ===== HTML standard / mapped elements =====
            'rect'    => $this->parseRectAsDiv($tok),
            'text'    => $this->parseTextAsSpan($tok),
            'btn'     => $this->parseBtnAsButton($tok),
            'textbox' => $this->parseTextBoxAsInput($tok),
            'button'  => $this->parseButtonElement($tok),
            'div'     => $this->parseDivElement($tok),
            'span'    => $this->parseSpanElement($tok),
            'input'   => $this->parseInputElement($tok),
            'p'       => $this->parseGenericElement($tok, 'p'),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6'
                      => $this->parseGenericElement($tok, $tagName),

            // ===== Extended HTML elements (v8) =====
            'label'     => $this->parseGenericElement($tok, 'label'),
            'textarea'  => $this->parseGenericElement($tok, 'textarea'),
            'select'    => $this->parseGenericElement($tok, 'select'),
            'option'    => $this->parseGenericElement($tok, 'option'),
            'header'    => $this->parseGenericElement($tok, 'header'),
            'footer'    => $this->parseGenericElement($tok, 'footer'),
            'nav'       => $this->parseGenericElement($tok, 'nav'),
            'main'      => $this->parseGenericElement($tok, 'main'),
            'section'   => $this->parseGenericElement($tok, 'section'),
            'aside'     => $this->parseGenericElement($tok, 'aside'),
            'table'     => $this->parseGenericElement($tok, 'table'),
            'thead'     => $this->parseGenericElement($tok, 'thead'),
            'tbody'     => $this->parseGenericElement($tok, 'tbody'),
            'tfoot'     => $this->parseGenericElement($tok, 'tfoot'),
            'colgroup'  => $this->parseTableColgroup($tok),
            'col'       => $this->parseGenericElement($tok, 'col'),
            'caption'   => $this->parseGenericElement($tok, 'caption'),
            'tr'        => $this->parseGenericElement($tok, 'tr'),
            'th'        => $this->parseGenericElement($tok, 'th'),
            'td'        => $this->parseGenericElement($tok, 'td'),
            'a'         => $this->parseGenericElement($tok, 'a'),
            'img'       => $this->parseGenericElement($tok, 'img'),
            'br'        => $this->parseGenericElement($tok, 'br'),
            'hr'        => $this->parseGenericElement($tok, 'hr'),
            'ul'        => $this->parseGenericElement($tok, 'ul'),
            'ol'        => $this->parseGenericElement($tok, 'ol'),
            'li'        => $this->parseGenericElement($tok, 'li'),
            'strong'    => $this->parseGenericElement($tok, 'strong'),
            'b'         => $this->parseGenericElement($tok, 'b'),
            'em'        => $this->parseGenericElement($tok, 'em'),
            'code'      => $this->parseGenericElement($tok, 'code'),
            'pre'       => $this->parseGenericElement($tok, 'pre'),

            // ===== Layout containers → div with style =====
            'grid'             => $this->parseGridAsDiv($tok),
            'flex'             => $this->parseFlexAsDiv($tok),
            'scroll-container' => $this->parseScrollContainerAsDiv($tok),

            // ===== Template / v-for =====
            'template' => $this->parseTemplateNode($tok),

            // ===== Root element =====
            'app'       => $this->parseRoot($tok),

            // ===== Unknown / component tags =====
            default => $this->parseUnknownOrComponent($tok, $tagName),
        };

        return $result;
    }

    // ============================================================
    // Element-specific parsers (all return VNode)
    // ============================================================

    /**
     * <rect x="10" y="10" w="100" h="30" class="foo" @click="handler">
     * → VNode('div', ['class'=>'foo', 'style'=>'left:10px;top:10px;width:100px;height:30px', '@click'=>'handler'])
     */
    private function parseRectAsDiv(Token $tok): VNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        // If rect has @click, convert to button (PUI backward compat)
        $hasClickHandler = isset($attrs['@click']) || isset($attrs['click']);
        $tagType = $hasClickHandler ? 'button' : 'div';

        $props = $this->convertElementAttrs($attrs, $tok->line, $tagType);

        // Parse @click: "handler" or "handler('arg')"
        if ($hasClickHandler) {
            $click = $attrs['@click'] ?? $attrs['click'] ?? '';
            $handler = $click;
            $arg = null;
            if ($click !== '') {
                if (preg_match("/^(\w+)\(['\"]([^'\"]*)['\"]\)$/", $click, $m)) {
                    $handler = $m[1];
                    $arg = $m[2];
                } elseif (preg_match("/^(\w+)\(([^)]+)\)$/", $click, $m)) {
                    $handler = $m[1];
                    $arg = $m[2];
                }
            }
            $props['@click'] = $handler;
            if ($arg !== null) {
                $props['click-arg'] = $arg;
            }
        }

        return VNode::h($tagType, $props);
    }

    /**
     * <text x="10" y="10" :bind="prop" class="foo">
     * → VNode('span', ['class'=>'foo', 'style'=>'left:10px;top:10px', ':bind'=>'prop'])
     */
    private function parseTextAsSpan(Token $tok): VNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        $props = $this->convertElementAttrs($attrs, $tok->line, 'text');
        // Bind processing: :bind="prop" stays as-is for runtime
        return VNode::h('span', $props);
    }

    /**
     * <btn row="0" col="0" label="OK" class="foo" @click="handler('arg')">
     * → VNode('button', ['class'=>'foo', '@click'=>'handler', 'click-arg'=>'arg'], 'OK')
     */
    private function parseBtnAsButton(Token $tok): VNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        $row   = (int)($attrs['row'] ?? 0);
        $col   = (int)($attrs['col'] ?? 0);
        $label = $attrs['label'] ?? '';
        $cls   = $attrs['class'] ?? '';
        $click = $attrs['@click'] ?? '';

        $handler = $click;
        $arg     = null;
        if ($click !== '') {
            if (preg_match("/^(\w+)\(['\"]([^'\"]*)['\"]\)$/", $click, $m)) {
                $handler = $m[1];
                $arg     = $m[2];
            } elseif (preg_match("/^(\w+)\(([^)]+)\)$/", $click, $m)) {
                $handler = $m[1];
                $arg     = $m[2];
            }
        }

        if ($label === '') {
            $this->error('<btn> missing label', $tok->line);
        }
        if ($click === '') {
            $this->error('<btn> missing @click handler', $tok->line);
        }

        $props = [];
        if ($cls !== '') $props['class'] = $cls;
        if ($handler !== '') $props['@click'] = $handler;
        if ($arg !== null) $props['click-arg'] = $arg;
        // Store grid position info for LayoutResolver
        $props['grid-row'] = $row;
        $props['grid-col'] = $col;

        // v-if on button
        $vIf = $attrs['v-if'] ?? '';
        if ($vIf !== '') {
            $props['v-if'] = $vIf;
        }

        return VNode::h('button', $props, $label);
    }

    /**
     * <textbox x="10" y="10" w="200" h="30" v-model="prop" placeholder="Search">
     * → VNode('input', ['v-model'=>'prop', 'placeholder'=>'Search', 'style'=>'left:10px;top:10px;width:200px;height:30px'])
     */
    private function parseTextBoxAsInput(Token $tok): VNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        $x = (int)($attrs['x'] ?? 0);
        $y = (int)($attrs['y'] ?? 0);
        $w = (int)($attrs['w'] ?? 200);
        $h = (int)($attrs['h'] ?? 30);
        $vModel = $attrs['v-model'] ?? '';
        $placeholder = $attrs['placeholder'] ?? '';
        $class = $attrs['class'] ?? 'textbox';
        $align = $attrs['align'] ?? 'left';

        $props = [];
        $styleParts = [];
        $styleParts[] = "left:{$x}px";
        $styleParts[] = "top:{$y}px";
        $styleParts[] = "width:{$w}px";
        $styleParts[] = "height:{$h}px";
        $props['style'] = implode(';', $styleParts);

        if ($class !== '') $props['class'] = $class;
        if ($vModel !== '') $props['v-model'] = $vModel;
        if ($placeholder !== '') $props['placeholder'] = $placeholder;
        if ($align !== 'left') $props['align'] = $align;

        // Keyboard events
        if (isset($attrs['@keyup'])) $props['@keyup'] = $attrs['@keyup'];
        if (isset($attrs['@keydown'])) $props['@keydown'] = $attrs['@keydown'];
        if (isset($attrs['@enter'])) $props['@enter'] = $attrs['@enter'];

        return VNode::h('input', $props);
    }

    /**
     * HTML <button class="foo" @click="handler">Label</button>
     */
    private function parseButtonElement(Token $tok): VNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        $props = $this->convertElementAttrs($attrs, $tok->line, 'button');
        $children = $this->parseChildrenUntil('button');
        // Children could be text or VNodes
        if (count($children) === 1 && $children[0]->isText()) {
            $textProps = $children[0]->props;
            if (isset($textProps['bind'])) {
                $props['bind'] = $textProps['bind'];
            }
            if (isset($textProps['parts'])) {
                $props['parts'] = $textProps['parts'];
            }
            return VNode::h('button', $props, $children[0]->children);
        }
        return VNode::h('button', $props, $children);
    }

    /**
     * HTML <div class="foo" style="...">children</div>
     */
    private function parseDivElement(Token $tok): VNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        $props = $this->convertElementAttrs($attrs, $tok->line, 'div');
        $children = $this->parseChildrenUntil('div');
        return VNode::h('div', $props, $children);
    }

    /**
     * HTML <span class="foo">{{ expr }}</span>
     */
    private function parseSpanElement(Token $tok): VNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        $props = $this->convertElementAttrs($attrs, $tok->line, 'span');
        $children = $this->parseChildrenUntil('span');

        // If single text child, store as string and preserve bind/parts
        if (count($children) === 1 && $children[0]->isText()) {
            $textProps = $children[0]->props;
            if (isset($textProps['bind'])) {
                $props['bind'] = $textProps['bind'];
            }
            if (isset($textProps['parts'])) {
                $props['parts'] = $textProps['parts'];
            }
            return VNode::h('span', $props, $children[0]->children);
        }
        return VNode::h('span', $props, $children);
    }

    /**
     * HTML <input v-model="prop" placeholder="..." />
     */
    private function parseInputElement(Token $tok): VNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        $props = $this->convertElementAttrs($attrs, $tok->line, 'input');
        return VNode::h('input', $props);
    }

    /**
     * Generic HTML element (<p>, <h1>, etc.)
     */
    private function parseTableColgroup(Token $tok): VNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance(); // consume <colgroup>

        $props = $this->convertElementAttrs($attrs, $tok->line, 'colgroup');
        $children = [];

        // Parse only <col> / <template> children
        // HTML5 §12.2.6.4.8: any non-col child auto-closes colgroup
        while ($this->pos < count($this->tokens)) {
            $tt = $this->tokens[$this->pos];
            $type = $tt->type;

            if ($type === TOK_TAG_CLOSE && strtolower(trim($tt->content)) === 'colgroup') {
                $this->advance();
                break;
            }

            if ($type !== TOK_TAG_OPEN && $type !== TOK_TAG_SELF) {
                $this->advance();
                continue;
            }

            // Determine child tag name
            $content = trim($tt->content);
            $spacePos = strpos($content, ' ');
            $childTag = $spacePos !== false ? substr($content, 0, $spacePos) : $content;
            $childTag = strtolower($childTag);

            // Only <col> and <template> are valid inside colgroup
            if ($childTag === 'col' || $childTag === 'template') {
                $child = $this->parseElement();
                if ($child !== null) $children[] = $child;
            } else {
                // Auto-close colgroup on any other tag (HTML5)
                break;
            }
        }

        return VNode::h('colgroup', $props, $children);
    }




    private function parseGenericElement(Token $tok, string $tag): VNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        $props = $this->convertElementAttrs($attrs, $tok->line, $tag);

        // Apply default UA styles for HTML elements (CSS standard defaults)
        static $defaultStyles = [
            'b'       => 'font-weight:700',
            'strong'  => 'font-weight:700',
            'em'      => 'font-style:italic',
            'i'       => 'font-style:italic',
            'u'       => 'text-decoration:underline',
            'code'    => 'font-family:Consolas,monospace',
            'small'   => 'font-size:smaller',
            'mark'    => 'background:#ffff00',
        ];
        if (isset($defaultStyles[$tag])) {
            if (isset($props['style']) && $props['style'] !== '') {
                $props['style'] .= ';' . $defaultStyles[$tag];
            } else {
                $props['style'] = $defaultStyles[$tag];
            }
        }

        // HTML void elements: no children, no closing tag
        static $voidTags = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];
        if (in_array($tag, $voidTags, true)) {
            return VNode::h($tag, $props);
        }

        $children = $this->parseChildrenUntil($tag);

        if (count($children) === 1 && $children[0]->isText()) {
            $textProps = $children[0]->props;
            if (isset($textProps['bind'])) {
                $props['bind'] = $textProps['bind'];
            }
            if (isset($textProps['parts'])) {
                $props['parts'] = $textProps['parts'];
            }
            return VNode::h($tag, $props, $children[0]->children);
        }
        return VNode::h($tag, $props, $children);
    }

    // ============================================================
    // Layout container parsers → div with CSS
    // ============================================================

    /**
     * <grid cols="4" rows="5" cell-w="80" cell-h="60" margin="4">
     *   <btn ... />
     * </grid>
     * → VNode('div', ['style'=>'display:grid;...'], [button VNodes...])
     */
    private function parseGridAsDiv(Token $openTok): VNode
    {
        $attrs = $this->parseAttrs($openTok->content);

        $x      = (int)($attrs['x'] ?? 0);
        $y      = (int)($attrs['y'] ?? 0);
        $cols   = (int)($attrs['cols'] ?? 4);
        $rows   = (int)($attrs['rows'] ?? 5);
        $cellW  = (int)($attrs['cell-w'] ?? 80);
        $cellH  = (int)($attrs['cell-h'] ?? 60);
        $margin = (int)($attrs['margin'] ?? 4);

        $totalW = $cols * $cellW;
        $totalH = $rows * $cellH;

        $style = $this->buildGridStyle($x, $y, $totalW, $totalH, $cols, $rows, $cellW, $cellH, $margin);
        $vIf = $attrs['v-if'] ?? '';

        $props = [];
        $props['style'] = $style;
        $props['class'] = $attrs['class'] ?? '';
        if ($vIf !== '') $props['v-if'] = $vIf;

        $this->advance(); // consume <grid>

        // Parse children (btn elements) until </grid>
        $children = $this->parseChildrenUntil('grid');

        return VNode::h('div', $props, $children);
    }

    /**
     * <flex direction="row" gap="8" x="10" y="10" w="300" h="200">...</flex>
     * → VNode('div', ['style'=>'display:flex;flex-direction:row;gap:8px;left:10px;top:10px;width:300px;height:200px'])
     */
    private function parseFlexAsDiv(Token $openTok): VNode
    {
        $attrs = $this->parseAttrs($openTok->content);

        $x         = (int)($attrs['x'] ?? 0);
        $y         = (int)($attrs['y'] ?? 0);
        $w         = (int)($attrs['w'] ?? 0);
        $h         = (int)($attrs['h'] ?? 0);
        $direction = $attrs['direction'] ?? 'row';
        $gap       = (int)($attrs['gap'] ?? 0);
        $justify   = $attrs['justify'] ?? 'flex-start';
        $align     = $attrs['align'] ?? 'stretch';
        $wrap      = $attrs['wrap'] ?? 'nowrap';

        $style = $this->buildFlexStyle($x, $y, $w, $h, $direction, $gap, $justify, $align, $wrap);

        $props = [];
        $props['style'] = $style;
        if (isset($attrs['class'])) $props['class'] = $attrs['class'];

        $this->advance(); // consume <flex>

        $children = $this->parseChildrenUntil('flex');

        return VNode::h('div', $props, $children);
    }

    /**
     * <scroll-container x="10" y="50" w="380" h="400" :scroll-top="scrollTop">...</scroll-container>
     * → VNode('div', ['style'=>'...', ':scroll-top'=>'scrollTop'])
     */
    private function parseScrollContainerAsDiv(Token $openTok): VNode
    {
        $attrs = $this->parseAttrs($openTok->content);

        $x = (int)($attrs['x'] ?? 0);
        $y = (int)($attrs['y'] ?? 0);
        $w = (int)($attrs['w'] ?? 0);
        $h = (int)($attrs['h'] ?? 0);
        $scrollTopBind = $attrs[':scroll-top'] ?? $attrs['scroll-top'] ?? '';

        if ($w === 0 || $h === 0) {
            $this->error("<scroll-container> has zero width or height", $openTok->line);
        }

        $styleParts = [];
        $styleParts[] = "left:{$x}px";
        $styleParts[] = "top:{$y}px";
        $styleParts[] = "width:{$w}px";
        $styleParts[] = "height:{$h}px";
        $styleParts[] = "overflow:auto";

        $props = [];
        $props['style'] = implode(';', $styleParts);
        if ($scrollTopBind !== '') $props[':scroll-top'] = $scrollTopBind;

        $this->advance(); // consume <scroll-container>

        $children = $this->parseChildrenUntil('scroll-container');

        return VNode::h('div', $props, $children);
    }

    /**
     * <template v-for="item in items" :key="item.id">...</template>
     * <template v-if="condition">...</template>
     * <template v-else-if="otherCond">...</template>
     * <template v-else>...</template>
     *
     * Vue 3 语义：<template> 作为不渲染的透明容器，支持 v-for/v-if/v-else-if/v-else。
     * 子节点直接展开到父节点（不产出 DOM 元素）。
     */
    private function parseTemplateNode(Token $openTok): VNode
    {
        $attrs = $this->parseAttrs($openTok->content);
        $this->advance(); // consume <template>

        $vFor = $attrs['v-for'] ?? '';
        $keyExpr = $attrs[':key'] ?? '';
        $vIf = $attrs['v-if'] ?? '';
        $vElseIf = $attrs['v-else-if'] ?? '';
        $vElse = isset($attrs['v-else']);

        // Vue 3: <template> 必须有 v-for、v-if、v-else-if 或 v-else 之一
        if ($vFor === '' && $vIf === '' && $vElseIf === '' && !$vElse) {
            $this->error('<template> requires v-for, v-if, v-else-if, or v-else attribute', $openTok->line);
        }

        $props = [];
        if ($vFor !== '') $props['v-for'] = $vFor;
        if ($keyExpr !== '') $props[':key'] = $keyExpr;
        if ($vIf !== '') $props['v-if'] = $vIf;
        if ($vElseIf !== '') $props['v-else-if'] = $vElseIf;
        if ($vElse) $props['v-else'] = '';

        $children = $this->parseChildrenUntil('template');

        return VNode::h('template', $props, $children);
    }

    /**
     * Unknown tag or component reference.
     * v8: Treat unknown tags as generic HTML elements (for flexibility).
     */
    private function parseUnknownOrComponent(Token $tok, string $tagName): VNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $isSelfClosing = ($tok->type === TOK_TAG_SELF);

        // Check component registry first
        if ($this->componentRegistry !== null) {
            $compFile = $this->componentRegistry->resolve($tagName);
            if ($compFile !== null) {
                return $this->parseComponentRef($tok, $tagName, $compFile, $isSelfClosing);
            }
        }

        // Unknown tag — treat as generic HTML element (not an error)
        // This allows users to use any HTML element without pre-registration
        if ($isSelfClosing) {
            $this->advance();
            return VNode::h($tagName, $this->convertElementAttrs($attrs, $tok->line, $tagName));
        }

        $this->advance(); // consume opening tag
        $children = $this->parseChildrenUntil($tagName);
        return VNode::h($tagName, $this->convertElementAttrs($attrs, $tok->line, $tagName), $children);
    }

    /**
     * Component reference tag.
     */
    private function parseComponentRef(Token $tok, string $tagName, string $compFile, bool $selfClosing): VNode
    {
        $attrs = $this->parseAttrs($tok->content);

        if ($selfClosing) {
            $this->advance();
            $props = $this->convertElementAttrs($attrs, $tok->line, $tagName);
            $props['__componentFile'] = $compFile;
            return VNode::h($tagName, $props);
        }

        $this->advance(); // consume opening tag
        $children = $this->parseChildrenUntil($tagName);
        $props = $this->convertElementAttrs($attrs, $tok->line, $tagName);
        $props['__componentFile'] = $compFile;
        return VNode::h($tagName, $props, $children);
    }

    // ============================================================
    // Attribute conversion: old-style → HTML/CSS
    // ============================================================

    /**
     * Convert old-style PUI element attributes to VNode props.
     *
     * Rules:
     *   - x/y/w/h       → inline style (left/top/width/height)
     *   - class          → passed through
     *   - style          → merged with position style
     *   - :bind/:prop    → passed through
     *   - @click/@event  → passed through
     *   - v-if/v-model   → passed through
     *   - container-w/h/x → passed through
     *   - align          → passed through
     *   - Other known    → passed through
     */
    private function convertElementAttrs(array $attrs, int $line, string $tagName): array
    {
        $props = [];
        $styleParts = [];

        // Position and size
        $x = (int)($attrs['x'] ?? 0);
        $y = (int)($attrs['y'] ?? 0);
        $w = (int)($attrs['w'] ?? 0);
        $h = (int)($attrs['h'] ?? 0);

        if ($x !== 0) $styleParts[] = "left:{$x}px";
        if ($y !== 0) $styleParts[] = "top:{$y}px";
        if ($w !== 0) $styleParts[] = "width:{$w}px";
        if ($h !== 0) $styleParts[] = "height:{$h}px";

        // Class
        if (isset($attrs['class']) && $attrs['class'] !== '') {
            $props['class'] = $attrs['class'];
        }

        // Inline style (merge)
        if (isset($attrs['style']) && $attrs['style'] !== '') {
            $styleParts[] = $attrs['style'];
        }

        // Assemble style
        if (count($styleParts) > 0) {
            $props['style'] = implode(';', $styleParts);
        }

        // Vue directives
        foreach ($attrs as $k => $v) {
            if ($k === 'x' || $k === 'y' || $k === 'w' || $k === 'h' ||
                $k === 'class' || $k === 'style') {
                continue;
            }

            // Directive attributes
            if ($k[0] === ':' || $k[0] === '@') {
                // Split @click="handler('arg')" or @click="handler(expr)" into handler + arg
                if ($k === '@click') {
                    if (preg_match("/^(\w+)\(['\"]([^'\"]*)['\"]\)$/", $v, $m)) {
                        $props['@click'] = $m[1];
                        $props['click-arg'] = $m[2];
                    } elseif (preg_match("/^(\w+)\(([^)]+)\)$/", $v, $m)) {
                        $props['@click'] = $m[1];
                        $props['click-arg'] = $m[2];
                    } else {
                        $props[$k] = $v;
                    }
                } else {
                    $props[$k] = $v;
                }
                continue;
            }
            if (str_starts_with($k, 'v-')) {
                $props[$k] = $v;
                continue;
            }

            // Known PUI-specific attributes → map to HTML equivalents
            switch ($k) {
                case 'bind':
                    $props[':bind'] = $v;
                    break;
                case 'align':
                    $props['align'] = $v;
                    break;
                case 'container-w':
                    $props['container-w'] = $v;
                    break;
                case 'container-h':
                    $props['container-h'] = $v;
                    break;
                case 'container-x':
                    $props['container-x'] = $v;
                    break;
                case 'label':
                    $props['label'] = $v;
                    break;
                case 'title':
                    $props['title'] = $v;
                    break;
                case 'width':
                case 'height':
                    // Transfer to style
                    $unit = is_numeric($v) ? "{$v}px" : $v;
                    $cssProp = ($k === 'width') ? 'width' : 'height';
                    if (isset($props['style'])) {
                        $props['style'] .= ";{$cssProp}:{$unit}";
                    } else {
                        $props['style'] = "{$cssProp}:{$unit}";
                    }
                    break;
                default:
                    // Unknown attribute — keep as-is for component props
                    $props[$k] = $v;
                    break;
            }
        }

        return $props;
    }

    /**
     * Build CSS grid style from grid attributes.
     */
    private function buildGridStyle(
        int $x, int $y,
        int $totalW, int $totalH,
        int $cols, int $rows,
        int $cellW, int $cellH,
        int $margin
    ): string {
        $buttonW = $cellW - $margin * 2;
        $buttonH = $cellH - $margin * 2;

        $parts = [];
        $parts[] = "display:grid";
        $parts[] = "grid-template-columns:repeat({$cols},{$cellW}px)";
        $parts[] = "grid-template-rows:repeat({$rows},{$cellH}px)";
        if ($x !== 0) $parts[] = "left:{$x}px";
        if ($y !== 0) $parts[] = "top:{$y}px";
        $parts[] = "width:{$totalW}px";
        $parts[] = "height:{$totalH}px";

        return implode(';', $parts);
    }

    /**
     * Build CSS flex style from flex attributes.
     */
    private function buildFlexStyle(
        int $x, int $y, int $w, int $h,
        string $direction, int $gap,
        string $justify, string $align, string $wrap
    ): string {
        $parts = [];
        $parts[] = "display:flex";
        $parts[] = "flex-direction:{$direction}";
        if ($gap > 0) $parts[] = "gap:{$gap}px";
        $parts[] = "justify-content:{$justify}";
        $parts[] = "align-items:{$align}";
        if ($wrap !== 'nowrap') $parts[] = "flex-wrap:{$wrap}";
        if ($x !== 0) $parts[] = "left:{$x}px";
        if ($y !== 0) $parts[] = "top:{$y}px";
        if ($w !== 0) $parts[] = "width:{$w}px";
        if ($h !== 0) $parts[] = "height:{$h}px";

        return implode(';', $parts);
    }

    // ============================================================
    // Helpers (kept from original parser)
    // ============================================================

    /**
     * Extract tag name from "<tagname ...>" or "</tagname>" or "<tagname ... />"
     */
    private function getTagName(string $tagText): string
    {
        $tagText = trim($tagText, "<> \t\n\r\0\x0B/");

        // Handle namespace prefix if present (e.g., "pui:button")
        $spacePos = strpos($tagText, ' ');
        if ($spacePos !== false) {
            $name = substr($tagText, 0, $spacePos);
        } else {
            $name = $tagText;
        }

        // Handle self-closing "tagname/"
        if (substr($name, -1) === '/') {
            $name = rtrim(substr($name, 0, -1));
        }

        return $name;
    }

    /**
     * Parse attributes from a tag string like: key="value" key2="value2"
     */
    private function parseAttrs(string $tagText): array
    {
        $attrs = [];
        $tagName = $this->getTagName($tagText);
        $attrStr = substr($tagText, strlen($tagName) + 1); // skip "<tagname "
        $attrStr = trim($attrStr, "> \t\n\r\0\x0B/");

        if ($attrStr === '') {
            return $attrs;
        }

        // Match attr="value" or attr='value' (attribute names start with letter, @, or :)
        // v8 fix: exclude modifiers like .self, .capture, .stop from being treated as separate attrs
        if (preg_match_all('#([a-zA-Z@:][a-zA-Z0-9@:_.-]*)(?:\s*=\s*"([^"]*)"|\s*=\s*\'([^\']*)\')?#', $attrStr, $m, PREG_SET_ORDER)) {
            foreach ($m as $a) {
                $key = $a[1];
                $value = $a[2] ?? ($a[3] ?? '');
                // Skip if key starts with . (modifier, not a separate attribute)
                if (str_starts_with($key, '.')) {
                    continue;
                }
                $attrs[$key] = $value;
            }
        }

        return $attrs;
    }

    private function current(): Token
    {
        return $this->tokens[$this->pos] ?? new Token(TOK_EOF, '', 0);
    }

    private function advance(): void
    {
        if ($this->pos < count($this->tokens)) {
            $this->pos++;
        }
    }

    private function skipUntilContent(): void
    {
        while (true) {
            $t = $this->current();
            if ($t->type === TOK_TAG_OPEN || $t->type === TOK_TAG_SELF || $t->type === TOK_EOF) {
                break;
            }
            $this->advance();
        }
    }

    private function error(string $message, int $line): void
    {
        $this->errors[] = new TemplateParseError($message, $line);
    }

    // ============================================================
    // VNode tree → array (for debug dump)
    // ============================================================

    private function vnodeToArray(VNode $vnode): array
    {
        $result = [
            'type'     => $vnode->type,
            'props'    => $vnode->props,
        ];

        if ($vnode->key !== null) {
            $result['key'] = $vnode->key;
        }

        if (is_string($vnode->children)) {
            $result['children'] = $vnode->children;
        } elseif (is_array($vnode->children)) {
            $result['children'] = array_map([$this, 'vnodeToArray'], $vnode->children);
        } else {
            $result['children'] = null;
        }

        return $result;
    }
}
