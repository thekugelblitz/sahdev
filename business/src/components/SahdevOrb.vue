<script setup lang="ts">defineProps<{size?:number;palette?:string;motion?:boolean}>();</script>
<template><span class="sahdev-orb" :style="{'--orb-size':(size||42)+'px'}" :data-palette="palette||'aurora'" :data-motion="motion!==false" aria-hidden="true"></span></template>
