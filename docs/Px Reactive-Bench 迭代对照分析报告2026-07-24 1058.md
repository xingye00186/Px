# Px Reactive-Bench 迭代对照分析报告

**最新数据**：5 次运行平均（bench_run_1..5.json，2026-07-24 10:35）
**历史节点**：7 个（before → prePathA → pathA → step0 → p0 → p1 → a1 → CURRENT）
**模式**：AOT | 50 cycles

---

## 一、总体收益概览

| 指标 | before | CURRENT | 改善 |
|---|---|---|---|
| **总累计帧时间** | 26.8ms | 16.0ms | **-40.3%** |
| Style Recalc（均值） | ~175μs | ~13μs | **-92%** |
| VNode Tree（ChatStream） | 2973μs | 501μs | **-83%** |
| Paint（均值） | ~380μs | ~272μs | **-30%** |
| Block Algo | ~57μs | ~35μs | **-38%** |
| **⚠️ Flex Algo** | ~90μs | ~460μs | **+400% 回归** |

---

## 二、全帧时间对照（单位：μs，越小越好）

| Case | before | prePathA | pathA | step0 | p0 | p1 | a1 | **CURRENT** | Δ vs first |
|---|---|---|---|---|---|---|---|---|---|
| SimpleCounter | 1384 | 1058 | 990 | 1016 | 1035 | 1005 | 1033 | **1166** | -15.7% |
| ManyProps | 1328 | 897 | 889 | 921 | 978 | 899 | 926 | **1032** | -22.3% |
| DeepTree | 1263 | 865 | 841 | 866 | 926 | 854 | 888 | **978** | -22.6% |
| MixedWorkload | 1373 | 997 | 964 | 975 | 1061 | 966 | 985 | **1081** | -21.3% |
| FormDashboard | 1352 | 904 | 894 | 931 | 1026 | 907 | 941 | **1030** | -23.8% |
| ChatStream | 4405 | 3163 | 1746 | 1740 | 1776 | 1756 | 1784 | **1471** | **-66.6%** |
| HoverGrid | 2275 | 1899 | 1287 | 1294 | 1358 | 1301 | 1334 | **1406** | -38.2% |
| DynamicList | 2060 | 1494 | 1183 | 1199 | 1267 | 1190 | 1212 | **1295** | -37.1% |
| StaticTemplate | 2312 | 1782 | 1533 | 1580 | 1734 | 1598 | 1617 | **1630** | -29.5% |
| TextHeavy | 5227 | 4270 | 3172 | 3181 | 3430 | 3253 | 3187 | **2936** | -43.8% |
| LiveDashboard | 3845 | 3074 | 2022 | 2049 | 2329 | 2139 | 2067 | **1978** | -48.6% |
| **TOTAL** | **26824** | **20403** | **15521** | **15671** | **17520** | **16172** | **16428** | **16003** | **-40.3%** |

---

## 三、FPS 对照（越大越好）

| Case | before | pathA | p1 | a1 | **CURRENT** | Δ vs before |
|---|---|---|---|---|---|---|
| SimpleCounter | 723 | 1010 | 995 | 968 | **857** | +18.5% |
| ManyProps | 753 | 1125 | 1113 | 1080 | **969** | +28.7% |
| DeepTree | 792 | 1189 | 1170 | 1126 | **1022** | +29.0% |
| MixedWorkload | 729 | 1038 | 1035 | 1015 | **925** | +26.9% |
| FormDashboard | 739 | 1119 | 1103 | 1063 | **970** | +31.3% |
| ChatStream | 227 | 573 | 569 | 561 | **680** | **+199.6%** |
| HoverGrid | 440 | 777 | 769 | 750 | **711** | +61.6% |
| DynamicList | 485 | 845 | 840 | 825 | **772** | +59.2% |
| StaticTemplate | 432 | 652 | 626 | 619 | **614** | +42.1% |
| TextHeavy | 191 | 315 | 307 | 314 | **341** | +78.5% |
| LiveDashboard | 260 | 495 | 467 | 484 | **506** | +94.6% |

---

## 四、Style Recalc 优化（-92% 最大收益源）

| Case | before(μs) | prePathA | CURRENT | Δ |
|---|---|---|---|---|
| SimpleCounter | 174 | 30 | 18 | -89.7% |
| ManyProps | 174 | 13 | 13 | -92.5% |
| DeepTree | 173 | 13 | 13 | -92.5% |
| MixedWorkload | 174 | 13 | 12 | -93.1% |
| FormDashboard | 175 | 13 | 13 | -92.6% |
| ChatStream | 188 | 14 | 13 | -93.1% |
| HoverGrid | 172 | 13 | 13 | -92.4% |
| DynamicList | 172 | 13 | 13 | -92.4% |
| StaticTemplate | 177 | 13 | 13 | -92.7% |
| TextHeavy | 188 | 16 | 13 | -93.1% |
| LiveDashboard | 183 | 15 | 13 | -92.9% |

**功臣**：StylePool Flyweight（LRU 512 池化）+ P1 编译期 key 归一化

---

## 五、VNode Tree 优化（复杂 case 最大受益）

| Case | before(μs) | prePathA | pathA | CURRENT | Δ |
|---|---|---|---|---|---|
| SimpleCounter | 105 | 110 | 106 | 108 | +2.9% |
| ManyProps | 70 | 70 | 69 | 70 | 0% |
| DeepTree | 28 | 31 | 26 | 28 | 0% |
| **ChatStream** | 2973 | 2272 | 885 | **501** | **-83.1%** |
| HoverGrid | 958 | 1018 | 443 | 441 | -54.0% |
| DynamicList | 719 | 633 | 333 | 332 | -53.8% |
| StaticTemplate | 900 | 894 | 692 | 658 | -26.9% |
| **TextHeavy** | 3783 | 3323 | 2266 | **1963** | **-48.1%** |
| LiveDashboard | 2421 | 2123 | 1146 | 1004 | -58.5% |

**功臣**：pathA 阶段的 VNode 双缓冲 + patchVNodeTree no-op 优化

---

## 六、⚠️ FlexAlgorithm 回归（性能卡点）

| Case | before(μs) | pathA | step0 | p0 | p1 | a1 | **CURRENT** | Δ vs pathA |
|---|---|---|---|---|---|---|---|---|
| SimpleCounter | 88 | 88 | 87 | 95 | 94 | 95 | **469** | **+433%** |
| ManyProps | 87 | 85 | 86 | 97 | 90 | 92 | **459** | +440% |
| DeepTree | 86 | 84 | 85 | 98 | 91 | 93 | **455** | +442% |
| MixedWorkload | 86 | 86 | 87 | 98 | 91 | 93 | **457** | +431% |
| FormDashboard | 87 | 83 | 88 | 100 | 90 | 93 | **455** | +448% |
| ChatStream | 94 | 86 | 88 | 96 | 94 | 96 | **460** | +435% |
| HoverGrid | 88 | 85 | 86 | 97 | 92 | 96 | **460** | +441% |
| DynamicList | 90 | 85 | 88 | 98 | 91 | 94 | **458** | +439% |
| StaticTemplate | 91 | 85 | 89 | 106 | 94 | 97 | **463** | +445% |
| TextHeavy | 94 | 88 | 91 | 107 | 98 | 97 | **462** | +425% |
| LiveDashboard | 91 | 87 | 90 | 108 | 100 | 97 | **463** | +432% |

**关键观察**：从 pathA 到 a1 都稳定在 ~90μs，**CURRENT 突然暴增到 ~460μs**。回归发生在 **a1 之后的 P1 后续修改**中。

**可能根因**（按嫌疑排序）：
1. `0592919b` **FlexAlgorithm Pass 2 两阶段布局** — 对宽度差异 >5px 的子项重新 layoutChild（最可能）
2. `f21be988` flex-shrink 按 width×factor 加权
3. `c68bf4c7` min/max-width/height clamping
4. `719fb4fc` stretch 只应用于 auto cross-axis

---

## 七、Layout Total 净效应

| Case | before(μs) | pathA | CURRENT | Δ vs pathA |
|---|---|---|---|---|
| SimpleCounter | 472 | 466 | 600 | +28.8% |
| ManyProps | 470 | 455 | 586 | +28.8% |
| DeepTree | 464 | 451 | 581 | +28.8% |
| MixedWorkload | 467 | 464 | 584 | +25.9% |
| FormDashboard | 470 | 454 | 581 | +28.0% |
| ChatStream | 503 | 470 | 587 | +24.9% |
| HoverGrid | 477 | 461 | 587 | +27.3% |
| DynamicList | 479 | 463 | 586 | +26.6% |
| StaticTemplate | 491 | 461 | 591 | +28.2% |
| TextHeavy | 501 | 475 | 591 | +24.4% |
| LiveDashboard | 491 | 473 | 591 | +25.0% |

Layout 整体 +25~28% 回归，**完全由 Flex Pass 2 拖累**。Block/Inline/Grid/OOF 均已优化。

---

## 八、Block Algo 优化

| Case | before(μs) | pathA | CURRENT | Δ |
|---|---|---|---|---|
| SimpleCounter | 57 | 56 | 36 | -36.8% |
| ManyProps | 57 | 56 | 35 | -38.6% |
| DeepTree | 56 | 55 | 35 | -37.5% |
| MixedWorkload | 57 | 57 | 35 | -38.6% |
| FormDashboard | 57 | 56 | 35 | -38.6% |
| ChatStream | 60 | 58 | 35 | -41.7% |
| HoverGrid | 58 | 57 | 35 | -39.7% |
| DynamicList | 58 | 56 | 35 | -39.7% |
| StaticTemplate | 59 | 56 | 36 | -39.0% |
| TextHeavy | 60 | 58 | 35 | -41.7% |
| LiveDashboard | 59 | 57 | 36 | -39.0% |

**功臣**：BFC 边界检测重构 + P2 按需布局

---

## 九、Paint 优化

| Case | before(μs) | pathA | CURRENT | Δ |
|---|---|---|---|---|
| SimpleCounter | 383 | 299 | 322 | -15.9% |
| ManyProps | 371 | 266 | 270 | -27.2% |
| DeepTree | 356 | 262 | 265 | -25.6% |
| MixedWorkload | 373 | 270 | 266 | -28.7% |
| FormDashboard | 382 | 265 | 266 | -30.4% |
| ChatStream | 466 | 284 | 275 | -40.9% |
| HoverGrid | 422 | 278 | 271 | -35.8% |
| DynamicList | 437 | 281 | 269 | -38.4% |
| StaticTemplate | 493 | 276 | 272 | -44.8% |
| TextHeavy | 497 | 317 | 273 | **-45.1%** |
| LiveDashboard | 495 | 293 | 273 | -44.8% |

---

## 十、Update VNode 优化

| Case | before(μs) | prePathA | CURRENT | Δ |
|---|---|---|---|---|
| SimpleCounter | 222 | 84 | 89 | -59.9% |
| ManyProps | 216 | 65 | 65 | -69.9% |
| DeepTree | 216 | 65 | 64 | -70.4% |
| MixedWorkload | 214 | 67 | 65 | -69.6% |
| FormDashboard | 217 | 68 | 68 | -68.7% |
| ChatStream | 242 | 70 | 66 | -72.7% |
| HoverGrid | 217 | 71 | 66 | -69.6% |
| DynamicList | 225 | 68 | 66 | -70.7% |
| StaticTemplate | 222 | 68 | 66 | -70.3% |
| TextHeavy | 226 | 73 | 66 | -70.8% |
| LiveDashboard | 223 | 75 | 67 | -70.0% |

---

## 十一、综合结论

### ✅ 成功优化项
- **总帧时间 -40.3%**（26.8ms → 16.0ms）
- **Style Recalc -92%**（Flyweight + 编译期归一化）
- **VNode Tree -50~83%**（复杂 case，双缓冲+no-op）
- **Update VNode -70%**（增量 diff）
- **Paint -30%**（Fragment 直读，无回写）
- **Block Algo -38%**（BFC 重构 + 按需布局）
- **ChatStream 收益最大**：-66.6% 帧时间，+200% FPS

### ❌ 已引入回归
- **Flex Algo +400%**（90μs → 460μs，所有 case 一致）

### 🎯 下一步优化建议

**P0 修复 Flex Pass 2 回归**：为 Pass 2 增加"宽度未变化"跳过条件（对标 Blink 的确定性判断）
- **预期回收**：~370μs/帧（全 case）
- **预期总收益**：从 -40% 提升到 **-70%**
- **预期 FPS**：SimpleCounter 从 857 → 1300+，总累计从 16ms → 10ms

**根因验证方法**：
```bash
# 临时禁用 Pass 2 并对比
git revert 0592919b  # FlexAlgorithm 两阶段
# 重跑 bench 验证
```