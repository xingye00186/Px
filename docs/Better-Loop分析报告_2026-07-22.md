# Px 框架 — Agent 工作循环分析报告

> 审查日期：2026-07-22 | 模式：session-limited（无可用会话数据）| 平台：Qoder

## 概述

Px 框架拥有丰富的架构文档和分层 CI 设计，但 agent 工作循环在**环境可复现性**、**快速静态反馈**、**机械化质量门禁**和**变更安全**方面存在结构性缺口。本次审查因会话数据源未启用，行为层证据均为 Unobserved，结论基于项目静态证据。

---

## 维度评分

| 维度 | 得分 | 摘要 |
|------|------|------|
| 任务理解 | 65/100 | AGENTS.md 内容全面但 2009 行单体文件缺乏渐进式披露，agent 无法按任务域快速路由 |
| 可控执行 | 48/100 | build.bat 有错误码语义，但专有工具链和机器特定路径使 agent 无法自主搭建环境 |
| 改动验证 | 62/100 | 192 个测试文件 + 四层 CI，但缺乏 lint/typecheck 快速信号层 |
| 可靠交付 | 45/100 | CI 在 push/PR 上运行，但无 required status check、分支保护或合并审批证据 |
| 经验沉淀 | 38/100 | css-test-workflow Skill 存在但无会话证据证明其被激活或产生效果 |

---

## 已配置 Agent 资产

| 类型 | 范围 | 路径 |
|------|------|------|
| Rules | Project | `AGENTS.md` |
| Skills | Project | `.qoder/skills/css-test-workflow/SKILL.md` |
| Workflows | Project | `.github/workflows/css-test.yml` |

---

## 发现（按修复优先级排序）

### 1. [High] 专有工具链和机器特定路径使环境不可复现

**影响**：agent 在新环境中无法完成 build.bat 的任何步骤，也无法独立诊断环境缺失。

**原因**：config.yml 硬编码专有 AOT 编译器路径和机器特定的 vcvarsall 路径，无 devcontainer、Docker、Nix 或任何容器化方案。

**修复方向**：添加 config.example.yml 模板和环境预检脚本（doctor），将机器特定路径改为环境变量或相对路径约定。

**验证**：doctor 脚本可在无专有工具链时给出明确缺失项列表。

---

### 2. [Medium] AGENTS.md 单体文件超出有效 agent 导航阈值

**影响**：agent 处理简单任务时仍需解析无关内容，增加上下文噪声和定位延迟。

**原因**：2009 行的单一文件包含从架构概述到具体 bug 修复经验的所有内容，缺乏「按需加载」结构和条件触发路由。

**修复方向**：拆分为路由入口（~120行）+ 按域链接的深度文档（架构、AOT、布局、渲染、测试）。

**验证**：入口文件 ≤ 150 行，每个深度文档有明确的「何时加载」条件。

---

### 3. [Medium] 缺乏 lint/typecheck 快速反馈信号层

**影响**：agent 修改核心文件后无 30 秒内的类型/接口违规检测，最快反馈仅为 `php -l`（语法层）。

**原因**：PHP 框架代码无 PHPStan、Psalm 或任何静态分析集成。

**修复方向**：为 framework/ 引入 PHPStan（level 1-3 起步），优先覆盖 Layout/ 和 Css/ 高频修改目录。

**验证**：`phpstan analyze framework/Layout/` 在 30 秒内完成并输出可定位的违规信息。

---

### 4. [Medium] 22 条修改检查清单无机械化强制

**影响**：agent 或人可跳过所有检查直接提交，无机制拦截违规变更。

**原因**：AGENTS.md 第十三节的详细修改规则无对应的 pre-commit hook、CI 步骤或自动扫描。

**修复方向**：将可自动化的规则（clip 栈平衡、auto-height 排除规则等）转化为 CI 步骤或 pre-commit 检查脚本。

**验证**：违规代码触发检查失败并输出修复方向，检查在 CI fast 层（5分钟内）完成。

---

### 5. [Medium] agent 变更路径无生命周期护栏

**影响**：agent 可在无验证、无审批的情况下将变更推送至主分支，高风险操作无额外确认。

**原因**：无 .qoder/hooks、pre-commit/pre-push 配置或任何编辑→提交→推送路径的拦截点。

**修复方向**：配置最小护栏 — pre-commit 运行 `php -l` + aot-checker，framework/ 修改触发额外提示。

**验证**：语法错误被拦截，framework/ 修改触发风险提示，合规变更不被阻断。

---

### 6. [Low] 交付验收缺乏强制合并门禁

**影响**：变更可在 CI 未通过或未完成时被合并至主分支。

**原因**：无证据表明 CI status check 为 required、存在 branch protection ruleset 或 PR 审批规则。

**修复方向**：为默认分支配置 branch protection，要求 PR 合并前至少通过 fast 层 CI。

**验证**：直接推送被拒绝，PR 在 CI 未通过时无法合并。

---

## 建议

| 建议 | 类型 | 下一步 |
|------|------|--------|
| 在下次 CSS 布局修复中激活已配置的 css-test-workflow Skill | try-existing | 通过 `/css-test-workflow` 触发，验证决策路径覆盖度 |
| 为 framework/ 引入 PHPStan 快速静态分析层 | horizon | 先确认标准 PHP CLI 可运行框架代码，再评估兼容性 |

---

## 证据边界与限制

- **会话数据不可用**：0 个合格会话被分析（数据源根路径未启用），所有行为层声明为 Unobserved
- **CI 配置未验证**：无法确认 status check 是否为 required（需 GitHub 仓库设置访问）
- **测试未执行**：未运行测试套件，无法确认当前通过状态
- **Git 分支保护未检查**：需 GitHub host 访问

---

## 优势确认

- AGENTS.md 是高质量全流程导航文档（架构、数据流、类速查、AOT 约束、检查清单）
- build.bat 定义 exit 1-5 对应不同失败阶段，agent 可精确定位失败层
- CI 四层分离（fast/full/stress/regression），agent 可选择合适层级验证
- css-test-workflow Skill 提供结构化决策树（根因→修复→验证→归档）
- 测试运行器支持 `--group=fast` 分组和 TAP/JSON 格式输出

---

*报告产出物：`.qoder/better-loop/2026-07-22/235908-px/`（findings.json + canvas.json + report.canvas.tsx）*
