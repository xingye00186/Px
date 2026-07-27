# CSS Test Sandbox — 测试报告

**运行时间**: 2026-07-27 12:46:19 | **总耗时**: 52.8s

| 用例 | 构建 | 布局 | 多帧 | 浏览器 | 元素对比 | Phase L | Phase G | 差异 | 截图 | 结果 | 耗时 |
|------|------|------|------|--------|----------|---------|---------|------|------|------|------|
| case-001-wrapper-x | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 41.6s |
| case-002-auto-height | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 2diff 🔴1 | ⏭️ | ❌ 失败 | 0.1s |
| case-003-basic-block | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 0.1s |
| case-004-flex-layout | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 0.2s |
| case-005-grid-layout | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 55diff 🔴51 | ⏭️ | ❌ 失败 | 0.1s |
| case-006-typography | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 2diff 🔴1 | ⏭️ | ❌ 失败 | 0.2s |
| case-007-border-styles | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 92diff 🔴51 | ⏭️ | ❌ 失败 | 0.2s |
| case-008-box-shadow | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 34diff 🟡34 | ⏭️ | ❌ 失败 | 0.1s |
| case-009-outline | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 31diff 🟡31 | ⏭️ | ❌ 失败 | 0.1s |
| case-010-display-none | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 37diff 🔴1 | ⏭️ | ❌ 失败 | 0.1s |
| case-011-position-absolute | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 31diff 🔴3 | ⏭️ | ❌ 失败 | 0.1s |
| case-012-position-relative | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 42diff 🔴1 | ⏭️ | ❌ 失败 | 0.1s |
| case-013-z-index | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 84diff 🔴84 | ⏭️ | ❌ 失败 | 0.1s |
| case-014-overflow-hidden | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 27diff 🔴22 | ⏭️ | ❌ 失败 | 0.1s |
| case-015-min-max-height | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 123diff 🔴51 | ⏭️ | ❌ 失败 | 0.2s |
| case-016-margin-collapse | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 86diff 🔴2 | ⏭️ | ❌ 失败 | 0.2s |
| case-017-negative-margin | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 3diff 🔴1 | ⏭️ | ❌ 失败 | 0.1s |
| case-018-opacity | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 28diff 🟡28 | ⏭️ | ❌ 失败 | 0.1s |
| case-019-visibility | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 235diff 🔴145 | ⏭️ | ❌ 失败 | 0.2s |
| case-020-text-align | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 126diff 🔴3 | ⏭️ | ❌ 失败 | 0.1s |
| case-021-line-height | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 140diff 🔴4 | ⏭️ | ❌ 失败 | 0.2s |
| case-022-white-space | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 8diff 🔴2 | ⏭️ | ❌ 失败 | 0.2s |
| case-023-word-break | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 81diff 🔴10 | ⏭️ | ❌ 失败 | 0.2s |
| case-024-font-weight | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 60diff 🔴5 | ⏭️ | ❌ 失败 | 0.1s |
| case-025-english-text | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 6diff 🔴3 | ⏭️ | ❌ 失败 | 0.2s |
| case-026-font-style | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 106diff 🔴6 | ⏭️ | ❌ 失败 | 0.1s |
| case-027-scroll-diagnostic | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 1diff | ⏭️ | ❌ 失败 | 0.6s |
| case-028-scroll-block | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 0.6s |
| case-029-scroll-flex-col | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 1diff | ⏭️ | ❌ 失败 | 0.8s |
| case-030-scroll-flex-row | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 1diff | ⏭️ | ❌ 失败 | 0.3s |
| case-031-scroll-grid | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 1diff | ⏭️ | ❌ 失败 | 0.7s |
| case-032-scroll-relative | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 1diff 🔴1 | ⏭️ | ❌ 失败 | 0.7s |
| case-033-text-shadow | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 30diff 🟡30 | ⏭️ | ❌ 失败 | 0.2s |
| case-034-letter-spacing | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 38diff 🟡38 | ⏭️ | ❌ 失败 | 0.1s |
| case-035-text-indent | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 141diff 🔴73 | ⏭️ | ❌ 失败 | 0.2s |
| case-036-background-repeat | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 31diff 🟡31 | ⏭️ | ❌ 失败 | 0.2s |
| case-037-direction-rtl | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 88diff 🔴56 | ⏭️ | ❌ 失败 | 0.2s |
| case-038-list-style | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 49diff 🔴10 | ⏭️ | ❌ 失败 | 0.2s |
| case-039-vertical-align | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 169diff 🔴2 | ⏭️ | ❌ 失败 | 0.2s |
| case-040-word-wrap | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 139diff 🔴18 | ⏭️ | ❌ 失败 | 0.3s |
| case-041-background-clip | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 74diff 🟡42 | ⏭️ | ❌ 失败 | 0.2s |
| case-042-font-variant | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 45diff 🟡45 | ⏭️ | ❌ 失败 | 0.2s |
| case-043-resize | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 63diff 🟡44 | ⏭️ | ❌ 失败 | 0.2s |
| case-044-background-attachment | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 44diff 🟡35 | ⏭️ | ❌ 失败 | 0.2s |
| case-045-text-decoration | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 42diff 🟡34 | ⏭️ | ❌ 失败 | 0.2s |
| case-046-appearance | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 237diff 🔴189 | ⏭️ | ❌ 失败 | 0.2s |
| case-047-object-fit | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 46diff 🟡30 | ⏭️ | ❌ 失败 | 0.2s |
| case-048-table-props | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 287diff 🔴102 | ⏭️ | ❌ 失败 | 0.2s |
| case-049-multi-column | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 321diff 🔴308 | ⏭️ | ❌ 失败 | 0.2s |
| case-050-text-emphasis | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 276diff 🔴50 | ⏭️ | ❌ 失败 | 0.2s |
| case-051-aspect-ratio | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 12diff 🔴9 | ⏭️ | ❌ 失败 | 0.1s |
| case-052-flow-root | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 3diff 🔴2 | ⏭️ | ❌ 失败 | 0.1s |
| case-053-intrinsic-sizing | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 7diff 🔴4 | ⏭️ | ❌ 失败 | 0.1s |
| case-054-inline-block-nest | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 17diff 🔴9 | ⏭️ | ❌ 失败 | 0.1s |
| case-055-sticky-multi | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 18diff 🔴4 | ⏭️ | ❌ 失败 | 0.1s |

**汇总**: 4 ✅ / 51 ❌ / 55 总计 (总耗时: 52.8s)

## 回归判定

> 🔴 **检测到回归** — 与上一次运行相比，以下指标恶化:
>
> - prop width diff 145->169
> - prop height diff 108->128
> - prop border-left-color diff 2->4
> - case-012-position-relative geometry 35->37
> - case-012-position-relative mismatch 3->5
> - case-012-position-relative major 34->35
> - case-015-min-max-height geometry 85->91
> - case-015-min-max-height mismatch 20->32
> - case-015-min-max-height major 34->40
> - case-019-visibility geometry 219->221
> - case-019-visibility mismatch 12->14
> - case-019-visibility critical 144->145
> - case-019-visibility major 53->54
> - case-021-line-height geometry 106->114
> - case-021-line-height mismatch 10->26
> - case-021-line-height critical 0->4
> - case-021-line-height major 106->110
> - case-035-text-indent geometry 127->129
> - case-035-text-indent mismatch 8->12
> - case-035-text-indent major 52->54
> - case-046-appearance geometry 205->210
> - case-046-appearance mismatch 22->27
> - case-046-appearance critical 185->189
> - case-046-appearance major 12->13
> - case-048-table-props mismatch 46->54
> - case-048-table-props major 3->103
>
> ⚠️ 建议: 检查本次变更是否引入了预期外的行为改变。

## 样式属性统计

| 属性 | 通过率 | 通过/总 |
|------|--------|--------|
| width | 98% | 8348/8517 |
| height | 98.5% | 8389/8517 |
| overflow-x | 99.9% | 8506/8517 |
| overflow-y | 99.9% | 8506/8517 |
| margin-bottom | 99.9% | 8507/8517 |
| margin-right | 99.9% | 8512/8517 |
| margin-left | 99.9% | 8512/8517 |
| border-left-color | 100% | 8513/8517 |
| color | 100% | 8517/8517 |
| position | 100% | 8517/8517 |
| flex-direction | 100% | 8517/8517 |
| flex-wrap | 100% | 8517/8517 |
| padding-top | 100% | 8517/8517 |
| padding-right | 100% | 8517/8517 |
| padding-bottom | 100% | 8517/8517 |
| padding-left | 100% | 8517/8517 |
| margin-top | 100% | 8517/8517 |
| font-weight | 100% | 8517/8517 |
| opacity | 100% | 8517/8517 |
| border-left-width | 100% | 8517/8517 |
| text-align | 100% | 8510/8510 |
| border-radius | 100% | 8312/8312 |
| display | 100% | 8263/8263 |
| background-color | 100% | 8117/8120 |
| font-size | 100% | 7467/7467 |
| align-items | 95.3% | 61/64 |
| justify-content | 100% | 52/52 |
| gap | 88.4% | 38/43 |
| top | 100% | 12/12 |
| min-width | 100% | 12/12 |
| left | 100% | 11/11 |
| line-height | 100% | 7/7 |
| text-decoration-line | 0% | 0/6 |
| grid-template-columns | 0% | 0/2 |
| white-space | 100% | 2/2 |
| text-decoration-color | 0% | 0/1 |
| text-decoration-style | 0% | 0/1 |
| text-decoration-thickness | 0% | 0/1 |
| max-height | 100% | 1/1 |
| min-height | 100% | 1/1 |
| max-width | 0% | 0/0 |
| overflow | 0% | 0/0 |
| box-sizing | 0% | 0/0 |
| word-break | 0% | 0/0 |
| visibility | 0% | 0/0 |
| pointer-events | 0% | 0/0 |
| font-family | 0% | 0/0 |
| border-top-width | 0% | 0/0 |
| border-right-width | 0% | 0/0 |
| border-bottom-width | 0% | 0/0 |
| border-style | 0% | 0/0 |
| border-top-color | 0% | 0/0 |
| border-right-color | 0% | 0/0 |
| border-bottom-color | 0% | 0/0 |
| font-style | 0% | 0/0 |
| cursor | 0% | 0/0 |
| direction | 0% | 0/0 |
| border-width | 0% | 0/0 |
| border-color | 0% | 0/0 |
| flex-grow | 0% | 0/0 |
| flex-shrink | 0% | 0/0 |
| box-shadow | 0% | 0/0 |

---

## 逐 Case 差异详情

| 用例 | 缺失(MISSING) | 严重(>20px) | 中等(5-20px) | 值(MISMATCH) | 结构(STRUCTURE) | Phase G 溢出 |
|------|:-------------:|:-----------:|:------------:|:-------------:|:---------------:|:------------:|
| case-001-wrapper-x | 0 | 0 | 0 | 0 | 0 | 0 |
| case-002-auto-height | 0 | **1** | 0 | 1 | 0 | 0 |
| case-003-basic-block | 0 | 0 | 0 | 0 | 0 | 0 |
| case-004-flex-layout | 0 | 0 | 0 | 0 | 0 | 0 |
| case-005-grid-layout | 0 | **51** | 0 | 4 | 0 | 0 |
| case-006-typography | 0 | **1** | 0 | 1 | 0 | 0 |
| case-007-border-styles | 0 | **51** | **23** | 18 | 0 | 0 |
| case-008-box-shadow | 0 | 0 | **34** | 0 | 0 | 0 |
| case-009-outline | 0 | 0 | **31** | 0 | 0 | 0 |
| case-010-display-none | 0 | **1** | **6** | 4 | 26 | 0 |
| case-011-position-absolute | 0 | **3** | **21** | 7 | 0 | 0 |
| case-012-position-relative | 0 | **1** | **35** | 5 | 0 | 0 |
| case-013-z-index | 0 | **84** | 0 | 0 | 0 | 0 |
| case-014-overflow-hidden | 0 | **22** | 0 | 5 | 0 | 0 |
| case-015-min-max-height | 0 | **51** | **40** | 32 | 0 | 0 |
| case-016-margin-collapse | 0 | **2** | **43** | 5 | 0 | 0 |
| case-017-negative-margin | 0 | **1** | 0 | 2 | 0 | 0 |
| case-018-opacity | 0 | 0 | **28** | 0 | 0 | 0 |
| case-019-visibility | 0 | **145** | **54** | 14 | 0 | 0 |
| case-020-text-align | 0 | **3** | **77** | 9 | 0 | 0 |
| case-021-line-height | 0 | **4** | **110** | 26 | 0 | 0 |
| case-022-white-space | 0 | **2** | **1** | 5 | 0 | 0 |
| case-023-word-break | 0 | **10** | **67** | 4 | 0 | 0 |
| case-024-font-weight | 0 | **5** | **50** | 5 | 0 | 0 |
| case-025-english-text | 0 | **3** | 0 | 3 | 0 | 0 |
| case-026-font-style | 0 | **6** | **90** | 10 | 0 | 0 |
| case-027-scroll-diagnostic | 0 | 0 | 0 | 0 | 0 | 0 |
| case-028-scroll-block | 0 | 0 | 0 | 0 | 0 | 0 |
| case-029-scroll-flex-col | 0 | 0 | 0 | 0 | 0 | 0 |
| case-030-scroll-flex-row | 0 | 0 | 0 | 0 | 0 | 0 |
| case-031-scroll-grid | 0 | 0 | 0 | 0 | 0 | 0 |
| case-032-scroll-relative | 0 | **1** | 0 | 0 | 0 | 0 |
| case-033-text-shadow | 0 | 0 | **30** | 0 | 0 | 0 |
| case-034-letter-spacing | 0 | 0 | **38** | 0 | 0 | 0 |
| case-035-text-indent | 0 | **73** | **54** | 12 | 0 | 0 |
| case-036-background-repeat | 0 | 0 | **31** | 0 | 0 | 0 |
| case-037-direction-rtl | 0 | **56** | **32** | 0 | 0 | 0 |
| case-038-list-style | 0 | **10** | **29** | 10 | 0 | 0 |
| case-039-vertical-align | 0 | **2** | **140** | 24 | 0 | 0 |
| case-040-word-wrap | 0 | **18** | **121** | 0 | 0 | 0 |
| case-041-background-clip | 0 | 0 | **42** | 0 | 0 | 0 |
| case-042-font-variant | 0 | 0 | **45** | 0 | 0 | 0 |
| case-043-resize | 0 | 0 | **44** | 0 | 19 | 0 |
| case-044-background-attachment | 0 | 0 | **35** | 9 | 0 | 0 |
| case-045-text-decoration | 0 | 0 | **34** | 8 | 0 | 0 |
| case-046-appearance | 2 | **189** | **13** | 27 | 0 | 0 |
| case-047-object-fit | 0 | 0 | **30** | 16 | 0 | 0 |
| case-048-table-props | 0 | **102** | **103** | 54 | 0 | 0 |
| case-049-multi-column | 3 | **308** | **3** | 10 | 0 | 0 |
| case-050-text-emphasis | 0 | **50** | **161** | 15 | 0 | 0 |
| case-051-aspect-ratio | 0 | **9** | 0 | 3 | 0 | 0 |
| case-052-flow-root | 0 | **2** | 0 | 1 | 0 | 0 |
| case-053-intrinsic-sizing | 0 | **4** | 0 | 3 | 0 | 0 |
| case-054-inline-block-nest | 0 | **9** | **5** | 3 | 0 | 0 |
| case-055-sticky-multi | 0 | **4** | **8** | 5 | 0 | 0 |

