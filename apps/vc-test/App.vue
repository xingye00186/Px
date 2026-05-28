<template>
  <div style="width:700px;height:960px" title="VC-UI Full Test">

    <!-- 背景 -->
    <div style="left:0px;top:0px;width:700px;height:960px" class="bg"></div>
    <span style="left:16px;top:12px;font-size:20px;font-weight:bold;color:#FFFFFF">VC-UI All Layers Test</span>

    <!-- P0 基础组件 -->
    <vc-title style="left:16px;top:48px;width:668px;height:24px" text="P0 — Base Components" />
    <vc-button style="left:16px;top:76px;width:80px;height:32px" text="Button" @click="onBtn" />
    <vc-button style="left:106px;top:76px;width:80px;height:32px" text="Success" @click="onBtn" />
    <vc-button style="left:196px;top:76px;width:80px;height:32px" text="Drawer" @click="onDrawer" />
    <vc-button style="left:286px;top:76px;width:80px;height:32px" text="Tooltip" @click="onTip" />
    <vc-button style="left:376px;top:76px;width:80px;height:32px" text="Popconfirm" @click="onConfirm" />
    <vc-button style="left:466px;top:76px;width:80px;height:32px" text="Table" @click="onShowTable" />

    <!-- P1 布局 -->
    <vc-title style="left:16px;top:120px;width:668px;height:24px" text="P1 — Layout" />
    <vc-row style="left:16px;top:148px;width:668px;height:36px" text="Grid Row">
      <vc-col style="left:0px;top:0px" :span="8"><div class="col-box">col-8</div></vc-col>
      <vc-col style="left:0px;top:0px" :span="8"><div class="col-box">col-8</div></vc-col>
      <vc-col style="left:0px;top:0px" :span="8"><div class="col-box">col-8</div></vc-col>
    </vc-row>
    <vc-card style="left:16px;top:192px;width:200px;height:140px" header="Card" text="Card content." shadow="always" />
    <vc-card style="left:228px;top:192px;width:200px;height:140px" header="Card 2" text="Hover shadow." shadow="hover" />

    <!-- P2 表单 -->
    <vc-title style="left:16px;top:348px;width:668px;height:24px" text="P2 — Form" />
    <vc-input style="left:16px;top:376px;width:200px;height:32px" :modelValue="inputVal" placeholder="Enter text..." @input="onInput" />
    <vc-switch style="left:230px;top:376px;width:44px;height:22px" :modelValue="switchVal" @change="onSwitch" />
    <vc-slider style="left:290px;top:376px;width:160px;height:32px" :modelValue="sliderVal" @input="onSlider" />
    <vc-select style="left:16px;top:416px;width:200px;height:32px" :modelValue="selectVal" :options="selectOpts" @change="onSelect" />
    <vc-input-number style="left:230px;top:416px;width:140px;height:32px" :modelValue="numberVal" @input="onNumber" />

    <!-- P3 浮层 -->
    <vc-title style="left:16px;top:464px;width:668px;height:24px" text="P3 — Overlay &amp; Feedback" />
    <vc-button style="left:16px;top:492px;width:100px;height:32px" text="Open Modal" @click="onModal" />
    <vc-button style="left:126px;top:492px;width:100px;height:32px" text="Open Drawer" @click="onDrawer" />
    <vc-button style="left:236px;top:492px;width:100px;height:32px" text="Notification" @click="onNotif" />
    <vc-button style="left:16px;top:534px;width:80px;height:32px" text="Success" @click="onMsgOk" />
    <vc-button style="left:106px;top:534px;width:80px;height:32px" text="Error" @click="onMsgErr" />
    <vc-button style="left:196px;top:534px;width:80px;height:32px" text="Warning" @click="onMsgWarn" />
    <vc-button style="left:286px;top:534px;width:80px;height:32px" text="Info" @click="onMsgInfo" />
    <vc-button style="left:16px;top:576px;width:100px;height:32px" text="Tooltip" @click="onTip" />
    <vc-button style="left:126px;top:576px;width:100px;height:32px" text="Popconfirm" @click="onConfirm" />

    <!-- P4 数据展示 -->
    <vc-title style="left:16px;top:608px;width:668px;height:24px" text="P4 — Data Display" />
    <vc-tag style="left:16px;top:636px" text="Tag" type="primary" />
    <vc-tag style="left:70px;top:636px" text="Success" type="success" />
    <vc-tag style="left:136px;top:636px" text="Warning" type="warning" />
    <vc-tag style="left:202px;top:636px" text="Danger" type="danger" />
    <vc-badge style="left:268px;top:636px" :value="badgeVal" />
    <vc-avatar style="left:310px;top:636px" text="JD" />
    <vc-avatar style="left:350px;top:636px" text="UX" type="square" />
    <vc-progress style="left:400px;top:636px;width:120px;height:20px" :percentage="progressVal" />
    <vc-empty style="left:530px;top:636px" text="No Data" />
    <vc-skeleton style="left:600px;top:636px" :rows="2" />

    <!-- P5 高级表单 -->
    <vc-title style="left:16px;top:660px;width:668px;height:24px" text="P5 — Advanced Form" />
    <vc-datepicker style="left:16px;top:688px;width:240px;height:36px" :modelValue="dateVal" @change="onDateChange" />
    <vc-timepicker style="left:266px;top:688px;width:200px;height:36px" :modelValue="timeVal" @change="onTimeChange" />
    <vc-rate style="left:476px;top:688px" :modelValue="rateVal" :count="5" @change="onRateChange" />
    <vc-colorpicker style="left:576px;top:688px;width:100px;height:36px" :modelValue="colorVal" @change="onColorChange" />

    <!-- P6 复杂组件 -->
    <vc-title style="left:16px;top:730px;width:668px;height:24px" text="P6 — Complex Components" />
    <vc-tree style="left:16px;top:758px;width:280px;height:160px" :data="treeData" :showCheckbox="showCheck" />
    <vc-calendar style="left:310px;top:758px;width:340px;height:320px" />
    <vc-timeline style="left:664px;top:758px;width:160px;height:160px" :data="timelineData" />

    <!-- P6 扩展组件 -->
    <vc-title style="left:16px;top:1090px;width:668px;height:24px" text="P6 — Extended" />
    <vc-carousel style="left:16px;top:1118px;width:320px;height:200px" :data="carouselData" />
    <vc-transfer style="left:350px;top:1118px;width:320px;height:200px" :data="transferData" />
    <vc-image style="left:684px;top:1118px;width:160px;height:120px" placeholder="No Image" />

    <!-- P6 图表 -->
    <vc-title style="left:16px;top:1330px;width:668px;height:24px" text="P6 — Charts" />
    <vc-chartline style="left:16px;top:1358px;width:320px;height:240px" :data="chartLineData" />
    <vc-chartbar style="left:350px;top:1358px;width:320px;height:240px" :data="chartBarData" />

    <!-- 状态栏 -->
    <div style="left:16px;top:1610px;width:668px;height:20px" class="state-bar">
      <span style="left:4px;top:2px;font-size:12px;color:#67C23A">VC-UI All Layers: P0-P6 Complete ({{ totalComponents }} components)</span>
    </div>

    <!-- P4 Table Modal -->
    <vc-modal v-if="showTable === '1'" style="left:0px;top:0px" :visible="showTable" title="User List" text="" width="440" height="300">
      <vc-table style="left:0px;top:0px" :data="tableData" :columns="tableCols" :currentPage="curPage" :pageSize="pgSize" />
    </vc-modal>

    <!-- P3 浮层实例 -->
    <vc-modal v-if="showModal === '1'" style="left:0px;top:0px" :visible="showModal" title="Confirm" text="Proceed with this action?" width="380" height="200" />
    <vc-drawer v-if="showDrawer === '1'" style="left:0px;top:0px" :visible="showDrawer" title="Drawer" text="Drawer content from P3 overlay components." width="280" placement="right" />
    <vc-message v-if="msgTxt !== ''" style="left:250px;top:100px" :text="msgTxt" :type="msgTp" />
    <vc-notification v-if="notifTxt !== ''" style="right:16px;top:60px" :title="notifTitle" :text="notifTxt" />
    <vc-tooltip v-if="tipVis === '1'" style="left:500px;top:80px" content="Tooltip text here!" placement="top" />
    <vc-popconfirm v-if="confirmVis === '1'" style="left:350px;top:576px" text="Delete this item?" @confirm="onDoConfirm" @cancel="onDoCancel" />
  </div>
</template>

<script lang="php">

    // P2 state
    public string $inputVal = '';
    public string $switchVal = '';
    public string $sliderVal = '50';
    public string $selectVal = 'sh';
    public string $numberVal = '10';
    public string $selectOpts = '[{"label":"Beijing","value":"bj"},{"label":"Shanghai","value":"sh"},{"label":"Guangzhou","value":"gz"}]';

    // P3 state
    public string $showModal = '';
    public string $showDrawer = '';
    public string $msgTxt = '';
    public string $msgTp = 'success';
    public string $notifTxt = '';
    public string $notifTitle = 'Notification';
    public string $tipVis = '';
    public string $confirmVis = '';

    // P4 state
    public string $showTable = '';
    public string $badgeVal = '5';
    public string $progressVal = '70';
    public string $tableData = '[{"name":"Alice","age":28,"status":"active"},{"name":"Bob","age":35,"status":"active"},{"name":"Carol","age":42,"status":"inactive"},{"name":"David","age":31,"status":"active"}]';
    public string $tableCols = '[{"key":"name","label":"Name"},{"key":"age","label":"Age"},{"key":"status","label":"Status"}]';
    public string $curPage = '1';
    public string $pgSize = '10';

    // P5 state
    public string $dateVal = '';
    public string $timeVal = '';
    public string $rateVal = '3';
    public string $colorVal = '#409EFF';

    // P6 state
    public string $treeData = '[{"label":"Root","value":"root","children":[{"label":"Node 1","value":"n1"},{"label":"Node 2","value":"n2","children":[{"label":"Node 2.1","value":"n21"}]}]}]';
    public string $showCheck = '1';
    public string $timelineData = '[{"timestamp":"2025-01-01","content":"Initial release","type":"success"},{"timestamp":"2025-03-15","content":"Feature update"},{"timestamp":"2025-06-20","content":"Bug fix","type":"warning"}]';
    public string $carouselData = '[{"text":"Slide 1","bg":"#409EFF"},{"text":"Slide 2","bg":"#67C23A"},{"text":"Slide 3","bg":"#F56C6C"}]';
    public string $transferData = '[{"label":"Option 1","value":"1"},{"label":"Option 2","value":"2"},{"label":"Option 3","value":"3"},{"label":"Option 4","value":"4"}]';
    public string $transferVal = '[]';
    public string $chartLineData = '[120,200,150,180,220,190,260]';
    public string $chartBarData = '[30,45,60,35,80,55,40]';
    public string $totalComponents = '58';

    // P2 handlers
    public function onInput(string $v): void { $this->inputVal = $v; }
    public function onSwitch(string $v): void { $this->switchVal = $v; }
    public function onSlider(string $v): void { $this->sliderVal = $v; }
    public function onSelect(string $v): void { $this->selectVal = $v; }
    public function onNumber(string $v): void { $this->numberVal = $v; }

    // P3 handlers
    public function onBtn(): void {}
    public function onModal(): void { $this->showModal = '1'; }
    public function onDrawer(): void { $this->showDrawer = '1'; }
    public function onNotif(): void { $this->notifTitle = 'New Message'; $this->notifTxt = 'You have 3 unread notifications.'; }
    public function onMsgOk(): void { $this->msgTxt = 'Operation completed!'; $this->msgTp = 'success'; }
    public function onMsgErr(): void { $this->msgTxt = 'Error occurred!'; $this->msgTp = 'error'; }
    public function onMsgWarn(): void { $this->msgTxt = 'Check your input.'; $this->msgTp = 'warning'; }
    public function onMsgInfo(): void { $this->msgTxt = 'System running OK.'; $this->msgTp = 'info'; }
    public function onTip(): void { $this->tipVis = '1'; }
    public function onConfirm(): void { $this->confirmVis = '1'; }
    public function onDoConfirm(): void { $this->confirmVis = ''; $this->msgTxt = 'Confirmed!'; $this->msgTp = 'success'; }
    public function onDoCancel(): void { $this->confirmVis = ''; }
    public function onShowTable(): void { $this->showTable = '1'; }
    public function onDateChange(string $v): void { $this->dateVal = $v; }
    public function onTimeChange(string $v): void { $this->timeVal = $v; }
    public function onRateChange(string $v): void { $this->rateVal = $v; }
    public function onColorChange(string $v): void { $this->colorVal = $v; }
</script>

<style>
.bg { background: #1A1A2E; }
.col-box { background: #409EFF; color: #FFFFFF; font-size: 12px; }
.state-bar { background: #16213E; }
</style>