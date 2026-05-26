<template>
  <div style="width:328px;height:340px">
    <!-- 按钮网格 4x5 — 使用 CSS Grid 布局 -->
    <div style="display:grid;grid-template-columns:repeat(4,80px);grid-template-rows:repeat(5,60px);left:0px;top:0px;gap:2px">
      <button style="grid-row:1;grid-column:1" class="btn-func" @click="reset">C</button>
      <button style="grid-row:1;grid-column:2" class="btn-func" @click="backspace"><-</button>
      <button style="grid-row:1;grid-column:3" class="btn-op"   @click="handleButton('/')">/</button>
      <button style="grid-row:1;grid-column:4" class="btn-op"   @click="handleButton('*')">*</button>

      <button style="grid-row:2;grid-column:1" class="btn-num"  @click="handleButton('7')">7</button>
      <button style="grid-row:2;grid-column:2" class="btn-num"  @click="handleButton('8')">8</button>
      <button style="grid-row:2;grid-column:3" class="btn-num"  @click="handleButton('9')">9</button>
      <button style="grid-row:2;grid-column:4" class="btn-op"   @click="handleButton('-')">-</button>

      <button style="grid-row:3;grid-column:1" class="btn-num"  @click="handleButton('4')">4</button>
      <button style="grid-row:3;grid-column:2" class="btn-num"  @click="handleButton('5')">5</button>
      <button style="grid-row:3;grid-column:3" class="btn-num"  @click="handleButton('6')">6</button>
      <button style="grid-row:3;grid-column:4" class="btn-op"   @click="handleButton('+')">+</button>

      <button style="grid-row:4;grid-column:1" class="btn-num"  @click="handleButton('1')">1</button>
      <button style="grid-row:4;grid-column:2" class="btn-num"  @click="handleButton('2')">2</button>
      <button style="grid-row:4;grid-column:3" class="btn-num"  @click="handleButton('3')">3</button>
      <button style="grid-row:4;grid-column:4" class="btn-eq"   @click="calculate">=</button>

      <button style="grid-row:5;grid-column:1" class="btn-num"  @click="handleButton('0')">0</button>
      <button style="grid-row:5;grid-column:2" class="btn-num"  @click="handleButton('.')">.</button>
    </div>
  </div>
</template>

<style>
.btn-num  { background: #323232; color: #ffffff; }
.btn-op   { background: #ff9500; color: #ffffff; }
.btn-eq   { background: #007aff; color: #ffffff; }
.btn-func { background: #505050; color: #ffffff; }
</style>
