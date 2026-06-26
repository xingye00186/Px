## 缺失 CSS 特性实现计划

### Context
框架共有 20 个缺失 CSS 特性未实现（B-043, B-045~B-062）。这些特性分布在渲染层、布局层和平台层。经代码审查发现：
- **B-057 text-decoration**：解析、序列化、元素传递、渲染代码**均已存在**，但测试仍有 12 个 CRITICAL
- 部分特性只有 CSS 解析层支持，渲染/布局层缺失

### 分阶段计划

#### Phase 1：验证已有实现（text-decoration）
- B-057：排查 text-decoration 测试失败的真正原因（可能是字体度量/布局位置而非渲染缺失）
- 修复后构建验证

#### Phase 2：渲染层添加（CssMappings 已解析，缺 GDI/Skia 渲染）
| 特性 | 影响 Case | CRITICAL | 实现位置 |
|------|----------|:--------:|---------|
| B-045 text-shadow | case-033 | 4 | VNodeRenderer + Gdi/SkiaRenderContext + C++ sk_render |
| B-046 letter-spacing | case-034 | 9 | Gdi/SkiaRenderContext::drawText |
| B-047 text-indent | case-035 | 17 | VNodeRenderer::makeSpanElement 首行偏移 |
| B-052 word-wrap | case-040 | 8 | TextOverflowProcessor 断词逻辑 |
| B-055 outline-offset | case-043 | 22 | 布局偏移 + 渲染 |

#### Phase 3：背景/图片渲染
| B-048 background-repeat | case-036 | 12 | 平铺模式实现 |
| B-053 background-clip | case-041 | 12 | 裁剪区域 |
| B-056 background-attachment | case-044 | 11 | 滚动/固定背景 |
| B-059 object-fit | case-047 | 15 | 替换元素适配 |

#### Phase 4：布局层改动
| B-043 transform:% | case-011 | 1 | 百分比解析 + 布局计算 |
| B-051 vertical-align | case-039 | 26 | 行内基线与偏移 |
| B-058 appearance/cursor | case-046 | 34 | 控件样式 + 光标 |

#### Phase 5：大型新实现
| B-049 direction:rtl | case-037 | 9 | 修复崩溃 + 反向布局 |
| B-050 list-style | case-038 | 31 | 修复崩溃 + ul/ol/li 支持 |
| B-054 font-variant | case-042 | 12 | 小型大写等字体变体 |
| B-060 table | case-048 | — | 表格布局策略 |
| B-061 multi-column | case-049 | 9 | 多列布局 |
| B-062 text-emphasis | case-050 | 23 | 文字标记渲染 |

### 每步流程
1. 读 case 的 .vue/.html 理解需求
2. 确定实现位置（哪层缺）
3. 写实现代码
4. `build.bat css-test` 重编
5. `php test_pipeline.php --case=case-xxx --skip-build --browser-engine-el-compare` 单 case 验证
6. 截图对比缩小差异
7. 更新 B-xxx 状态 → 提交

### 验证方式
- 对每个 case：`php test_pipeline.php --case=case-xxx --skip-build --browser-engine-el-compare`
- 检查 element_compare_report.md 的 CRITICAL/MAJOR 减少
- 对渲染特性：`--screenshot=` 保存引擎截图与浏览器截图对比