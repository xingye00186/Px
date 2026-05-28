<template>
  <div style="width:1000px;height:700px" class="guide-wrapper">

    <!-- 左侧导航 -->
    <div style="left:0px;top:0px;width:220px;height:700px" class="sidebar">
      <div style="left:0px;top:0px;width:220px;height:48px" class="sidebar-header">
        <span style="left:12px;top:12px;font-size:16px;font-weight:bold;color:#FFFFFF">Px Framework</span>
      </div>
      <div style="left:0px;top:48px;width:220px;height:652px;overflow-y:auto" class="nav-list">
        <div v-for="cat in categories" :key="cat.id" style="left:0px;top:0px;width:220px;height:auto" class="nav-category">
          <div style="left:0px;top:0px;width:220px;height:32px" class="cat-header" @click="toggleCat(cat.id)">
            <span style="left:12px;top:6px;font-size:13px;font-weight:bold;color:#409EFF">{{ cat.name }}</span>
            <span style="right:12px;top:8px;font-size:10px;color:#C0C4CC">{{ cat.expanded ? '-' : '+' }}</span>
          </div>
          <div v-if="cat.expanded === '1'" style="left:0px;top:0px;width:220px;height:auto" class="cat-items">
            <div v-for="item in cat.items" :key="item.id" :style="'left:0px;top:0px;width:220px;height:28px'" :class="item.cls" @click="selectItem(cat.id, item.id)">
              <span style="left:24px;top:4px;font-size:12px" :class="item.cls2">{{ item.name }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 主内容区 -->
    <div style="left:220px;top:0px;width:780px;height:700px" class="main-content">

      <!-- 顶部标题 -->
      <div style="left:0px;top:0px;width:780px;height:48px" class="content-header">
        <span style="left:24px;top:12px;font-size:18px;font-weight:bold;color:#303133">{{ currentTitle }}</span>
      </div>

      <!-- 内容滚动区 -->
      <div style="left:0px;top:48px;width:780px;height:652px;overflow-y:auto" class="content-body">

        <!-- P0 介绍 -->
        <div v-if="currentSection === 'p0-intro'" style="left:0px;top:0px;width:740px;padding:20px" class="doc-section">
          <vc-title style="width:700px;height:32px" text="P0 — 基础组件介绍" />
          <vc-paragraph style="width:700px;margin-top:12px" text="基础组件是 UI 组件库的原子单元，提供最基本的功能元素。这些组件通常不包含复杂的业务逻辑，是构建更复杂组件的基础。" />

          <vc-divider style="width:700px;margin-top:16px" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="组件列表" />

          <vc-row style="width:700px;margin-top:12px" text="">
            <vc-col style="left:0px;top:0px" :span="8"><vc-card style="width:200px;height:80px" header="Button" text="按钮组件" /></vc-col>
            <vc-col style="left:0px;top:0px" :span="8"><vc-card style="width:200px;height:80px" header="Icon" text="图标组件" /></vc-col>
            <vc-col style="left:0px;top:0px" :span="8"><vc-card style="width:200px;height:80px" header="Link" text="链接组件" /></vc-col>
          </vc-row>
          <vc-row style="width:700px;margin-top:8px" text="">
            <vc-col style="left:0px;top:0px" :span="8"><vc-card style="width:200px;height:80px" header="Title" text="标题组件" /></vc-col>
            <vc-col style="left:0px;top:0px" :span="8"><vc-card style="width:200px;height:80px" header="Divider" text="分隔线" /></vc-col>
            <vc-col style="left:0px;top:0px" :span="8"><vc-card style="width:200px;height:80px" header="Space" text="间距组件" /></vc-col>
          </vc-row>

          <vc-title style="width:700px;height:24px;margin-top:24px" text="快速开始" />
          <vc-paragraph style="width:700px;margin-top:8px" text="导入组件库后，即可直接在模板中使用 vc- 前缀的组件。" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="代码示例" />
          <div style="left:0px;top:8px;width:700px;height:80px;background:#1E1E1E;padding:12px" class="code-block">
            <vc-paragraph style="font-size:13px;color:#D4D4D4;font-family:Consolas" text="import VcUI from 'vc-ui'" />
            <vc-paragraph style="font-size:13px;color:#D4D4D4;font-family:Consolas" text="(template)" />
            <vc-paragraph style="font-size:13px;color:#D4D4D4;font-family:Consolas" text="vc-button text=Click me @click=onClick" />
          </div>
        </div>

        <!-- P0 Button 详情 -->
        <div v-if="currentSection === 'p0-button'" style="left:0px;top:0px;width:740px;padding:20px" class="doc-section">
          <vc-title style="width:700px;height:32px" text="Button 按钮" />
          <vc-paragraph style="width:700px;margin-top:8px" text="按钮用于触发操作。支持多种类型和状态。" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="示例" />
          <div style="left:0px;top:8px;width:700px;height:60px" class="demo-area">
            <vc-button style="left:16px;top:16px" text="Default" @click="onBtnDemo" />
            <vc-button style="left:106px;top:16px" text="Primary" @click="onBtnDemo" />
            <vc-button style="left:196px;top:16px" text="Success" @click="onBtnDemo" />
            <vc-button style="left:286px;top:16px" text="Warning" @click="onBtnDemo" />
            <vc-button style="left:376px;top:16px" text="Danger" @click="onBtnDemo" />
          </div>

          <vc-title style="width:700px;height:24px;margin-top:16px" text="属性" />
          <vc-table style="width:700px" :data="buttonPropsData" :columns="tableCols" :currentPage="curPage" :pageSize="pgSize" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="事件" />
          <vc-table style="width:700px" :data="buttonEventsData" :columns="tableCols" :currentPage="curPage" :pageSize="pgSize" />
        </div>

        <!-- P1 介绍 -->
        <div v-if="currentSection === 'p1-intro'" style="left:0px;top:0px;width:740px;padding:20px" class="doc-section">
          <vc-title style="width:700px;height:32px" text="P1 — 布局组件介绍" />
          <vc-paragraph style="width:700px;margin-top:12px" text="布局组件用于构建页面的基本框架结构，包括容器、栅格、卡片等。" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="栅格系统" />
          <vc-row style="width:700px;height:40px;margin-top:12px" text="">
            <vc-col style="left:0px;top:0px" :span="8"><div class="col-demo">col-8</div></vc-col>
            <vc-col style="left:0px;top:0px" :span="8"><div class="col-demo">col-8</div></vc-col>
            <vc-col style="left:0px;top:0px" :span="8"><div class="col-demo">col-8</div></vc-col>
          </vc-row>

          <vc-title style="width:700px;height:24px;margin-top:16px" text="卡片示例" />
          <vc-row style="width:700px;margin-top:12px" text="">
            <vc-col style="left:0px;top:0px" :span="6"><vc-card style="height:100px" header="Card 1" text="Always shadow" shadow="always" /></vc-col>
            <vc-col style="left:0px;top:0px" :span="6"><vc-card style="height:100px" header="Card 2" text="Hover shadow" shadow="hover" /></vc-col>
            <vc-col style="left:0px;top:0px" :span="6"><vc-card style="height:100px" header="Card 3" text="Never shadow" shadow="never" /></vc-col>
          </vc-row>
        </div>

        <!-- P1 Card 详情 -->
        <div v-if="currentSection === 'p1-card'" style="left:0px;top:0px;width:740px;padding:20px" class="doc-section">
          <vc-title style="width:700px;height:32px" text="Card 卡片" />
          <vc-paragraph style="width:700px;margin-top:8px" text="卡片用于展示内容区块，支持阴影效果和头部定制。" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="示例" />
          <vc-card style="width:300px;margin-top:12px" header="Card Header" text="This is card content area. You can put any content here." shadow="always" />
        </div>

        <!-- P2 介绍 -->
        <div v-if="currentSection === 'p2-intro'" style="left:0px;top:0px;width:740px;padding:20px" class="doc-section">
          <vc-title style="width:700px;height:32px" text="P2 — 表单组件介绍" />
          <vc-paragraph style="width:700px;margin-top:12px" text="表单组件用于收集用户输入，支持 v-model 双向绑定。" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="输入组件示例" />
          <vc-input style="width:300px;margin-top:12px" :modelValue="demoInput" placeholder="Enter text..." @input="onDemoInput" />
          <vc-paragraph style="width:700px;margin-top:8px" :text="'当前输入: ' . demoInput" />

          <vc-row style="width:700px;margin-top:16px" text="">
            <vc-col style="left:0px;top:0px" :span="6">
              <vc-switch style="width:44px;height:22px" :modelValue="demoSwitch" @change="onDemoSwitch" />
              <span style="left:60px;top:4px;font-size:13px">{{ demoSwitch }}</span>
            </vc-col>
            <vc-col style="left:0px;top:0px" :span="6">
              <vc-slider style="width:160px;height:32px" :modelValue="demoSlider" @input="onDemoSlider" />
            </vc-col>
          </vc-row>

          <vc-row style="width:700px;margin-top:16px" text="">
            <vc-col style="left:0px;top:0px" :span="6">
              <vc-select style="width:200px;height:36px" :modelValue="demoSelect" :options="selectOptsDemo" @change="onDemoSelect" />
            </vc-col>
            <vc-col style="left:0px;top:0px" :span="6">
              <vc-input-number style="width:120px;height:36px" :modelValue="demoNumber" @input="onDemoNumber" />
            </vc-col>
          </vc-row>
        </div>

        <!-- P2 Input 详情 -->
        <div v-if="currentSection === 'p2-input'" style="left:0px;top:0px;width:740px;padding:20px" class="doc-section">
          <vc-title style="width:700px;height:32px" text="Input 输入框" />
          <vc-paragraph style="width:700px;margin-top:8px" text="输入框用于收集用户文本输入。" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="基础用法" />
          <vc-input style="width:300px;margin-top:12px" :modelValue="demoInput" placeholder="Please enter..." @input="onDemoInput" />
        </div>

        <!-- P3 介绍 -->
        <div v-if="currentSection === 'p3-intro'" style="left:0px;top:0px;width:740px;padding:20px" class="doc-section">
          <vc-title style="width:700px;height:32px" text="P3 — 浮层与反馈组件" />
          <vc-paragraph style="width:700px;margin-top:12px" text="浮层组件用于展示模态内容、通知、提示等。" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="Modal 对话框" />
          <vc-button style="width:120px;height:36px" text="Open Modal" @click="onShowModal" />
          <vc-modal v-if="showGuideModal === '1'" style="left:0px;top:0px" :visible="showGuideModal" title="Modal Title" text="This is a modal dialog." width="380" height="200" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="Drawer 抽屉" />
          <vc-button style="width:120px;height:36px" text="Open Drawer" @click="onShowDrawer" />
          <vc-drawer v-if="showGuideDrawer === '1'" style="left:0px;top:0px" :visible="showGuideDrawer" title="Drawer" text="Drawer content from guide." width="280" placement="right" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="消息提示" />
          <vc-button style="width:80px;height:32px" text="Success" @click="onGuideMsg('success')" />
          <vc-button style="left:90px;top:0px;width:80px;height:32px" text="Error" @click="onGuideMsg('error')" />
          <vc-button style="left:180px;top:0px;width:80px;height:32px" text="Warning" @click="onGuideMsg('warning')" />
          <vc-message v-if="guideMsgTxt !== ''" style="left:270px;top:0px" :text="guideMsgTxt" :type="guideMsgTp" />
        </div>

        <!-- P4 介绍 -->
        <div v-if="currentSection === 'p4-intro'" style="left:0px;top:0px;width:740px;padding:20px" class="doc-section">
          <vc-title style="width:700px;height:32px" text="P4 — 数据展示组件" />
          <vc-paragraph style="width:700px;margin-top:12px" text="数据展示组件用于呈现结构化数据和状态信息。" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="Table 表格" />
          <vc-table style="width:700px;margin-top:12px" :data="tableDemoData" :columns="tableCols" :currentPage="curPage" :pageSize="pgSize" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="Tag 标签" />
          <vc-tag style="margin-top:12px" text="Primary" type="primary" />
          <vc-tag style="left:80px;top:0px" text="Success" type="success" />
          <vc-tag style="left:160px;top:0px" text="Warning" type="warning" />
          <vc-tag style="left:240px;top:0px" text="Danger" type="danger" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="Progress 进度条" />
          <vc-progress style="width:300px;margin-top:12px" :percentage="demoProgress" />
        </div>

        <!-- P5 介绍 -->
        <div v-if="currentSection === 'p5-intro'" style="left:0px;top:0px;width:740px;padding:20px" class="doc-section">
          <vc-title style="width:700px;height:32px" text="P5 — 高级表单组件" />
          <vc-paragraph style="width:700px;margin-top:12px" text="高级表单组件提供更复杂的输入选择功能。" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="DatePicker 日期选择" />
          <vc-datepicker style="width:240px;height:36px;margin-top:12px" :modelValue="demoDate" @change="onDemoDate" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="Rate 星级评分" />
          <vc-rate style="margin-top:12px" :modelValue="demoRate" :count="5" @change="onDemoRate" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="ColorPicker 颜色选择" />
          <vc-colorpicker style="width:100px;height:36px;margin-top:12px" :modelValue="demoColor" @change="onDemoColor" />
          <div style="left:0px;top:8px;width:60px;height:30px" :style="'background:' . demoColor" class="color-swatch"></div>
        </div>

        <!-- P6 介绍 -->
        <div v-if="currentSection === 'p6-intro'" style="left:0px;top:0px;width:740px;padding:20px" class="doc-section">
          <vc-title style="width:700px;height:32px" text="P6 — 复杂与扩展组件" />
          <vc-paragraph style="width:700px;margin-top:12px" text="复杂组件提供高级交互功能。" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="Tree 树形控件" />
          <vc-tree style="width:280px;margin-top:12px" :data="treeDemoData" :showCheckbox="showCheck" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="Carousel 轮播图" />
          <vc-carousel style="width:400px;height:180px;margin-top:12px" :data="carouselDemoData" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="Transfer 穿梭框" />
          <vc-transfer style="width:400px;height:180px;margin-top:12px" :data="transferDemoData" />
        </div>

        <!-- 快速参考 -->
        <div v-if="currentSection === 'quickstart'" style="left:0px;top:0px;width:740px;padding:20px" class="doc-section">
          <vc-title style="width:700px;height:32px" text="快速开始" />
          <vc-paragraph style="width:700px;margin-top:12px" text="在 project.yml 中配置组件库路径，即可使用所有组件。" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="1. 配置 project.yml" />
          <div style="left:0px;top:8px;width:700px;height:80px;background:#1E1E1E;padding:12px" class="code-block">
            <vc-paragraph style="font-size:13px;color:#D4D4D4;font-family:Consolas" text="component-libraries:" />
            <vc-paragraph style="font-size:13px;color:#D4D4D4;font-family:Consolas" text="  - path: ../../library/vc-ui" />
            <vc-paragraph style="font-size:13px;color:#D4D4D4;font-family:Consolas" text="    prefix: vc" />
            <vc-paragraph style="font-size:13px;color:#D4D4D4;font-family:Consolas" text="    mode: auto-register" />
          </div>

          <vc-title style="width:700px;height:24px;margin-top:16px" text="2. 在模板中使用" />
          <div style="left:0px;top:8px;width:700px;height:100px;background:#1E1E1E;padding:12px" class="code-block">
            <vc-paragraph style="font-size:13px;color:#D4D4D4;font-family:Consolas" text="vc-button text=Submit @click=onSubmit" />
            <vc-paragraph style="font-size:13px;color:#D4D4D4;font-family:Consolas" text="vc-input :modelValue=username placeholder=Username" />
            <vc-paragraph style="font-size:13px;color:#D4D4D4;font-family:Consolas" text="vc-modal v-if=show :visible=show title=Alert" />
          </div>

          <vc-title style="width:700px;height:24px;margin-top:16px" text="3. 构建运行" />
          <div style="left:0px;top:8px;width:700px;height:40px;background:#1E1E1E;padding:12px" class="code-block">
            <vc-paragraph style="font-size:13px;color:#D4D4D4;font-family:Consolas" text="build.bat vc-guide --run" />
          </div>
        </div>

        <!-- 组件索引 -->
        <div v-if="currentSection === 'index'" style="left:0px;top:0px;width:740px;padding:20px" class="doc-section">
          <vc-title style="width:700px;height:32px" text="组件索引" />
          <vc-paragraph style="width:700px;margin-top:8px" text="共 59 个组件，分为 7 个阶段。" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="P0 基础组件 (8)" />
          <vc-tag style="margin-top:8px" text="Button" type="primary" />
          <vc-tag style="left:8px;top:0px" text="Icon" type="primary" />
          <vc-tag style="left:60px;top:0px" text="Link" type="primary" />
          <vc-tag style="left:100px;top:0px" text="Title" type="primary" />
          <vc-tag style="left:148px;top:0px" text="Text" type="primary" />
          <vc-tag style="left:190px;top:0px" text="Paragraph" type="primary" />
          <vc-tag style="left:254px;top:0px" text="Divider" type="primary" />
          <vc-tag style="left:316px;top:0px" text="Space" type="primary" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="P1 布局组件 (9)" />
          <vc-tag style="margin-top:8px" text="Container" type="success" />
          <vc-tag style="left:8px;top:0px" text="Row" type="success" />
          <vc-tag style="left:60px;top:0px" text="Col" type="success" />
          <vc-tag style="left:96px;top:0px" text="Card" type="success" />
          <vc-tag style="left:136px;top:0px" text="Split" type="success" />
          <vc-tag style="left:176px;top:0px" text="Collapse" type="success" />
          <vc-tag style="left:232px;top:0px" text="Tabs" type="success" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="P2 表单组件 (10)" />
          <vc-tag style="margin-top:8px" text="Input" type="warning" />
          <vc-tag style="left:8px;top:0px" text="Textarea" type="warning" />
          <vc-tag style="left:72px;top:0px" text="Select" type="warning" />
          <vc-tag style="left:124px;top:0px" text="Switch" type="warning" />
          <vc-tag style="left:176px;top:0px" text="Slider" type="warning" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="P3 浮层组件 (7)" />
          <vc-tag style="margin-top:8px" text="Modal" type="danger" />
          <vc-tag style="left:8px;top:0px" text="Drawer" type="danger" />
          <vc-tag style="left:60px;top:0px" text="Message" type="danger" />
          <vc-tag style="left:124px;top:0px" text="Notification" type="danger" />
          <vc-tag style="left:200px;top:0px" text="Tooltip" type="danger" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="P4 数据展示 (8)" />
          <vc-tag style="margin-top:8px" text="Table" type="info" />
          <vc-tag style="left:8px;top:0px" text="Tag" type="info" />
          <vc-tag style="left:52px;top:0px" text="Progress" type="info" />
          <vc-tag style="left:112px;top:0px" text="Badge" type="info" />
          <vc-tag style="left:160px;top:0px" text="Avatar" type="info" />
          <vc-tag style="left:212px;top:0px" text="Pagination" type="info" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="P5 高级表单 (8)" />
          <vc-tag style="margin-top:8px" text="DatePicker" type="primary" />
          <vc-tag style="left:8px;top:0px" text="TimePicker" type="primary" />
          <vc-tag style="left:80px;top:0px" text="Upload" type="primary" />
          <vc-tag style="left:132px;top:0px" text="Cascader" type="primary" />
          <vc-tag style="left:192px;top:0px" text="Rate" type="primary" />
          <vc-tag style="left:232px;top:0px" text="ColorPicker" type="primary" />

          <vc-title style="width:700px;height:24px;margin-top:16px" text="P6 复杂组件 (9)" />
          <vc-tag style="margin-top:8px" text="Tree" type="success" />
          <vc-tag style="left:8px;top:0px" text="Calendar" type="success" />
          <vc-tag style="left:76px;top:0px" text="Timeline" type="success" />
          <vc-tag style="left:136px;top:0px" text="Transfer" type="success" />
          <vc-tag style="left:200px;top:0px" text="Carousel" type="success" />
          <vc-tag style="left:264px;top:0px" text="ChartLine" type="success" />
          <vc-tag style="left:332px;top:0px" text="ChartBar" type="success" />
        </div>

      </div>
    </div>
  </div>
</template>

<script lang="php">

    // 当前选中
    public string $currentSection = 'quickstart';
    public string $currentTitle = '快速开始';

    // Demo 状态
    public string $demoInput = '';
    public string $demoSwitch = 'off';
    public string $demoSlider = '50';
    public string $demoSelect = '';
    public string $demoNumber = '10';
    public string $demoProgress = '65';
    public string $demoDate = '';
    public string $demoRate = '3';
    public string $demoColor = '#409EFF';

    // 浮层状态
    public string $showGuideModal = '';
    public string $showGuideDrawer = '';
    public string $guideMsgTxt = '';
    public string $guideMsgTp = 'success';

    // 表格列定义
    public string $tableCols = '[{"key":"name","label":"名称"},{"key":"type","label":"类型"},{"key":"desc","label":"说明"}]';
    public string $curPage = '1';
    public string $pgSize = '10';

    // Button 文档数据
    public string $buttonPropsData = '[{"name":"text","type":"string","desc":"按钮文字"},{"name":"type","type":"string","desc":"按钮类型"}]';
    public string $buttonEventsData = '[{"name":"click","type":"handler","desc":"点击事件"}]';

    // Table 示例数据
    public string $tableDemoData = '[{"name":"Alice","age":28,"status":"active"},{"name":"Bob","age":35,"status":"active"},{"name":"Carol","age":42,"status":"inactive"}]';

    // Select 选项
    public string $selectOptsDemo = '[{"label":"Option 1","value":"1"},{"label":"Option 2","value":"2"},{"label":"Option 3","value":"3"}]';

    // Tree 示例数据
    public string $treeDemoData = '[{"label":"Root","value":"root","children":[{"label":"Node 1","value":"n1"},{"label":"Node 2","value":"n2","children":[{"label":"Node 2.1","value":"n21"}]}]}]';
    public string $showCheck = '1';

    // Carousel 示例数据
    public string $carouselDemoData = '[{"text":"Slide 1","bg":"#409EFF"},{"text":"Slide 2","bg":"#67C23A"},{"text":"Slide 3","bg":"#F56C6C"}]';

    // Transfer 示例数据
    public string $transferDemoData = '[{"label":"Option 1","value":"1"},{"label":"Option 2","value":"2"},{"label":"Option 3","value":"3"},{"label":"Option 4","value":"4"}]';

    // 导航分类
    public string $categories = '[
      {
        "id":"getting-started",
        "name":"开始使用",
        "expanded":"1",
        "items":[
          {"id":"quickstart","name":"快速开始","cls":"nav-item nav-item-active","cls2":"nav-item-text-active"},
          {"id":"index","name":"组件索引","cls":"nav-item","cls2":"nav-item-text"}
        ]
      },
      {
        "id":"p0",
        "name":"P0 基础组件",
        "expanded":"1",
        "items":[
          {"id":"p0-intro","name":"介绍","cls":"nav-item","cls2":"nav-item-text"},
          {"id":"p0-button","name":"Button","cls":"nav-item","cls2":"nav-item-text"}
        ]
      },
      {
        "id":"p1",
        "name":"P1 布局组件",
        "expanded":"1",
        "items":[
          {"id":"p1-intro","name":"介绍","cls":"nav-item","cls2":"nav-item-text"},
          {"id":"p1-card","name":"Card","cls":"nav-item","cls2":"nav-item-text"}
        ]
      },
      {
        "id":"p2",
        "name":"P2 表单组件",
        "expanded":"1",
        "items":[
          {"id":"p2-intro","name":"介绍","cls":"nav-item","cls2":"nav-item-text"},
          {"id":"p2-input","name":"Input","cls":"nav-item","cls2":"nav-item-text"}
        ]
      },
      {
        "id":"p3",
        "name":"P3 浮层组件",
        "expanded":"1",
        "items":[
          {"id":"p3-intro","name":"介绍","cls":"nav-item","cls2":"nav-item-text"}
        ]
      },
      {
        "id":"p4",
        "name":"P4 数据展示",
        "expanded":"1",
        "items":[
          {"id":"p4-intro","name":"介绍","cls":"nav-item","cls2":"nav-item-text"}
        ]
      },
      {
        "id":"p5",
        "name":"P5 高级表单",
        "expanded":"1",
        "items":[
          {"id":"p5-intro","name":"介绍","cls":"nav-item","cls2":"nav-item-text"}
        ]
      },
      {
        "id":"p6",
        "name":"P6 复杂组件",
        "expanded":"1",
        "items":[
          {"id":"p6-intro","name":"介绍","cls":"nav-item","cls2":"nav-item-text"}
        ]
      }
    ]';

    /**
     * 切换分类展开
     */
    public function toggleCat(string $catId): void
    {
        $cats = json_decode($this->categories, true);
        if (!is_array($cats)) return;
        foreach ($cats as &$cat) {
            if ($cat['id'] === $catId) {
                $cat['expanded'] = ($cat['expanded'] === '1') ? '0' : '1';
            }
        }
        $this->categories = json_encode($cats);
    }

    /**
     * 选择导航项
     */
    public function selectItem(string $catId, string $itemId): void
    {
        $this->currentSection = $itemId;
        $cats = json_decode($this->categories, true);
        if (!is_array($cats)) return;
        foreach ($cats as &$cat) {
            foreach ($cat['items'] as &$item) {
                if ($item['id'] === $itemId) {
                    $item['cls'] = 'nav-item nav-item-active';
                    $item['cls2'] = 'nav-item-text-active';
                    $this->currentTitle = $item['name'];
                } else {
                    $item['cls'] = 'nav-item';
                    $item['cls2'] = 'nav-item-text';
                }
            }
        }
        $this->categories = json_encode($cats);
    }

    // Demo handlers
    public function onBtnDemo(): void {}
    public function onDemoInput(string $v): void { $this->demoInput = $v; }
    public function onDemoSwitch(string $v): void { $this->demoSwitch = $v; }
    public function onDemoSlider(string $v): void { $this->demoSlider = $v; }
    public function onDemoSelect(string $v): void { $this->demoSelect = $v; }
    public function onDemoNumber(string $v): void { $this->demoNumber = $v; }

    public function onShowModal(): void { $this->showGuideModal = '1'; }
    public function onShowDrawer(): void { $this->showGuideDrawer = '1'; }
    public function onGuideMsg(string $type): void { $this->guideMsgTp = $type; $this->guideMsgTxt = ucfirst($type) . ' message!'; }

    public function onDemoDate(string $v): void { $this->demoDate = $v; }
    public function onDemoRate(string $v): void { $this->demoRate = $v; }
    public function onDemoColor(string $v): void { $this->demoColor = $v; }
</script>

<style>
.guide-wrapper { background: #F0F2F5; }
.sidebar { background: #001529; }
.sidebar-header { background: #001529; border-bottom: 1px solid #002140; }
.nav-list { background: #001529; }
.nav-category { border-bottom: 1px solid #002140; }
.cat-header { cursor: pointer; background: #001529; }
.cat-header:hover { background: #002140; }
.cat-items { background: #000C17; }
.nav-item { cursor: pointer; background: transparent; }
.nav-item:hover { background: #002140; }
.nav-item-active { background: #1890FF; }
.nav-item-text { color: #FFFFFF; font-size: 12px; }
.nav-item-text-active { color: #FFFFFF; font-size: 12px; font-weight: bold; }
.main-content { background: #FFFFFF; }
.content-header { background: #FFFFFF; border-bottom: 1px solid #E8E8E8; }
.content-body { background: #FFFFFF; }
.doc-section { background: #FFFFFF; }
.demo-area { background: #FAFAFA; border: 1px solid #E8E8E8; }
.code-block { background: #1E1E1E; font-family: Consolas; }
.col-demo { background: #409EFF; color: #FFFFFF; font-size: 12px; text-align: center; }
.color-swatch { border: 1px solid #DCDFE6; }
</style>