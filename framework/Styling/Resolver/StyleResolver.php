<?php
declare(strict_types=1);

namespace Px\Styling\Resolver;

use Px\Styling\Provider\ThemeProvider;
use Px\Styling\Theme\ThemeData;

/**
 * 解析组件最终样式。
 *
 * 合并优先级（从低到高）：
 *   1. 主题 ComponentTheme 默认样式（组件类型，如 'button'）
 *   2. 编译后 class styles（从 ThemeProvider::classStyleRegistry 查找）
 *   3. 主题 ComponentTheme class 样式（如 '.btn-primary' 在主题中定义）
 *   4. 内联 style 属性
 *   5. 显式 props（如 flex 分配的 width）
 */
class StyleResolver
{
    /**
     * 解析组件最终样式。
     *
     * @param string         $componentType 组件类型（如 'button', 'div', 'input'）
     * @param array          $inlineStyle   从 style 属性解析的键值对（已是 BGR 格式）
     * @param array          $classNames    从 class / :class 得到的类名列表（已 split）
     * @param array          $explicitProps 显式传入的布局属性（如 width, height）
     * @param ThemeData|null $theme         可选，默认使用 ThemeProvider::of()
     * @return array 合并后的样式数组，键名与 CssMappings::PROPERTY_MAP 输出键一致
     */
    public static function resolve(
        string $componentType,
        array $inlineStyle = [],
        array $classNames = [],
        array $explicitProps = [],
        ?ThemeData $theme = null
    ): array {
        $theme = $theme ?? ThemeProvider::of();

        // 1. 主题 ComponentTheme 中组件类型的默认样式
        $merged = $theme->getComponentStyle($componentType);

        // 2. 编译后 class styles — 从 ThemeProvider 的注册表中查找
        $allRegistered = ThemeProvider::getAllClassStyles();
        foreach ($classNames as $className) {
            if ($className === '') continue;
            foreach ($allRegistered as $compName => $componentStyles) {
                if (isset($componentStyles[$className])) {
                    foreach ($componentStyles[$className] as $k => $v) {
                        $merged[$k] = $v;
                    }
                }
            }
        }

        // 3. 主题 ComponentTheme 中的 class 样式（主题级覆盖）
        foreach ($classNames as $className) {
            if ($className === '') continue;
            $classStyle = $theme->componentTheme->get($className);
            if ($classStyle !== []) {
                foreach ($classStyle as $k => $v) {
                    $merged[$k] = $v;
                }
            }
        }

        // 4. 内联 style（覆盖前序）
        foreach ($inlineStyle as $k => $v) {
            $merged[$k] = $v;
        }

        // 5. 显式 props（最高优先级）
        foreach ($explicitProps as $k => $v) {
            $merged[$k] = $v;
        }

        return $merged;
    }
}
