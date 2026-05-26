<?php
/**
 * PDU Framework 单元测试套件
 * ==============================
 * 
 * ## 目录结构
 * 
 * ```
 * tests/
 * ├── unit/                              # 单元测试 (本文档所在目录)
 * │   ├── bootstrap.php                  # 测试基础设施 (autoload + 断言函数)
 * │   ├── ReactiveComponentTest.php      # VNode 缓存与 Dirty 追踪
 * │   ├── HitTestTest.php               # 命中测试 (反向遍历点击检测)
 * │   ├── LayoutResolverTest.php         # CSS 布局引擎 (layer/zIndex/flex/grid)
 * │   ├── VNodeRendererTest.php          # VNode 渲染器 (元素收集/按layer分组)
 * │   ├── SfcCompilerVIfTest.php         # 编译期 v-if 优化
 * │   ├── PlatformTest.php              # Platform 接口 (SOLID/DIP)
 * │   └── README.md                      # 本文档
 * ├── sfc-compiler-test.php              # SFC 编译器集成测试 (已有)
 * ├── parser-robustness-test.php         # 模板解析器鲁棒性测试 (已有)
 * └── verify-layout.php                  # 布局验证测试 (已有)
 * ```
 * 
 * ## 快速开始
 * 
 * ```bash
 * # 运行全部单元测试
 * php tests/unit/ReactiveComponentTest.php
 * php tests/unit/HitTestTest.php
 * php tests/unit/LayoutResolverTest.php
 * php tests/unit/VNodeRendererTest.php
 * php tests/unit/SfcCompilerVIfTest.php
 * php tests/unit/PlatformTest.php
 * 
 * # Windows 下批量运行
 * for %f in (tests\unit\*Test.php) do @php %f
 * ```
 * 
 * ## 测试覆盖
 * 
 * ### 1. ReactiveComponentTest — VNode 缓存 & Dirty 追踪
 * 
 * 验证 Vue 3 风格的惰性 VNode 树重建：
 * - 首次调用 getVNodeTree() 触发 render()
 * - 二次调用返回缓存 (不调用 render())
 * - markDirty() 清除缓存，下次调用重建
 * - performUpdate() 触发渲染请求回调
 * 
 * ### 2. HitTestTest — 命中测试
 * 
 * 验证反向遍历 + Layer 感知的点击命中逻辑：
 * - 后渲染兄弟在重叠区域优先命中 (视觉上层)
 * - 子节点优先于父节点被命中
 * - 无 @click 的元素不被命中
 * - 坐标越界返回 null
 * - 深度嵌套的 @click 元素可被命中
 * 
 * ### 3. LayoutResolverTest — CSS 布局引擎
 * 
 * 验证四种布局模式 + layer 继承：
 * - 子节点继承父节点的 z-index layer
 * - 自身 z-index 可覆盖继承值
 * - setClassStyles() 运行时注入 CSS class
 * - inline style 覆盖 class style
 * - block/flex/grid 布局计算正确
 * 
 * ### 4. VNodeRendererTest — 渲染器
 * 
 * 验证 VNode 树渲染：
 * - 无运行时 v-if 检查 (编译期已处理)
 * - 元素按 layer 分组
 * - 类型正确的渲染元素
 * - beginFrame/endFrame 调用
 * 
 * ### 5. SfcCompilerVIfTest — 编译期 v-if
 * 
 * 验证编译期 v-if 代码生成：
 * - v-if 子节点生成 if($this->cond) 闭包构建器
 * - false 分支的 VNode::h() 在 if 块内 (仅 true 时执行)
 * - 连续相同条件子节点合并在同一 if 块
 * - v-if prop 从生成的 VNode 中移除
 * - 无 v-if 时仍用内联数组
 * 
 * ### 6. PlatformTest — Platform 接口
 * 
 * 验证 SOLID/DIP 合规：
 * - Platform 接口不暴露 hwnd
 * - init() 封装窗口创建，返回 RenderContext
 * - shutdown() 清理资源
 * - Mock Platform 可被 Application 使用
 * 
 * ## 技术说明
 * 
 * - **测试框架**: 纯 PHP，无第三方依赖。使用自定义 `test()` 函数 + 断言辅助函数
 * - **PHP 兼容**: PHP 7.4+，无 PHP 8 特性
 * - **反射**: 私有方法通过 ReflectionMethod 访问 (仅测试使用)
 * - **Mock**: 使用内存 mock 对象，不依赖文件系统或外部服务
 * - **退出码**: 0 = 全部通过, 1 = 存在失败
 */
