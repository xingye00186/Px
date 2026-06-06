#!/usr/bin/env python3
"""
encoding 修复工具 — 上下文重写与三路合并

功能:
  1. analyze   — 分析文件中的 U+FFFD 行，输出含上下文的修复模板
  2. rewrite   — 根据提供的修复映射，将写入的新注释应用到文件
  3. merge     — 从 git 干净版本合并中文注释（ASCII word-overlap 匹配）

用法:
  python tools/encoding/repair_context.py analyze <file>
  python tools/encoding/repair_context.py rewrite <file> <fix_spec.py>
  python tools/encoding/repair_context.py merge <file> <clean_ref>
"""

import sys
import os
import re
import json


# ═══════════════════════════════════════════════════
# 1. analyze — U+FFFD 上下文分析
# ═══════════════════════════════════════════════════

def analyze_file(filepath):
    """分析文件中所有 U+FFFD 行，输出含上下文的修复模板"""
    if not os.path.exists(filepath):
        print(f"错误: 文件不存在: {filepath}")
        sys.exit(1)

    with open(filepath, 'r', encoding='utf-8', errors='replace') as f:
        lines = f.readlines()

    ffd_lines = []
    for i, line in enumerate(lines):
        if '\ufffd' in line:
            start = max(0, i - 5)
            end = min(len(lines), i + 6)
            ctx = []
            for j in range(start, end):
                marker = '>>>' if j == i else '   '
                text = lines[j].rstrip('\n\r')
                if len(text) > 120:
                    text = text[:120] + '...'
                ctx.append(f"{marker} L{j+1:>4}: {text}")
            ffd_lines.append({
                'line': i + 1,
                'content': line,
                'context': ctx,
                'ffd_count': line.count('\ufffd'),
            })

    if not ffd_lines:
        print(f"✅ 文件没有 U+FFFD: {filepath}")
        return []

    total_ffd = sum(l['ffd_count'] for l in ffd_lines)

    print(f"═══ U+FFFD 分析: {filepath} ═══")
    print(f"总行数: {len(lines)}, U+FFFD 位置: {len(ffd_lines)} 行, 共 {total_ffd} 个字符\n")

    for idx, item in enumerate(ffd_lines):
        n = idx + 1
        print(f"── [{n}/{len(ffd_lines)}] L{item['line']} (x{item['ffd_count']}) ──")
        for ctx_line in item['context']:
            print(f"  {ctx_line}")
        print()

    # 生成修复脚本模板
    tmpl_file = os.path.splitext(filepath)[0] + '_fix.py'
    print(f"═══ 修复指引 ═══")
    print(f"已生成修复模板: {tmpl_file}")
    print("编辑模板中的中文注释，然后运行:")
    print(f"  python {os.path.relpath(__file__).replace(os.sep, '/')} rewrite {filepath} {os.path.basename(tmpl_file)}")

    # 写入修复模板（可编辑）
    with open(tmpl_file, 'w', encoding='utf-8') as f:
        f.write(f"""#!/usr/bin/env python3
# 修复脚本 — 在下方填写正确的中文注释，然后执行此文件即可应用修复
# 自动由 repair_context.py analyze 生成

FIXES = {{
""")
        for item in ffd_lines:
            # 猜测注释类型
            stripped = item['content'].strip()
            if stripped.startswith('*'):
                hint = 'docblock 注释'
            elif stripped.startswith('//'):
                hint = '行内注释'
            elif stripped.startswith('/**'):
                hint = 'docblock 开始'
            elif stripped.startswith('*/'):
                hint = 'docblock 结束'
            else:
                hint = '未知类型'

            f.write(f"    # L{item['line']} — {hint}\n")
            f.write(f"    # 自动猜测（仅供参考）:\n")

            # 尝试从上下文推断：提取前面的 ASCII 注释内容作为 hint
            prev_text = ''
            for ctx_line in item['context']:
                if ctx_line.startswith('   ') and ':' in ctx_line:
                    parts = ctx_line.split(':', 1)
                    if len(parts) > 1:
                        prev_text = parts[1].strip()

            # 如果是 section header 类型的 ──，按常规推断
            guessed = guess_comment(item['content'], item['context'])
            f.write(f"    # 建议: {guessed}\n")
            f.write(f"    # TODO: 将下面字符串中的 '需要修复' 改为正确文字\n")
            f.write(f"    {item['line']}: '''{guessed}''',\n\n")

        f.write("""}

if __name__ == '__main__':
    import sys
    filepath = sys.argv[1] if len(sys.argv) > 1 else __file__.replace('_fix.py', '.php')
    with open(filepath, 'r', encoding='utf-8') as f:
        lines = f.readlines()

    for line_no, new_text in FIXES.items():
        idx = line_no - 1
        if idx < len(lines):
            indent = lines[idx][:len(lines[idx]) - len(lines[idx].lstrip())]
            lines[idx] = indent + new_text + '\\n'

    with open(filepath, 'w', encoding='utf-8') as f:
        f.writelines(lines)

    print(f'✅ Applied {len(FIXES)} fixes to {filepath}')
""")

    return ffd_lines


def guess_comment(line, context_lines):
    """根据上下文猜测注释内容"""
    stripped = line.strip()

    # Section header with ── 标记
    if '─' in stripped or '�' in stripped:
        # 找附近的关键词
        keywords = []
        for ctx in context_lines:
            if '>>>' not in ctx:  # 只有上下文行
                lower = ctx.lower()
                # 提取可能的 section 名称
                for kw in ['sticky', 'scroll', 'flex', 'grid', 'dirty', 'layout',
                           'child', 'render', 'style', 'position', 'width', 'height',
                           'margin', 'padding', '计算', '处理', '路径', '布局', '容器',
                           '节点', '标记', '检查', '拷贝', '克隆', '深度']:
                    if kw in lower:
                        keywords.append(kw)

        if 'sticky' in str(keywords):
            return '─ position:sticky 处理 ──'
        elif 'scroll' in str(keywords):
            return '─ 快速滚动路径 ──'
        elif 'dirty' in str(keywords) or '脏' in str(keywords):
            return '─ 脏路径：完整布局计算 ──'
        elif 'child' in str(keywords) or '子节点' in str(keywords):
            return '─ 子节点脏标记处理 ──'
        else:
            return '─ section header (需重写) ──'

    # Docblock list item
    if stripped.startswith('*') and not stripped.startswith('/'):
        return '* 与旧版 VNode 版的关键区别：'

    # Deep copy
    if '拷贝' in stripped or 'copy' in stripped.lower() or 'clone' in stripped.lower():
        return '// 深度拷贝：避免修改原始 $node->style'

    # Available width
    if '宽度' in ''.join(context_lines).lower() or 'width' in ''.join(context_lines).lower():
        return '// 计算可用宽度：容器宽度减去列间距'

    # Grid cell
    if 'cell' in ''.join(context_lines).lower() or '网格' in ''.join(context_lines).lower():
        return '// 计算网格单元位置'

    # Percentage
    if '百分比' in line or 'percent' in line.lower():
        return ' * 解析百分比尺寸并计算'

    return '需要修复 — 根据上方上下文填写正确中文'


# ═══════════════════════════════════════════════════
# 2. rewrite — 应用修复
# ═══════════════════════════════════════════════════

def apply_rewrite(filepath, fix_script):
    """应用 fix_spec 中的修复映射到文件"""
    if not os.path.exists(fix_script):
        print(f"错误: 修复脚本不存在: {fix_script}")
        sys.exit(1)

    # 执行 fix script — 它会直接修改文件
    ret = os.system(f'"{sys.executable}" "{fix_script}" "{filepath}"')
    if ret == 0:
        print(f"✅ 修复已应用到: {filepath}")
    else:
        print(f"❌ 修复脚本执行失败 (exit={ret})")


# ═══════════════════════════════════════════════════
# 3. merge — 从 git 干净版本合并中文注释
# ═══════════════════════════════════════════════════

def word_overlap(a_words, b_words):
    """计算两个词集合的重叠度"""
    if not a_words or not b_words:
        return 0
    intersection = a_words & b_words
    return len(intersection)


def strip_ascii(s):
    """只保留 ASCII 可打印字符，用于结构匹配"""
    result = ''.join(c for c in s if 32 <= ord(c) <= 126)
    result = re.sub(r'\s+', ' ', result)
    return result.strip()


def three_way_merge(filepath, clean_ref='HEAD'):
    """
    从 git 的干净版本合并中文注释。
    使用 ASCII-only word-overlap 匹配策略。
    """
    if not os.path.exists(filepath):
        print(f"错误: 文件不存在: {filepath}")
        sys.exit(1)

    # 读取当前文件
    with open(filepath, 'r', encoding='utf-8', errors='replace') as f:
        curr_lines = f.readlines()

    # 从 git 获取干净版本
    import subprocess
    result = subprocess.run(
        ['git', 'show', f'{clean_ref}:{os.path.relpath(filepath, os.getcwd()).replace(os.sep, "/")}'],
        capture_output=True, text=True, encoding='utf-8', errors='replace'
    )
    if result.returncode != 0:
        print(f"错误: 无法从 git 获取 {clean_ref} 版本")
        print(f"  {result.stderr.strip()}")
        sys.exit(1)

    clean_lines = result.stdout.splitlines(keepends=True)

    print(f"═══ 三路合并: {filepath} ═══")
    print(f"参考版本: {clean_ref}")
    print(f"当前文件: {len(curr_lines)} 行")
    print(f"参考文件: {len(clean_lines)} 行\n")

    # 对每一行计算可打印 ASCII key
    curr_keys = []
    for i, line in enumerate(curr_lines):
        is_comment = line.strip().startswith('//') or line.strip().startswith('*')
        is_chinese = bool(re.search(r'[\u4e00-\u9fff\u3400-\u4dbf]', line))
        stripped = strip_ascii(line)
        words = set(stripped.split())
        curr_keys.append({
            'index': i,
            'is_comment': is_comment,
            'is_chinese': is_chinese,
            'stripped': stripped,
            'words': words,
            'fixed': is_chinese and '\ufffd' in line,
        })

    clean_keys = []
    for i, line in enumerate(clean_lines):
        stripped = strip_ascii(line)
        words = set(stripped.split())
        clean_keys.append({
            'index': i,
            'stripped': stripped,
            'words': words,
        })

    # 匹配需要修复的行
    fixed_count = 0
    for ck in curr_keys:
        if not ck['fixed']:
            continue

        # 找重叠度最高的干净行
        best_score = 0
        best_match = None
        for cl in clean_keys:
            if not strip_ascii(cl['stripped']):
                continue
            score = word_overlap(ck['words'], cl['words'])
            if score > best_score:
                best_score = score
                best_match = cl

        if best_match and best_score >= 2:
            # 用干净版本替换整行内容，但保留行尾换行符
            curr_lines[ck['index']] = clean_lines[best_match['index']]
            fixed_count += 1
            old_text = ck['stripped'][:60]
            new_text = best_match['stripped'][:60]
            print(f"  L{ck['index']+1}: [{old_text}]")
            print(f"      → [{new_text}] (score={best_score})")

    # 写回文件
    with open(filepath, 'w', encoding='utf-8') as f:
        f.writelines(curr_lines)

    # 统计剩余 U+FFFD
    remaining = 0
    for line in curr_lines:
        remaining += line.count('\ufffd')

    print(f"\n修复了 {fixed_count} 行注释")
    print(f"剩余 U+FFFD: {remaining}")
    if remaining > 0:
        print(f"→ 运行 analyze 子命令查看残留行上下文并手动重写")
    else:
        print(f"✅ 全部修复！")


# ═══════════════════════════════════════════════════
# CLI 入口
# ═══════════════════════════════════════════════════

def main():
    if len(sys.argv) < 3:
        print(__doc__)
        sys.exit(1)

    command = sys.argv[1]
    filepath = sys.argv[2]

    if command == 'analyze':
        analyze_file(filepath)
    elif command == 'rewrite':
        if len(sys.argv) < 4:
            print("用法: repair_context.py rewrite <file> <fix_script.py>")
            sys.exit(1)
        apply_rewrite(filepath, sys.argv[3])
    elif command == 'merge':
        clean_ref = sys.argv[3] if len(sys.argv) > 3 else 'HEAD'
        three_way_merge(filepath, clean_ref)
    else:
        print(f"未知命令: {command}")
        print(__doc__)
        sys.exit(1)


if __name__ == '__main__':
    main()
