import {widgetSchema} from './widget-settings';
import {z}from'zod';import{defaultIntelligence}from'../src/lib/intelligence';import type{Workspace,Session}from'../src/lib/model';import{HTTPError,audit,requireRole}from'./support';
const connection=z.object({id:z.string().min(1).max(100),name:z.string().min(1).max(100),provider:z.enum(['openai','anthropic','gemini','openrouter']),model:z.string().min(1).max(150),tasks:z.array(z.enum(['reply','summary','classification','embedding'])).max(4),credentialSet:z.boolean()}).strict();
const schema=z.object({assistantName:z.string().trim().min(1).max(60),instructions:z.string().max(8000),tone:z.enum(['concise','warm','formal']),mode:z.enum(['hosted','byok']),modelOfferId:z.string().max(100),providerId:z.string().max(100),providers:z.array(connection).max(10),prompts:z.array(z.object({id:z.string().max(100),name:z.string().min(1).max(100),task:z.string().max(60),text:z.string().min(1).max(4000)}).strict()).max(30),memory:z.object({collect:z.boolean(),includeProfile:z.boolean(),includeTimeline:z.boolean(),retentionDays:z.number().int().min(1).max(365),profileTokenLimit:z.literal(200),timelineTokenLimit:z.literal(1000)}).strict(),widget:widgetSchema}).strict();
export function updateIntelligence(w:Workspace,s:Session,route:string,method:string,body:any,features:{byok:boolean;memory:boolean;customBranding:boolean}){
 requireRole(s,['owner','admin']);w.intelligence??=defaultIntelligence();const intel=w.intelligence;
 if(route==='/intelligence/widget'&&method==='PATCH'){
  const widget=widgetSchema.parse(body);
  if(!w.sample&&!widget.showBranding&&!features.customBranding)throw new HTTPError(402,'Removing Sahdev branding requires an eligible plan.');
  intel.widget=widget;audit(w,s,'widget.updated','Chat widget appearance, behavior and installation settings updated');return widget;
 }
 if(route==='/intelligence'&&method==='PATCH'){
  const v=schema.parse(body);if(!w.sample){if(v.mode==='byok'&&!features.byok)throw new HTTPError(402,'BYOK requires an eligible plan.');if((v.memory.collect||v.memory.includeProfile||v.memory.includeTimeline)&&!features.memory)throw new HTTPError(402,'Customer memory requires an eligible plan.');if(!v.widget.showBranding&&!features.customBranding)throw new HTTPError(402,'Custom branding requires an eligible plan.');}
  // Secret-present flags are derived on the server; the browser cannot assert them.
  v.providers=v.providers.map(p=>({...p,credentialSet:intel.providers.find(old=>old.id===p.id)?.credentialSet||false}));Object.assign(intel,v);audit(w,s,'intelligence.updated','Assistant, prompt, memory and widget preferences updated');return intel;
 }
 if(route==='/intelligence/sources'&&method==='POST'){
  const b=z.object({name:z.string().trim().min(2).max(100),kind:z.enum(['website','document','database']),location:z.string().max(2000),audience:z.enum(['public','internal']),content:z.string().max(8000).optional()}).strict().parse(body);
  if(b.kind==='website'){const u=new URL(b.location);if(u.protocol!=='https:'||u.username||u.password)throw new HTTPError(400,'Use an HTTPS website URL without credentials.');}
  const id=crypto.randomUUID();let articleId:string|undefined;
  if(b.kind==='document'){if(!b.content?.trim())throw new HTTPError(400,'Paste the text to import.');articleId=crypto.randomUUID();w.articles.unshift({id:articleId,title:b.name,body:b.content,category:'Imported knowledge',audience:b.audience,status:'draft',updatedAt:new Date().toISOString()});}
  intel.sources.push({id,name:b.name,kind:b.kind,location:b.location,audience:b.audience,status:'registered',articleId});audit(w,s,'source.registered',b.name);return {id,articleId,notice:b.kind==='document'?'Imported as a draft. Review and publish in Knowledge.':'Source registered. No remote data has been fetched.'};
 }
 if(route.startsWith('/intelligence/brain/')&&method==='PATCH'){
  const id=route.slice('/intelligence/brain/'.length);const b=z.object({summary:z.string().max(4000),reviewed:z.boolean()}).strict().parse(body);const brain=intel.brains.find(x=>x.contactId===id);if(!brain)throw new HTTPError(404,'Client memory not found.');brain.summary=b.summary;brain.reviewed=b.reviewed;brain.updatedAt=new Date().toISOString();brain.profileUpdatedAt=brain.updatedAt;audit(w,s,'memory.reviewed','Customer profile reviewed');return brain;
 }
 if(route.startsWith('/intelligence/brain/')&&method==='DELETE'){const id=route.slice('/intelligence/brain/'.length);intel.brains=intel.brains.filter(x=>x.contactId!==id);audit(w,s,'memory.deleted','Customer memory erased');return {deleted:true};}
 throw new HTTPError(404,'Intelligence action not found.');
}
export function collectResolvedMemory(w:Workspace,conversationId:string){
 const intel=w.intelligence;if(!intel?.memory.collect)return;const c=w.conversations.find(c=>c.id===conversationId);if(!c||c.status!=='resolved')return;
 const customerText=c.messages.filter(m=>m.kind==='customer').map(m=>m.text).join(' ').slice(0,2000);let brain=intel.brains.find(b=>b.contactId===c.contactId);
 if(!brain){brain={contactId:c.contactId,summary:'',events:[],updatedAt:new Date().toISOString(),reviewed:false};intel.brains.push(brain);}
 const cutoff=Date.now()-intel.memory.retentionDays*86400000;brain.events=brain.events.filter(e=>Date.parse(e.at)>cutoff&&e.conversationId!==c.id);brain.events.unshift({id:crypto.randomUUID(),at:new Date().toISOString(),conversationId:c.id,title:c.subject,text:customerText,method:'extractive-draft',audience:'internal'});brain.events=brain.events.slice(0,30);brain.updatedAt=new Date().toISOString();
 // A deterministic transcript extract is never presented as an AI-written profile.
}
