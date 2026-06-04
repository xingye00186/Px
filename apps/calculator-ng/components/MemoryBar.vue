<template>
  <div style="width:318px;height:36px;display:grid;grid-template-columns:repeat(5,62px);grid-template-rows:repeat(1,36px);gap:2px">
    <button style="grid-row:1;grid-column:1" class="btn-func" @click="mc">MC</button>
    <button style="grid-row:1;grid-column:2" class="btn-func" @click="mr">MR</button>
    <button style="grid-row:1;grid-column:3" class="btn-func" @click="mPlus">M+</button>
    <button style="grid-row:1;grid-column:4" class="btn-func" @click="mMinus">M−</button>
    <button style="grid-row:1;grid-column:5" class="btn-func" @click="ms">MS</button>
  </div>
</template>

<style>
.btn-func { background: #505050; color: #FFFFFF; font-size: 13px; }
</style>
