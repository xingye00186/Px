<?php

namespace PxTest\Snapshot;

/**
 * 快照域枚举 — 定义快照的存储分类。
 *
 * 用法:
 *   SnapshotDomain::LAYOUT   — 布局快照 (engine_layout.json)
 *   SnapshotDomain::RENDER_TREE — RenderNode 树快照
 *   SnapshotDomain::EVENT_SEQUENCE — 事件序列快照
 */
enum SnapshotDomain: string
{
    case LAYOUT = 'layout_snapshots';
    case RENDER_TREE = 'render_tree_snapshots';
    case EVENT_SEQUENCE = 'event_sequence_snapshots';

    /** 获取此域的默认存储子目录 */
    public function dir(): string
    {
        return match ($this) {
            self::LAYOUT => 'layout_snapshots',
            self::RENDER_TREE => 'render_tree_snapshots',
            self::EVENT_SEQUENCE => 'event_sequence_snapshots',
        };
    }
}
