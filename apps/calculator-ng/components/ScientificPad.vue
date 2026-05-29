<template>
  <div style="left:0px;top:0px;width:318px;height:112px;display:grid;grid-template-columns:repeat(5,62px);grid-template-rows:repeat(3,36px);gap:2px">
    <!-- Row 1: trig + log -->
    <button style="grid-row:1;grid-column:1" class="btn-func" @click="sin">sin</button>
    <button style="grid-row:1;grid-column:2" class="btn-func" @click="cos">cos</button>
    <button style="grid-row:1;grid-column:3" class="btn-func" @click="tan">tan</button>
    <button style="grid-row:1;grid-column:4" class="btn-func" @click="log">log</button>
    <button style="grid-row:1;grid-column:5" class="btn-func" @click="ln">ln</button>
    <!-- Row 2: power + root -->
    <button style="grid-row:2;grid-column:1" class="btn-func" @click="x2">x²</button>
    <button style="grid-row:2;grid-column:2" class="btn-func" @click="x3">x³</button>
    <button style="grid-row:2;grid-column:3" class="btn-func" @click="sqrt">√x</button>
    <button style="grid-row:2;grid-column:4" class="btn-func" @click="inv">1/x</button>
    <button style="grid-row:2;grid-column:5" class="btn-func" @click="pi">π</button>
    <!-- Row 3: constants + edit -->
    <button style="grid-row:3;grid-column:1" class="btn-func" @click="euler">e</button>
    <button style="grid-row:3;grid-column:2" class="btn-func" @click="openParen">(</button>
    <button style="grid-row:3;grid-column:3" class="btn-func" @click="closeParen">)</button>
    <button style="grid-row:3;grid-column:4" class="btn-func" @click="backspace">⌫</button>
    <button style="grid-row:3;grid-column:5" class="btn-func" @click="clear">C</button>
  </div>
</template>

<style>
.btn-func { background: #505050; color: #FFFFFF; font-size: 13px; }
</style>
