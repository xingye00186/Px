<template>
  <div class="list">
    <div v-for="item in items" :key="item.id" :style="'bg:' + themeBg">
      <span>{{ item.label }}</span>
      <span>{{ globalTitle }}</span>
    </div>
  </div>
</template>
