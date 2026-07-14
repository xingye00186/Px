<?php
$fw = 'f:/work/Px/framework';
$dirs = ['Paint/Backend', 'Component/Contracts'];
$oldRefs = ['Px\\Rendering\\', 'Px\\Styling\\', 'Px\\Interfaces\\'];
$newRefs = ['Px\\Paint\\', 'Px\\Theme\\', 'Px\\Component\\Contracts\\']; // partial, need exact mapping

$files = [];
foreach ($dirs as $d) {
    $files = array_merge($files, glob("$fw/$d/*.php"));
}

foreach ($files as $f) {
    $c = file_get_contents($f);
    $orig = $c;
    $rel = substr($f, strlen($fw) + 1);
    
    // Fix use statements with old namespaces
    $c = str_replace('use Px\\Rendering\\VNode;', 'use Px\\Dom\\VNode;', $c);
    $c = str_replace('use Px\\Rendering\\RenderNode;', 'use Px\\Render\\RenderNode;', $c);
    $c = str_replace('use Px\\Rendering\\RenderTreeManager;', 'use Px\\Render\\RenderTreeManager;', $c);
    $c = str_replace('use Px\\Rendering\\RenderContext;', 'use Px\\Paint\\RenderContext;', $c);
    $c = str_replace('use Px\\Rendering\\SkiaRenderContext;', 'use Px\\Paint\\SkiaRenderContext;', $c);
    $c = str_replace('use Px\\Rendering\\GdiRenderContext;', 'use Px\\Paint\\GdiRenderContext;', $c);
    $c = str_replace('use Px\\Rendering\\VNodeRenderer;', 'use Px\\Paint\\VNodeRenderer;', $c);
    $c = str_replace('use Px\\Rendering\\ImageManager;', 'use Px\\Paint\\ImageManager;', $c);
    $c = str_replace('use Px\\Rendering\\ComputedStyle;', 'use Px\\Css\\ComputedStyle;', $c);
    $c = str_replace('use Px\\Rendering\\StyleResolver;', 'use Px\\Css\\StyleResolver;', $c);
    $c = str_replace('use Px\\Rendering\\StyleRecalcPass;', 'use Px\\Css\\StyleRecalcPass;', $c);
    $c = str_replace('use Px\\Rendering\\CssMappings;', 'use Px\\Css\\CssMappings;', $c);
    $c = str_replace('use Px\\Rendering\\CssValueParser;', 'use Px\\Css\\CssValueParser;', $c);
    $c = str_replace('use Px\\Rendering\\CssValue;', 'use Px\\Css\\CssValue;', $c);
    $c = str_replace('use Px\\Rendering\\CssLength;', 'use Px\\Css\\CssLength;', $c);
    $c = str_replace('use Px\\Rendering\\CssKeyword;', 'use Px\\Css\\CssKeyword;', $c);
    $c = str_replace('use Px\\Rendering\\CssColor;', 'use Px\\Css\\CssColor;', $c);
    $c = str_replace('use Px\\Rendering\\CssFlex;', 'use Px\\Css\\CssFlex;', $c);
    $c = str_replace('use Px\\Rendering\\CssRect;', 'use Px\\Css\\CssRect;', $c);
    $c = str_replace('use Px\\Rendering\\Diag;', 'use Px\\Core\\Diag;', $c);
    $c = str_replace('use Px\\Rendering\\InteractionState;', 'use Px\\Paint\\InteractionState;', $c);
    $c = str_replace('use Px\\Rendering\\ScrollState;', 'use Px\\Render\\ScrollState;', $c);
    $c = str_replace('use Px\\Rendering\\TextOverflowProcessor;', 'use Px\\Layout\\TextOverflowProcessor;', $c);
    // Layout classes
    $c = str_replace('use Px\\Rendering\\Layout\\', 'use Px\\Layout\\', $c);
    // Backend classes
    $c = str_replace('use Px\\Rendering\\Backend\\', 'use Px\\Paint\\Backend\\', $c);
    // TextBackend classes
    $c = str_replace('use Px\\Rendering\\TextBackend\\', 'use Px\\Text\\', $c);
    // Styling classes
    $c = str_replace('use Px\\Styling\\Provider\\ThemeProvider;', 'use Px\\Theme\\ThemeProvider;', $c);
    $c = str_replace('use Px\\Styling\\Theme\\', 'use Px\\Theme\\', $c);
    $c = str_replace('use Px\\Styling\\Adapter\\', 'use Px\\Theme\\', $c);
    // BaseComponent/ReactiveComponent
    $c = str_replace('use Px\\BaseComponent;', 'use Px\\Component\\BaseComponent;', $c);
    $c = str_replace('use Px\\ReactiveComponent;', 'use Px\\Component\\ReactiveComponent;', $c);
    $c = str_replace('use Px\\Interfaces\\', 'use Px\\Component\\Contracts\\', $c);
    
    // Also fix method return types that use old class names
    $c = str_replace(': Px\\Rendering\\VNode', ': Px\\Dom\\VNode', $c);
    $c = str_replace(': ?Px\\Rendering\\RenderNode', ': ?Px\\Render\\RenderNode', $c);
    $c = str_replace('?Px\\Rendering\\RenderNode', '?Px\\Render\\RenderNode', $c);
    
    if ($c !== $orig) {
        file_put_contents($f, $c);
        echo "FIXED: $rel\n";
    }
}
echo "done\n";
