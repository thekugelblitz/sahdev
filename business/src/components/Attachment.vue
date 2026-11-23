<script setup lang="ts">
import Icon from './Icon.vue';import {attachmentRequest} from '../lib/api';import type {Attachment} from '../lib/model';
const props=defineProps<{file:Attachment;conversation:string;workspace:string;portal?:boolean}>();const emit=defineEmits<{error:[message:string]}>();
async function download(){try{const r=await attachmentRequest(`/conversations/${props.conversation}/attachments/${props.file.id}`,{workspace:props.workspace,portal:props.portal});const url=URL.createObjectURL(await r.blob());const a=document.createElement('a');a.href=url;a.download=props.file.name;a.click();URL.revokeObjectURL(url);}catch(e:any){emit('error',e.message);}}
</script>
<template><button class="attachment" @click="download"><Icon name="file" :size="17"/><span>{{file.name}}<small>{{Math.ceil(file.size/1024)}} KB · Download</small></span><Icon name="download" :size="15"/></button></template>
