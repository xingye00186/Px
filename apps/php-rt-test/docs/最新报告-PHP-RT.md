# CSS Test Sandbox — 测试报告

**运行时间**: 2026-06-29 10:05:09 | **总耗时**: 157.1s

| 用例 | 构建 | 布局 | 多帧 | 浏览器 | 元素对比 | Phase L | Phase G | 差异 | 截图 | 结果 | 耗时 |
|------|------|------|------|--------|----------|---------|---------|------|------|------|------|
| prt-01-margin | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 2.4s |
| prt-02-margin-auto | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 3.1s |
| prt-03-negative-margin | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 1.1s |
| prt-04-padding | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 2.5s |
| prt-05-border | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 1.2s |
| prt-06-border-radius | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 2.3s |
| prt-07-width-height | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 26diff 🔴9 | ⏭️ | ❌ 失败 | 1.1s |
| prt-08-min-max | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 1.2s |
| prt-09-box-sizing | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 2 | 32diff 🔴20 | ⏭️ | ❌ 失败 | 2.5s |
| prt-10-overflow | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 4 | 44diff 🔴27 | ⏭️ | ❌ 失败 | 2.3s |
| prt-100-table-caption | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 7 | 154diff 🔴110 | ⏭️ | ❌ 失败 | 4.8s |
| prt-101-table-empty-cells | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 3 | 52diff 🔴29 | ⏭️ | ❌ 失败 | 4.9s |
| prt-102-column-span | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 2 | 141diff 🔴110 | ⏭️ | ❌ 失败 | 5.3s |
| prt-103-list-style-position | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 4 | 153diff 🔴124 | ⏭️ | ❌ 失败 | 1.4s |
| prt-104-margin-percent | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 17diff | ⏭️ | ❌ 失败 | 1.2s |
| prt-105-width-percent-chain | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 6diff | ⏭️ | ❌ 失败 | 1.3s |
| prt-106-min-width-override | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 2 | 20diff 🔴1 | ⏭️ | ❌ 失败 | 1.2s |
| prt-107-max-width-constraint | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 1 | 19diff 🔴1 | ⏭️ | ❌ 失败 | 1.1s |
| prt-108-integ-overlap-layers | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 12diff 🔴1 | ⏭️ | ❌ 失败 | 1.1s |
| prt-109-integ-responsive-card | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 23diff | ⏭️ | ❌ 失败 | 1.1s |
| prt-11-display-types | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 1diff | ⏭️ | ❌ 失败 | 2.3s |
| prt-110-integ-complex-toolbar | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ❌ 2issue | ⚠️ 1 | 84diff 🔴38 | ⏭️ | ❌ 失败 | 1.2s |
| prt-12-display-none | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 2.5s |
| prt-13-position-relative | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 2diff 🔴1 | ⏭️ | ❌ 失败 | 1.2s |
| prt-14-position-absolute | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ✅ 通过 | 2.5s |
| prt-15-position-fixed | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 2diff 🔴2 | ⏭️ | ❌ 失败 | 2.5s |
| prt-16-top-left-right-bottom | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 51diff 🔴21 | ⏭️ | ❌ 失败 | 1.2s |
| prt-17-z-index | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 40diff 🔴16 | ⏭️ | ❌ 失败 | 1.1s |
| prt-18-position-static | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 41diff 🔴9 | ⏭️ | ❌ 失败 | 1.2s |
| prt-19-flex-basic | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 1 | 44diff 🔴8 | ⏭️ | ❌ 失败 | 1.7s |
| prt-20-flex-direction | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 75diff 🔴18 | ⏭️ | ❌ 失败 | 1.1s |
| prt-21-flex-wrap | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ❌ 1issue | ⚠️ 2 | 50diff 🔴12 | ⏭️ | ❌ 失败 | 1.1s |
| prt-22-flex-jc-start | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 41diff 🔴8 | ⏭️ | ❌ 失败 | 1.1s |
| prt-23-flex-jc-center | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 41diff 🔴9 | ⏭️ | ❌ 失败 | 1.1s |
| prt-24-flex-jc-end | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 41diff 🔴9 | ⏭️ | ❌ 失败 | 2.5s |
| prt-25-flex-jc-between | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 40diff 🔴8 | ⏭️ | ❌ 失败 | 1.1s |
| prt-26-flex-jc-around | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 40diff 🔴9 | ⏭️ | ❌ 失败 | 1.1s |
| prt-27-flex-jc-evenly | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 40diff 🔴9 | ⏭️ | ❌ 失败 | 1.1s |
| prt-28-flex-align-items | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 67diff 🔴17 | ⏭️ | ❌ 失败 | 1.3s |
| prt-29-flex-align-self | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 46diff 🔴12 | ⏭️ | ❌ 失败 | 1.1s |
| prt-30-flex-grow | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 2 | 76diff 🔴17 | ⏭️ | ❌ 失败 | 1.1s |
| prt-31-flex-order | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ❌ 2issue | ✅ | 47diff 🔴8 | ⏭️ | ❌ 失败 | 1s |
| prt-32-flex-gap | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 1 | 76diff 🔴17 | ⏭️ | ❌ 失败 | 1.5s |
| prt-33-grid-basic | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 38diff 🔴8 | ⏭️ | ❌ 失败 | 1.1s |
| prt-34-grid-fr | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 38diff 🔴8 | ⏭️ | ❌ 失败 | 1s |
| prt-35-grid-repeat | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 62diff 🔴18 | ⏭️ | ❌ 失败 | 1s |
| prt-36-grid-gap | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 47diff 🔴12 | ⏭️ | ❌ 失败 | 1.2s |
| prt-37-grid-span | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 56diff 🔴18 | ⏭️ | ❌ 失败 | 1s |
| prt-38-grid-align | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 33diff 🔴10 | ⏭️ | ❌ 失败 | 1.1s |
| prt-39-grid-justify-self | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 33diff 🔴7 | ⏭️ | ❌ 失败 | 1s |
| prt-40-grid-minmax | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 42diff 🔴3 | ⏭️ | ❌ 失败 | 1.1s |
| prt-41-font-size | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 144diff 🔴70 | ⏭️ | ❌ 失败 | 1.2s |
| prt-42-font-weight | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 150diff 🔴59 | ⏭️ | ❌ 失败 | 1.1s |
| prt-43-text-align | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 126diff 🔴41 | ⏭️ | ❌ 失败 | 1.1s |
| prt-44-line-height | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 153diff 🔴77 | ⏭️ | ❌ 失败 | 1.1s |
| prt-45-text-indent | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 78diff 🔴32 | ⏭️ | ❌ 失败 | 1.1s |
| prt-46-white-space | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 226diff 🔴111 | ⏭️ | ❌ 失败 | 1.2s |
| prt-47-opacity | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 2 | 86diff 🔴28 | ⏭️ | ❌ 失败 | 1.1s |
| prt-48-visibility | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 39diff 🔴9 | ⏭️ | ❌ 失败 | 1s |
| prt-49-outline | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 21diff 🔴3 | ⏭️ | ❌ 失败 | 1.1s |
| prt-50-background | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 20diff 🔴5 | ⏭️ | ❌ 失败 | 1.1s |
| prt-51-integ-flex-grid | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 1 | 113diff 🔴33 | ⏭️ | ❌ 失败 | 1.2s |
| prt-52-integ-card-grid | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 205diff 🔴70 | ⏭️ | ❌ 失败 | 1.2s |
| prt-53-integ-form | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 148diff 🔴49 | ⏭️ | ❌ 失败 | 1.1s |
| prt-54-integ-navigation | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ❌ 1issue | ✅ | 152diff 🔴44 | ⏭️ | ❌ 失败 | 1.2s |
| prt-55-integ-dashboard | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 1 | 151diff 🔴54 | ⏭️ | ❌ 失败 | 1.2s |
| prt-56-integ-fullpage | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 225diff 🔴79 | ⏭️ | ❌ 失败 | 1.1s |
| prt-57-margin-collapse | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 37diff 🔴9 | ⏭️ | ❌ 失败 | 1.1s |
| prt-58-float-left | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 51diff 🔴12 | ⏭️ | ❌ 失败 | 1.3s |
| prt-59-float-right | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 51diff 🔴12 | ⏭️ | ❌ 失败 | 1s |
| prt-60-float-clear | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 42diff 🔴11 | ⏭️ | ❌ 失败 | 1.1s |
| prt-61-direction-rtl | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 98diff 🔴37 | ⏭️ | ❌ 失败 | 1.2s |
| prt-62-text-transform | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 211diff 🔴101 | ⏭️ | ❌ 失败 | 1.3s |
| prt-63-word-break | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 120diff 🔴51 | ⏭️ | ❌ 失败 | 1.2s |
| prt-64-overflow-wrap | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 1 | 152diff 🔴79 | ⏭️ | ❌ 失败 | 1.2s |
| prt-65-vertical-align | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 134diff 🔴48 | ⏭️ | ❌ 失败 | 1.1s |
| prt-66-letter-spacing | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 180diff 🔴81 | ⏭️ | ❌ 失败 | 1.3s |
| prt-67-font-variant | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 82diff 🔴28 | ⏭️ | ❌ 失败 | 1.2s |
| prt-68-scroll-container | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 62diff 🔴14 | ⏭️ | ❌ 失败 | 1.2s |
| prt-69-table-basic | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 6 | 213diff 🔴74 | ⏭️ | ❌ 失败 | 1.2s |
| prt-70-grid-auto-flow | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 118diff 🔴31 | ⏭️ | ❌ 失败 | 1.1s |
| prt-71-grid-areas | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 191diff 🔴71 | ⏭️ | ❌ 失败 | 1.3s |
| prt-72-integ-complex-sidebar | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 4 | 942diff 🔴392 | ⏭️ | ❌ 失败 | 2s |
| prt-73-integ-media-object | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ❌ 1issue | ⚠️ 2 | 421diff 🔴234 | ⏭️ | ❌ 失败 | 1.4s |
| prt-74-integ-holy-grail | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 1 | 297diff 🔴137 | ⏭️ | ❌ 失败 | 1.3s |
| prt-75-integ-masonry | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 191diff 🔴66 | ⏭️ | ❌ 失败 | 1.3s |
| prt-76-integ-complex-form | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 442diff 🔴178 | ⏭️ | ❌ 失败 | 1.5s |
| prt-77-position-sticky | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 34diff | ⏭️ | ❌ 失败 | 1.8s |
| prt-78-clip-path | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ❌ 失败 | 1s |
| prt-79-flex-basis-pct | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 9diff 🔴1 | ⏭️ | ❌ 失败 | 1.1s |
| prt-80-flex-align-content | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ❌ 1issue | ⚠️ 1 | 5diff | ⏭️ | ❌ 失败 | 1.1s |
| prt-81-flex-column-gap | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 4diff | ⏭️ | ❌ 失败 | 1.2s |
| prt-82-flex-auto-margin | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 3diff | ⏭️ | ❌ 失败 | 1.7s |
| prt-83-grid-justify-content | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 10diff 🔴3 | ⏭️ | ❌ 失败 | 1.1s |
| prt-84-grid-align-content | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 19diff 🔴4 | ⏭️ | ❌ 失败 | 1.1s |
| prt-85-grid-auto-columns | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 13diff 🔴3 | ⏭️ | ❌ 失败 | 1.2s |
| prt-86-grid-item-order | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 79diff 🔴30 | ⏭️ | ❌ 失败 | 1.5s |
| prt-87-white-space-pre | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 321diff 🔴176 | ⏭️ | ❌ 失败 | 1.5s |
| prt-88-text-overflow | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 217diff 🔴140 | ⏭️ | ❌ 失败 | 1.3s |
| prt-89-word-spacing | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 41diff 🔴36 | ⏭️ | ❌ 失败 | 1.1s |
| prt-90-text-decoration | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 92diff 🔴78 | ⏭️ | ❌ 失败 | 1.2s |
| prt-91-text-shadow | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 45diff 🔴40 | ⏭️ | ❌ 失败 | 1.2s |
| prt-92-filter | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ❌ 失败 | 1.1s |
| prt-93-mix-blend-mode | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ❌ 失败 | 1.1s |
| prt-94-backdrop-filter | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ❌ 失败 | 1.1s |
| prt-95-outline-offset | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 3diff | ⏭️ | ❌ 失败 | 1.7s |
| prt-96-box-shadow-spread | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 1diff | ⏭️ | ❌ 失败 | 1.7s |
| prt-97-appearance-none | ⏭️ | ✅ | ⏭️ | ✅ | ✅ | ✅ | ✅ | ✅ | ⏭️ | ❌ 失败 | 1.1s |
| prt-98-resize-both | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ✅ | 41diff 🔴37 | ⏭️ | ❌ 失败 | 1.2s |
| prt-99-table-layout-fixed | ⏭️ | ✅ | ⏭️ | ✅ | ❌ | ✅ | ⚠️ 4 | 87diff 🔴48 | ⏭️ | ❌ 失败 | 1.1s |

**汇总**: 9 ✅ / 101 ❌ / 110 总计 (总耗时: 157.1s)

## 回归判定

> 🔴 **检测到回归** — 与上一次运行相比，以下指标恶化:
>
> - prop background-color diff 300->476
> - prop width diff 640->714
> - prop height diff 695->1093
> - prop text-align diff 7->22
> - prop white-space diff 11->189
> - prop line-height diff 1->105
> - prop flex-grow diff 34->47
> - prt-07-width-height structure 2->4
> - prt-07-width-height major 1->2
> - prt-100-table-caption geometry 42->132
> - prt-100-table-caption critical 29->110
> - prt-100-table-caption major 0->11
> - prt-101-table-empty-cells geometry 26->39
> - prt-101-table-empty-cells critical 15->29
> - prt-102-column-span geometry 21->132
> - prt-102-column-span structure 0->1
> - prt-102-column-span critical 12->110
> - prt-102-column-span major 0->3
> - prt-103-list-style-position geometry 53->126
> - prt-103-list-style-position critical 35->124
> - prt-106-min-width-override geometry 12->17
> - prt-107-max-width-constraint geometry 12->16
> - prt-110-integ-complex-toolbar geometry 60->63
> - prt-110-integ-complex-toolbar critical 6->38
> - prt-110-integ-complex-toolbar major 2->12
> - prt-16-top-left-right-bottom geometry 22->25
> - prt-16-top-left-right-bottom structure 2->4
> - prt-16-top-left-right-bottom critical 15->21
> - prt-17-z-index structure 2->5
> - prt-17-z-index critical 13->16
> - prt-18-position-static structure 2->5
> - prt-18-position-static critical 8->9
> - prt-19-flex-basic structure 2->5
> - prt-20-flex-direction geometry 28->29
> - prt-20-flex-direction structure 2->8
> - prt-20-flex-direction critical 15->18
> - prt-21-flex-wrap structure 2->6
> - prt-21-flex-wrap critical 10->12
> - prt-22-flex-jc-start geometry 16->17
> - prt-22-flex-jc-start structure 2->5
> - prt-22-flex-jc-start major 6->9
> - prt-23-flex-jc-center geometry 16->17
> - prt-23-flex-jc-center structure 2->5
> - prt-23-flex-jc-center major 5->8
> - prt-24-flex-jc-end geometry 16->17
> - prt-24-flex-jc-end structure 2->5
> - prt-24-flex-jc-end major 5->8
> - prt-25-flex-jc-between geometry 16->17
> - prt-25-flex-jc-between structure 2->5
> - prt-25-flex-jc-between major 6->9
> - prt-26-flex-jc-around geometry 16->17
> - prt-26-flex-jc-around structure 2->5
> - prt-26-flex-jc-around major 5->8
> - prt-27-flex-jc-evenly geometry 16->17
> - prt-27-flex-jc-evenly structure 2->5
> - prt-27-flex-jc-evenly major 5->8
> - prt-28-flex-align-items structure 2->7
> - prt-28-flex-align-items critical 16->17
> - prt-29-flex-align-self structure 2->5
> - prt-29-flex-align-self critical 11->12
> - prt-30-flex-grow geometry 26->29
> - prt-30-flex-grow structure 2->8
> - prt-30-flex-grow major 6->8
> - prt-31-flex-order geometry 16->17
> - prt-31-flex-order structure 2->5
> - prt-31-flex-order major 4->5
> - prt-32-flex-gap geometry 27->29
> - prt-32-flex-gap structure 2->8
> - prt-32-flex-gap critical 15->17
> - prt-33-grid-basic structure 2->5
> - prt-34-grid-fr structure 2->5
> - prt-34-grid-fr major 4->5
> - prt-35-grid-repeat geometry 20->25
> - prt-35-grid-repeat structure 2->8
> - prt-35-grid-repeat critical 16->18
> - prt-35-grid-repeat major 2->7
> - prt-36-grid-gap structure 2->6
> - prt-36-grid-gap critical 10->12
> - prt-36-grid-gap major 4->6
> - prt-37-grid-span geometry 20->22
> - prt-37-grid-span structure 2->7
> - prt-37-grid-span critical 14->18
> - prt-38-grid-align structure 2->4
> - prt-38-grid-align major 0->1
> - prt-39-grid-justify-self structure 2->4
> - prt-39-grid-justify-self major 1->3
> - prt-40-grid-minmax structure 2->6
> - prt-40-grid-minmax major 4->10
> - prt-41-font-size geometry 35->98
> - prt-41-font-size mismatch 39->40
> - prt-41-font-size critical 14->70
> - prt-41-font-size major 10->14
> - prt-42-font-weight geometry 32->93
> - prt-42-font-weight mismatch 39->51
> - prt-42-font-weight critical 12->59
> - prt-43-text-align geometry 33->61
> - prt-43-text-align mismatch 41->61
> - prt-43-text-align critical 15->41
> - prt-44-line-height geometry 23->102
> - prt-44-line-height mismatch 24->46
> - prt-44-line-height critical 10->77
> - prt-45-text-indent geometry 11->52
> - prt-45-text-indent mismatch 13->25
> - prt-45-text-indent critical 6->32
> - prt-45-text-indent major 3->4
> - prt-46-white-space geometry 23->144
> - prt-46-white-space mismatch 25->77
> - prt-46-white-space critical 12->111
> - prt-46-white-space major 5->12
> - prt-47-opacity geometry 34->48
> - prt-47-opacity critical 27->28
> - prt-47-opacity major 6->15
> - prt-48-visibility structure 2->5
> - prt-48-visibility critical 7->9
> - prt-50-background structure 2->3
> - prt-50-background major 2->3
> - prt-51-integ-flex-grid geometry 43->49
> - prt-51-integ-flex-grid mismatch 45->51
> - prt-51-integ-flex-grid structure 2->13
> - prt-51-integ-flex-grid major 5->11
> - prt-52-integ-card-grid geometry 47->103
> - prt-52-integ-card-grid mismatch 81->85
> - prt-52-integ-card-grid structure 8->17
> - prt-52-integ-card-grid critical 38->70
> - prt-52-integ-card-grid major 6->12
> - prt-53-integ-form geometry 52->78
> - prt-53-integ-form mismatch 59->61
> - prt-53-integ-form critical 32->49
> - prt-53-integ-form major 17->23
> - prt-54-integ-navigation geometry 59->63
> - prt-54-integ-navigation mismatch 70->72
> - prt-54-integ-navigation structure 2->17
> - prt-54-integ-navigation major 6->11
> - prt-55-integ-dashboard geometry 42->74
> - prt-55-integ-dashboard mismatch 61->67
> - prt-55-integ-dashboard structure 6->10
> - prt-55-integ-dashboard critical 32->54
> - prt-56-integ-fullpage geometry 81->110
> - prt-56-integ-fullpage mismatch 87->94
> - prt-56-integ-fullpage structure 9->21
> - prt-56-integ-fullpage critical 61->79
> - prt-56-integ-fullpage major 17->22
> - prt-57-margin-collapse structure 2->5
> - prt-57-margin-collapse critical 8->9
> - prt-57-margin-collapse major 2->3
> - prt-58-float-left structure 2->6
> - prt-58-float-left major 4->5
> - prt-59-float-right structure 2->6
> - prt-59-float-right major 3->5
> - prt-60-float-clear structure 2->5
> - prt-61-direction-rtl geometry 16->64
> - prt-61-direction-rtl mismatch 22->33
> - prt-61-direction-rtl critical 6->37
> - prt-62-text-transform geometry 34->144
> - prt-62-text-transform mismatch 37->60
> - prt-62-text-transform critical 13->101
> - prt-62-text-transform major 12->13
> - prt-63-word-break geometry 26->74
> - prt-63-word-break mismatch 37->42
> - prt-63-word-break critical 10->51
> - prt-64-overflow-wrap geometry 15->110
> - prt-64-overflow-wrap mismatch 23->39
> - prt-64-overflow-wrap critical 7->79
> - prt-64-overflow-wrap major 3->8
> - prt-65-vertical-align geometry 30->84
> - prt-65-vertical-align mismatch 29->47
> - prt-65-vertical-align critical 20->48
> - prt-65-vertical-align major 6->7
> - prt-66-letter-spacing geometry 25->120
> - prt-66-letter-spacing mismatch 28->54
> - prt-66-letter-spacing structure 5->6
> - prt-66-letter-spacing critical 10->81
> - prt-67-font-variant geometry 13->54
> - prt-67-font-variant mismatch 17->26
> - prt-67-font-variant critical 5->28
> - prt-68-scroll-container geometry 18->26
> - prt-68-scroll-container mismatch 28->29
> - prt-68-scroll-container structure 2->7
> - prt-68-scroll-container critical 9->14
> - prt-69-table-basic geometry 46->125
> - prt-69-table-basic mismatch 46->76
> - prt-69-table-basic structure 5->12
> - prt-69-table-basic critical 36->74
> - prt-69-table-basic major 7->27
> - prt-70-grid-auto-flow geometry 43->54
> - prt-70-grid-auto-flow mismatch 44->55
> - prt-70-grid-auto-flow structure 3->9
> - prt-70-grid-auto-flow major 3->9
> - prt-71-grid-areas geometry 51->108
> - prt-71-grid-areas mismatch 59->75
> - prt-71-grid-areas critical 37->71
> - prt-71-grid-areas major 13->29
> - prt-72-integ-complex-sidebar geometry 229->522
> - prt-72-integ-complex-sidebar mismatch 278->367
> - prt-72-integ-complex-sidebar structure 41->53
> - prt-72-integ-complex-sidebar critical 141->392
> - prt-72-integ-complex-sidebar major 64->95
> - prt-73-integ-media-object geometry 59->300
> - prt-73-integ-media-object mismatch 83->105
> - prt-73-integ-media-object structure 5->16
> - prt-73-integ-media-object critical 45->234
> - prt-73-integ-media-object major 8->51
> - prt-74-integ-holy-grail geometry 67->181
> - prt-74-integ-holy-grail mismatch 79->103
> - prt-74-integ-holy-grail critical 53->137
> - prt-74-integ-holy-grail major 13->15
> - prt-75-integ-masonry geometry 60->84
> - prt-75-integ-masonry mismatch 76->88
> - prt-75-integ-masonry structure 2->19
> - prt-75-integ-masonry critical 43->66
> - prt-76-integ-complex-form geometry 103->265
> - prt-76-integ-complex-form mismatch 127->153
> - prt-76-integ-complex-form critical 69->178
> - prt-76-integ-complex-form major 18->52
> - prt-77-position-sticky geometry 11->32
> - prt-83-grid-justify-content major 4->5
> - prt-86-grid-item-order geometry 16->68
> - prt-86-grid-item-order critical 5->30
> - prt-86-grid-item-order major 9->38
> - prt-87-white-space-pre geometry 31->194
> - prt-87-white-space-pre mismatch 15->121
> - prt-87-white-space-pre structure 0->6
> - prt-87-white-space-pre critical 0->176
> - prt-88-text-overflow geometry 8->144
> - prt-88-text-overflow mismatch 5->73
> - prt-88-text-overflow critical 0->140
> - prt-89-word-spacing geometry 7->40
> - prt-89-word-spacing critical 0->36
> - prt-89-word-spacing major 1->2
> - prt-90-text-decoration geometry 12->86
> - prt-90-text-decoration critical 0->78
> - prt-90-text-decoration major 2->4
> - prt-91-text-shadow geometry 7->44
> - prt-91-text-shadow critical 0->40
> - prt-91-text-shadow major 0->2
> - prt-98-resize-both geometry 5->40
> - prt-98-resize-both critical 0->37
> - prt-98-resize-both major 1->3
> - prt-99-table-layout-fixed geometry 43->67
> - prt-99-table-layout-fixed critical 30->48
> - prt-99-table-layout-fixed major 0->9
>
> ⚠️ 建议: 检查本次变更是否引入了预期外的行为改变。

## 样式属性统计

| 属性 | 通过率 | 通过/总 |
|------|--------|--------|
| height | 75.1% | 3294/4387 |
| width | 83.7% | 3673/4387 |
| position | 98.4% | 4318/4387 |
| text-align | 99.5% | 4365/4387 |
| opacity | 99.8% | 4379/4387 |
| font-weight | 100% | 4387/4387 |
| background-color | 88.8% | 3785/4261 |
| top | 100% | 4139/4139 |
| left | 100% | 4138/4138 |
| font-size | 100% | 4085/4086 |
| display | 100% | 3823/3823 |
| white-space | 2.1% | 4/193 |
| border-width | 100% | 159/159 |
| padding-left | 41.9% | 62/148 |
| padding-right | 41.9% | 62/148 |
| padding-bottom | 49.3% | 73/148 |
| padding-top | 50% | 74/148 |
| margin-bottom | 59.7% | 77/129 |
| gap | 19.8% | 21/106 |
| line-height | 0% | 0/105 |
| flex-shrink | 96.7% | 58/60 |
| flex-grow | 19% | 11/58 |
| flex-direction | 23.1% | 6/26 |
| grid-template-columns | 0% | 0/24 |
| margin-top | 50% | 11/22 |
| border-radius | 100% | 22/22 |
| margin-left | 76.2% | 16/21 |
| margin-right | 80% | 8/10 |
| flex-wrap | 85.7% | 6/7 |
| order | 0% | 0/6 |
| grid-column | 0% | 0/6 |
| min-width | 100% | 5/5 |
| align-items | 100% | 5/5 |
| min-height | 33.3% | 1/3 |
| align-self | 0% | 0/2 |
| justify-self | 0% | 0/2 |
| outline-width | 0% | 0/2 |
| outline-style | 0% | 0/2 |
| outline-color | 0% | 0/2 |
| grid-template-rows | 0% | 0/2 |
| align-content | 0% | 0/2 |
| text-decoration-line | 0% | 0/2 |
| max-width | 100% | 2/2 |
| justify-items | 0% | 0/1 |
| grid-auto-rows | 0% | 0/1 |
| text-decoration-style | 0% | 0/1 |
| text-decoration-thickness | 0% | 0/1 |
| box-shadow | 0% | 0/1 |
| max-height | 100% | 1/1 |
| justify-content | 100% | 1/1 |
| box-sizing | 0% | 0/0 |
| pointer-events | 0% | 0/0 |
| font-style | 0% | 0/0 |
| word-break | 0% | 0/0 |
| visibility | 0% | 0/0 |
| cursor | 0% | 0/0 |
| direction | 0% | 0/0 |
| color | 0% | 0/0 |
| border-left-width | 0% | 0/0 |
| border-left-color | 0% | 0/0 |
| border-color | 0% | 0/0 |
| font-family | 0% | 0/0 |
| overflow-x | 0% | 0/0 |
| overflow-y | 0% | 0/0 |
| border-style | 0% | 0/0 |
| overflow | 0% | 0/0 |

---

## 逐 Case 差异详情

| 用例 | 缺失(MISSING) | 严重(>20px) | 中等(5-20px) | 值(MISMATCH) | 结构(STRUCTURE) | Phase G 溢出 |
|------|:-------------:|:-----------:|:------------:|:-------------:|:---------------:|:------------:|
| prt-01-margin | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-02-margin-auto | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-03-negative-margin | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-04-padding | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-05-border | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-06-border-radius | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-07-width-height | 0 | **9** | **2** | 10 | 4 | 0 |
| prt-08-min-max | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-09-box-sizing | 0 | **20** | 0 | 12 | 0 | 2 |
| prt-10-overflow | 0 | **27** | 0 | 17 | 0 | 4 |
| prt-100-table-caption | 0 | **110** | **11** | 22 | 0 | 7 |
| prt-101-table-empty-cells | 0 | **29** | **2** | 13 | 0 | 3 |
| prt-102-column-span | 1 | **110** | **3** | 8 | 1 | 2 |
| prt-103-list-style-position | 0 | **124** | 0 | 27 | 0 | 4 |
| prt-104-margin-percent | 0 | 0 | 0 | 7 | 0 | 0 |
| prt-105-width-percent-chain | 0 | 0 | 0 | 3 | 0 | 0 |
| prt-106-min-width-override | 0 | **1** | 0 | 3 | 0 | 2 |
| prt-107-max-width-constraint | 0 | **1** | 0 | 3 | 0 | 1 |
| prt-108-integ-overlap-layers | 0 | **1** | 0 | 8 | 0 | 0 |
| prt-109-integ-responsive-card | 0 | 0 | 0 | 13 | 0 | 0 |
| prt-11-display-types | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-110-integ-complex-toolbar | 0 | **38** | **12** | 21 | 0 | 1 |
| prt-12-display-none | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-13-position-relative | 0 | **1** | 0 | 0 | 0 | 0 |
| prt-14-position-absolute | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-15-position-fixed | 0 | **2** | 0 | 0 | 0 | 0 |
| prt-16-top-left-right-bottom | 0 | **21** | **3** | 22 | 4 | 0 |
| prt-17-z-index | 0 | **16** | **1** | 18 | 5 | 0 |
| prt-18-position-static | 0 | **9** | **4** | 20 | 5 | 0 |
| prt-19-flex-basic | 0 | **8** | **5** | 22 | 5 | 1 |
| prt-20-flex-direction | 0 | **18** | **1** | 38 | 8 | 0 |
| prt-21-flex-wrap | 0 | **12** | **5** | 23 | 6 | 2 |
| prt-22-flex-jc-start | 0 | **8** | **9** | 19 | 5 | 0 |
| prt-23-flex-jc-center | 0 | **9** | **8** | 19 | 5 | 0 |
| prt-24-flex-jc-end | 0 | **9** | **8** | 19 | 5 | 0 |
| prt-25-flex-jc-between | 0 | **8** | **9** | 18 | 5 | 0 |
| prt-26-flex-jc-around | 0 | **9** | **8** | 18 | 5 | 0 |
| prt-27-flex-jc-evenly | 0 | **9** | **8** | 18 | 5 | 0 |
| prt-28-flex-align-items | 0 | **17** | **7** | 35 | 7 | 0 |
| prt-29-flex-align-self | 0 | **12** | **4** | 24 | 5 | 0 |
| prt-30-flex-grow | 0 | **17** | **8** | 39 | 8 | 2 |
| prt-31-flex-order | 0 | **8** | **5** | 25 | 5 | 0 |
| prt-32-flex-gap | 0 | **17** | **3** | 39 | 8 | 1 |
| prt-33-grid-basic | 0 | **8** | **5** | 20 | 5 | 0 |
| prt-34-grid-fr | 0 | **8** | **5** | 20 | 5 | 0 |
| prt-35-grid-repeat | 0 | **18** | **7** | 29 | 8 | 0 |
| prt-36-grid-gap | 0 | **12** | **6** | 23 | 6 | 0 |
| prt-37-grid-span | 0 | **18** | **4** | 27 | 7 | 0 |
| prt-38-grid-align | 0 | **10** | **1** | 18 | 4 | 0 |
| prt-39-grid-justify-self | 0 | **7** | **3** | 19 | 4 | 0 |
| prt-40-grid-minmax | 0 | **3** | **10** | 23 | 6 | 0 |
| prt-41-font-size | 0 | **70** | **14** | 40 | 6 | 0 |
| prt-42-font-weight | 0 | **59** | **12** | 51 | 6 | 0 |
| prt-43-text-align | 0 | **41** | **6** | 61 | 4 | 0 |
| prt-44-line-height | 0 | **77** | **5** | 46 | 5 | 0 |
| prt-45-text-indent | 0 | **32** | **4** | 25 | 1 | 0 |
| prt-46-white-space | 0 | **111** | **12** | 77 | 5 | 0 |
| prt-47-opacity | 1 | **28** | **15** | 33 | 5 | 2 |
| prt-48-visibility | 0 | **9** | **4** | 18 | 5 | 0 |
| prt-49-outline | 0 | **3** | **4** | 12 | 2 | 0 |
| prt-50-background | 0 | **5** | **3** | 9 | 3 | 0 |
| prt-51-integ-flex-grid | 0 | **33** | **11** | 51 | 13 | 1 |
| prt-52-integ-card-grid | 3 | **70** | **12** | 85 | 17 | 0 |
| prt-53-integ-form | 1 | **49** | **23** | 61 | 9 | 0 |
| prt-54-integ-navigation | 0 | **44** | **11** | 72 | 17 | 0 |
| prt-55-integ-dashboard | 0 | **54** | **8** | 67 | 10 | 1 |
| prt-56-integ-fullpage | 0 | **79** | **22** | 94 | 21 | 0 |
| prt-57-margin-collapse | 0 | **9** | **3** | 17 | 5 | 0 |
| prt-58-float-left | 0 | **12** | **5** | 25 | 6 | 0 |
| prt-59-float-right | 0 | **12** | **5** | 25 | 6 | 0 |
| prt-60-float-clear | 0 | **11** | **4** | 20 | 5 | 0 |
| prt-61-direction-rtl | 0 | **37** | **6** | 33 | 1 | 0 |
| prt-62-text-transform | 0 | **101** | **13** | 60 | 7 | 0 |
| prt-63-word-break | 0 | **51** | **5** | 42 | 4 | 0 |
| prt-64-overflow-wrap | 0 | **79** | **8** | 39 | 3 | 1 |
| prt-65-vertical-align | 0 | **48** | **7** | 47 | 3 | 0 |
| prt-66-letter-spacing | 0 | **81** | **6** | 54 | 6 | 0 |
| prt-67-font-variant | 0 | **28** | **5** | 26 | 2 | 0 |
| prt-68-scroll-container | 0 | **14** | **4** | 29 | 7 | 0 |
| prt-69-table-basic | 0 | **74** | **27** | 76 | 12 | 6 |
| prt-70-grid-auto-flow | 0 | **31** | **9** | 55 | 9 | 0 |
| prt-71-grid-areas | 1 | **71** | **29** | 75 | 8 | 0 |
| prt-72-integ-complex-sidebar | 8 | **392** | **95** | 367 | 53 | 4 |
| prt-73-integ-media-object | 1 | **234** | **51** | 105 | 16 | 2 |
| prt-74-integ-holy-grail | 0 | **137** | **15** | 103 | 13 | 1 |
| prt-75-integ-masonry | 0 | **66** | **4** | 88 | 19 | 0 |
| prt-76-integ-complex-form | 5 | **178** | **52** | 153 | 24 | 0 |
| prt-77-position-sticky | 0 | 0 | 0 | 2 | 0 | 0 |
| prt-78-clip-path | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-79-flex-basis-pct | 0 | **1** | **4** | 3 | 0 | 0 |
| prt-80-flex-align-content | 0 | 0 | 0 | 1 | 0 | 1 |
| prt-81-flex-column-gap | 0 | 0 | 0 | 1 | 0 | 0 |
| prt-82-flex-auto-margin | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-83-grid-justify-content | 0 | **3** | **5** | 2 | 0 | 0 |
| prt-84-grid-align-content | 0 | **4** | **8** | 7 | 0 | 0 |
| prt-85-grid-auto-columns | 0 | **3** | **6** | 4 | 0 | 0 |
| prt-86-grid-item-order | 0 | **30** | **38** | 11 | 0 | 0 |
| prt-87-white-space-pre | 0 | **176** | **9** | 121 | 6 | 0 |
| prt-88-text-overflow | 0 | **140** | **2** | 73 | 0 | 0 |
| prt-89-word-spacing | 0 | **36** | **2** | 1 | 0 | 0 |
| prt-90-text-decoration | 0 | **78** | **4** | 6 | 0 | 0 |
| prt-91-text-shadow | 0 | **40** | **2** | 1 | 0 | 0 |
| prt-92-filter | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-93-mix-blend-mode | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-94-backdrop-filter | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-95-outline-offset | 0 | 0 | 0 | 3 | 0 | 0 |
| prt-96-box-shadow-spread | 0 | 0 | 0 | 1 | 0 | 0 |
| prt-97-appearance-none | 0 | 0 | 0 | 0 | 0 | 0 |
| prt-98-resize-both | 0 | **37** | **3** | 1 | 0 | 0 |
| prt-99-table-layout-fixed | 0 | **48** | **9** | 20 | 0 | 4 |

---

## Phase G 容器溢出详情

### prt-09-box-sizing

- child(type=div right=1920) overflows parent(type=div contentRight=1100) by 820px (w: child=1620 parent=800)
- child(type=span right=3661) overflows parent(type=div contentRight=1920) by 1741px (w: child=81 parent=800)

### prt-10-overflow

- child(type=div right=2724) overflows parent(type=div contentRight=1100) by 1624px (w: child=2424 parent=800)
- child(type=div right=450) overflows parent(type=div contentRight=401) by 49px (w: child=150 parent=100)
- child(type=span right=3593) overflows parent(type=div contentRight=1912) by 1681px (w: child=45 parent=800)
- child(type=span right=6841) overflows parent(type=div contentRight=2724) by 4117px (w: child=45 parent=800)

### prt-100-table-caption

- child(type=span right=498) overflows parent(type=div contentRight=407) by 91px (w: child=191 parent=100)
- child(type=span right=366) overflows parent(type=div contentRight=357) by 9px (w: child=53 parent=50)
- child(type=span bottom=132) overflows parent(type=div contentBottom=121) by 11px (bottom overflow)
- child(type=span bottom=132) overflows parent(type=div contentBottom=121) by 11px (bottom overflow)
- child(type=span right=366) overflows parent(type=div contentRight=357) by 9px (w: child=53 parent=50)
- child(type=span bottom=148) overflows parent(type=div contentBottom=121) by 27px (bottom overflow)
- child(type=span bottom=148) overflows parent(type=div contentBottom=121) by 27px (bottom overflow)

### prt-101-table-empty-cells

- child(type=span right=348) overflows parent(type=div contentRight=340) by 8px (w: child=35 parent=33)
- child(type=span bottom=132) overflows parent(type=div contentBottom=121) by 11px (bottom overflow)
- child(type=span bottom=132) overflows parent(type=div contentBottom=121) by 11px (bottom overflow)

### prt-102-column-span

- child(type=span right=469) overflows parent(type=div contentRight=460) by 9px (w: child=157 parent=148)
- child(type=span right=890) overflows parent(type=div contentRight=788) by 102px (w: child=250 parent=148)

### prt-103-list-style-position

- child(type=div right=1920) overflows parent(type=div contentRight=1100) by 820px (w: child=1620 parent=800)
- child(type=span right=3636) overflows parent(type=div contentRight=1920) by 1716px (w: child=56 parent=800)
- child(type=div right=4370) overflows parent(type=div contentRight=1929) by 2441px (w: child=762 parent=800)
- child(type=div right=4370) overflows parent(type=div contentRight=1929) by 2441px (w: child=762 parent=800)

### prt-106-min-width-override

- [FLEX-UNBALANCED] flex items have uneven widths: first=120px max-diff=27px (gap=4px, children=2)
- child(type=div right=521) overflows parent(type=div contentRight=505) by 16px (w: child=93 parent=200)

### prt-107-max-width-constraint

- [FLEX-UNBALANCED] flex items have uneven widths: first=100px max-diff=264px (gap=4px, children=2)

### prt-110-integ-complex-toolbar

- child(type=div right=1469) overflows parent(type=div contentRight=921) by 548px (w: child=1169 parent=620)

### prt-19-flex-basic

- [FLEX-UNBALANCED] flex items have uneven widths: first=119px max-diff=118px (gap=8px, children=3)

### prt-21-flex-wrap

- [FLEX-WIDTH] row flex items total width (518) exceeds container content width (380) by 138px (gap=${gap}px, children=4, wrap=wrap)
- [FLEX-WRAP-WIDTH] wrap container: items exceed row width by 138px — items may be too wide for flex:1 distribution

### prt-30-flex-grow

- [FLEX-UNBALANCED] flex items have uneven widths: first=121px max-diff=120px (gap=4px, children=3)
- [FLEX-UNBALANCED] flex items have uneven widths: first=365px max-diff=244px (gap=4px, children=2)

### prt-32-flex-gap

- [FLEX-UNBALANCED] flex items have uneven widths: first=158px max-diff=158px (gap=16px, children=2)

### prt-47-opacity

- child(type=span right=689) overflows parent(type=span contentRight=413) by 276px (w: child=21 parent=21)
- child(type=span right=1057) overflows parent(type=span contentRight=505) by 552px (w: child=21 parent=21)

### prt-51-integ-flex-grid

- [FLEX-UNBALANCED] flex items have uneven widths: first=181px max-diff=180px (gap=12px, children=2)

### prt-55-integ-dashboard

- [FLEX-UNBALANCED] flex items have uneven widths: first=388px max-diff=194px (gap=12px, children=2)

### prt-64-overflow-wrap

- child(type=span right=627) overflows parent(type=div contentRight=455) by 172px (w: child=323 parent=150)

### prt-69-table-basic

- child(type=span right=379) overflows parent(type=div contentRight=359) by 20px (w: child=62 parent=50)
- child(type=span bottom=144) overflows parent(type=div contentBottom=131) by 13px (bottom overflow)
- child(type=span bottom=144) overflows parent(type=div contentBottom=131) by 13px (bottom overflow)
- child(type=span right=379) overflows parent(type=div contentRight=359) by 20px (w: child=62 parent=50)
- child(type=span bottom=160) overflows parent(type=div contentBottom=131) by 29px (bottom overflow)
- child(type=span bottom=160) overflows parent(type=div contentBottom=131) by 29px (bottom overflow)

### prt-72-integ-complex-sidebar

- [FLEX-UNBALANCED] flex items have uneven widths: first=268px max-diff=134px (gap=12px, children=2)
- child(type=span right=900) overflows parent(type=div contentRight=892) by 8px (w: child=100 parent=108)
- [FLEX-WIDTH] row flex items total width (123) exceeds container content width (108) by 15px (gap=${gap}px, children=2, wrap=nowrap)
- child(type=span right=907) overflows parent(type=div contentRight=892) by 15px (w: child=107 parent=108)

### prt-73-integ-media-object

- child(type=span right=1056) overflows parent(type=div contentRight=866) by 190px (w: child=672 parent=482)
- child(type=span right=848) overflows parent(type=div contentRight=794) by 54px (w: child=536 parent=482)

### prt-74-integ-holy-grail

- child(type=span bottom=170) overflows parent(type=div contentBottom=164) by 6px (bottom overflow)

### prt-80-flex-align-content

- [FLEX-WIDTH] row flex items total width (338) exceeds container content width (300) by 38px (gap=${gap}px, children=4, wrap=wrap)

### prt-99-table-layout-fixed

- child(type=span right=366) overflows parent(type=div contentRight=340) by 26px (w: child=53 parent=33)
- child(type=span bottom=132) overflows parent(type=div contentBottom=121) by 11px (bottom overflow)
- child(type=span bottom=132) overflows parent(type=div contentBottom=121) by 11px (bottom overflow)
- child(type=span bottom=132) overflows parent(type=div contentBottom=121) by 11px (bottom overflow)

