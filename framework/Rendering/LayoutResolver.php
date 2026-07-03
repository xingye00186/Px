<?php


namespace Px\Rendering;

use native_types;

use Px\Core\Config;
use Px\Rendering\Layout\AbsolutePositioning;
use Px\Rendering\Layout\AbsoluteStrategy;
use Px\Rendering\Layout\LayoutStrategyInterface;
use Px\Rendering\Layout\BlockLayoutStrategy;
use Px\Rendering\Layout\FlexLayoutStrategy;
use Px\Rendering\Layout\GridLayoutStrategy;
use Px\Rendering\Layout\InlineLayoutStrategy;
use Px\Rendering\Layout\TableLayoutStrategy;
use Px\Rendering\Layout\MultiColumnLayoutStrategy;
use Px\Rendering\Layout\LayoutConstraints;
use Px\Rendering\Layout\LayoutFragment;
use Px\Rendering\Layout\FragmentBuilder;
use Px\Rendering\Layout\StickyPostProcessor;


/**
 * LayoutResolver — 运行时 CSS 布局引擎（RenderNode 版）
 *
 * Phase 3: 使用 FragmentBuilder 的新流程。
 *
 * 流程：
 *   resolve(RenderNode) → 创建 LayoutConstraints → resolveNodeInternal()
 *   resolveNodeInternal() 负责递归：
 *     1. 读取 computedStyle
 *     2. 创建 FragmentBuilder
 *     3. 按 display/position 选择策略
 *     4. 新策略：先 resolveChildren() 再调用策略
 *     5. build() → LayoutFragment → applyTo()
 *     6. 后处理（滚动容器、sticky 等）
 */
class LayoutResolver
{
    private int $resolveDepth = 0;
    private ?RenderNode $rootNode = null;

    private AbsoluteStrategy $absolutePositioning;
    private LayoutStrategyInterface $blockStrategy;
    private LayoutStrategyInterface $flexStrategy;
    private LayoutStrategyInterface $gridStrategy;
    private LayoutStrategyInterface $inlineStrategy;
    private LayoutStrategyInterface $tableStrategy;
    private LayoutStrategyInterface $multiColumnStrategy;

    /** @var array<string, array> Per-scroll-container sticky stack (vertical) */
    private array $stickyStack = [];

    /** @var array<string, array> Per-scroll-container sticky stack (horizontal) */
    private array $stickyStackX = [];

    /** 婊氬姩瀹瑰櫒鏀堕泦鏁扮粍锛堝竷灞€杩囩▼鎸夐渶杩藉姞锛?*/
    private array $scrollContainers = [];

    private StickyPostProcessor $stickyProcessor;

    public function __construct()
    {
        $this->absolutePositioning = new AbsolutePositioning($this);
        $this->blockStrategy = new BlockLayoutStrategy($this);
        $this->flexStrategy = new FlexLayoutStrategy($this);
        $this->gridStrategy = new GridLayoutStrategy($this);
        $this->inlineStrategy = new InlineLayoutStrategy($this);
        $this->tableStrategy = new TableLayoutStrategy($this);
        $this->multiColumnStrategy = new MultiColumnLayoutStrategy($this);
        $this->stickyProcessor = new StickyPostProcessor();
    }

    public function getAbsolutePositioning(): AbsoluteStrategy
    {
        return $this->absolutePositioning;
    }

    public function getBlockStrategy(): BlockLayoutStrategy
    {
        return $this->blockStrategy;
    }

    public function getFlexStrategy(): FlexLayoutStrategy
    {
        return $this->flexStrategy;
    }

    public function getGridStrategy(): GridLayoutStrategy
    {
        return $this->gridStrategy;
    }

    public function getInlineStrategy(): InlineLayoutStrategy
    {
        return $this->inlineStrategy;
    }

    public function getRootNode(): ?RenderNode
    {
        return $this->rootNode;
    }


    /**
     * Resolve layout for the entire RenderNode tree.
     *
     * Phase 3: 鍒涘缓鍒濆 LayoutConstraints锛岃繘鍏?resolveNodeInternal 鏂版祦绋嬨€?
     *
     * @param RenderNode $root Root RenderNode (mutated in-place via applyTo)
     * @return LayoutFragment 鏍?fragment
     */
    public function resolve(RenderNode $root): LayoutFragment
    {
        $this->rootNode = $root;
        $this->scrollContainers = [];
        $this->stickyProcessor->reset();
        $this->stickyStack = [];
        $this->stickyStackX = [];
        

        
        $constraints = new LayoutConstraints(
            $root->w,
            $root->h,
            0,
            0,
            $root->w,
            $root->h,
        );

        $rootFragment = $this->resolveNodeInternal($root, $constraints);
        $rootFragment->applyTo($root);
    
        return $rootFragment;
    }
    
    /**
     * 渚?FlexLayoutStrategy/GridLayoutStrategy 鍐呴儴绠楁硶浣撲娇鐢ㄧ殑瀛愯妭鐐硅В鏋愬叆鍙ｃ€?
     * 鏇夸唬 resolveNode() 鏂规硶锛岀洿鎺ヤ娇鐢ㄥ潗鏍囧弬鏁般€?
     */
    public function resolveChildNode(RenderNode $child, int $parentX, int $parentY, ?RenderNode $parentNode): void
    {
        $parentW = $parentNode !== null ? $parentNode->w : $child->w;
        $parentH = $parentNode !== null ? $parentNode->h : $child->h;
        $constraints = new LayoutConstraints(
            containerWidth: $parentW,
            containerHeight: $parentH,
            parentContentX: $parentX,
            parentContentY: $parentY,
            contentWidth: $parentW,
            contentHeight: $parentH,
        );
        $fragment = $this->resolveNodeInternal($child, $constraints);
        $fragment->applyTo($child);
    }

    /**
     * Phase 3 鏍稿績閫掑綊甯冨眬鏂规硶銆?
     *
     * @param RenderNode         $node            褰撳墠鑺傜偣
     * @param LayoutConstraints  $constraints     甯冨眬绾︽潫
     * @param LayoutFragment|null $parentFragment 鐖?fragment锛堢敤浜庡眰缁ф壙绛夛級
     * @return LayoutFragment
     */
    private function resolveNodeInternal(
        RenderNode         $node,
        LayoutConstraints  $constraints,
        ?LayoutFragment    $parentFragment = null
    ): LayoutFragment {
        $this->resolveDepth++;
        if ($this->resolveDepth > 500) {
            error_log('[DIAG_LAYOUT] INFINITE RECURSION? depth=' . $this->resolveDepth . ' type=' . $node->type . ' x=' . $node->x . ' y=' . $node->y . ' w=' . $node->w . ' h=' . $node->h . ' layoutDirty=' . ($node->layoutDirty ? '1' : '0'));
            if ($this->resolveDepth > 520) {
                error_log('[DIAG_LAYOUT] HALTING - depth exceeded 520');
                $this->resolveDepth--;
                return (new FragmentBuilder())->build();
            }
        }

        // 鈹€鈹€ 璇诲彇 computedStyle 鈹€鈹€
        $style = $node->computedStyle;
        $display = $style?->display?->value ?? 'block';
        $position = $style?->position?->value ?? 'static';

        // 鈹€鈹€ 鑴忔爣璁版鏌?鈹€鈹€
        // 闈炶剰鑺傜偣锛氱洿鎺ユ瀯寤?Fragment 骞堕€掑綊瀛愯妭鐐癸紙鏃犻渶閲嶆柊甯冨眬璁＄畻锛?
        if (!$node->layoutDirty) {
            $builder = new FragmentBuilder();
            // 闈炶剰鑺傜偣涔熷彲鑳借缁濆瀹氫綅瀛愯妭鐐瑰紩鐢ㄤ负鍏跺畾浣嶇鍏?
            // 纭繚浣嶇疆宸蹭粠绾︽潫涓缃紝浣跨粷瀵瑰畾浣嶅瓙鑺傜偣鑳芥纭幏鍙栫鍏堝潗鏍?
            $posStr = $style?->position?->value ?? 'static';
            if ($posStr === 'static' || $posStr === 'relative') {
                $leftVal = $style?->left ?? null;
                $topVal = $style?->top ?? null;
                $leftPx = $leftVal instanceof \Px\Rendering\CssLength
                    ? $leftVal->resolveInContext($constraints->contentWidth)
                    : (int)($leftVal ?? 0);
                $topPx = $topVal instanceof \Px\Rendering\CssLength
                    ? $topVal->resolveInContext($constraints->contentHeight)
                    : (int)($topVal ?? 0);
                $node->x = (int)($constraints->parentContentX ?? 0) + $leftPx;
                $node->y = (int)($constraints->parentContentY ?? 0) + $topPx;
            }
            $builder
                ->setPosition($node->x, $node->y)
                ->setSize($node->w, $node->h, $style)
                ->setLayer($node->layer)
                ->setContentSize($node->contentWidth, $node->contentHeight);

            // 閫掑綊瑙ｆ瀽瀛愯妭鐐癸紙瀛愯妭鐐瑰彲鑳借剰锛?
            $this->resolveCurrentChildren($node, $constraints, $builder);

            // Absolute positioning for clean-path children (preserve first-pass positions)
            foreach ($node->children as $child) {
                $cp = $child->computedStyle?->position?->value ?? 'static';
                if ($cp === 'absolute' || $cp === 'fixed') {
                    $child->positioningAncestorValid = false;
                    $this->absolutePositioning->resolveAbsolutePositioning(
                        $child, $constraints, $child->computedStyle, new FragmentBuilder()
                    );
                }
            }
            // Rebuild fragment to prevent stale applyTo overwrite
            $this->rebuildChildFragments($builder, $node);

            $this->resolveDepth--;
            return $builder->build($style);
        }

        // 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲
        //  鑴忚矾寰勶細瀹屾暣甯冨眬璁＄畻
        // 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲

        // 鈹€鈹€ Layer 缁ф壙 鈹€鈹€
        if ($node->parent !== null && $node->parent->layer > 0) {
            $node->layer = $node->parent->layer;
        }

        // 搴旂敤鑷韩 z-index 鈫?RenderNode layer
        if ($style !== null && $style->zIndex > $node->layer) {
            $node->layer = $style->zIndex;
        }

        // 鈹€鈹€ 婊氬姩瀹瑰櫒妫€娴?鈹€鈹€
        $overflowX = $style?->overflowX?->value
            ?? $style?->overflow?->value ?? 'visible';
        $overflowY = $style?->overflowY?->value
            ?? $style?->overflow?->value ?? 'visible';
        $hasHScroll = ($overflowX === 'auto' || $overflowX === 'scroll');
        $hasVScroll = ($overflowY === 'auto' || $overflowY === 'scroll');

        if ($hasHScroll || $hasVScroll) {
            $node->isScrollContainer = true;
            $this->scrollContainers[] = $node;
        }

        // 鈹€鈹€ 鍒涘缓 FragmentBuilder 鈹€鈹€
        $builder = new FragmentBuilder();
        
        // 鈺愨晲 鍦ㄧ瓥鐣ヨ皟搴﹀墠棰勭疆鑺傜偣浣嶇疆锛堜娇缁濆瀹氫綅瀛愯妭鐐硅兘姝ｇ‘鑾峰彇绁栧厛鍧愭爣锛夆晲鈺?
        $posStr = $style?->position?->value ?? 'static';
        if ($posStr === 'static' || $posStr === 'relative') {
            $leftVal = $style?->left ?? null;
            $topVal = $style?->top ?? null;
            $leftPx = $leftVal instanceof \Px\Rendering\CssLength
                ? $leftVal->resolveInContext($constraints->contentWidth)
                : (int)($leftVal ?? 0);
            $topPx = $topVal instanceof \Px\Rendering\CssLength
                ? $topVal->resolveInContext($constraints->contentHeight)
                : (int)($topVal ?? 0);
            $node->x = (int)($constraints->parentContentX ?? 0) + $leftPx;
            $node->y = (int)($constraints->parentContentY ?? 0) + $topPx;
        }
        
        // 鈹€鈹€ 鎸?display/position 绛栫暐璋冨害 鈹€鈹€
        $oldNodeX = $node->x;
        $oldNodeY = $node->y;
        switch ($display) {
            case 'none':
                // CSS 2.2 搂9.2.4: display:none 鈫?element generates no box
                $builder->setSize(0, 0);
                break;

            case 'flex':
            case 'inline-flex':
                if ($position === 'absolute' || $position === 'fixed') {
                    // 鏂?AbsoluteStrategy锛氬厛 resolve 瀛愯妭鐐癸紝鍐嶈皟鐢ㄦ柊绛惧悕
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->absolutePositioning->resolveAbsolutePositioning(
                        $node, $constraints, $style, $builder
                    );
                } else {
                    // FlexLayoutStrategy 鏀寔鏂?resolveWithBuilder
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->flexStrategy->resolveWithBuilder(
                        $node, $constraints, $style, $builder
                    );
                }
                break;

            case 'grid':
                $this->resolveChildren($node, $constraints, $builder);
                $this->gridStrategy->resolveWithBuilder(
                    $node, $constraints, $style, $builder
                );
                break;

            case 'inline':
            case 'inline-block':
                if ($position === 'absolute' || $position === 'fixed') {
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->absolutePositioning->resolveAbsolutePositioning(
                        $node, $constraints, $style, $builder
                    );
                } else {
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->inlineStrategy->resolveWithBuilder(
                        $node, $constraints, $style, $builder
                    );
                }
                break;

            case 'table':
            case 'table-row':
            case 'table-cell':
            case 'table-caption':
                if ($position === 'absolute' || $position === 'fixed') {
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->absolutePositioning->resolveAbsolutePositioning(
                        $node, $constraints, $style, $builder
                    );
                } else {
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->tableStrategy->resolveWithBuilder(
                        $node, $constraints, $style, $builder
                    );
                }
                break;

            default: // block, scroll-container, etc.
                // 澶氬垪甯冨眬妫€娴?
                $isMultiCol = ($style !== null
                    && ($style->columnCount > 0 || ($style->columnWidth ?? 0) > 0));
                if ($isMultiCol) {
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->multiColumnStrategy->resolveWithBuilder(
                        $node, $constraints, $style, $builder
                    );
                } elseif ($position === 'absolute' || $position === 'fixed') {
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->absolutePositioning->resolveAbsolutePositioning(
                        $node, $constraints, $style, $builder
                    );
                } else {
                    // BlockLayoutStrategy 鏀寔鏂?resolveWithBuilder
                    /** @var BlockLayoutStrategy $blockStrategy */
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->blockStrategy->resolveWithBuilder(
                        $node, $constraints, $style, $builder
                    );
                }
                break;
        }

        // Phase 1: shift children by parent delta + absolute positioning
        // (strategy already updated node->x/y; children need to follow)
        $parentDx = $node->x - $oldNodeX;
        $parentDy = $node->y - $oldNodeY;
        if ($parentDx !== 0 || $parentDy !== 0) {
            foreach ($node->children as $ch) {
                $chPos = $ch->computedStyle?->position?->value ?? 'static';
                if ($chPos !== 'absolute' && $chPos !== 'fixed') {
                    $ch->x += $parentDx;
                    $ch->y += $parentDy;
                }
            }
        }
        foreach ($node->children as $child) {
            $childPos = $child->computedStyle?->position?->value ?? 'static';
            if ($childPos === 'absolute' || $childPos === 'fixed') {
                $child->positioningAncestorValid = false;
                $this->absolutePositioning->resolveAbsolutePositioning(
                    $child, $constraints, $child->computedStyle, new FragmentBuilder()
                );
            }
        }

        // Phase 2: sync ground truth to fragment and persist
        $this->rebuildChildFragments($builder, $node);
        $fragment = $builder->build($style);
        $fragment->applyTo($node);

        // 鈹€鈹€ 婊氬姩瀹瑰櫒鍚庡鐞嗭紙flex/grid display 妯″紡锛?鈹€鈹€
        if ($node->isScrollContainer
            && ($display === 'flex' || $display === 'inline-flex' || $display === 'grid')
        ) {
            $padT = (int)($style?->paddingTop?->toPx() ?? $style?->padding?->top?->toPx() ?? 0);
            $padL = (int)($style?->paddingLeft?->toPx() ?? $style?->padding?->left?->toPx() ?? 0);
            $padR = (int)($style?->paddingRight?->toPx() ?? $style?->padding?->right?->toPx() ?? 0);
            $padB = (int)($style?->paddingBottom?->toPx() ?? $style?->padding?->bottom?->toPx() ?? 0);

            $childBaseY = $node->y + $padT;

            // Calculate contentHeight: max bottom edge of all children
            $maxBottom = $childBaseY;
            foreach ($node->children as $child) {
                $bottom = (int)($child->y + $child->visualH);
                if ($bottom > $maxBottom) {
                    $maxBottom = $bottom;
                }
            }

            $node->contentHeight = (int)max(0, $maxBottom - $childBaseY) + $padB;

            // Clamp scrollTop when content shrinks
            $maxScroll = (int)max($node->contentHeight - $node->h, 0);
            if ($node->scrollTop > $maxScroll) {
                $node->scrollTop = $maxScroll;
            }

            // ContentWidth for horizontal scroll
            $overflowX2 = $style?->overflowX?->value
                ?? $style?->overflow?->value ?? 'visible';
            $hasHScroll2 = ($overflowX2 === 'auto' || $overflowX2 === 'scroll');
            if ($hasHScroll2) {
                $maxRight = 0;
                foreach ($node->children as $child) {
                    $cLeft = $child->computedStyle?->left ?? 0;
                    $right = (int)($cLeft + $child->visualW);
                    if ($right > $maxRight) {
                        $maxRight = $right;
                    }
                }
                $node->contentWidth = (int)max($maxRight, $node->visualW);

                $maxScrollX = (int)max($node->contentWidth - $node->w, 0);
                if ($node->scrollLeft > $maxScrollX) {
                    $node->scrollLeft = $maxScrollX;
                }
            } else {
                $node->contentWidth = $node->visualW;
            }
        }

        // 鈹€鈹€ position:sticky 澶勭悊 鈹€鈹€
        if ($position === 'sticky' and $style !== null) {
$this->stickyProcessor->process($node, $style, $this->scrollContainers);
        }

        $node->layoutDirty = false;

        // 鈹€鈹€ 缁熶竴 scrollTop/scrollLeft clamp 鈹€鈹€
        if ($node->isScrollContainer) {
            $maxScroll = (int)max($node->contentHeight - $node->h, 0);
            if ($node->scrollTop > $maxScroll) {
                $node->scrollTop = $maxScroll;
            }
            $maxScrollX = (int)max($node->contentWidth - $node->w, 0);
            if ($node->scrollLeft > $maxScrollX) {
                $node->scrollLeft = $maxScrollX;
            }
        }

        $this->resolveDepth--;
        return $fragment;
    }


    // 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲
    //  杈呭姪鏂规硶
    // 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲

    /**
     * 鏂扮瓥鐣ユā寮忥細棰勮В鏋愬瓙鑺傜偣銆?
     *
     * 鍦ㄨ皟鐢ㄦ柊绛惧悕绛栫暐锛堝 AbsoluteStrategy::resolveAbsolutePositioning锛変箣鍓嶏紝
     * 鍏堥€掑綊 resolve 鎵€鏈夊瓙鑺傜偣锛屽苟鍔犲叆 builder銆?
     */
    private function resolveChildren(
        RenderNode        $node,
        LayoutConstraints $constraints,
        FragmentBuilder   $builder
    ): void {
        // 浣跨敤鑺傜偣鑷韩鐨?content box 浣滀负瀛愯妭鐐圭殑鍖呭惈鍧楋紝鑰岄潪浼犻€掔害鏉?
        // 瀵逛簬 block 鍏冪礌锛岃妭鐐瑰昂瀵稿湪 resolveWithBuilder 涓缃紝浣?resolveChildren 鍏堟墽琛?
        // 姝ゅ浣跨敤 node->w/h 鐨勫綋鍓嶅€硷紙鍙兘鍦ㄧ瓥鐣ユ墽琛屽悗鏇存柊锛?
        $cs = $node->computedStyle;
        $padL = $cs !== null ? $cs->padding->left->toPx() : 0;
        $padR = $cs !== null ? $cs->padding->right->toPx() : 0;
        $padT = $cs !== null ? $cs->padding->top->toPx() : 0;
        $padB = $cs !== null ? $cs->padding->bottom->toPx() : 0;
        $bL = $cs !== null ? $cs->borderLeftWidth : 0;
        $bR = $cs !== null ? $cs->borderRightWidth : 0;
        $bT = $cs !== null ? $cs->borderTopWidth : 0;
        $bB = $cs !== null ? $cs->borderBottomWidth : 0;
        $isBorderBox = $cs !== null && $cs->boxSizing->value === 'border-box';

        // 瀛愯妭鐐圭殑鍖呭惈鍧楀搴︼細浼樺厛鐢?node->w锛堢瓥鐣ュ凡鎵ц锛夛紝鍏舵鐢?style 鏄惧紡瀹藉害锛屾渶鍚庡洖閫€鍒扮害鏉熷€?
        // 娉ㄦ剰锛歯ode->w 鐨勬剰涔夊彇鍐充簬 box-sizing
        //   content-box: node->w = 鍐呭瀹藉害锛堜笉鍖呭惈 padding/border锛夛紝鐩存帴鐢ㄤ綔鍖呭惈鍧楀搴?
        //   border-box:  node->w = 鎬诲搴︼紙鍖呭惈 padding/border锛夛紝闇€鍑忓幓 padding/border 寰楀唴瀹瑰搴?
        $csW = $cs !== null ? $cs->width->toPx() : 0;
        $rawW = $node->w > 0 ? $node->w : ($csW > 0 ? $csW : 0);
        if ($rawW > 0) {
            $cbWidth = $isBorderBox ? max(0, $rawW - $padL - $padR - $bL - $bR) : $rawW;
        } else {
            $cW = $constraints->contentWidth;
            $cbWidth = max(0, $cW !== null ? $cW : 0);
        }
        if ($node->h > 0) {
            $cbHeight = $node->h - $padT - $padB - $bT - $bB;
        } else {
            $cH = $constraints->contentHeight;
            $cbHeight = max(0, $cH !== null ? $cH : 0);
        }
        $childOffX = $node->x + $padL + $bL;
        $childOffY = $node->y + $padT + $bT;

        foreach ($node->children as $child) {
            $childConstraints = new LayoutConstraints(
                (int)max(0, $cbWidth),
                (int)max(0, $cbHeight),
                (int)($childOffX),
                (int)($childOffY),
                (int)max(0, $cbWidth),
                (int)max(0, $cbHeight),
            );
            $childFragment = $this->resolveNodeInternal($child, $childConstraints);
            $builder->addChild($childFragment);
        }
    }

    /**
     * 娲佸噣璺緞锛氶€掑綊瑙ｆ瀽瀛愯妭鐐癸紙鏃犻渶绛栫暐璋冨害锛屼粎浼犻€掔害鏉燂級銆?
     */
    private function resolveCurrentChildren(
        RenderNode        $node,
        LayoutConstraints $constraints,
        FragmentBuilder   $builder
    ): void {
        $childOffX = $node->computedStyle !== null ? $node->computedStyle->childOffsetX() : 0;
        $childOffY = $node->computedStyle !== null ? $node->computedStyle->childOffsetY() : 0;

        foreach ($node->children as $child) {
            $childConstraints = new LayoutConstraints(
                (int)($constraints->contentWidth ?? 0),
                (int)($constraints->contentHeight ?? 0),
                $node->x + (int)($childOffX ?? 0),
                $node->y + (int)($childOffY ?? 0),
                (int)($constraints->contentWidth ?? 0),
                (int)($constraints->contentHeight ?? 0),
            );
            $childFragment = $this->resolveNodeInternal($child, $childConstraints);
            $builder->addChild($childFragment);
        }
    }


    /**
     * Rebuild builder child fragments from current RenderNode positions.
     * Call after absolute positioning / auto-stack to prevent stale applyTo overwrite.
     */
    private function rebuildChildFragments(FragmentBuilder $builder, RenderNode $node): void
    {
        $synced = [];
        foreach ($node->children as $i => $ch) {
            $synced[] = new LayoutFragment(
                x: $ch->x, y: $ch->y,
                w: $ch->w, h: $ch->h,
                visualW: $ch->visualW, visualH: $ch->visualH,
                layer: $ch->layer,
                contentWidth: $ch->contentWidth,
                contentHeight: $ch->contentHeight,
                style: $ch->computedStyle,
                children: $this->rebuildDescendantFrags($ch),
            );
        }
        $builder->replaceChildren($synced);
    }

    private function rebuildDescendantFrags(RenderNode $node): array
    {
        $result = [];
        foreach ($node->children as $ch) {
            $result[] = new LayoutFragment(
                x: $ch->x, y: $ch->y,
                w: $ch->w, h: $ch->h,
                visualW: $ch->visualW, visualH: $ch->visualH,
                layer: $ch->layer,
                contentWidth: $ch->contentWidth,
                contentHeight: $ch->contentHeight,
                style: $ch->computedStyle,
                children: $this->rebuildDescendantFrags($ch),
            );
        }
        return $result;
    }
}