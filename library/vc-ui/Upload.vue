<template>
  <div style="position:relative;width:300px;height:auto;min-height:80px" class="upload-wrapper">
    <!-- 触发区 -->
    <div style="position:absolute;left:0px;top:0px;width:300px;height:80px" class="upload-trigger" @click="openFileDialog">
      <span style="position:absolute;left:12px;top:20px;width:276px;height:20px;font-size:14px;color:#909399;text-align:center">Click to select files</span>
      <span style="position:absolute;left:130px;top:44px;width:40px;height:16px;font-size:12px;color:#409EFF;text-align:center">Browse</span>
    </div>
    <!-- 文件列表 -->
    <div v-if="count(fileListJson) > 0" style="position:absolute;left:0px;top:88px;width:300px;height:120px" class="file-list">
      <div v-for="f in fileList" :key="f.name" style="position:absolute;left:0px;top:0px;width:300px;height:28px" class="file-item">
        <span style="position:absolute;left:8px;top:4px;width:220px;height:20px;font-size:12px;color:#606266">{{ f.name }}</span>
        <span style="position:absolute;right:40px;top:4px;width:40px;height:20px;font-size:12px;color:#67C23A">{{ f.status }}</span>
        <span style="position:absolute;right:8px;top:4px;width:24px;height:20px;font-size:12px;color:#F56C6C" @click="removeFile(f.name)">✕</span>
      </div>
    </div>
    <!-- 提示 -->
    <div style="position:absolute;left:0px;top:208px;width:300px;height:20px" class="upload-tip">
      <span style="position:absolute;left:8px;top:2px;font-size:12px;color:#909399">{{ tipText }}</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 选中的文件路径列表 (v-model, JSON 数组) */
    public string $modelValue = '';

    /** 上传动作 (本地目标路径) */
    public string $action = '';

    /** 是否支持多选 */
    public string $multiple = '1';

    /** 接受的文件类型 */
    public string $accept = '*';

    /** 文件大小限制 (MB) */
    public string $limit = '10';

    /** 是否可拖拽上传 */
    public string $drag = '';

    /** 文件列表 */
    public array $fileListJson = [];

    /** 提示文字 */
    public string $tipText = 'Max 10MB per file';

    /**
     * 打开文件对话框
     */
    public function openFileDialog(): void
    {
        // 原生对话框由平台层调用，这里用模拟文件列表演示
        $this->fileListJson = [
            ['name' => 'document.pdf', 'status' => 'ready'],
            ['name' => 'image.png', 'status' => 'ready']
        ];
    }

    /**
     * 移除文件
     */
    public function removeFile(string $name): void
    {
        $list = $this->fileListJson;
        $newList = [];
        foreach ($list as $f) {
            if (($f['name'] ?? '') !== $name) {
                $newList[] = $f;
            }
        }
        $this->fileListJson = $newList;
    }

    /**
     * 获取文件列表
     */
    public function getFileList(): array
    {
        return $this->fileListJson;
    }
</script>

<style>
.upload-wrapper { background: transparent; }
.upload-trigger { background: #FFFFFF; border: 2px dashed #DCDFE6; }
.upload-tip { background: transparent; }
.file-list { background: #FFFFFF; border: 1px solid #E8E8E8; overflow-y: auto; }
.file-item { background: #FAFAFA; border-bottom: 1px solid #F0F0F0; }
</style>