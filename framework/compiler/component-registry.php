<?php
/**
 * Component Registry for VueCalc SFC Compiler (v5 M2)
 * 
 * Resolves custom HTML tag names to their .vue source files.
 * Loaded from the app's project.yml components section.
 * 
 * Usage:
 *   $registry = new ComponentRegistry();
 *   $registry->load(['my-panel' => './components/MyPanel.vue'], $baseDir);
 *   $file = $registry->resolve('my-panel'); // → absolute path or null
 */

class ComponentRegistry
{
    /** @var array<string, string> tagName → absolute .vue file path */
    private array $components = [];

    /**
     * Load component mappings from a config array.
     * 
     * @param array  $config   e.g. ['my-panel' => './components/MyPanel.vue']
     * @param string $baseDir  Base directory for resolving relative paths
     * @return string[]  Warnings for missing files
     */
    public function load(array $config, string $baseDir): array
    {
        $warnings = [];
        foreach ($config as $tagName => $relativePath) {
            $absolutePath = $baseDir . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            // Normalize path (resolve . and .. segments)
            $absolutePath = realpath($absolutePath) ?: $absolutePath;
            if (file_exists($absolutePath)) {
                $this->components[$tagName] = $absolutePath;
            } else {
                $warnings[] = "Component '$tagName' source not found: $absolutePath";
            }
        }
        return $warnings;
    }

    /**
     * Resolve a tag name to its .vue file path.
     * 
     * @param string $tagName  e.g., 'my-panel'
     * @return string|null  Absolute path to .vue file, or null if not registered
     */
    public function resolve(string $tagName): ?string
    {
        return $this->components[$tagName] ?? null;
    }

    /**
     * Check if a tag name is a registered component.
     */
    public function isComponent(string $tagName): bool
    {
        return isset($this->components[$tagName]);
    }

    /**
     * @return array<string, string>  All registered components (tagName → path)
     */
    public function all(): array
    {
        return $this->components;
    }
}
