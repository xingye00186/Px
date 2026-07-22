<template>
  <button :style="'background:' + bgColor + ';padding:8px 16px;color:' + textColor" @click="click">
    {{ label }}
  </button>
</template>
