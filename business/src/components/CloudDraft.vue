<script setup lang="ts">
import {ref,onUnmounted} from 'vue';
import type {Conversation} from '../lib/model';
const props=defineProps<{conversation:Conversation}>();
const emit=defineEmits<{draft:[text:string]}>();
type Choice={id:string;name:string;max_output_tokens:number};
type Options={brands:Choice[];models:Choice[];csrf:string};
type Inference={id:string;state:string;message?:string;result?:{content?:string};charged_credits:number;credit_scale:number};
const options=ref<Options>(),brand=ref(''),model=ref(''),status=ref(''),working=ref(false),opened=ref(false);
let active=true,lastInput='',key='';
onUnmounted(()=>{active=false;});
async function configure(){
 opened.value=!opened.value;if(!opened.value||options.value)return;
 try{const r=await fetch('/business/ai-options',{headers:{Accept:'application/json'}});if(!r.ok)throw Error('Sign in with a brand operator account.');const data=await r.json() as Options;options.value=data;brand.value=data.brands[0]?.id||'';model.value=data.models[0]?.id||'';if(!brand.value||!model.value)status.value='Configure a provider, plan model and provisioned brand in Command Station.';}
 catch(e:any){status.value=e.message;}
}
async function generate(){
 if(working.value||!options.value)return;
 const latest=props.conversation.messages.filter(m=>m.kind==='customer').at(-1);
 if(!latest){status.value='This thread has no customer message.';return;}
 const limit=Math.min(512,options.value.models.find(m=>m.id===model.value)!.max_output_tokens);
 const data={message:'Draft a concise customer-support reply for an agent to review. Customer message:\n'+latest.text.slice(0,8000),model_id:model.value,max_output_tokens:limit};
 const fingerprint=JSON.stringify([brand.value,data]);if(lastInput!==fingerprint){key=crypto.randomUUID();lastInput=fingerprint;}
 working.value=true;status.value='Waiting for the AI worker…';
 try{
  let r=await fetch('/brands/'+brand.value+'/inference',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':options.value.csrf},body:JSON.stringify({...data,idempotency_key:key})});
  let result=await r.json() as Inference;if(!r.ok)throw Error(result.message||'Request declined.');
  for(let i=0;i<80&&['reserved','dispatched'].includes(result.state);i++){await new Promise(resolve=>setTimeout(resolve,1500));if(!active)return;r=await fetch('/inference/'+result.id,{headers:{Accept:'application/json'}});if(!r.ok)throw Error('Unable to read request status.');result=await r.json() as Inference;}
  if(result.state==='settled'&&result.result?.content){emit('draft',result.result.content);status.value='Draft ready for review. Charged '+result.charged_credits/result.credit_scale+' credits.';lastInput='';}
  else{status.value='Request '+result.id+' · '+result.state;if(result.state==='released')lastInput='';}
 }catch(e:any){status.value=e.message;}finally{working.value=false;}
}
</script>
<template><div class="pilot-draft"><button type="button" class="text-action" @click="configure">✧ AI reply draft</button><div v-if="opened"><p>Send the latest customer message to the selected brand’s provider. This uses that brand’s credits.</p><select v-model="brand" aria-label="Funding brand" :disabled="working"><option v-for="b in options?.brands" :value="b.id">{{b.name}}</option></select> <select v-model="model" aria-label="AI model" :disabled="working"><option v-for="m in options?.models" :value="m.id">{{m.name}}</option></select> <button type="button" class="button primary small" :disabled="working||!brand||!model" @click="generate">Generate draft</button><p role="status">{{status}}</p></div></div></template>
<style scoped>.pilot-draft{margin:8px 16px;color:var(--text);font-size:12px}.pilot-draft p{margin:8px 0}.pilot-draft select{max-width:100%;background:var(--input);color:var(--text);border:1px solid var(--border);padding:5px;border-radius:6px}</style>
