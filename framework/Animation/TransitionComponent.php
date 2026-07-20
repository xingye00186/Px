<?php

namespace Px\Animation;
use Px\Render\RenderTreeManager;

use Px\Component\ReactiveComponent;
use Px\Dom\VNode;
use Px\Animation\AnimationManager;
use Px\Animation\TransitionController;
use Px\Render\RenderNode;
use Px\Css\CssMappings;

/**
 * TransitionComponent — 过渡组件
 *
 * 对标 Vue 3 的 <Transition> 组件：
 * - 基于 v-show 语义（visibility 控制）
 * - 管理 enter/leave 过渡动画
 * - 通过 CSS transition 实现平滑过渡
 *
 * 使用方式：
 * ```php
 * // 模板中使用
 * <transition name="fade">
 *   <div v-show="isVisible">Content</div>
 * </transition>
 * ```
 *
 * AOT 兼容: 使用 protected static 数组，不使用闭包
 */
class TransitionComponent extends ReactiveComponent
{
    /** @var string 过渡名称（用于 CSS 类名前缀） */
    protected string $name = 'v';

    /** @var TransitionController 过渡控制器 */
    protected TransitionController $controller;

    /** @var string show 绑定值（"true" / "false"） */
    protected string $show = 'false';

    /** @var array 缓存的子节点渲染结果 */
    protected ?VNode $childVNode = null;

    public function __construct()
    {
        parent::__construct();
        $this->controller = new TransitionController(null, $this->name);
    }

    /**
     * 设置过渡名称。
     *
     * @param string $name CSS 类名前缀
     */
    public function setName(string $name): void
    {
        $this->name = $name;
        $this->controller->name = $name;
    }

    /**
     * 获取过渡控制器。
     */
    public function getController(): TransitionController
    {
        return $this->controller;
    }

    // ============================================================
    // ReactiveComponent 实现
    // ============================================================

    public function render(): VNode
    {
        if ($this->childVNode !== null) {
            return $this->childVNode;
        }

        // 默认渲染一个占位 div（实际内容由 children 传入）
        return VNode::h('div', [
            'style' => 'position:absolute;left:0;top:0',
        ]);
    }

    /**
     * 设置子节点 VNode（由编译器生成的代码调用）。
     *
     * @param VNode $child 单一子节点
     */
    public function setChild(VNode $child): void
    {
        $this->childVNode = $child;
    }

    public function setBindValue(string $key, string $value): void
    {
        // 转发 bind 值给子组件
        if ($this->childVNode !== null && $this->childVNode->isComponent()) {
            $instance = $this->childVNode->componentInstance;
            if ($instance !== null) {
                $instance->setBindValue($key, $value);
            }
        }

        // 处理 show 绑定（"true" / "false"）
        if ($key === 'show') {
            $nowShowing = ($value === 'true');
            $wasShowing = ($this->show === 'true');

            if ($nowShowing !== $wasShowing) {
                $this->show = $value;
                $this->handleShowChange($nowShowing);
            }
        }
    }

    public function getBindValue(string $key): string
    {
        if ($key === 'show') {
            return $this->show;
        }

        // 从子组件获取
        if ($this->childVNode !== null && $this->childVNode->isComponent()) {
            $instance = $this->childVNode->componentInstance;
            if ($instance !== null) {
                return $instance->getBindValue($key);
            }
        }

        return '';
    }

    // ============================================================
    // 过渡逻辑
    // ============================================================

    /**
     * 处理 show 属性变化。
     *
     * @param bool $nowShowing 是否显示
     */
    private function handleShowChange(bool $nowShowing): void
    {
        if ($nowShowing) {
            // 显示 → 进入动画
            $this->controller->enter();
        } else {
            // 隐藏 → 离开动画
            $this->controller->leave();
        }
    }

    /**
     * 设置子节点的 visibility 样式。
     *
     * @param bool $visible 是否可见
     */
    private function setChildVisibility(bool $visible): void
    {
        if ($this->childVNode === null) {
            return;
        }

        $style = $this->childVNode->props['style'] ?? '';

        if ($visible) {
            // 移除 visibility:hidden
            $style = $this->removeVisibilityHidden($style);
        } else {
            // 添加 visibility:hidden
            if (stripos($style, 'visibility:hidden') === false) {
                $style .= ';visibility:hidden';
            }
        }

        $this->childVNode->props['style'] = $style;
    }

    /**
     * 从样式字符串中移除 visibility:hidden。
     */
    private function removeVisibilityHidden(string $style): string
    {
        $parts = explode(';', $style);
        $filtered = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (stripos($part, 'visibility:hidden') === false) {
                $filtered[] = $part;
            }
        }
        return implode(';', $filtered);
    }

    // ============================================================
    // 生命周期（与 RenderTreeManager 集成）
    // ============================================================

    public function onMount(): void
    {
    }

    public function onUnmount(): void
    {
        // 取消所有动画
        AnimationManager::getInstance()->cancelAllTransitions(
            $this->controller->node
        );
    }

    public function onUpdated(): void
    {
        // 布局完成后更新 lastX/lastY（用于 FLIP 算法）
    }
}
