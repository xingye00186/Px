<?php
/**
 * Component Registry for SFC Compiler v8
 * 
 * Resolves custom HTML tag names to their .vue source files.
 * Supports user components (components/ directory) and external libraries
 * configured via project.yml component-libraries.
 * 
 * Usage:
 *   $registry = new ComponentRegistry();
 *   $registry->register('my-panel', './components/MyPanel.vue', 'user');
 *   $registry->loadLibraries([...], $projectDir);
 *   $file = $registry->resolve('my-panel'); // → absolute path or null
 */

class ComponentRegistry
{
    /** @var array<string, string> tagName → absolute .vue file path */
    private array $components = [];

    /** @var array<string, string> tagName → source identifier ('user' | 'library:path') */
    private array $sources = [];

    /**
     * Register a single component with conflict detection.
     * 
     * Priority rules:
     *   - User components (source='user') always override library components
     *   - Library components: later-registered overrides earlier, emits warning
     *
     * @param string $tagName   e.g., 'vc-button'
     * @param string $filePath  Absolute or relative path to .vue file
     * @param string $source    Source identifier: 'user' or 'library:<libPath>'
     * @return string|null  Warning message if conflict detected, null otherwise
     */
    public function register(string $tagName, string $filePath, string $source): ?string
    {
        // Normalize path
        $absolutePath = realpath($filePath) ?: $filePath;

        if (!file_exists($absolutePath)) {
            return "Component '$tagName' source not found: $absolutePath";
        }

        // Check for conflicts
        if (isset($this->components[$tagName])) {
            $existingSource = $this->sources[$tagName] ?? 'unknown';

            // User component always wins — warn if overriding library
            if ($source === 'user') {
                if ($existingSource !== 'user') {
                    $this->components[$tagName] = $absolutePath;
                    $this->sources[$tagName] = $source;
                    return "User component '$tagName' overrides library component from '$existingSource'";
                }
                // User vs user — duplicate in components/ dir (same source), skip
                return null;
            }

            // Library component — only override if user hasn't registered
            if ($existingSource === 'user') {
                return "Library component '$tagName' from '$source' ignored — user component takes priority";
            }

            // Library vs library — later overrides earlier
            $this->components[$tagName] = $absolutePath;
            $this->sources[$tagName] = $source;
            return "Tag '$tagName' from '$source' overrides existing from '$existingSource'";
        }

        // No conflict — register
        $this->components[$tagName] = $absolutePath;
        $this->sources[$tagName] = $source;
        return null;
    }

    /**
     * Load component libraries from project.yml configuration.
     *
     * @param array  $librariesConfig  Array of library config entries from project.yml
     * @param string $projectDir       Project root directory for resolving relative paths
     * @return string[]  Warnings
     */
    public function loadLibraries(array $librariesConfig, string $projectDir): array
    {
        $warnings = [];

        foreach ($librariesConfig as $libConfig) {
            $libPath = $libConfig['path'] ?? '';
            if ($libPath === '') {
                $warnings[] = "Library entry missing 'path' — skipping";
                continue;
            }

            // Resolve relative path against project directory
            $absoluteLibPath = realpath($projectDir . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $libPath));

            if ($absoluteLibPath === false || !is_dir($absoluteLibPath)) {
                $warnings[] = "Library directory not found: $libPath";
                continue;
            }

            $prefix = $libConfig['prefix'] ?? '';
            $mode = $libConfig['mode'] ?? 'auto-register';
            $source = 'library:' . $libPath;

            if ($mode === 'mappings') {
                // Manual mappings mode
                $mappings = $libConfig['mappings'] ?? [];
                if (!is_array($mappings)) {
                    $warnings[] = "Library '$libPath': 'mappings' must be an array — skipping";
                    continue;
                }
                foreach ($mappings as $tagSuffix => $fileName) {
                    $tagName = $prefix !== '' ? $prefix . '-' . $tagSuffix : $tagSuffix;
                    $filePath = $absoluteLibPath . DIRECTORY_SEPARATOR . $fileName;
                    $warn = $this->register($tagName, $filePath, $source);
                    if ($warn !== null) {
                        $warnings[] = $warn;
                    }
                }
            } else {
                // Auto-register mode: recursively scan for .vue files
                $this->scanLibraryDir($absoluteLibPath, $prefix, $source, $warnings);
            }
        }

        return $warnings;
    }

    /**
     * Recursively scan a library directory for .vue files and register them.
     * Converts PascalCase filenames to kebab-case tag names with optional prefix.
     *
     * @param string   $dir       Absolute path to library directory
     * @param string   $prefix    Tag prefix (e.g., 'vc')
     * @param string   $source    Source identifier for conflict detection
     * @param string[] &$warnings Output warnings array
     */
    private function scanLibraryDir(string $dir, string $prefix, string $source, array &$warnings): void
    {
        $items = scandir($dir);
        if ($items === false) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $fullPath = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                $this->scanLibraryDir($fullPath, $prefix, $source, $warnings);
                continue;
            }

            if (strtolower(pathinfo($item, PATHINFO_EXTENSION)) !== 'vue') continue;

            $baseName = pathinfo($item, PATHINFO_FILENAME);
            // Convert PascalCase to kebab-case: Button → button, MyComponent → my-component
            $kebabName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $baseName));
            $kebabName = strtolower($kebabName);

            if ($kebabName === '') continue;

            // Apply prefix: 'vc' + 'button' → 'vc-button'
            $tagName = $prefix !== '' ? $prefix . '-' . $kebabName : $kebabName;

            $warn = $this->register($tagName, $fullPath, $source);
            if ($warn !== null) {
                $warnings[] = $warn;
            }
        }
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
