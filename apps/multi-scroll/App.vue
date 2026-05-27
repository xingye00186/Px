<template>
    <div style="width:820px;height:620px;background:0x1A1A1A">
        <!-- 标题 -->
        <span style="left:10px;top:8px;font-size:18px;color:0xFFFFFF">多视口滚动测试 —— 左：垂直 | 右：横+纵 (Shift+滚轮)</span>

        <!-- 左侧面板：纯垂直滚动 -->
        <div style="overflow-y:auto;left:10px;top:40px;width:390px;height:570px;background:0x252525"
             :scroll-top="leftTop">
            <div v-for="item in leftItems" :key="item.id"
                 style="height:55px;width:370px;margin:3px 0 0 0;background:0x333333">
                <span style="left:8px;top:6px;font-size:14px;color:0xCCCCCC">{{ item.text }}</span>
                <span style="left:8px;top:30px;font-size:12px;color:0x888888">#{{ item.id }}</span>
            </div>
        </div>

        <!-- 右侧面板：横向+纵向滚动 -->
        <div style="overflow:auto;left:410px;top:40px;width:390px;height:570px;background:0x252525"
             :scroll-top="rightTop"
             :scroll-left="rightLeft">
            <!-- 宽内容条——强制横向溢出 -->
            <div style="left:0;width:1400px;height:36px;background:0x336699">
                <span style="left:8px;top:8px;font-size:14px;color:0xFFFFFF">
                    宽内容区域 — 横滚动条测试 | Col_A | Col_B | Col_C | Col_D | Col_E | Col_F | Col_G | Col_H | Col_I | Col_J | Col_K | Col_L | Col_M
                </span>
            </div>
            <div style="left:0;width:1400px;height:24px;background:0x444444">
                <span style="left:8px;top:4px;font-size:12px;color:0xAAAAAA">
                    列1: ID | 列2: 名称 | 列3: 描述 | 列4: 状态 | 列5: 优先级 | 列6: 负责人 | 列7: 截止日期
                </span>
            </div>
            <div v-for="item in rightItems" :key="item.id"
                 style="left:0;height:45px;width:1400px;margin:2px 0 0 0;background:0x333333">
                <span style="left:8px;top:6px;font-size:13px;color:0xCCCCCC">{{ item.text }}</span>
                <span style="left:8px;top:26px;font-size:11px;color:0x888888">
                    状态: {{ item.status }} | 优先级: {{ item.priority }}
                </span>
            </div>
        </div>
    </div>
</template>

<script lang="php">
public string $leftTop = "0";
public string $rightTop = "0";
public string $rightLeft = "0";

public array $leftItems = [];
public array $rightItems = [];

public function __construct(?string $componentId = null)
{
    parent::__construct($componentId ?? 'multi-scroll');
    for ($i = 1; $i <= 25; $i++) {
        $this->leftItems[] = [
            'id' => "L" . $i,
            'text' => "左侧垂直项目 {$i} —— " . str_repeat("内容 ", 3)
        ];
    }
    for ($i = 1; $i <= 20; $i++) {
        $status = ($i % 3 === 0) ? '已完成' : (($i % 3 === 1) ? '进行中' : '待开始');
        $priority = ($i <= 5) ? '高' : (($i <= 12) ? '中' : '低');
        $this->rightItems[] = [
            'id' => "R" . $i,
            'text' => "R{$i} | 项目名称-{$i} | 超长描述文本用于横向滚动条测试 " . str_repeat("填充文字 ", 15),
            'status' => $status,
            'priority' => $priority
        ];
    }
}
</script>

<style>
</style>
