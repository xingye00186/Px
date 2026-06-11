/**
 * dump_layout.js — 浏览器布局提取脚本
 *
 * 遍历页面 DOM 树，提取每个可见元素的坐标/尺寸、计算样式和文本内容。
 * 将结果序列化为 JSON 写入隐藏的 <textarea>，供 PHP 端 auto_test.php 与引擎布局对比。
 *
 * 工作流程:
 *   1. 由 generate_project_ref.php / generate_browser_refs.php 注入到包装 HTML 中
 *   2. Edge headless --dump-dom 渲染页面，JS 自动执行
 *   3. 提取的 JSON 写入 <textarea id="layout-output">
 *   4. PHP 端从 DOM 中提取该 textarea 的内容
 *
 * 提取的属性包含: 坐标(x/y/w/h)、文本内容、以及 30+ 个关键 CSS 计算样式
 *   (font-size, color, background-color, display, padding, margin, border 等)
 *
 * 输出格式: { browser, viewport, elements: [{ tag, x, y, w, h, text, styles, depth }] }
 *
 * 用法: 由 generate_browser_refs.php / generate_project_ref.php 间接调用
 *   不直接运行，作为 JS 片段注入 HTML 通过 Edge headless --dump-dom 执行
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
