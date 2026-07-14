<?php
$fw = 'f:/work/Px/framework';
$base = 'a5f08cc3';

$list = [
    ['Paint/Backend/BackendCapability.php', 'Rendering/Backend/BackendCapability.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Paint/Backend/BackendInitException.php', 'Rendering/Backend/BackendInitException.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Paint/Backend/BackendRegistry.php', 'Rendering/Backend/BackendRegistry.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Paint/Backend/GdiDirect2DBackend.php', 'Rendering/Backend/GdiDirect2DBackend.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Paint/Backend/GdiLegacyBackend.php', 'Rendering/Backend/GdiLegacyBackend.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Paint/Backend/IRenderBackend.php', 'Rendering/Backend/IRenderBackend.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Paint/Backend/RenderBackendFailedException.php', 'Rendering/Backend/RenderBackendFailedException.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Paint/Backend/ResilientRenderContext.php', 'Rendering/Backend/ResilientRenderContext.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Paint/Backend/RuntimeBackendSelector.php', 'Rendering/Backend/RuntimeBackendSelector.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Paint/Backend/SkiaCpuBackend.php', 'Rendering/Backend/SkiaCpuBackend.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Paint/Backend/SkiaGaneshD3D11Backend.php', 'Rendering/Backend/SkiaGaneshD3D11Backend.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Paint/Backend/SkiaGaneshWGLBackend.php', 'Rendering/Backend/SkiaGaneshWGLBackend.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Paint/Backend/SkiaGraphiteDawnBackend.php', 'Rendering/Backend/SkiaGraphiteDawnBackend.php', 'Px\Rendering\Backend', 'Px\Paint\Backend'],
    ['Component/Contracts/ComponentInterface.php', 'Interfaces/ComponentInterface.php', 'Px\Interfaces', 'Px\Component\Contracts'],
    ['Component/Contracts/ReactiveComponentInterface.php', 'Interfaces/ReactiveComponentInterface.php', 'Px\Interfaces', 'Px\Component\Contracts'],
];

foreach ($list as $item) {
    $dst = $item[0];
    $src = $item[1];
    $oldNs = $item[2];
    $newNs = $item[3];
    
    $gitPath = 'framework/' . str_replace('\\', '/', $src);
    $cmd = 'git -C f:/work/Px show ' . $base . ':' . $gitPath;
    $content = shell_exec($cmd);
    
    if (empty($content) || strlen($content) < 10) {
        echo "SKIP: $src (not in $base)\n";
        continue;
    }
    
    $content = str_replace("namespace $oldNs;", "namespace $newNs;", $content);
    $content = str_replace("use $oldNs\\", "use $newNs\\", $content);
    file_put_contents("$fw/$dst", $content);
    
    $check = exec("php -l \"$fw/$dst\" 2>&1", $out, $code);
    if ($code === 0) {
        echo "OK: $dst\n";
    } else {
        echo "FAIL: $dst - $check\n";
    }
}
echo "All done\n";
