<script setup lang="ts">
import {ref} from 'vue';import Icon from './Icon.vue';import {attachmentRequest} from '../lib/api';
const props=defineProps<{conversation:string;workspace:string;version:number;portal?:boolean;kind?:string}>();const emit=defineEmits<{uploaded:[];error:[message:string]}>();const uploading=ref(false);
async function upload(e:Event){const input=e.target as HTMLInputElement,file=input.files?.[0];if(!file)return;if(file.size>5*1024*1024){emit('error','Files must be 5 MB or smaller.');input.value='';return;}uploading.value=true;try{await attachmentRequest(`/conversations/${props.conversation}/attachments`,{...props,file});emit('uploaded');}catch(e:any){emit('error',e.message);}finally{uploading.value=false;input.value='';}}
</script>
<template><label class="upload-control" :title="uploading?'Uploading…':'Attach PNG, JPEG, PDF or text (max 5 MB)'"><Icon name="plus" :size="15"/><span>{{uploading?'Uploading…':'Attach'}}</span><input type="file" accept=".png,.jpg,.jpeg,.pdf,.txt" :disabled="uploading" @change="upload" aria-label="Attach a file"/></label></template>
