<?php

/**
 * Px Design System Constants
 *
 * Material Design 3 inspired design tokens
 */
class DesignTokens
{
    // ── 颜色系统 ──────────────────────────────────────────

    // 主色系 (Indigo)
    public const COLOR_PRIMARY = '#6366F1';
    public const COLOR_PRIMARY_LIGHT = '#818CF8';
    public const COLOR_PRIMARY_DARK = '#4F46E5';

    // 强调色 (Pink)
    public const COLOR_ACCENT = '#EC4899';

    // 语义色
    public const COLOR_SUCCESS = '#10B981';
    public const COLOR_WARNING = '#F59E0B';
    public const COLOR_ERROR = '#EF4444';
    public const COLOR_INFO = '#3B82F6';

    // 中性色阶
    public const COLOR_NEUTRAL_50 = '#F9FAFB';
    public const COLOR_NEUTRAL_100 = '#F3F4F6';
    public const COLOR_NEUTRAL_200 = '#E5E7EB';
    public const COLOR_NEUTRAL_300 = '#D1D5DB';
    public const COLOR_NEUTRAL_400 = '#9CA3AF';
    public const COLOR_NEUTRAL_500 = '#6B7280';
    public const COLOR_NEUTRAL_600 = '#4B5563';
    public const COLOR_NEUTRAL_700 = '#374151';
    public const COLOR_NEUTRAL_800 = '#1F2937';
    public const COLOR_NEUTRAL_900 = '#111827';

    // 深色主题
    public const COLOR_DARK_BG = '#1C1C1E';
    public const COLOR_DARK_SURFACE = '#282840';
    public const COLOR_DARK_CARD = '#333336';
    public const COLOR_DARK_TEXT = '#FFFFFF';
    public const COLOR_DARK_TEXT_SECONDARY = '#8E8E93';

    // ── 排版系统 ──────────────────────────────────────────

    public const FONT_SIZE_DISPLAY = 48;
    public const FONT_SIZE_HEADLINE = 32;
    public const FONT_SIZE_TITLE = 20;
    public const FONT_SIZE_BODY = 14;
    public const FONT_SIZE_LABEL = 12;
    public const FONT_SIZE_CAPTION = 11;

    public const FONT_WEIGHT_BOLD = 700;
    public const FONT_WEIGHT_SEMIBOLD = 600;
    public const FONT_WEIGHT_MEDIUM = 500;
    public const FONT_WEIGHT_REGULAR = 400;

    // ── 间距系统 ─────────────────────────────────────────

    public const SPACE_XS = 4;
    public const SPACE_SM = 8;
    public const SPACE_MD = 16;
    public const SPACE_LG = 24;
    public const SPACE_XL = 32;
    public const SPACE_2XL = 48;

    // ── 圆角系统 ─────────────────────────────────────────

    public const RADIUS_SM = 4;
    public const RADIUS_MD = 8;
    public const RADIUS_LG = 12;
    public const RADIUS_XL = 16;
    public const RADIUS_FULL = 9999;

    // ── 阴影系统 ─────────────────────────────────────────

    public const SHADOW_SM = '0 1px 2px rgba(0,0,0,0.05)';
    public const SHADOW_MD = '0 2px 8px rgba(0,0,0,0.1)';
    public const SHADOW_LG = '0 4px 16px rgba(0,0,0,0.15)';
    public const SHADOW_XL = '0 8px 24px rgba(0,0,0,0.2)';

    // ── 动画 ─────────────────────────────────────────────

    public const TRANSITION_FAST = '150ms';
    public const TRANSITION_NORMAL = '300ms';
    public const TRANSITION_SLOW = '500ms';

    public const EASING_STANDARD = 'ease';
    public const EASING_DECELERATE = 'ease-out';
    public const EASING_ACCELERATE = 'ease-in';
}
