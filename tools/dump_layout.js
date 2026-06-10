/**
 * dump_layout.js — 浏览器布局提取函数
 *
 * 遍历页面 DOM 树，提取每个元素的:
 *   - getBoundingClientRect() 坐标/尺寸
 *   - getComputedStyle() 关键 CSS 属性
 *   - 文本内容
 *
 * 输出 JSON 可通过 Px 框架的 auto_test.php 与 engine_layout.json 对比。
 *
 * 用法: 由 generate_browser_refs.php 注入包装 HTML 后通过 Edge headless --dump-dom 提取
 */
(function() {
    'use strict';

    // 需要提取的 CSS 属性列表（与 engine_layout.json 的 style 字段对齐）
    var STYLE_PROPERTIES = [
        'font-size',
        'font-weight',
        'color',
        'background-color',
        'border-left-width',
        'border-left-color',
        'border-width',
        'border-color',
        'border-radius',
        'text-align',
        'display',
        'padding-top',
        'padding-left',
        'padding-right',
        'padding-bottom',
        'margin-top',
        'margin-left',
        'margin-right',
        'margin-bottom',
        'font-family',
        'line-height',
        'white-space',
        'opacity',
        'width',
        'height',
        'position',
        'top',
        'left',
        'flex-direction',
        'align-items',
        'justify-content',
        'flex-wrap',
        'gap',
        'overflow-x',
        'overflow-y'
    ];

    /**
     * 提取单个元素的布局和样式信息。
     */
    function extractElement(el, depth) {
        var rect = el.getBoundingClientRect();
        var style = window.getComputedStyle(el);
        var tag = el.tagName.toLowerCase();

        // 跳过不可见元素
        if (style.display === 'none') return null;

        var result = {
            tag: tag,
            x: Math.round(rect.left),
            y: Math.round(rect.top),
            w: Math.round(rect.width),
            h: Math.round(rect.height),
            depth: depth,
            styles: {}
        };

        // 提取文本内容（截断过长文本）
        var text = el.textContent.trim();
        if (text.length > 0) {
            if (text.length > 200) text = text.substring(0, 200);
            result.text = text;
        }

        // 提取 computedStyle
        for (var i = 0; i < STYLE_PROPERTIES.length; i++) {
            var prop = STYLE_PROPERTIES[i];
            var val = style.getPropertyValue(prop);
            // 跳过默认值以减少体积
            if (val !== '' && val !== 'none' && val !== 'normal') {
                result.styles[prop] = val;
            }
        }

        return result;
    }

    /**
     * 递归遍历 DOM 树，收集所有可见元素的布局数据。
     */
    function walkDOM(node, depth, results) {
        if (node.nodeType !== 1) return; // 只处理 Element 节点
        var tag = node.tagName.toLowerCase();

        // 跳过元标签
        if (tag === 'script' || tag === 'style' || tag === 'textarea' ||
            tag === 'noscript' || tag === 'meta' || tag === 'link' ||
            tag === 'head' || tag === 'html') {
            return;
        }

        var el = extractElement(node, depth);
        if (el) results.push(el);

        for (var i = 0; i < node.children.length; i++) {
            walkDOM(node.children[i], depth + 1, results);
        }
    }

    // 找到 root 容器(第一个有 width/style 的 div)
    function findRootContainer() {
        var body = document.body;
        if (!body) return null;

        // 找到第一个有宽度的容器 div
        for (var i = 0; i < body.children.length; i++) {
            var child = body.children[i];
            if (child.tagName === 'DIV') {
                var rect = child.getBoundingClientRect();
                if (rect.width > 500) return child;
            }
        }
        return body;
    }

    // 主逻辑
    var container = findRootContainer();
    if (!container) container = document.body;

    var elements = [];
    walkDOM(container, 0, elements);

    var output = {
        browser: (navigator && navigator.userAgent) ? navigator.userAgent : 'unknown',
        viewport: {
            width: window.innerWidth,
            height: window.innerHeight
        },
        elements: elements
    };

    // 写入输出容器
    var outputEl = document.getElementById('layout-output');
    if (outputEl) {
        outputEl.textContent = JSON.stringify(output);
    }

    return output; // 供调试用
})();
