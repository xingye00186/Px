<template>
  <div style="width:1200px;height:800px;background:#F5F7FA">
    <!-- 左侧导航 -->
    <div style="left:0px;top:0px;width:220px;height:800px;background:#304156;position:absolute">
      <div style="left:0px;top:0px;width:220px;height:60px;background:#263444;align-items:center;justify-content:center">
        <span style="left:16px;top:20px;font-size:18px;font-weight:bold;color:#FFFFFF">Px 组件展示</span>
      </div>
      <div style="left:0px;top:60px;width:220px;height:740px;overflow-y:auto">
        <div v-for="item in basicItems" :key="item.name" @click="selectComponent(item.name)" style="left:0px;width:220px;height:48px;display:flex;align-items:center;cursor:pointer">
          <span style="left:16px;font-size:14px;color:#BFC7D5">{{ item.label }}</span>
        </div>
        <div v-for="item in formItems" :key="item.name" @click="selectComponent(item.name)" style="left:0px;width:220px;height:48px;display:flex;align-items:center;cursor:pointer">
          <span style="left:16px;font-size:14px;color:#BFC7D5">{{ item.label }}</span>
        </div>
        <div v-for="item in displayItems" :key="item.name" @click="selectComponent(item.name)" style="left:0px;width:220px;height:48px;display:flex;align-items:center;cursor:pointer">
          <span style="left:16px;font-size:14px;color:#BFC7D5">{{ item.label }}</span>
        </div>
      </div>
    </div>

    <!-- 右侧内容区 -->
    <div style="left:220px;top:0px;width:980px;height:800px;background:#FFFFFF;position:absolute">
      <div style="left:0px;top:0px;width:980px;height:60px;background:#FFFFFF;border-bottom:1px solid #E4E7ED;align-items:center">
        <span style="left:24px;top:18px;font-size:20px;font-weight:bold;color:#303133">{{ currentLabel }}</span>
      </div>
      <div style="left:24px;top:80px;width:932px;height:700px;overflow-y:auto">
        <!-- Button 演示 -->
        <div v-if="selected === 'button'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Button 按钮用于触发操作。基础用法：</div>
          <div style="left:0px;top:32px;width:400px;height:80px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-button text="Primary" style="left:0px;top:0px;margin-right:12px"></vc-button>
            <vc-button text="Success" style="left:80px;top:0px;margin-right:12px"></vc-button>
            <vc-button text="Warning" style="left:160px;top:0px;margin-right:12px"></vc-button>
            <vc-button text="Danger" style="left:240px;top:0px"></vc-button>
          </div>
        </div>

        <!-- Input 演示 -->
        <div v-if="selected === 'input'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Input 输入框用于收集用户文本输入。基础用法：</div>
          <div style="left:0px;top:32px;width:400px;height:60px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-input placeholder="请输入内容" :model-value="inputValue" style="left:0px;top:0px;width:300px;height:40px"></vc-input>
          </div>
        </div>

        <!-- Switch 演示 -->
        <div v-if="selected === 'switch'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Switch 开关用于切换布尔值状态。基础用法：</div>
          <div style="left:0px;top:32px;width:200px;height:40px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-switch :model-value="switchValue" active-text="开" inactive-text="关" style="left:0px;top:0px"></vc-switch>
          </div>
        </div>

        <!-- Slider 演示 -->
        <div v-if="selected === 'slider'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Slider 滑块用于选择数值范围。基础用法：</div>
          <div style="left:0px;top:32px;width:400px;height:60px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-slider :model-value="sliderValue" style="left:0px;top:0px;width:300px;height:40px"></vc-slider>
          </div>
        </div>

        <!-- Tag 演示 -->
        <div v-if="selected === 'tag'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Tag 标签用于标记和分类。基础用法：</div>
          <div style="left:0px;top:32px;width:500px;height:80px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-tag text="标签一" style="left:0px;top:0px;margin-right:12px"></vc-tag>
            <vc-tag text="标签二" type="success" style="left:80px;top:0px;margin-right:12px"></vc-tag>
            <vc-tag text="标签三" type="warning" style="left:160px;top:0px;margin-right:12px"></vc-tag>
            <vc-tag text="标签四" type="danger" style="left:240px;top:0px;margin-right:12px"></vc-tag>
            <vc-tag text="标签五" type="info" style="left:320px;top:0px"></vc-tag>
          </div>
        </div>

        <!-- Badge 演示 -->
        <div v-if="selected === 'badge'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Badge 徽章用于显示数量。基础用法：</div>
          <div style="left:0px;top:32px;width:400px;height:80px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-badge value="100" style="left:0px;top:0px;margin-right:24px"></vc-badge>
            <vc-badge value="50" max="99" style="left:80px;top:0px;margin-right:24px"></vc-badge>
            <vc-badge value="新" style="left:160px;top:0px;margin-right:24px"></vc-badge>
            <vc-badge dot="1" style="left:240px;top:0px"></vc-badge>
          </div>
        </div>

        <!-- Progress 演示 -->
        <div v-if="selected === 'progress'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Progress 进度条用于显示进度。基础用法：</div>
          <div style="left:0px;top:32px;width:500px;height:120px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-progress percentage="30" style="left:0px;top:0px;margin-bottom:16px"></vc-progress>
            <vc-progress percentage="60" status="success" style="left:0px;top:40px;margin-bottom:16px"></vc-progress>
            <vc-progress percentage="80" status="warning" style="left:0px;top:80px;margin-bottom:16px"></vc-progress>
          </div>
        </div>

        <!-- Icon 演示 -->
        <div v-if="selected === 'icon'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Icon 图标用于显示符号。基础用法：</div>
          <div style="left:0px;top:32px;width:500px;height:120px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-icon name="☀" style="left:0px;top:0px;font-size:32px;color:#409EFF;margin-right:24px"></vc-icon>
            <vc-icon name="★" style="left:60px;top:0px;font-size:32px;color:#67C23A;margin-right:24px"></vc-icon>
            <vc-icon name="♥" style="left:120px;top:0px;font-size:32px;color:#F56C6C;margin-right:24px"></vc-icon>
            <vc-icon name="●" style="left:180px;top:0px;font-size:32px;color:#E6A23C;margin-right:24px"></vc-icon>
            <vc-icon name="▶" style="left:240px;top:0px;font-size:32px;color:#909399"></vc-icon>
          </div>
        </div>

        <!-- Row/Col 演示 -->
        <div v-if="selected === 'row-col'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Row/Col 网格布局用于排列元素。基础用法：</div>
          <div style="left:0px;top:32px;width:500px;height:160px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-row style="left:0px;top:0px;margin-bottom:8px">
              <vc-col span="8" style="left:0px;top:0px;height:40px;background:#409EFF;align-items:center;justify-content:center">
                <span style="font-size:14px;color:#FFFFFF">col-8</span>
              </vc-col>
              <vc-col span="8" style="left:0px;top:0px;height:40px;background:#67C23A;align-items:center;justify-content:center">
                <span style="font-size:14px;color:#FFFFFF">col-8</span>
              </vc-col>
              <vc-col span="8" style="left:0px;top:0px;height:40px;background:#E6A23C;align-items:center;justify-content:center">
                <span style="font-size:14px;color:#FFFFFF">col-8</span>
              </vc-col>
            </vc-row>
            <vc-row style="left:0px;top:0px">
              <vc-col span="6" style="left:0px;top:0px;height:40px;background:#409EFF;align-items:center;justify-content:center">
                <span style="font-size:14px;color:#FFFFFF">col-6</span>
              </vc-col>
              <vc-col span="6" style="left:0px;top:0px;height:40px;background:#67C23A;align-items:center;justify-content:center">
                <span style="font-size:14px;color:#FFFFFF">col-6</span>
              </vc-col>
              <vc-col span="12" style="left:0px;top:0px;height:40px;background:#E6A23C;align-items:center;justify-content:center">
                <span style="font-size:14px;color:#FFFFFF">col-12</span>
              </vc-col>
            </vc-row>
          </div>
        </div>

        <!-- Card 演示 -->
        <div v-if="selected === 'card'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Card 卡片用于组织和展示内容。基础用法：</div>
          <div style="left:0px;top:32px;width:500px;height:200px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-card header="卡片标题" text="这是卡片的内容区域，可以放置任意内容。" style="left:0px;top:0px;width:400px;height:160px"></vc-card>
          </div>
        </div>

        <!-- Select 演示 -->
        <div v-if="selected === 'select'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Select 选择器用于下拉选择。基础用法：</div>
          <div style="left:0px;top:32px;width:400px;height:60px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-select placeholder="请选择" :options="selectOptions" :model-value="selectValue" style="left:0px;top:0px;width:300px;height:40px"></vc-select>
          </div>
        </div>

        <!-- Modal 演示 -->
        <div v-if="selected === 'modal'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Modal 弹窗用于重要操作确认。基础用法：</div>
          <div style="left:0px;top:32px;width:200px;height:48px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-button text="打开弹窗" @click="openModal" style="left:0px;top:0px"></vc-button>
          </div>
          <vc-modal v-if="modalVisible === '1'" title="提示" :visible="modalVisible" :mask-closable="'1'" @click="closeModal" style="left:260px;top:200px;width:400px;height:200px">
            <span style="font-size:14px;color:#606266">这是一段内容</span>
          </vc-modal>
        </div>

        <!-- Drawer 演示 -->
        <div v-if="selected === 'drawer'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Drawer 抽屉用于侧面展开内容。基础用法：</div>
          <div style="left:0px;top:32px;width:200px;height:48px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-button text="打开抽屉" @click="openDrawer" style="left:0px;top:0px"></vc-button>
          </div>
        </div>

        <!-- Table 演示 -->
        <div v-if="selected === 'table'">
          <div style="left:0px;top:0px;font-size:14px;color:#606266;margin-bottom:16px">Table 表格用于展示结构化数据。基础用法：</div>
          <div style="left:0px;top:32px;width:600px;height:200px;background:#F5F7FA;padding:20px;margin-bottom:24px">
            <vc-table :data="tableData" :columns="tableColumns" style="left:0px;top:0px;width:560px;height:180px"></vc-table>
          </div>
        </div>

        <!-- 其他组件提示 -->
        <div v-if="selected !== 'button' && selected !== 'input' && selected !== 'switch' && selected !== 'slider' && selected !== 'tag' && selected !== 'badge' && selected !== 'progress' && selected !== 'icon' && selected !== 'row-col' && selected !== 'card' && selected !== 'select' && selected !== 'modal' && selected !== 'drawer' && selected !== 'table'">
          <div style="left:0px;top:0px;font-size:16px;color:#606266">组件演示正在开发中...</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="php">
public array $basicItems = [
    ['name' => 'button', 'label' => 'Button 按钮'],
    ['name' => 'icon', 'label' => 'Icon 图标'],
    ['name' => 'text', 'label' => 'Text 文本']
];
public array $formItems = [
    ['name' => 'input', 'label' => 'Input 输入框'],
    ['name' => 'switch', 'label' => 'Switch 开关'],
    ['name' => 'slider', 'label' => 'Slider 滑块'],
    ['name' => 'select', 'label' => 'Select 选择器']
];
public array $displayItems = [
    ['name' => 'tag', 'label' => 'Tag 标签'],
    ['name' => 'badge', 'label' => 'Badge 徽章'],
    ['name' => 'progress', 'label' => 'Progress 进度条']
];

public string $selected = "button";
public string $inputValue = "";
public string $switchValue = "0";
public string $sliderValue = "50";
public string $selectValue = "";
public string $modalVisible = "0";
public string $drawerVisible = "0";
public array $selectOptions = [
    ['label' => '选项一', 'value' => '1'],
    ['label' => '选项二', 'value' => '2'],
    ['label' => '选项三', 'value' => '3']
];
public array $tableData = [
    ['name' => '张三', 'age' => '25', 'address' => '北京市'],
    ['name' => '李四', 'age' => '30', 'address' => '上海市'],
    ['name' => '王五', 'age' => '28', 'address' => '广州市']
];
public array $tableColumns = [
    ['label' => '姓名', 'prop' => 'name'],
    ['label' => '年龄', 'prop' => 'age'],
    ['label' => '地址', 'prop' => 'address']
];

public function getCurrentLabel(): string {
    $allItems = array_merge($this->basicItems, $this->formItems, $this->displayItems);
    foreach ($allItems as $item) {
        if ($item['name'] === $this->selected) {
            return $item['label'];
        }
    }
    return '';
}

public function selectComponent(string $name): void {
    $this->selected = $name;
    $this->markDirty();
}

public function openModal(): void {
    $this->modalVisible = "1";
}

public function closeModal(): void {
    $this->modalVisible = "0";
}

public function openDrawer(): void {
    $this->drawerVisible = "1";
}
</script>

