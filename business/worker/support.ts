import { z } from 'zod';
import type { Session, Workspace } from '../src/lib/model';
export class HTTPError extends Error {constructor(public status:number,message:string){super(message);}}
export function requireRole(session:Session,roles:string[]){if(!roles.includes(session.role))throw new HTTPError(403,'Your role does not allow this action.');}
export function visibleWorkspace(data:Workspace,session:Session):Workspace {
  const result=structuredClone(data);
  if(session.role==='client'){
    result.conversations=result.conversations.filter(x=>x.contactId===session.contactId).map(x=>({...x,messages:x.messages.filter(m=>m.kind!=='note')}));
    result.contacts=result.contacts.filter(x=>x.id===session.contactId);
    result.articles=result.articles.filter(x=>x.audience==='public'&&x.status==='published');result.members=[];result.audit=[];
    delete result.intelligence;
    result.settings={...result.settings,monthlyBudget:0};
  }
  return result;
}
export function audit(w:Workspace,s:Session,action:string,detail:string){w.audit.unshift({id:crypto.randomUUID(),actor:s.name,actorId:s.subject,action,detail,at:new Date().toISOString()});w.audit=w.audit.slice(0,1000);}
const text=z.string().trim().min(1).max(8000);
const article=z.object({title:z.string().trim().min(3).max(150),body:text,category:z.string().trim().min(1).max(60),audience:z.enum(['public','internal']),status:z.enum(['draft','published'])}).strict();
const config=z.object({replyName:z.string().trim().min(1).max(60),welcome:z.string().trim().min(1).max(500),accent:z.string().regex(/^#[0-9a-fA-F]{6}$/),officeHours:z.string().trim().max(100),monthlyBudget:z.number().min(0).max(10000),requireApproval:z.boolean(),autoAssign:z.boolean(),slaMinutes:z.number().int().min(5).max(10080),aiMode:z.literal('disabled')}).strict();
export function mutate(w:Workspace,s:Session,route:string,method:string,body:unknown):unknown {
  const now=new Date().toISOString();
  if(w.audit.filter(x=>x.actorId===s.subject&&Date.parse(x.at)>Date.now()-60000).length>=60)throw new HTTPError(429,'Please wait a minute before making more changes.');
  if(route==='/conversations'&&method==='POST'){
    requireRole(s,['owner','admin','agent','client']);
    const b=z.object({subject:z.string().trim().min(3).max(150),text,contactId:z.string().optional(),priority:z.enum(['low','normal','high','urgent']).default('normal'),channel:z.enum(['chat','portal']).default('portal')}).strict().parse(body);
    const contactId=s.role==='client'?s.contactId:b.contactId;
    const contact=w.contacts.find(x=>x.id===contactId);if(!contact)throw new HTTPError(400,'Choose an existing customer.');
    if(w.conversations.length>=2000)throw new HTTPError(409,'Workspace conversation limit reached. Export and archive before adding more.');
    const conversation={id:crypto.randomUUID(),subject:b.subject,contactId:contact.id,status:'open' as const,priority:b.priority,channel:s.role==='client'?'portal' as const:'chat' as const,assignee:w.settings.autoAssign?w.members.find(x=>x.role==='agent'||x.role==='owner')?.name||'':'',createdAt:now,updatedAt:now,tags:[],messages:[{id:crypto.randomUUID(),text:b.text,author:s.role==='client'?contact.name:s.name,kind:s.role==='client'?'customer' as const:'reply' as const,at:now}]};
    conversation.channel=b.channel;w.conversations.unshift(conversation);audit(w,s,'conversation.created',b.subject);return conversation;
  }
  const match=route.match(/^\/conversations\/([^/]+)(\/messages)?$/);
  if(match){
    const c=w.conversations.find(x=>x.id===match[1]&&(s.role!=='client'||x.contactId===s.contactId));if(!c)throw new HTTPError(404,'Conversation not found.');
    if(match[2]&&method==='POST'){
      requireRole(s,['owner','admin','agent','client']);const b=z.object({text,kind:z.enum(['reply','note']).default('reply')}).strict().parse(body);
      if(s.role==='client'&&b.kind==='note')throw new HTTPError(403,'Internal notes are restricted to the team.');
      if(c.messages.length>=1000)throw new HTTPError(409,'Conversation message limit reached. Please open a new conversation.');
      c.messages.push({id:crypto.randomUUID(),text:b.text,kind:s.role==='client'?'customer':b.kind,author:s.role==='client'?w.contacts.find(x=>x.id===s.contactId)?.name||s.name:s.name,at:now});c.updatedAt=now;
      if(s.role==='client')c.status='open';audit(w,s,b.kind==='note'?'note.created':'message.sent',c.subject);return c;
    }
    if(!match[2]&&method==='PATCH'){
      requireRole(s,['owner','admin','agent']);const b=z.object({status:z.enum(['open','pending','resolved']).optional(),priority:z.enum(['low','normal','high','urgent']).optional(),assignee:z.string().max(100).optional()}).strict().parse(body);
      if(b.assignee&&!w.members.some(m=>m.name===b.assignee&&['owner','admin','agent'].includes(m.role)))throw new HTTPError(400,'Choose a support team member.');
      Object.assign(c,b,{updatedAt:now});audit(w,s,'conversation.updated',`${c.subject}: ${Object.entries(b).map(([k,v])=>`${k} → ${v}`).join(', ')}`);return c;
    }
  }
  if(route==='/contacts'&&method==='POST'){
    requireRole(s,['owner','admin','agent']);const b=z.object({name:z.string().trim().min(2).max(100),email:z.email().max(200),company:z.string().max(100),plan:z.string().max(60)}).strict().parse(body);
    if(w.contacts.some(c=>c.email.toLowerCase()===b.email.toLowerCase()))throw new HTTPError(409,'A customer with that email already exists.');
    const c={...b,id:crypto.randomUUID(),color:'mint'};w.contacts.push(c);audit(w,s,'customer.created',b.name);return c;
  }
  if(route==='/articles'&&method==='POST'){
    requireRole(s,['owner','admin']);const b=article.parse(body);const a={...b,id:crypto.randomUUID(),updatedAt:now};w.articles.unshift(a);audit(w,s,'article.created',a.title);return a;
  }
  if(route.startsWith('/articles/')){
    requireRole(s,['owner','admin']);const id=route.slice(10),index=w.articles.findIndex(a=>a.id===id);if(index<0)throw new HTTPError(404,'Article not found.');
    if(method==='PATCH'){const b=article.parse(body);Object.assign(w.articles[index],b,{updatedAt:now});audit(w,s,'article.updated',b.title);return w.articles[index];}
    if(method==='DELETE'){const [a]=w.articles.splice(index,1);audit(w,s,'article.deleted',a.title);return {deleted:true};}
  }
  if(route==='/settings'&&method==='PATCH'){requireRole(s,['owner','admin']);w.settings=config.parse(body);audit(w,s,'settings.updated','Workspace preferences updated');return w.settings;}
  throw new HTTPError(404,'This action is not available.');
}
export function suggest(w:Workspace,s:Session,query:string){
  const terms=[...new Set(query.toLowerCase().match(/[a-z0-9]{3,}/g)||[])].filter(t=>!['the','and','for','our','you','can','have','with','this','that','from','help','about','how','are','still'].includes(t));
  const articles=w.articles.filter(a=>a.status==='published'&&(s.role!=='client'||a.audience==='public'));
  const matches=articles.map(a=>({article:a,score:terms.reduce((n,t)=>n+(a.title.toLowerCase().includes(t)?3:0)+(a.body.toLowerCase().includes(t)?1:0),0)})).filter(x=>x.score>=2).sort((a,b)=>b.score-a.score).slice(0,3);
  return {mode:'knowledge-search',generated:false,matches:matches.map(x=>({id:x.article.id,title:x.article.title,excerpt:x.article.body,audience:x.article.audience})),notice:'Published knowledge excerpts. No AI model was called. Review before sending.'};
}
