<script setup lang="ts">
import { SwitchRoot, SwitchThumb } from 'reka-ui';
defineProps<{modelValue:boolean;label:string;disabled?:boolean}>();defineEmits<{'update:modelValue':[value:boolean]}>();
</script>
<template><SwitchRoot :model-value="modelValue" :disabled="disabled" :aria-label="label" class="switch" @update:model-value="$emit('update:modelValue',$event)"><SwitchThumb class="switch-thumb"/></SwitchRoot></template>
