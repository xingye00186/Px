<?php

use native_types;

use Px\ReactiveComponent;
use Px\Rendering\VNode;

/**
 * Px视频平台 — 首页组件
 *
 * 手动实现（非 SFC 编译），完整布局：导航栏、轮播、视频网格、侧边栏推荐
 *
 * 关键修复：
 *   1. NavTab 添加 min-width 确保 flex 布局正确分配宽度
 *   2. 视频网格容器显式 background:#E8ECF2 防止默认暗色背景
 *   3. Grid container 显式 height 使 contentHeight 计算正确
 *   4. 侧边栏滚动容器显式 background:#FFFFFF 与父层融合
 *   5. 侧边栏 item 使用绝对坐标避免 flex 布局嵌套 auto-stack 冲突
 */
class AppComponent extends ReactiveComponent
{
    // —— 导航 ——
    public string $activeTab = '首页';
    public array $navTabs = ['首页', '视频', '直播', '专栏', '会员', '创作中心'];

    // —— 轮播 ——
    public int $currentSlide = 0;
    public string $slideTitle = '热门推荐';
    public string $slideSubtitle = '本周精选热门视频，不容错过';
    public int $carouselTimer = 0;
    public array $carouselData = [
        ['title' => '热门推荐', 'subtitle' => '本周精选热门视频，不容错过'],
        ['title' => '游戏盛宴', 'subtitle' => '三战二周年S10巅峰对决精彩回顾'],
        ['title' => '娱乐综艺', 'subtitle' => '极限挑战最高颜值特辑'],
        ['title' => '探索历史', 'subtitle' => '夏商周考古发现大揭秘'],
    ];

    // —— 滚动 ——
    public string $scrollTopGrid = '0';
    public string $scrollTopSide = '0';

    // —— 视频数据 ——
    public array $videos = [
        ['id'=>'v1','title'=>'三战二周年S10巅峰对决','views'=>'194万','time'=>'1天前','channel'=>'游戏频道','duration'=>'12:30','thumbColor'=>'#E53935'],
        ['id'=>'v2','title'=>'极限挑战','views'=>'178万','time'=>'1天前','channel'=>'综艺频道','duration'=>'45:00','thumbColor'=>'#FFC107'],
        ['id'=>'v3','title'=>'夏商周存在吗？','views'=>'2000万+','time'=>'1天前','channel'=>'历史频道','duration'=>'08:15','thumbColor'=>'#43A047'],
        ['id'=>'v4','title'=>'CodeX · AI赋能的AI时代','views'=>'15万','time'=>'1天前','channel'=>'科技频道','duration'=>'18:42','thumbColor'=>'#1E88E5'],
        ['id'=>'v5','title'=>'小熊大冒险','views'=>'12万','time'=>'1天前','channel'=>'少儿频道','duration'=>'06:30','thumbColor'=>'#FF9800'],
        ['id'=>'v6','title'=>'新版本OpenCV 4.5.5','views'=>'10万','time'=>'1天前','channel'=>'编程频道','duration'=>'22:10','thumbColor'=>'#1E88E5'],
    ];
    public array $sidebarItems = [
        ['id'=>'s1','title'=>'小熊大冒险','views'=>'12万','time'=>'1天前','channel'=>'少儿频道'],
        ['id'=>'s2','title'=>'暗夜行动','views'=>'8万','time'=>'1天前','channel'=>'军事频道'],
        ['id'=>'s3','title'=>'新版本OpenCV 4.5.5','views'=>'10万','time'=>'1天前','channel'=>'编程频道'],
        ['id'=>'s4','title'=>'三战二周年S10巅峰对决','views'=>'194万','time'=>'1天前','channel'=>'游戏频道'],
        ['id'=>'s5','title'=>'CodeX · AI赋能的AI时代','views'=>'15万','time'=>'1天前','channel'=>'科技频道'],
    ];

    // 轮播幻灯片背景色（与 carouselData 索引对应）
    private array $slideBgColors = ['#1565C0', '#E53935', '#6A1B9A', '#2E7D32'];
    private array $slideAccentColors = ['#1976D2', '#EF5350', '#8E24AA', '#43A047'];
    private array $slideLightColors = ['#B0C4DE', '#FFCDD2', '#CE93D8', '#A5D6A7'];
    private array $slideDarkColors = ['#1557B0', '#C62828', '#4A148C', '#1B5E20'];

    public function __construct(?string $componentId = null)
    {
        parent::__construct($componentId ?? 'VideoPlatform');
    }

    public function onMount(): void
    {
        parent::onMount();
        $this->initData();
    }

    private function initData(): void
    {
        // 数组属性已全部在声明处内联初始化（AOT use native_types 兼容）
        // 此处仅保留从数据源同步 string 属性
        if (count($this->carouselData) > 0) {
            $s = $this->carouselData[0];
            $this->slideTitle   = (string)$s['title'];
            $this->slideSubtitle = (string)$s['subtitle'];
        }
    }

    // ──────────────────── 定时器 ────────────────────

    public function onTimerTick(): void
    {
        $this->carouselTimer++;
        if ($this->carouselTimer >= 3) {
            $this->carouselTimer = 0;
            $this->advanceCarousel();
        }
    }

    private function advanceCarousel(): void
    {
        $count = count($this->carouselData);
        if ($count === 0) return;
        $this->currentSlide = ($this->currentSlide + 1) % $count;
        $this->updateSlideContent();
        $this->markDirty();
    }

    private function updateSlideContent(): void
    {
        $count = count($this->carouselData);
        if ($count > 0 && $this->currentSlide >= 0 && $this->currentSlide < $count) {
            $slide = $this->carouselData[$this->currentSlide];
            $this->slideTitle   = (string)$slide['title'];
            $this->slideSubtitle = (string)$slide['subtitle'];
        }
    }

    // ──────────────────── 事件 ────────────────────

    public function goToSlide(string $index): void
    {
        $count = count($this->carouselData);
        $idx = (int)$index;
        if ($idx >= 0 && $idx < $count) {
            $this->currentSlide = $idx;
            $this->carouselTimer = 0;
            $this->updateSlideContent();
            $this->markDirty();
        }
    }

    public function setActiveTab(string $tabName): void
    {
        $this->activeTab = $tabName;
        $this->markDirty();
    }

    // ──────────────────── RENDER ────────────────────

    public function render(): VNode
    {
        return VNode::h('#root', ['title' => 'Px视频', 'style' => 'width:1280px;height:720px'],
            VNode::h('div', ['style' => 'width:1280px;height:720px;background:#E8ECF2'], [
                $this->renderNavBar(),
                $this->renderLeftPanel(),
                $this->renderSidebar(),
            ])
        );
    }

    // ─────────── 导航栏 ───────────

    private function renderNavBar(): VNode
    {
        return VNode::h('div', ['style' => 'left:0;top:0;width:1280px;height:54px;background:#FFFFFF;z-index:10'], [
            // Logo
            VNode::h('div', ['style' => 'left:16px;top:9px;width:36px;height:36px;border-radius:18px;background:#00A1D6'],
                VNode::h('span', ['style' => 'left:9px;top:5px;font-size:20px;color:#FFFFFF;font-weight:bold'], 'P')
            ),
            // Tab buttons — 使用显式 min-width 确保 flex 布局分配正确宽度
            VNode::h('div', ['style' => 'left:64px;top:0;width:600px;height:54px;display:flex;flex-direction:row;align-items:center;gap:2px'],
                $this->renderNavTabs()
            ),
            // Search
            VNode::h('div', ['style' => 'left:800px;top:11px;width:200px;height:32px;border-radius:16px;background:#F0F2F5;border:1px solid #E0E4EA'],
                VNode::h('span', ['style' => 'left:14px;top:7px;font-size:13px;color:#999999'], '搜索视频...')
            ),
            // Buttons
            VNode::h('button', ['style' => 'left:1040px;top:11px;width:56px;height:32px;border-radius:4px;font-size:13px;color:#FFFFFF;background:#00A1D6;border:none'], '登录'),
            VNode::h('button', ['style' => 'left:1102px;top:11px;width:56px;height:32px;border-radius:4px;font-size:13px;color:#00A1D6;background:#FFFFFF;border:1px solid #00A1D6'], '注册'),
        ]);
    }

    private function renderNavTabs(): array
    {
        $children = [];
        foreach ($this->navTabs as $tab) {
            $isActive = $this->activeTab === $tab;
            $bg   = $isActive ? '#00A1D6' : 'transparent';
            $fg   = $isActive ? '#FFFFFF' : '#61666D';
            // min-width 保证 flex 布局正确分配非零宽度，避免按钮重叠
            $children[] = VNode::h('button', [
                'style'    => "min-width:44px;height:32px;padding:0 12px;border-radius:4px;font-size:13px;border:none;background:{$bg};color:{$fg}",
                '@click'   => 'setActiveTab',
                'click-arg' => $tab,
            ], $tab);
        }
        return $children;
    }

    // ─────────── 左侧面板 ───────────

    private function renderLeftPanel(): VNode
    {
        return VNode::h('div', ['style' => 'left:16px;top:64px;width:880px;height:646px'], [
            $this->renderCurrentSlide(),
            $this->renderDots(),
            $this->renderSectionHeader(),
            $this->renderVideoGrid(),
        ]);
    }

    // ─────────── 轮播 ───────────

    private function renderCurrentSlide(): VNode
    {
        $idx = $this->currentSlide;
        $count = count($this->carouselData);
        if ($idx < 0 || $idx >= $count) {
            $idx = 0;
        }

        $bg     = $this->slideBgColors[$idx] ?? '#1565C0';
        $accent = $this->slideAccentColors[$idx] ?? '#1976D2';
        $light  = $this->slideLightColors[$idx] ?? '#B0C4DE';
        $dark   = $this->slideDarkColors[$idx] ?? '#1557B0';

        return VNode::h('div', ['style' => "left:0;top:0;width:880px;height:300px;overflow:hidden;border-radius:8px"],
            VNode::h('div', ['style' => "left:0;top:0;width:880px;height:300px;background:{$bg}"], [
                // Text area
                VNode::h('div', ['style' => 'left:40px;top:60px;width:500px;height:180px'], [
                    VNode::h('span', ['style' => 'left:0;top:0;font-size:28px;color:#FFFFFF;font-weight:bold'], $this->slideTitle),
                    VNode::h('div', ['style' => "left:0;top:42px;width:400px;height:20px"],
                        VNode::h('span', ['style' => "font-size:14px;color:{$light}"], $this->slideSubtitle)
                    ),
                    VNode::h('div', ['style' => "left:0;top:76px;width:120px;height:36px;border-radius:18px;background:{$accent};border:1px solid {$light}"],
                        VNode::h('span', ['style' => 'left:18px;top:9px;font-size:13px;color:#FFFFFF'], '立即观看')
                    ),
                ]),
                // Decorative circles
                VNode::h('div', ['style' => "left:600px;top:30px;width:240px;height:240px;border-radius:120px;background:{$accent}"]),
                VNode::h('div', ['style' => "left:660px;top:80px;width:120px;height:120px;border-radius:60px;background:{$dark}"]),
            ])
        );
    }

    // ─────────── 指示点 ───────────

    private function renderDots(): VNode
    {
        $count = count($this->carouselData);
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $isActive = $this->currentSlide === $i;
            $w = $isActive ? '24px' : '8px';
            $bg = $isActive ? '#00A1D6' : '#CCD0D7';
            $children[] = VNode::h('button', [
                'style'     => "width:{$w};height:8px;border-radius:4px;border:none;padding:0;background:{$bg}",
                '@click'    => 'goToSlide',
                'click-arg' => (string)$i,
            ]);
        }
        return VNode::h('div', ['style' => 'left:0;top:308px;width:880px;height:20px;display:flex;flex-direction:row;justify-content:center;gap:8px'], $children);
    }

    // ─────────── 区域标题 ───────────

    private function renderSectionHeader(): VNode
    {
        return VNode::h('div', ['style' => 'left:0;top:336px;width:880px;height:36px;display:flex;flex-direction:row;align-items:center'], [
            VNode::h('span', ['style' => 'font-size:18px;font-weight:bold;color:#18191C'], '热门推荐'),
            VNode::h('span', ['style' => 'margin-left:12px;font-size:13px;color:#999999'], '发现更多精彩内容'),
            VNode::h('span', ['style' => 'margin-left:auto;font-size:13px;color:#00A1D6'], '查看更多 >'),
        ]);
    }

    // ─────────── 视频网格 ───────────

    private function renderVideoGrid(): VNode
    {
        // 外层滚动容器：显式 background:#E8ECF2 避免默认暗色背景
        return VNode::h('div', ['style' => 'left:0;top:376px;width:880px;height:260px;overflow-y:auto;background:#E8ECF2', ':scroll-top' => 'scrollTopGrid'],
            // grid container: 显式 height 确保 contentHeight 计算正确
            VNode::h('div', ['style' => 'left:0;top:0;width:880px;height:612px;display:grid;grid-template-columns:repeat(2,434px);grid-template-rows:repeat(3,196px);gap:12px'],
                $this->renderVideoCards()
            )
        );
    }

    private function renderVideoCards(): array
    {
        $children = [];
        foreach ($this->videos as $v) {
            $title   = (string)($v['title'] ?? '');
            $views   = (string)($v['views'] ?? '');
            $time    = (string)($v['time'] ?? '');
            $channel = (string)($v['channel'] ?? '');
            $dur     = (string)($v['duration'] ?? '');
            $tc      = (string)($v['thumbColor'] ?? '#78909C');

            $children[] = VNode::h('div', ['style' => 'width:434px;height:196px;background:#FFFFFF;border-radius:8px'], [
                // Thumbnail
                VNode::h('div', ['style' => "width:434px;height:136px;border-radius:8px 8px 0 0;background:{$tc}"], [
                    VNode::h('span', ['style' => 'left:12px;top:10px;font-size:14px;color:#FFFFFF;font-weight:bold'], $title),
                    VNode::h('div', ['style' => 'left:12px;top:108px;height:20px;padding:0 6px;background:#000000;border-radius:3px'],
                        VNode::h('span', ['style' => 'font-size:11px;color:#FFFFFF'], $dur)
                    ),
                ]),
                // Info
                VNode::h('div', ['style' => 'left:12px;top:142px;width:410px;height:50px'], [
                    VNode::h('span', ['style' => 'font-size:13px;font-weight:bold;color:#18191C'], $title),
                    VNode::h('div', ['style' => 'left:0;top:22px;width:410px;height:24px;display:flex;flex-direction:row;align-items:center;gap:8px'], [
                        VNode::h('span', ['style' => 'font-size:11px;color:#00A1D6;background:#F0F8FF;padding:1px 5px;border-radius:3px'], $channel),
                        VNode::h('span', ['style' => 'font-size:11px;color:#999999'], $views . '播放'),
                        VNode::h('span', ['style' => 'font-size:11px;color:#999999'], $time),
                    ]),
                ]),
            ]);
        }
        return $children;
    }

    // ─────────── 侧边栏 ───────────

    private function renderSidebar(): VNode
    {
        return VNode::h('div', ['style' => 'left:912px;top:64px;width:352px;height:646px;background:#FFFFFF;border-radius:8px'], [
            // Header
            VNode::h('div', ['style' => 'left:16px;top:14px;width:320px;height:24px'],
                VNode::h('span', ['style' => 'font-size:16px;font-weight:bold;color:#18191C'], '推荐视频')
            ),
            // 滚动容器
            VNode::h('div', ['style' => 'left:16px;top:46px;width:320px;height:588px;overflow-y:auto;background:#FFFFFF', ':scroll-top' => 'scrollTopSide'],
                $this->renderSidebarItems()
            ),
        ]);
    }

    private function renderSidebarItems(): array
    {
        $children = [];
        // 缩略图颜色池
        $colors = ['#E53935', '#FFC107', '#43A047', '#1E88E5', '#FF9800'];
        $i = 0;
        foreach ($this->sidebarItems as $item) {
            $title   = (string)($item['title'] ?? '');
            $views   = (string)($item['views'] ?? '');
            $time    = (string)($item['time'] ?? '');
            $channel = (string)($item['channel'] ?? '');
            $tc = $colors[$i % count($colors)];
            $yPos = $i * 80;
            $children[] = VNode::h('div', ['style' => "left:0;top:{$yPos}px;width:320px;height:76px;background:#FFFFFF;border-bottom:1px solid #F0F2F5"], [
                // 缩略图占位
                VNode::h('div', ['style' => "left:0;top:0;width:96px;height:76px;background:{$tc};border-radius:4px"],
                    VNode::h('span', ['style' => 'left:40px;top:28px;font-size:20px;color:#FFFFFF'], '▶')
                ),
                // 标题
                VNode::h('span', ['style' => 'left:108px;top:8px;width:204px;font-size:13px;color:#18191C;font-weight:bold'], $title),
                // 频道 · 播放量 · 时间
                VNode::h('div', ['style' => 'left:108px;top:32px;width:204px;display:flex;flex-direction:row;align-items:center;gap:6px'], [
                    VNode::h('span', ['style' => 'font-size:11px;color:#00A1D6;background:#F0F8FF;padding:1px 4px;border-radius:2px'], $channel),
                    VNode::h('span', ['style' => 'font-size:11px;color:#999999'], $views . '播放'),
                    VNode::h('span', ['style' => 'font-size:11px;color:#999999'], $time),
                ]),
            ]);
            $i++;
        }
        return $children;
    }

    // ──────────────────── bind ────────────────────

    public function setBindValue(string $key, string $val): void
    {
        switch ($key) {
            case 'activeTab': $this->activeTab = $val; break;
            case 'currentSlide': $this->currentSlide = (int)$val; break;
            case 'slideTitle': $this->slideTitle = $val; break;
            case 'slideSubtitle': $this->slideSubtitle = $val; break;
            case 'scrollTopGrid': $this->scrollTopGrid = $val; break;
            case 'scrollTopSide': $this->scrollTopSide = $val; break;
            default: return;
        }
        $this->markDirty();
    }

    public function getBindValue(string $key): string
    {
        return match ($key) {
            'activeTab' => $this->activeTab,
            'currentSlide' => (string)$this->currentSlide,
            'slideTitle' => $this->slideTitle,
            'slideSubtitle' => $this->slideSubtitle,
            'scrollTopGrid' => $this->scrollTopGrid,
            'scrollTopSide' => $this->scrollTopSide,
            default => '',
        };
    }

    public function dispatchClick(string $handler, ?string $arg = null): void
    {
        switch ($handler) {
            case 'setActiveTab': $this->setActiveTab($arg ?? ''); break;
            case 'goToSlide': $this->goToSlide($arg ?? '0'); break;
            default:
                if ($this->parent !== null) {
                    $this->parent->dispatchClick($handler, $arg);
                }
                break;
        }
    }

    public function dispatchKey(string $handler, string $action, int $keyCode, string $char): void
    {
        if ($this->parent !== null) {
            $this->parent->dispatchKey($handler, $action, $keyCode, $char);
        }
    }
}
