# CSS Test Sandbox — 测试报告

**运行时间**: 2026-07-12 10:48:13 | **总耗时**: 16.7s

| 用例 | 构建 | 布局 | 多帧 | 浏览器 | 元素对比 | Phase L | Phase G | 差异 | 截图 | 结果 | 耗时 |
|------|------|------|------|--------|----------|---------|---------|------|------|------|------|
| case-001-wrapper-x | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 698diff 🟡41 | ⏭️ | ❌ 失败 | 1.8s |
| case-002-auto-height | ⏭️ | ❌ | ⏭️ | ✅ | ❌ | ❌ | ✅ | ✅ | ⏭️ | ❌ 失败 | 1.8s |
| case-003-basic-block | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 700diff 🟡156 | ⏭️ | ❌ 失败 | 1.3s |
| case-004-flex-layout | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 634diff 🔴97 | ⏭️ | ❌ 失败 | 1.4s |
| case-005-grid-layout | ⏭️ | ❌ | ⏭️ | ✅ | ❌ | ❌ | ✅ | ✅ | ⏭️ | ❌ 失败 | 1.5s |
| case-006-typography | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 1058diff | ⏭️ | ❌ 失败 | 1.9s |
| case-007-border-styles | ⏭️ | ❌ | ⏭️ | ✅ | ❌ | ❌ | ✅ | ✅ | ⏭️ | ❌ 失败 | 1.4s |
| case-008-box-shadow | ⏭️ | ❌ | ⏭️ | ✅ | ❌ | ❌ | ✅ | ✅ | ⏭️ | ❌ 失败 | 1s |
| case-009-outline | ⏭️ | ❌ | ⏭️ | ✅ | ❌ | ❌ | ✅ | ✅ | ⏭️ | ❌ 失败 | 1.1s |
| case-010-display-none | ⏭️ | ❌ | ⏭️ | ✅ | ❌ | ❌ | ✅ | ✅ | ⏭️ | ❌ 失败 | 1.2s |
| case-011-position-absolute | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 166diff 🔴114 | ⏭️ | ❌ 失败 | 0s |
| case-012-position-relative | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 15 | 181diff 🔴138 | ⏭️ | ❌ 失败 | 0s |
| case-013-z-index | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 226diff 🔴167 | ⏭️ | ❌ 失败 | 0s |
| case-014-overflow-hidden | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 162diff 🔴141 | ⏭️ | ❌ 失败 | 0s |
| case-015-min-max-height | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 6 | 287diff 🔴139 | ⏭️ | ❌ 失败 | 0s |
| case-016-margin-collapse | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 282diff 🔴203 | ⏭️ | ❌ 失败 | 0s |
| case-017-negative-margin | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 142diff 🔴121 | ⏭️ | ❌ 失败 | 0s |
| case-018-opacity | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ❌ 3issue | ⚠️ 2 | 93diff 🔴82 | ⏭️ | ❌ 失败 | 0s |
| case-019-visibility | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 1 | 308diff 🔴189 | ⏭️ | ❌ 失败 | 0s |
| case-020-text-align | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 273diff 🔴143 | ⏭️ | ❌ 失败 | 0s |
| case-021-line-height | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 8 | 391diff 🔴164 | ⏭️ | ❌ 失败 | 0s |
| case-022-white-space | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 331diff 🔴176 | ⏭️ | ❌ 失败 | 0s |
| case-023-word-break | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 3 | 316diff 🔴205 | ⏭️ | ❌ 失败 | 0s |
| case-024-font-weight | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 2 | 260diff 🔴183 | ⏭️ | ❌ 失败 | 0s |
| case-025-english-text | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 460diff 🔴229 | ⏭️ | ❌ 失败 | 0s |
| case-026-font-style | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ❌ 2issue | ⚠️ 2 | 110diff 🔴98 | ⏭️ | ❌ 失败 | 0s |
| case-027-scroll-diagnostic | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 30 | 1diff 🔴1 | ⏭️ | ❌ 失败 | 0.1s |
| case-028-scroll-block | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 30 | 1diff 🔴1 | ⏭️ | ❌ 失败 | 0.1s |
| case-029-scroll-flex-col | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 30 | 1diff 🔴1 | ⏭️ | ❌ 失败 | 0.2s |
| case-030-scroll-flex-row | ⏭️ | ❌ | ⏭️ | ✅ | ❌ | ❌ | ✅ | ✅ | ⏭️ | ❌ 失败 | 0s |
| case-031-scroll-grid | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 37 | 1diff 🔴1 | ⏭️ | ❌ 失败 | 0.1s |
| case-032-scroll-relative | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 20 | 1diff 🔴1 | ⏭️ | ❌ 失败 | 0.1s |
| case-033-text-shadow | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 430diff 🔴201 | ⏭️ | ❌ 失败 | 0.1s |
| case-034-letter-spacing | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 218diff 🔴97 | ⏭️ | ❌ 失败 | 0s |
| case-035-text-indent | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 443diff 🔴193 | ⏭️ | ❌ 失败 | 0s |
| case-036-background-repeat | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 338diff 🔴138 | ⏭️ | ❌ 失败 | 0s |
| case-037-direction-rtl | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 372diff 🔴180 | ⏭️ | ❌ 失败 | 0s |
| case-038-list-style | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 10 | 420diff 🔴236 | ⏭️ | ❌ 失败 | 0s |
| case-039-vertical-align | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 344diff 🔴195 | ⏭️ | ❌ 失败 | 0s |
| case-040-word-wrap | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 572diff 🔴403 | ⏭️ | ❌ 失败 | 0.1s |
| case-041-background-clip | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 438diff 🔴211 | ⏭️ | ❌ 失败 | 0.1s |
| case-042-font-variant | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 579diff 🔴272 | ⏭️ | ❌ 失败 | 0.1s |
| case-043-resize | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 349diff 🔴170 | ⏭️ | ❌ 失败 | 0s |
| case-044-background-attachment | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 3 | 389diff 🔴202 | ⏭️ | ❌ 失败 | 0s |
| case-045-text-decoration | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 530diff 🔴236 | ⏭️ | ❌ 失败 | 0.1s |
| case-046-appearance | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 444diff 🔴217 | ⏭️ | ❌ 失败 | 0s |
| case-047-object-fit | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 360diff 🔴173 | ⏭️ | ❌ 失败 | 0s |
| case-048-table-props | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 540diff 🔴247 | ⏭️ | ❌ 失败 | 0.1s |
| case-049-multi-column | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 577diff 🔴410 | ⏭️ | ❌ 失败 | 0.1s |
| case-050-text-emphasis | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 472diff 🔴225 | ⏭️ | ❌ 失败 | 0.1s |
| case-051-aspect-ratio | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 13diff 🔴9 | ⏭️ | ❌ 失败 | 0s |
| case-052-flow-root | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 8diff 🔴6 | ⏭️ | ❌ 失败 | 0s |
| case-053-intrinsic-sizing | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 20diff 🔴9 | ⏭️ | ❌ 失败 | 0s |
| case-054-inline-block-nest | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 1 | 15diff 🔴9 | ⏭️ | ❌ 失败 | 0s |
| case-055-sticky-multi | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 22diff 🔴6 | ⏭️ | ❌ 失败 | 0s |

**汇总**: 0 ✅ / 55 ❌ / 55 总计 (总耗时: 16.7s)

## 回归判定

> 🔴 **检测到回归** — 与上一次运行相比，以下指标恶化:
>
> - prop text-decoration-thickness diff 223->955
> - prop width diff 222->1145
> - prop height diff 222->1158
> - prop position diff 1->18
> - case-003-basic-block geometry 0->156
> - case-003-basic-block mismatch 0->544
> - case-003-basic-block major 0->156
> - case-004-flex-layout geometry 0->99
> - case-004-flex-layout mismatch 0->535
> - case-004-flex-layout missing 0->3
> - case-004-flex-layout critical 0->97
> - case-004-flex-layout major 0->2
> - case-006-typography mismatch 0->1058
> - case-011-position-absolute geometry 0->128
> - case-011-position-absolute mismatch 0->38
> - case-011-position-absolute critical 0->114
> - case-011-position-absolute major 0->13
> - case-012-position-relative geometry 0->168
> - case-012-position-relative mismatch 0->10
> - case-012-position-relative structure 0->3
> - case-012-position-relative critical 0->138
> - case-012-position-relative major 0->28
> - case-013-z-index geometry 0->211
> - case-013-z-index mismatch 0->15
> - case-013-z-index critical 0->167
> - case-013-z-index major 0->43
> - case-014-overflow-hidden geometry 0->146
> - case-014-overflow-hidden mismatch 0->16
> - case-014-overflow-hidden critical 0->141
> - case-014-overflow-hidden major 0->4
> - case-015-min-max-height geometry 0->236
> - case-015-min-max-height mismatch 0->38
> - case-015-min-max-height structure 0->13
> - case-015-min-max-height critical 0->139
> - case-015-min-max-height major 0->93
> - case-016-margin-collapse geometry 0->252
> - case-016-margin-collapse mismatch 0->30
> - case-016-margin-collapse critical 0->203
> - case-016-margin-collapse major 0->45
> - case-017-negative-margin geometry 0->125
> - case-017-negative-margin mismatch 0->17
> - case-017-negative-margin critical 0->121
> - case-017-negative-margin major 0->2
> - case-018-opacity geometry 0->86
> - case-018-opacity mismatch 0->7
> - case-018-opacity critical 0->82
> - case-018-opacity major 0->3
> - case-019-visibility geometry 0->294
> - case-019-visibility mismatch 0->11
> - case-019-visibility structure 0->3
> - case-019-visibility critical 0->189
> - case-019-visibility major 0->63
> - case-020-text-align geometry 0->246
> - case-020-text-align mismatch 0->27
> - case-020-text-align critical 0->143
> - case-020-text-align major 0->102
> - case-021-line-height geometry 0->337
> - case-021-line-height mismatch 0->37
> - case-021-line-height structure 0->17
> - case-021-line-height critical 0->164
> - case-021-line-height major 0->172
> - case-022-white-space geometry 0->302
> - case-022-white-space mismatch 0->29
> - case-022-white-space critical 0->176
> - case-022-white-space major 0->125
> - case-023-word-break geometry 0->286
> - case-023-word-break mismatch 0->30
> - case-023-word-break critical 0->205
> - case-023-word-break major 0->80
> - case-024-font-weight geometry 0->231
> - case-024-font-weight mismatch 0->29
> - case-024-font-weight critical 0->183
> - case-024-font-weight major 0->46
> - case-025-english-text geometry 0->433
> - case-025-english-text mismatch 0->27
> - case-025-english-text critical 0->229
> - case-025-english-text major 0->203
> - case-026-font-style geometry 0->102
> - case-026-font-style mismatch 0->8
> - case-026-font-style critical 0->98
> - case-026-font-style major 0->3
> - case-027-scroll-diagnostic geometry 0->1
> - case-027-scroll-diagnostic critical 0->1
> - case-028-scroll-block geometry 0->1
> - case-028-scroll-block critical 0->1
> - case-029-scroll-flex-col geometry 0->1
> - case-029-scroll-flex-col critical 0->1
> - case-031-scroll-grid geometry 0->1
> - case-031-scroll-grid critical 0->1
> - case-032-scroll-relative geometry 0->1
> - case-032-scroll-relative critical 0->1
> - case-033-text-shadow geometry 0->399
> - case-033-text-shadow mismatch 0->31
> - case-033-text-shadow critical 0->201
> - case-033-text-shadow major 0->196
> - case-034-letter-spacing geometry 0->187
> - case-034-letter-spacing mismatch 0->31
> - case-034-letter-spacing critical 0->97
> - case-034-letter-spacing major 0->89
> - case-035-text-indent geometry 0->408
> - case-035-text-indent mismatch 0->30
> - case-035-text-indent structure 0->5
> - case-035-text-indent critical 0->193
> - case-035-text-indent major 0->214
> - case-036-background-repeat geometry 0->295
> - case-036-background-repeat mismatch 0->43
> - case-036-background-repeat critical 0->138
> - case-036-background-repeat major 0->156
> - case-037-direction-rtl geometry 0->333
> - case-037-direction-rtl mismatch 0->39
> - case-037-direction-rtl critical 0->180
> - case-037-direction-rtl major 0->151
> - case-038-list-style geometry 0->367
> - case-038-list-style mismatch 0->53
> - case-038-list-style critical 0->236
> - case-038-list-style major 0->130
> - case-039-vertical-align geometry 0->317
> - case-039-vertical-align mismatch 0->27
> - case-039-vertical-align critical 0->195
> - case-039-vertical-align major 0->115
> - case-040-word-wrap geometry 0->526
> - case-040-word-wrap mismatch 0->46
> - case-040-word-wrap critical 0->403
> - case-040-word-wrap major 0->121
> - case-041-background-clip geometry 0->379
> - case-041-background-clip mismatch 0->59
> - case-041-background-clip critical 0->211
> - case-041-background-clip major 0->166
> - case-042-font-variant geometry 0->532
> - case-042-font-variant mismatch 0->47
> - case-042-font-variant critical 0->272
> - case-042-font-variant major 0->258
> - case-043-resize geometry 0->293
> - case-043-resize mismatch 0->37
> - case-043-resize structure 0->19
> - case-043-resize critical 0->170
> - case-043-resize major 0->122
> - case-044-background-attachment geometry 0->337
> - case-044-background-attachment mismatch 0->52
> - case-044-background-attachment critical 0->202
> - case-044-background-attachment major 0->133
> - case-045-text-decoration geometry 0->475
> - case-045-text-decoration mismatch 0->55
> - case-045-text-decoration critical 0->236
> - case-045-text-decoration major 0->237
> - case-046-appearance geometry 0->371
> - case-046-appearance mismatch 0->68
> - case-046-appearance structure 0->5
> - case-046-appearance missing 0->2
> - case-046-appearance critical 0->217
> - case-046-appearance major 0->149
> - case-047-object-fit geometry 0->309
> - case-047-object-fit mismatch 0->51
> - case-047-object-fit critical 0->173
> - case-047-object-fit major 0->134
> - case-048-table-props geometry 0->461
> - case-048-table-props mismatch 0->70
> - case-048-table-props structure 0->9
> - case-048-table-props critical 0->247
> - case-048-table-props major 0->199
> - case-049-multi-column geometry 0->532
> - case-049-multi-column mismatch 0->45
> - case-049-multi-column critical 0->410
> - case-049-multi-column major 0->118
> - case-050-text-emphasis geometry 0->432
> - case-050-text-emphasis mismatch 0->40
> - case-050-text-emphasis critical 0->225
> - case-050-text-emphasis major 0->205
> - case-051-aspect-ratio geometry 0->10
> - case-051-aspect-ratio mismatch 0->3
> - case-051-aspect-ratio critical 0->9
> - case-052-flow-root geometry 0->7
> - case-052-flow-root mismatch 0->1
> - case-052-flow-root critical 0->6
> - case-053-intrinsic-sizing geometry 0->13
> - case-053-intrinsic-sizing mismatch 0->7
> - case-053-intrinsic-sizing critical 0->9
> - case-053-intrinsic-sizing major 0->3
> - case-054-inline-block-nest geometry 0->13
> - case-054-inline-block-nest mismatch 0->2
> - case-054-inline-block-nest critical 0->9
> - case-054-inline-block-nest major 0->2
> - case-055-sticky-multi geometry 0->18
> - case-055-sticky-multi mismatch 0->4
> - case-055-sticky-multi critical 0->6
> - case-055-sticky-multi major 0->10
> - overflow 0->200
>
> ⚠️ 建议: 检查本次变更是否引入了预期外的行为改变。

## 样式属性统计

| 属性 | 通过率 | 通过/总 |
|------|--------|--------|
| height | 85.1% | 6629/7787 |
| width | 85.3% | 6642/7787 |
| position | 99.8% | 7769/7787 |
| font-weight | 100% | 7787/7787 |
| opacity | 100% | 7787/7787 |
| top | 100% | 7724/7724 |
| left | 100% | 7724/7724 |
| font-size | 100% | 7016/7016 |
| background-color | 100% | 6695/6696 |
| display | 100% | 6655/6655 |
| border-radius | 100% | 973/973 |
| text-decoration-thickness | 0% | 0/955 |
| border-left-color | 99.9% | 953/954 |
| border-left-width | 100% | 954/954 |
| padding-top | 6.1% | 10/165 |
| padding-left | 7.6% | 10/131 |
| padding-right | 7.6% | 10/131 |
| padding-bottom | 7.6% | 10/131 |
| margin-bottom | 13.7% | 17/124 |
| text-align | 100% | 58/58 |
| margin-top | 20% | 10/50 |
| gap | 8.8% | 3/34 |
| flex-direction | 10.5% | 2/19 |
| margin-left | 66.7% | 10/15 |
| margin-right | 83.3% | 10/12 |
| align-items | 100% | 8/8 |
| text-decoration-line | 0% | 0/6 |
| justify-content | 100% | 6/6 |
| border-width | 100% | 5/5 |
| flex-wrap | 0% | 0/2 |
| min-height | 0% | 0/1 |
| text-decoration-color | 0% | 0/1 |
| text-decoration-style | 0% | 0/1 |
| overflow-y | 100% | 1/1 |
| font-family | 0% | 0/0 |
| line-height | 0% | 0/0 |
| border-top-width | 0% | 0/0 |
| border-right-width | 0% | 0/0 |
| border-bottom-width | 0% | 0/0 |
| border-style | 0% | 0/0 |
| border-top-color | 0% | 0/0 |
| border-right-color | 0% | 0/0 |
| border-bottom-color | 0% | 0/0 |
| font-style | 0% | 0/0 |
| white-space | 0% | 0/0 |
| word-break | 0% | 0/0 |
| visibility | 0% | 0/0 |
| cursor | 0% | 0/0 |
| direction | 0% | 0/0 |
| color | 0% | 0/0 |
| border-color | 0% | 0/0 |
| min-width | 0% | 0/0 |
| flex-grow | 0% | 0/0 |
| flex-shrink | 0% | 0/0 |
| overflow-x | 0% | 0/0 |
| pointer-events | 0% | 0/0 |
| overflow | 0% | 0/0 |
| max-height | 0% | 0/0 |

---

## 逐 Case 差异详情

| 用例 | 缺失(MISSING) | 严重(>20px) | 中等(5-20px) | 值(MISMATCH) | 结构(STRUCTURE) | Phase G 溢出 |
|------|:-------------:|:-----------:|:------------:|:-------------:|:---------------:|:------------:|
| case-001-wrapper-x | 0 | 0 | **41** | 657 | 0 | 0 |
| case-002-auto-height | 0 | 0 | 0 | 0 | 0 | 0 |
| case-003-basic-block | 0 | 0 | **156** | 544 | 0 | 0 |
| case-004-flex-layout | 3 | **97** | **2** | 535 | 0 | 0 |
| case-005-grid-layout | 0 | 0 | 0 | 0 | 0 | 0 |
| case-006-typography | 0 | 0 | 0 | 1058 | 0 | 0 |
| case-007-border-styles | 0 | 0 | 0 | 0 | 0 | 0 |
| case-008-box-shadow | 0 | 0 | 0 | 0 | 0 | 0 |
| case-009-outline | 0 | 0 | 0 | 0 | 0 | 0 |
| case-010-display-none | 0 | 0 | 0 | 0 | 0 | 0 |
| case-011-position-absolute | 0 | **114** | **13** | 38 | 0 | 0 |
| case-012-position-relative | 0 | **138** | **28** | 10 | 3 | 15 |
| case-013-z-index | 0 | **167** | **43** | 15 | 0 | 0 |
| case-014-overflow-hidden | 0 | **141** | **4** | 16 | 0 | 0 |
| case-015-min-max-height | 0 | **139** | **93** | 38 | 13 | 6 |
| case-016-margin-collapse | 0 | **203** | **45** | 30 | 0 | 0 |
| case-017-negative-margin | 0 | **121** | **2** | 17 | 0 | 0 |
| case-018-opacity | 0 | **82** | **3** | 7 | 0 | 2 |
| case-019-visibility | 0 | **189** | **63** | 11 | 3 | 1 |
| case-020-text-align | 0 | **143** | **102** | 27 | 0 | 0 |
| case-021-line-height | 0 | **164** | **172** | 37 | 17 | 8 |
| case-022-white-space | 0 | **176** | **125** | 29 | 0 | 0 |
| case-023-word-break | 0 | **205** | **80** | 30 | 0 | 3 |
| case-024-font-weight | 0 | **183** | **46** | 29 | 0 | 2 |
| case-025-english-text | 0 | **229** | **203** | 27 | 0 | 0 |
| case-026-font-style | 0 | **98** | **3** | 8 | 0 | 2 |
| case-027-scroll-diagnostic | 0 | **1** | 0 | 0 | 0 | 30 |
| case-028-scroll-block | 0 | **1** | 0 | 0 | 0 | 30 |
| case-029-scroll-flex-col | 0 | **1** | 0 | 0 | 0 | 30 |
| case-030-scroll-flex-row | 0 | 0 | 0 | 0 | 0 | 0 |
| case-031-scroll-grid | 0 | **1** | 0 | 0 | 0 | 37 |
| case-032-scroll-relative | 0 | **1** | 0 | 0 | 0 | 20 |
| case-033-text-shadow | 0 | **201** | **196** | 31 | 0 | 0 |
| case-034-letter-spacing | 0 | **97** | **89** | 31 | 0 | 0 |
| case-035-text-indent | 0 | **193** | **214** | 30 | 5 | 0 |
| case-036-background-repeat | 0 | **138** | **156** | 43 | 0 | 0 |
| case-037-direction-rtl | 0 | **180** | **151** | 39 | 0 | 0 |
| case-038-list-style | 0 | **236** | **130** | 53 | 0 | 10 |
| case-039-vertical-align | 0 | **195** | **115** | 27 | 0 | 0 |
| case-040-word-wrap | 0 | **403** | **121** | 46 | 0 | 0 |
| case-041-background-clip | 0 | **211** | **166** | 59 | 0 | 0 |
| case-042-font-variant | 0 | **272** | **258** | 47 | 0 | 0 |
| case-043-resize | 0 | **170** | **122** | 37 | 19 | 0 |
| case-044-background-attachment | 0 | **202** | **133** | 52 | 0 | 3 |
| case-045-text-decoration | 0 | **236** | **237** | 55 | 0 | 0 |
| case-046-appearance | 2 | **217** | **149** | 68 | 5 | 0 |
| case-047-object-fit | 0 | **173** | **134** | 51 | 0 | 0 |
| case-048-table-props | 0 | **247** | **199** | 70 | 9 | 0 |
| case-049-multi-column | 0 | **410** | **118** | 45 | 0 | 0 |
| case-050-text-emphasis | 0 | **225** | **205** | 40 | 0 | 0 |
| case-051-aspect-ratio | 0 | **9** | 0 | 3 | 0 | 0 |
| case-052-flow-root | 0 | **6** | 0 | 1 | 0 | 0 |
| case-053-intrinsic-sizing | 0 | **9** | **3** | 7 | 0 | 0 |
| case-054-inline-block-nest | 0 | **9** | **2** | 2 | 0 | 1 |
| case-055-sticky-multi | 0 | **6** | **10** | 4 | 0 | 0 |

---

## Phase G 容器溢出详情

### case-012-position-relative

- [FLEX-WIDTH] row flex items total width (2274) exceeds container content width (750) by 1524px (gap=${gap}px, children=3, wrap=nowrap)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)
- child(type=span bottom=115) overflows parent(type=div contentBottom=95) by 20px (bottom overflow)

### case-015-min-max-height

- [FLEX-WIDTH] row flex items total width (2282) exceeds container content width (750) by 1532px (gap=${gap}px, children=3, wrap=nowrap)
- child(type=div right=777) overflows parent(type=div contentRight=312) by 465px (w: child=716 parent=250)
- child(type=div right=777) overflows parent(type=div contentRight=312) by 465px (w: child=716 parent=250)
- child(type=div right=777) overflows parent(type=div contentRight=578) by 199px (w: child=716 parent=250)
- child(type=div right=777) overflows parent(type=div contentRight=578) by 199px (w: child=716 parent=250)
- child(type=div right=827) overflows parent(type=div contentRight=794) by 33px (w: child=250 parent=750)

### case-018-opacity

- [FLEX-WIDTH] row flex items total width (3048) exceeds container content width (750) by 2298px (gap=${gap}px, children=4, wrap=wrap)
- [FLEX-WRAP-WIDTH] wrap container: items exceed row width by 2298px — items may be too wide for flex:1 distribution

### case-019-visibility

- [FLEX-WIDTH] row flex items total width (3036) exceeds container content width (750) by 2286px (gap=${gap}px, children=4, wrap=nowrap)

### case-021-line-height

- [FLEX-WIDTH] row flex items total width (1516) exceeds container content width (750) by 766px (gap=${gap}px, children=2, wrap=nowrap)
- child(type=div right=779) overflows parent(type=div contentRight=435) by 344px (w: child=720 parent=375)
- child(type=div right=779) overflows parent(type=div contentRight=435) by 344px (w: child=720 parent=375)
- child(type=div right=811) overflows parent(type=div contentRight=794) by 17px (w: child=375 parent=750)
- [FLEX-WIDTH] row flex items total width (1516) exceeds container content width (750) by 766px (gap=${gap}px, children=2, wrap=nowrap)
- child(type=div right=779) overflows parent(type=div contentRight=435) by 344px (w: child=720 parent=375)
- child(type=div right=779) overflows parent(type=div contentRight=435) by 344px (w: child=720 parent=375)
- child(type=div right=811) overflows parent(type=div contentRight=794) by 17px (w: child=375 parent=750)

### case-023-word-break

- [FLEX-WIDTH] row flex items total width (1516) exceeds container content width (750) by 766px (gap=${gap}px, children=2, wrap=nowrap)
- child(type=div right=779) overflows parent(type=div contentRight=435) by 344px (w: child=720 parent=375)
- child(type=div right=811) overflows parent(type=div contentRight=794) by 17px (w: child=375 parent=750)

### case-024-font-weight

- [FLEX-WIDTH] row flex items total width (4416) exceeds container content width (716) by 3700px (gap=${gap}px, children=6, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1516) exceeds container content width (750) by 766px (gap=${gap}px, children=2, wrap=nowrap)

### case-026-font-style

- [FLEX-WIDTH] row flex items total width (2282) exceeds container content width (750) by 1532px (gap=${gap}px, children=3, wrap=wrap)
- [FLEX-WRAP-WIDTH] wrap container: items exceed row width by 1532px — items may be too wide for flex:1 distribution

### case-027-scroll-diagnostic

- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)

### case-028-scroll-block

- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)

### case-029-scroll-flex-col

- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)

### case-031-scroll-grid

- child(type=span right=380) overflows parent(type=div contentRight=173) by 207px (w: child=8 parent=120)
- child(type=span right=388) overflows parent(type=div contentRight=173) by 215px (w: child=8 parent=120)
- child(type=span right=396) overflows parent(type=div contentRight=173) by 223px (w: child=8 parent=120)
- child(type=span right=404) overflows parent(type=div contentRight=173) by 231px (w: child=8 parent=120)
- child(type=span right=412) overflows parent(type=div contentRight=173) by 239px (w: child=8 parent=120)
- child(type=span right=420) overflows parent(type=div contentRight=173) by 247px (w: child=8 parent=120)
- child(type=span right=428) overflows parent(type=div contentRight=173) by 255px (w: child=8 parent=120)
- child(type=span right=436) overflows parent(type=div contentRight=173) by 263px (w: child=8 parent=120)
- child(type=span right=444) overflows parent(type=div contentRight=173) by 271px (w: child=8 parent=120)
- child(type=span right=452) overflows parent(type=div contentRight=173) by 279px (w: child=8 parent=120)
- child(type=span right=460) overflows parent(type=div contentRight=173) by 287px (w: child=8 parent=120)
- child(type=span right=468) overflows parent(type=div contentRight=173) by 295px (w: child=8 parent=120)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1476) exceeds container content width (746) by 730px (gap=${gap}px, children=3, wrap=nowrap)

### case-032-scroll-relative

- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)
- [FLEX-WIDTH] row flex items total width (1472) exceeds container content width (746) by 726px (gap=${gap}px, children=3, wrap=nowrap)

### case-038-list-style

- child(type=li right=802) overflows parent(type=ul contentRight=753) by 49px (w: child=716 parent=692)
- child(type=li right=802) overflows parent(type=ul contentRight=753) by 49px (w: child=716 parent=692)
- child(type=li right=802) overflows parent(type=ul contentRight=753) by 49px (w: child=716 parent=692)
- child(type=li right=802) overflows parent(type=ol contentRight=753) by 49px (w: child=716 parent=692)
- child(type=li right=802) overflows parent(type=ol contentRight=753) by 49px (w: child=716 parent=692)
- child(type=li right=802) overflows parent(type=ol contentRight=753) by 49px (w: child=716 parent=692)
- child(type=li right=802) overflows parent(type=ul contentRight=753) by 49px (w: child=716 parent=692)
- child(type=li right=802) overflows parent(type=ul contentRight=753) by 49px (w: child=716 parent=692)
- child(type=li right=802) overflows parent(type=ul contentRight=753) by 49px (w: child=716 parent=692)
- child(type=li right=802) overflows parent(type=ul contentRight=753) by 49px (w: child=716 parent=692)

### case-044-background-attachment

- child(type=div bottom=213) overflows parent(type=div contentBottom=172) by 41px (bottom overflow)
- child(type=div bottom=213) overflows parent(type=div contentBottom=172) by 41px (bottom overflow)
- child(type=div bottom=213) overflows parent(type=div contentBottom=172) by 41px (bottom overflow)

### case-054-inline-block-nest

- child(type=div right=241) overflows parent(type=div contentRight=225) by 16px (w: child=92 parent=100)

