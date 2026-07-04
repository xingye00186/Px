# CSS Test Sandbox — 测试报告

**运行时间**: 2026-07-04 19:43:55 | **总耗时**: 730.8s

| 用例 | 构建 | 布局 | 多帧 | 浏览器 | 元素对比 | Phase L | Phase G | 差异 | 截图 | 结果 | 耗时 |
|------|------|------|------|--------|----------|---------|---------|------|------|------|------|
| case-001-wrapper-x | ⏭️ | ❌ | ⏭️ | ✅ | ❌ | ❌ | ✅ | ✅ | ⏭️ | ❌ 失败 | 43.2s |
| case-002-auto-height | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ | 306diff 🔴281 | ⏭️ | ❌ 失败 | 4.4s |
| case-003-basic-block | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ | 346diff 🔴326 | ⏭️ | ❌ 失败 | 9.7s |
| case-004-flex-layout | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 6issue | ⚠️ 2 | 368diff 🔴339 | ⏭️ | ❌ 失败 | 10.7s |
| case-005-grid-layout | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ | 201diff 🔴181 | ⏭️ | ❌ 失败 | 40.9s |
| case-006-typography | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ | 697diff 🔴659 | ⏭️ | ❌ 失败 | 8.9s |
| case-007-border-styles | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 2issue | ✅ | 415diff 🔴364 | ⏭️ | ❌ 失败 | 4.8s |
| case-008-box-shadow | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 3issue | ✅ | 117diff 🔴108 | ⏭️ | ❌ 失败 | 9.9s |
| case-009-outline | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 2issue | ✅ | 130diff 🔴119 | ⏭️ | ❌ 失败 | 4.2s |
| case-010-display-none | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 1issue | ✅ | 276diff 🔴233 | ⏭️ | ❌ 失败 | 8.9s |
| case-011-position-absolute | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ⚠️ 2 | 198diff 🔴170 | ⏭️ | ❌ 失败 | 4.2s |
| case-012-position-relative | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 2issue | ⚠️ 1 | 315diff 🔴288 | ⏭️ | ❌ 失败 | 5.1s |
| case-013-z-index | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ⚠️ 70 | 170diff 🔴162 | ⏭️ | ❌ 失败 | 8.4s |
| case-014-overflow-hidden | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ | 300diff 🔴278 | ⏭️ | ❌ 失败 | 4.4s |
| case-015-min-max-height | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 2issue | ⚠️ 6 | 332diff 🔴290 | ⏭️ | ❌ 失败 | 8.3s |
| case-016-margin-collapse | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ⚠️ 3 | 360diff 🔴334 | ⏭️ | ❌ 失败 | 5.2s |
| case-017-negative-margin | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ | 221diff 🔴209 | ⏭️ | ❌ 失败 | 10.5s |
| case-018-opacity | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 3issue | ✅ | 195diff 🔴178 | ⏭️ | ❌ 失败 | 4.2s |
| case-019-visibility | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 3issue | ⚠️ 1 | 463diff 🔴418 | ⏭️ | ❌ 失败 | 5.2s |
| case-020-text-align | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ⚠️ 6 | 298diff 🔴261 | ⏭️ | ❌ 失败 | 4.3s |
| case-021-line-height | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 2issue | ⚠️ 8 | 427diff 🔴359 | ⏭️ | ❌ 失败 | 4.3s |
| case-022-white-space | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ⚠️ 3 | 362diff 🔴329 | ⏭️ | ❌ 失败 | 15.2s |
| case-023-word-break | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 1issue | ⚠️ 2 | 362diff 🔴334 | ⏭️ | ❌ 失败 | 4.4s |
| case-024-font-weight | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 6issue | ⚠️ 2 | 332diff 🔴279 | ⏭️ | ❌ 失败 | 4.2s |
| case-025-english-text | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ⚠️ 6 | 491diff 🔴449 | ⏭️ | ❌ 失败 | 4.5s |
| case-026-font-style | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 2issue | ✅ | 264diff 🔴225 | ⏭️ | ❌ 失败 | 5.2s |
| case-027-scroll-diagnostic | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ | 2diff 🔴2 | ⏭️ | ❌ 失败 | 5.5s |
| case-028-scroll-block | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ | 2diff 🔴2 | ⏭️ | ❌ 失败 | 9s |
| case-029-scroll-flex-col | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ | 2diff 🔴2 | ⏭️ | ❌ 失败 | 4.8s |
| case-030-scroll-flex-row | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 14issue | ✅ | 2diff 🔴2 | ⏭️ | ❌ 失败 | 8.9s |
| case-031-scroll-grid | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ⚠️ 2 | 1diff 🔴1 | ⏭️ | ❌ 失败 | 3.5s |
| case-032-scroll-relative | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ | 2diff 🔴2 | ⏭️ | ❌ 失败 | 10.1s |
| case-033-text-shadow | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 5issue | ⚠️ 6 | 465diff 🔴420 | ⏭️ | ❌ 失败 | 4.4s |
| case-034-letter-spacing | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 3issue | ⚠️ 4 | 268diff 🔴230 | ⏭️ | ❌ 失败 | 5.3s |
| case-035-text-indent | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 3issue | ⚠️ 3 | 497diff 🔴460 | ⏭️ | ❌ 失败 | 8.8s |
| case-036-background-repeat | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 3issue | ⚠️ 4 | 358diff 🔴316 | ⏭️ | ❌ 失败 | 4.5s |
| case-037-direction-rtl | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 7issue | ⚠️ 8 | 409diff 🔴358 | ⏭️ | ❌ 失败 | 5.6s |
| case-038-list-style | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 5issue | ⚠️ 18 | 473diff 🔴395 | ⏭️ | ❌ 失败 | 7.7s |
| case-039-vertical-align | ❌ | ✅ | ❌ | ✅ | ❌ | ✅ | ⚠️ 4 | 372diff 🔴339 | ⏭️ | ❌ 失败 | 9.8s |
| case-040-word-wrap | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 2issue | ⚠️ 3 | 640diff 🔴584 | ⏭️ | ❌ 失败 | 4.3s |
| case-041-background-clip | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 3issue | ⚠️ 4 | 459diff 🔴419 | ⏭️ | ❌ 失败 | 4.2s |
| case-042-font-variant | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 9issue | ⚠️ 10 | 626diff 🔴558 | ⏭️ | ❌ 失败 | 8.8s |
| case-043-resize | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 4issue | ⚠️ 4 | 398diff 🔴342 | ⏭️ | ❌ 失败 | 4.3s |
| case-044-background-attachment | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 2issue | ⚠️ 6 | 400diff 🔴355 | ⏭️ | ❌ 失败 | 64.1s |
| case-045-text-decoration | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 9issue | ⚠️ 10 | 565diff 🔴489 | ⏭️ | ❌ 失败 | 63.5s |
| case-046-appearance | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 5issue | ⚠️ 9 | 458diff 🔴392 | ⏭️ | ❌ 失败 | 65.6s |
| case-047-object-fit | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 3issue | ⚠️ 4 | 377diff 🔴331 | ⏭️ | ❌ 失败 | 64.7s |
| case-048-table-props | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 4issue | ⚠️ 8 | 599diff 🔴463 | ⏭️ | ❌ 失败 | 64.7s |
| case-049-multi-column | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 2issue | ⚠️ 6 | 756diff 🔴664 | ⏭️ | ❌ 失败 | 0.8s |
| case-050-text-emphasis | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 6issue | ⚠️ 8 | 523diff 🔴475 | ⏭️ | ❌ 失败 | 8.3s |
| case-051-aspect-ratio | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 1issue | ⚠️ 3 | 20diff 🔴15 | ⏭️ | ❌ 失败 | 9.7s |
| case-052-flow-root | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 1issue | ⚠️ 2 | 14diff 🔴9 | ⏭️ | ❌ 失败 | 9.6s |
| case-053-intrinsic-sizing | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 3issue | ✅ | 20diff 🔴9 | ⏭️ | ❌ 失败 | 8.7s |
| case-054-inline-block-nest | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 1issue | ✅ | 19diff 🔴13 | ⏭️ | ❌ 失败 | 7.8s |
| case-055-sticky-multi | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ 1issue | ⚠️ 3 | 25diff 🔴17 | ⏭️ | ❌ 失败 | 10.6s |

**汇总**: 0 ✅ / 55 ❌ / 55 总计 (总耗时: 730.8s)

## 回归判定

> 🔴 **检测到回归** — 与上一次运行相比，以下指标恶化:
>
> - prop width diff 275->284
> - case-003-basic-block critical 325->326
> - case-004-flex-layout critical 338->339
> - case-005-grid-layout geometry 0->186
> - case-005-grid-layout mismatch 0->15
> - case-005-grid-layout critical 0->181
> - case-005-grid-layout major 0->1
> - case-006-typography critical 658->659
> - case-007-border-styles critical 363->364
> - case-008-box-shadow critical 107->108
> - case-009-outline critical 118->119
> - case-010-display-none critical 232->233
> - case-011-position-absolute critical 169->170
> - case-012-position-relative critical 287->288
> - case-013-z-index critical 161->162
> - case-014-overflow-hidden critical 277->278
> - case-015-min-max-height geometry 296->297
> - case-015-min-max-height critical 288->290
> - case-016-margin-collapse critical 333->334
> - case-017-negative-margin critical 208->209
> - case-018-opacity critical 177->178
> - case-019-visibility critical 417->418
> - case-020-text-align critical 259->261
> - case-021-line-height critical 358->359
> - case-022-white-space critical 328->329
> - case-023-word-break critical 332->334
> - case-024-font-weight critical 278->279
> - case-025-english-text critical 448->449
> - case-026-font-style critical 224->225
> - case-027-scroll-diagnostic geometry 1->2
> - case-027-scroll-diagnostic critical 1->2
> - case-028-scroll-block geometry 1->2
> - case-028-scroll-block critical 1->2
> - case-029-scroll-flex-col geometry 1->2
> - case-029-scroll-flex-col critical 1->2
> - case-030-scroll-flex-row geometry 1->2
> - case-030-scroll-flex-row critical 1->2
> - case-031-scroll-grid geometry 0->1
> - case-031-scroll-grid critical 0->1
> - case-032-scroll-relative geometry 1->2
> - case-032-scroll-relative critical 1->2
> - case-033-text-shadow critical 419->420
> - case-034-letter-spacing critical 229->230
> - case-035-text-indent critical 458->460
> - case-036-background-repeat critical 315->316
> - case-037-direction-rtl critical 357->358
> - case-038-list-style critical 393->395
> - case-039-vertical-align critical 337->339
> - case-040-word-wrap critical 583->584
> - case-041-background-clip critical 418->419
> - case-042-font-variant critical 557->558
> - case-043-resize critical 341->342
> - case-044-background-attachment critical 354->355
> - case-045-text-decoration critical 488->489
> - case-046-appearance critical 391->392
> - case-047-object-fit critical 330->331
> - case-048-table-props critical 462->463
> - case-049-multi-column critical 663->664
> - case-050-text-emphasis critical 474->475
> - case-051-aspect-ratio critical 14->15
> - case-052-flow-root critical 8->9
> - case-053-intrinsic-sizing critical 8->9
> - case-054-inline-block-nest critical 12->13
> - case-055-sticky-multi critical 16->17
> - overflow 239->241
> - time 9.1->730.8 (80.3x)
>
> ⚠️ 建议: 检查本次变更是否引入了预期外的行为改变。

## 样式属性统计

| 属性 | 通过率 | 通过/总 |
|------|--------|--------|
| height | 94.5% | 7833/8292 |
| width | 96.6% | 8008/8292 |
| position | 100% | 8292/8292 |
| opacity | 100% | 8292/8292 |
| background-color | 99.7% | 8239/8262 |
| top | 100% | 8223/8223 |
| left | 100% | 8223/8223 |
| text-align | 100% | 8101/8101 |
| display | 100% | 8024/8024 |
| font-weight | 100% | 7306/7306 |
| color | 100% | 6011/6011 |
| font-size | 100% | 243/243 |
| margin-bottom | 100% | 196/196 |
| padding-top | 100% | 186/186 |
| padding-bottom | 100% | 185/185 |
| border-radius | 100% | 172/172 |
| padding-left | 100% | 147/147 |
| padding-right | 100% | 147/147 |
| border-width | 100% | 130/130 |
| margin-top | 100% | 62/62 |
| align-items | 100% | 57/57 |
| justify-content | 100% | 48/48 |
| gap | 88.4% | 38/43 |
| flex-direction | 100% | 22/22 |
| margin-left | 73.7% | 14/19 |
| margin-right | 68.8% | 11/16 |
| line-height | 100% | 10/10 |
| text-decoration-line | 0% | 0/6 |
| flex-wrap | 100% | 6/6 |
| min-width | 100% | 3/3 |
| grid-template-columns | 0% | 0/2 |
| white-space | 100% | 2/2 |
| text-decoration-color | 0% | 0/1 |
| text-decoration-style | 0% | 0/1 |
| text-decoration-thickness | 0% | 0/1 |
| font-family | 100% | 1/1 |
| min-height | 100% | 1/1 |
| overflow-y | 100% | 1/1 |
| max-height | 100% | 1/1 |
| font-style | 0% | 0/0 |
| word-break | 0% | 0/0 |
| visibility | 0% | 0/0 |
| cursor | 0% | 0/0 |
| direction | 0% | 0/0 |
| border-left-width | 0% | 0/0 |
| border-left-color | 0% | 0/0 |
| border-color | 0% | 0/0 |
| flex-grow | 0% | 0/0 |
| flex-shrink | 0% | 0/0 |
| overflow-x | 0% | 0/0 |
| pointer-events | 0% | 0/0 |
| box-shadow | 0% | 0/0 |
| overflow | 0% | 0/0 |

---

## 逐 Case 差异详情

| 用例 | 缺失(MISSING) | 严重(>20px) | 中等(5-20px) | 值(MISMATCH) | 结构(STRUCTURE) | Phase G 溢出 |
|------|:-------------:|:-----------:|:------------:|:-------------:|:---------------:|:------------:|
| case-001-wrapper-x | 0 | 0 | 0 | 0 | 0 | 0 |
| case-002-auto-height | 0 | **281** | **13** | 10 | 0 | 0 |
| case-003-basic-block | 0 | **326** | **10** | 5 | 0 | 0 |
| case-004-flex-layout | 0 | **339** | **13** | 11 | 0 | 2 |
| case-005-grid-layout | 0 | **181** | **1** | 15 | 0 | 0 |
| case-006-typography | 0 | **659** | **23** | 7 | 0 | 0 |
| case-007-border-styles | 0 | **364** | **11** | 31 | 0 | 0 |
| case-008-box-shadow | 0 | **108** | **3** | 4 | 0 | 0 |
| case-009-outline | 0 | **119** | **3** | 4 | 0 | 0 |
| case-010-display-none | 0 | **233** | **27** | 10 | 0 | 0 |
| case-011-position-absolute | 0 | **170** | **13** | 12 | 0 | 2 |
| case-012-position-relative | 0 | **288** | **8** | 12 | 3 | 1 |
| case-013-z-index | 0 | **162** | **3** | 2 | 0 | 70 |
| case-014-overflow-hidden | 0 | **278** | **10** | 6 | 0 | 0 |
| case-015-min-max-height | 0 | **290** | **5** | 22 | 13 | 6 |
| case-016-margin-collapse | 0 | **334** | **11** | 10 | 0 | 3 |
| case-017-negative-margin | 0 | **209** | **6** | 3 | 0 | 0 |
| case-018-opacity | 0 | **178** | **3** | 12 | 0 | 0 |
| case-019-visibility | 0 | **418** | **24** | 14 | 4 | 1 |
| case-020-text-align | 0 | **261** | **15** | 17 | 0 | 6 |
| case-021-line-height | 0 | **359** | **19** | 30 | 17 | 8 |
| case-022-white-space | 0 | **329** | **16** | 11 | 0 | 3 |
| case-023-word-break | 0 | **334** | **12** | 14 | 0 | 2 |
| case-024-font-weight | 0 | **279** | **11** | 33 | 0 | 2 |
| case-025-english-text | 0 | **449** | **19** | 15 | 0 | 6 |
| case-026-font-style | 0 | **225** | **5** | 32 | 0 | 0 |
| case-027-scroll-diagnostic | 0 | **2** | 0 | 0 | 0 | 0 |
| case-028-scroll-block | 0 | **2** | 0 | 0 | 0 | 0 |
| case-029-scroll-flex-col | 0 | **2** | 0 | 0 | 0 | 0 |
| case-030-scroll-flex-row | 0 | **2** | 0 | 0 | 0 | 0 |
| case-031-scroll-grid | 0 | **1** | 0 | 0 | 0 | 2 |
| case-032-scroll-relative | 0 | **2** | 0 | 0 | 0 | 0 |
| case-033-text-shadow | 0 | **420** | **19** | 18 | 0 | 6 |
| case-034-letter-spacing | 0 | **230** | **12** | 15 | 0 | 4 |
| case-035-text-indent | 0 | **460** | **11** | 13 | 5 | 3 |
| case-036-background-repeat | 0 | **316** | **12** | 19 | 0 | 4 |
| case-037-direction-rtl | 0 | **358** | **18** | 23 | 0 | 8 |
| case-038-list-style | 0 | **395** | **19** | 43 | 0 | 18 |
| case-039-vertical-align | 0 | **339** | **15** | 12 | 0 | 4 |
| case-040-word-wrap | 0 | **584** | **36** | 15 | 0 | 3 |
| case-041-background-clip | 0 | **419** | **19** | 15 | 0 | 4 |
| case-042-font-variant | 0 | **558** | **28** | 28 | 0 | 10 |
| case-043-resize | 0 | **342** | **12** | 15 | 19 | 4 |
| case-044-background-attachment | 0 | **355** | **22** | 15 | 0 | 6 |
| case-045-text-decoration | 0 | **489** | **28** | 36 | 0 | 10 |
| case-046-appearance | 2 | **392** | **27** | 26 | 5 | 9 |
| case-047-object-fit | 0 | **331** | **12** | 23 | 0 | 4 |
| case-048-table-props | 0 | **463** | **48** | 67 | 9 | 8 |
| case-049-multi-column | 3 | **664** | **69** | 18 | 0 | 6 |
| case-050-text-emphasis | 0 | **475** | **18** | 23 | 0 | 8 |
| case-051-aspect-ratio | 0 | **15** | 0 | 5 | 0 | 3 |
| case-052-flow-root | 0 | **9** | **2** | 3 | 0 | 2 |
| case-053-intrinsic-sizing | 0 | **9** | **1** | 7 | 0 | 0 |
| case-054-inline-block-nest | 0 | **13** | **2** | 2 | 0 | 0 |
| case-055-sticky-multi | 0 | **17** | **3** | 4 | 0 | 3 |

---

## Phase G 容器溢出详情

### case-004-flex-layout

- child(type=div right=525) overflows parent(type=div contentRight=456) by 69px (w: child=200 parent=131)
- child(type=div right=585) overflows parent(type=div contentRight=456) by 129px (w: child=260 parent=131)

### case-011-position-absolute

- child(type=span right=333) overflows parent(type=div contentRight=60) by 273px (w: child=8 parent=60)
- child(type=span bottom=122) overflows parent(type=div contentBottom=60) by 62px (bottom overflow)

### case-012-position-relative

- child(type=div right=2600) overflows parent(type=div contentRight=1125) by 1475px (w: child=2275 parent=800)

### case-013-z-index

- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=300) by 33px (w: child=8 parent=300)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=280) by 53px (w: child=8 parent=280)
- child(type=span bottom=122) overflows parent(type=div contentBottom=100) by 22px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)
- child(type=span right=333) overflows parent(type=div contentRight=260) by 73px (w: child=8 parent=260)
- child(type=span bottom=122) overflows parent(type=div contentBottom=80) by 42px (bottom overflow)

### case-015-min-max-height

- child(type=div bottom=558) overflows parent(type=div contentBottom=193) by 365px (bottom overflow)
- [FLEX-WIDTH] row flex items total width (64) exceeds container content width (53) by 11px (gap=${gap}px, children=8, wrap=nowrap)
- child(type=div bottom=683) overflows parent(type=div contentBottom=184) by 499px (bottom overflow)
- child(type=div bottom=842) overflows parent(type=div contentBottom=184) by 658px (bottom overflow)
- child(type=div bottom=333) overflows parent(type=div contentBottom=184) by 149px (bottom overflow)
- child(type=div bottom=253) overflows parent(type=div contentBottom=184) by 69px (bottom overflow)

### case-016-margin-collapse

- child(type=div right=1048) overflows parent(type=div contentRight=1020) by 28px (w: child=718 parent=690)
- child(type=div right=1048) overflows parent(type=div contentRight=1020) by 28px (w: child=718 parent=690)
- child(type=div right=1064) overflows parent(type=div contentRight=1020) by 44px (w: child=734 parent=690)

### case-019-visibility

- child(type=div right=3362) overflows parent(type=div contentRight=1125) by 2237px (w: child=3037 parent=800)

### case-020-text-align

- child(type=div right=1090) overflows parent(type=div contentRight=1010) by 80px (w: child=750 parent=670)
- child(type=div right=1090) overflows parent(type=div contentRight=1010) by 80px (w: child=750 parent=670)
- child(type=div right=1090) overflows parent(type=div contentRight=1010) by 80px (w: child=750 parent=670)
- child(type=div right=1090) overflows parent(type=div contentRight=1010) by 80px (w: child=750 parent=670)
- child(type=div right=1090) overflows parent(type=div contentRight=1010) by 80px (w: child=750 parent=670)
- child(type=div right=1090) overflows parent(type=div contentRight=1010) by 80px (w: child=750 parent=670)

### case-021-line-height

- child(type=div bottom=706) overflows parent(type=div contentBottom=162) by 544px (bottom overflow)
- child(type=div bottom=516) overflows parent(type=div contentBottom=162) by 354px (bottom overflow)
- child(type=div bottom=631) overflows parent(type=div contentBottom=162) by 469px (bottom overflow)
- child(type=div bottom=416) overflows parent(type=div contentBottom=162) by 254px (bottom overflow)
- child(type=div bottom=681) overflows parent(type=div contentBottom=162) by 519px (bottom overflow)
- child(type=div bottom=516) overflows parent(type=div contentBottom=162) by 354px (bottom overflow)
- child(type=div bottom=656) overflows parent(type=div contentBottom=162) by 494px (bottom overflow)
- child(type=div bottom=416) overflows parent(type=div contentBottom=162) by 254px (bottom overflow)

### case-022-white-space

- child(type=div right=1090) overflows parent(type=div contentRight=1010) by 80px (w: child=750 parent=670)
- child(type=div right=1090) overflows parent(type=div contentRight=1010) by 80px (w: child=750 parent=670)
- child(type=div right=1072) overflows parent(type=div contentRight=1010) by 62px (w: child=732 parent=670)

### case-023-word-break

- child(type=div bottom=781) overflows parent(type=div contentBottom=205) by 576px (bottom overflow)
- child(type=div bottom=656) overflows parent(type=div contentBottom=205) by 451px (bottom overflow)

### case-024-font-weight

- child(type=div right=1092) overflows parent(type=div contentRight=1008) by 84px (w: child=750 parent=666)
- child(type=div right=1842) overflows parent(type=div contentRight=1125) by 717px (w: child=1517 parent=800)

### case-025-english-text

- child(type=div right=1092) overflows parent(type=div contentRight=1008) by 84px (w: child=750 parent=666)
- child(type=div right=1092) overflows parent(type=div contentRight=1008) by 84px (w: child=750 parent=666)
- child(type=div right=1092) overflows parent(type=div contentRight=1008) by 84px (w: child=750 parent=666)
- child(type=div right=1092) overflows parent(type=div contentRight=1008) by 84px (w: child=750 parent=666)
- child(type=div right=1092) overflows parent(type=div contentRight=1008) by 84px (w: child=750 parent=666)
- child(type=div right=1092) overflows parent(type=div contentRight=1008) by 84px (w: child=750 parent=666)

### case-031-scroll-grid

- [FLEX-WIDTH] row flex items total width (96) exceeds container content width (65) by 31px (gap=${gap}px, children=12, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (104) exceeds container content width (69) by 35px (gap=${gap}px, children=13, wrap=nowrap)

### case-033-text-shadow

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-034-letter-spacing

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-035-text-indent

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-036-background-repeat

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-037-direction-rtl

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-038-list-style

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=ul right=1068) overflows parent(type=div contentRight=1058) by 10px (w: child=726 parent=716)
- child(type=li right=1092) overflows parent(type=ul contentRight=1068) by 24px (w: child=750 parent=726)
- child(type=li right=1092) overflows parent(type=ul contentRight=1068) by 24px (w: child=750 parent=726)
- child(type=li right=1092) overflows parent(type=ul contentRight=1068) by 24px (w: child=750 parent=726)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=ol right=1068) overflows parent(type=div contentRight=1058) by 10px (w: child=726 parent=716)
- child(type=li right=1092) overflows parent(type=ol contentRight=1068) by 24px (w: child=750 parent=726)
- child(type=li right=1092) overflows parent(type=ol contentRight=1068) by 24px (w: child=750 parent=726)
- child(type=li right=1092) overflows parent(type=ol contentRight=1068) by 24px (w: child=750 parent=726)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=ul right=1068) overflows parent(type=div contentRight=1058) by 10px (w: child=726 parent=716)
- child(type=li right=1092) overflows parent(type=ul contentRight=1068) by 24px (w: child=750 parent=726)
- child(type=li right=1092) overflows parent(type=ul contentRight=1068) by 24px (w: child=750 parent=726)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=ul right=1068) overflows parent(type=div contentRight=1058) by 10px (w: child=726 parent=716)
- child(type=li right=1092) overflows parent(type=ul contentRight=1068) by 24px (w: child=750 parent=726)
- child(type=li right=1092) overflows parent(type=ul contentRight=1068) by 24px (w: child=750 parent=726)

### case-039-vertical-align

- child(type=div right=1092) overflows parent(type=div contentRight=1008) by 84px (w: child=750 parent=666)
- child(type=div right=1074) overflows parent(type=div contentRight=1008) by 66px (w: child=732 parent=666)
- child(type=div right=1092) overflows parent(type=div contentRight=1008) by 84px (w: child=750 parent=666)
- child(type=div right=1074) overflows parent(type=div contentRight=1008) by 66px (w: child=732 parent=666)

### case-040-word-wrap

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-041-background-clip

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-042-font-variant

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-043-resize

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-044-background-attachment

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div bottom=265) overflows parent(type=div contentBottom=195) by 70px (bottom overflow)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div bottom=265) overflows parent(type=div contentBottom=195) by 70px (bottom overflow)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div bottom=265) overflows parent(type=div contentBottom=195) by 70px (bottom overflow)

### case-045-text-decoration

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-046-appearance

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=select right=1074) overflows parent(type=div contentRight=1058) by 16px (w: child=732 parent=716)
- child(type=option right=1101) overflows parent(type=select contentRight=1083) by 18px (w: child=750 parent=732)
- child(type=option right=1101) overflows parent(type=select contentRight=1083) by 18px (w: child=750 parent=732)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=input right=1082) overflows parent(type=div contentRight=1058) by 24px (w: child=740 parent=716)

### case-047-object-fit

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-048-table-props

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=table right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=table right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=table right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=table right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-049-multi-column

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-050-text-emphasis

- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)
- child(type=div right=1092) overflows parent(type=div contentRight=1058) by 34px (w: child=750 parent=716)

### case-051-aspect-ratio

- child(type=span bottom=70) overflows parent(type=div contentBottom=58) by 12px (bottom overflow)
- child(type=span right=125) overflows parent(type=div contentRight=85) by 40px (w: child=80 parent=40)
- child(type=span bottom=215) overflows parent(type=div contentBottom=203) by 12px (bottom overflow)

### case-052-flow-root

- child(type=div right=807) overflows parent(type=div contentRight=783) by 24px (w: child=750 parent=726)
- child(type=div right=807) overflows parent(type=div contentRight=783) by 24px (w: child=750 parent=726)

### case-055-sticky-multi

- child(type=span right=94) overflows parent(type=div contentRight=85) by 9px (w: child=80 parent=40)
- child(type=span right=94) overflows parent(type=div contentRight=85) by 9px (w: child=80 parent=40)
- child(type=span right=94) overflows parent(type=div contentRight=85) by 9px (w: child=80 parent=40)

