<template>
  <div class="container">
    <template v-if="showDetails">
      <h1 style="font-size:24px">{{ title }}</h1>
      <p style="color:#666">{{ description }}</p>
    </template>
    <template v-else>
      <span>Hidden</span>
    </template>
    <span>Footer</span>
  </div>
</template>
