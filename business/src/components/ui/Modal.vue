<script setup lang="ts">
import { DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogTitle, DialogDescription, DialogClose } from 'reka-ui';
import Icon from '../Icon.vue';
defineProps<{open:boolean;title:string;description?:string}>();
defineEmits<{'update:open':[value:boolean]}>();
</script>
<template><DialogRoot :open="open" @update:open="$emit('update:open',$event)"><DialogPortal><DialogOverlay class="modal-overlay"/><DialogContent class="modal-content"><div class="flex items-start justify-between gap-4"><div><DialogTitle class="modal-title">{{ title }}</DialogTitle><DialogDescription class="muted mt-2">{{ description||'Changes are saved to your current workspace.' }}</DialogDescription></div><DialogClose class="icon-button" aria-label="Close dialog"><Icon name="x"/></DialogClose></div><slot/></DialogContent></DialogPortal></DialogRoot></template>
