<template>
  <div style="width:318px;height:260px;display:grid;grid-template-columns:repeat(4,1fr);grid-template-rows:repeat(5,50px);gap:2px">
    <!-- Row 1: AC +/- % ÷ -->
    <button style="grid-row:1;grid-column:1" class="btn-func btn-ac" @click="reset">{{ acLabel }}</button>
    <button style="grid-row:1;grid-column:2" class="btn-func" @click="toggleSign">+/−</button>
    <button style="grid-row:1;grid-column:3" class="btn-func" @click="percent">%</button>
    <button style="grid-row:1;grid-column:4" class="btn-operator" @click="inputOperator('÷')">÷</button>
    <!-- Row 2: 7 8 9 × -->
    <button style="grid-row:2;grid-column:1" class="btn-number" @click="inputDigit('7')">7</button>
    <button style="grid-row:2;grid-column:2" class="btn-number" @click="inputDigit('8')">8</button>
    <button style="grid-row:2;grid-column:3" class="btn-number" @click="inputDigit('9')">9</button>
    <button style="grid-row:2;grid-column:4" class="btn-operator" @click="inputOperator('×')">×</button>
    <!-- Row 3: 4 5 6 − -->
    <button style="grid-row:3;grid-column:1" class="btn-number" @click="inputDigit('4')">4</button>
    <button style="grid-row:3;grid-column:2" class="btn-number" @click="inputDigit('5')">5</button>
    <button style="grid-row:3;grid-column:3" class="btn-number" @click="inputDigit('6')">6</button>
    <button style="grid-row:3;grid-column:4" class="btn-operator" @click="inputOperator('−')">−</button>
    <!-- Row 4: 1 2 3 + -->
    <button style="grid-row:4;grid-column:1" class="btn-number" @click="inputDigit('1')">1</button>
    <button style="grid-row:4;grid-column:2" class="btn-number" @click="inputDigit('2')">2</button>
    <button style="grid-row:4;grid-column:3" class="btn-number" @click="inputDigit('3')">3</button>
    <button style="grid-row:4;grid-column:4" class="btn-operator" @click="inputOperator('+')">+</button>
    <!-- Row 5: 0 . = ☰ -->
    <button style="grid-row:5;grid-column:1" class="btn-number" @click="inputDigit('0')">0</button>
    <button style="grid-row:5;grid-column:2" class="btn-number" @click="inputDecimal">.</button>
    <button style="grid-row:5;grid-column:3" class="btn-operator btn-equals" @click="calculate">=</button>
    <button style="grid-row:5;grid-column:4" class="btn-number" @click="toggleHistory">☰</button>
  </div>
</template>

<script lang="php">
    public string $acLabel = 'AC';
</script>

<style>
.btn-number { background: #333336; color: #FFFFFF; font-size: 18px; }
.btn-func { background: #505050; color: #FFFFFF; font-size: 14px; }
.btn-ac { background: #505050; color: #FFFFFF; font-size: 14px; }
.btn-operator { background: #FF9F0A; color: #FFFFFF; font-size: 20px; }
.btn-equals { background: #FF9F0A; color: #FFFFFF; font-size: 22px; }
</style>
