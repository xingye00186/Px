<template>
  <!-- 浮动操作按钮：固定在右下角 -->
  <div style="position:fixed;right:40px;bottom:40px;z-index:999;display:flex;flex-direction:column;align-items:center;gap:8px">
    <!-- 回到顶部 -->
    <div style="width:44px;height:44px;background:#FFFFFF;border:1px solid #E3E5E7;border-radius:50%;box-shadow:0 2px 12px rgba(0,0,0,0.08);display:flex;align-items:center;justify-content:center;cursor:pointer" @click="scrollToTop">
      <span style="font-size:18px;color:#61666D">↑</span>
    </div>
    <!-- 投稿按钮 -->
    <div style="width:52px;height:52px;background:#FB7299;border-radius:50%;box-shadow:0 4px 14px rgba(251,114,153,0.4);display:flex;align-items:center;justify-content:center;cursor:pointer" @click="openUpload">
      <span style="font-size:22px;color:#FFFFFF;font-weight:bold">✚</span>
    </div>
  </div>
</template>

<script lang="php">
class FloatingButtonComponent extends ReactiveComponent
{
    /** 回到顶部 */
    public function scrollToTop(): void
    {
        $app = \Px\Core\Application::getInstance();
        $rootNode = $app->getRenderTreeManager()->getRootRenderNode();
        if ($rootNode !== null) {
            // 查找第一个 scroll-container 子节点并滚动到顶部
            $this->scrollNodeToTop($rootNode);
        }
    }

    /** 打开投稿（预留） */
    public function openUpload(): void
    {
        fprintf(STDOUT, "[FloatingButton] 投稿功能待实现\n");
    }

    private function scrollNodeToTop($node): void
    {
        if ($node->isScrollContainer && $node->scrollTop > 0) {
            $node->scrollTop = 0;
            $node->markDirty();
            \Px\Core\Application::getInstance()->requestRender();
            return;
        }
        foreach ($node->children as $child) {
            $this->scrollNodeToTop($child);
        }
    }
}
</script>

<style>
</style>
