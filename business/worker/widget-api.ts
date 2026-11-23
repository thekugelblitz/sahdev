import {z} from 'zod';
import {D1Repository} from './repository';
import {Billing} from './billing';
import {HTTPError,mutate,audit} from './support';
import {normalizeWidget} from '../src/lib/widget';
import type {Session,Conversation,Workspace} from '../src/lib/model';
import {enforceEntitlement} from './commercial-api';

const hash=async(value:string)=>Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(value)))).map(x=>x.toString(16).padStart(2,'0')).join('');
const messageSchema=z.object({text:z.string().trim().min(1).max(4000),requestId:z.string().uuid().optional()});
const startSchema=messageSchema.extend({name:z.string().trim().max(80).default(''),email:z.union([z.email().max(200),z.literal('')]).default(''),kind:z.enum(['chat','ticket']).default('chat'),subject:z.string().trim().min(3).max(150).optional()});
// Deliberately no internal tags, assignee identifiers, notes or replay keys in the public projection.
const project=(c:Conversation)=>({id:c.id,contactId:c.contactId,subject:c.subject,status:c.status,channel:c.channel,updatedAt:c.updatedAt,messages:c.messages.filter(m=>m.kind!=='note').map(({id,text,author,kind,at,attachment})=>({id,text,author,kind,at,attachment}))});
async function body(request:Request){const raw=await request.text();if(raw.length>12000)throw new HTTPError(413,'Message too long.');try{return JSON.parse(raw);}catch{throw new HTTPError(400,'Invalid request.');}}
function requestOrigin(request:Request){
 const explicit=request.headers.get('origin');if(explicit)return explicit;
 // Browsers omit Origin on same-origin GET. Never use an arbitrary caller-supplied query origin.
 const own=new URL(request.url).origin;
 if(request.headers.get('sec-fetch-site')==='same-origin')return own;
 try{if(new URL(request.headers.get('referer')||'').origin===own)return own;}catch{}
 return null;
}
async function installation(db:D1Database,workspace:string,origin:string,message=false){const now=new Date().toISOString();await db.prepare('INSERT INTO widget_installations (id,workspace_id,origin,last_seen_at,last_message_at) VALUES (?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET last_seen_at=excluded.last_seen_at,last_message_at=COALESCE(excluded.last_message_at,widget_installations.last_message_at)').bind(workspace+'|'+origin,workspace,origin,now,message?now:null).run();}
function visitorSession(w:Workspace,id:string,contactId:string):Session{return{subject:'visitor:'+id,name:w.contacts.find(c=>c.id===contactId)?.name||'Website visitor',role:'client',contactId,workspaceId:w.id,demo:false};}
export async function widgetAPI(request:Request,db:D1Database){
 const url=new URL(request.url),route=url.pathname.match(/^\/api\/widget\/([^/]+)\/(config|start|thread|messages|knowledge|ticket)$/);if(!route)return new Response('Not found',{status:404});
 const repo=new D1Repository(db),row=await repo.get(route[1]),origin=requestOrigin(request);
 if(!row)return Response.json({error:'Widget unavailable.'},{status:404});
 const widget=normalizeWidget(row.data.intelligence?.widget);
 if(!origin||!widget.allowedOrigins.includes(origin))return Response.json({error:'This website is not enabled. Add its exact origin in Widget Studio → Install.'},{status:403});
 const headers={'Access-Control-Allow-Origin':origin,'Access-Control-Allow-Methods':'GET, POST, OPTIONS','Access-Control-Allow-Headers':'Content-Type, X-Visitor-Token','Vary':'Origin','Cache-Control':'no-store','X-Content-Type-Options':'nosniff'};
 const reply=(value:unknown,status=200)=>Response.json(value,{status,headers});if(request.method==='OPTIONS')return new Response(null,{status:204,headers});
 try{
  const billing=new Billing(db);
  if(route[2]==='config'&&request.method==='GET'){
   const {allowedOrigins,...publicConfig}=widget;
   if(!row.data.sample&&!(await billing.entitlement(row.data.id)).plan.features.customBranding)publicConfig.showBranding=true;
   await installation(db,row.data.id,origin);
   return reply({widget:publicConfig,assistantName:row.data.intelligence?.assistantName||'Sahdev',liveAI:false});
  }
  if(route[2]==='knowledge'&&request.method==='GET'){
   if(!widget.enableKnowledge)throw new HTTPError(404,'Help articles are not enabled.');
   const query=(url.searchParams.get('q')||'').toLowerCase().slice(0,200),id=url.searchParams.get('id');
   const articles=row.data.articles.filter(a=>a.audience==='public'&&a.status==='published');
   if(id){const a=articles.find(a=>a.id===id);if(!a)throw new HTTPError(404,'Article unavailable.');return reply({article:{id:a.id,title:a.title,body:a.body,category:a.category}});}
   return reply({articles:articles.filter(a=>!query||[a.title,a.body,a.category].join(' ').toLowerCase().includes(query)).slice(0,20).map(a=>({id:a.id,title:a.title,category:a.category}))});
  }
  if(route[2]==='start'&&request.method==='POST'){
   const b=startSchema.parse(await body(request));
   const supplied=request.headers.get('x-visitor-token');
   if(supplied&&!/^[a-f0-9-]{72}$/i.test(supplied))throw new HTTPError(400,'Invalid chat session. Start a new conversation.');
   // The embed generates and retains a random 256-bit capability before its first request.
   // A retry can therefore recover a committed conversation after a lost response.
   const token=supplied||crypto.randomUUID()+crypto.randomUUID(),tokenHash=await hash(token);
   const prior=await db.prepare('SELECT * FROM visitor_sessions WHERE id=?').bind(tokenHash).first<any>();
   if(prior){
    if(prior.workspace_id!==row.data.id||prior.origin!==origin||prior.expires_at<=new Date().toISOString())throw new HTTPError(401,'This chat session has expired. Start a new conversation.');
    const c=row.data.conversations.find(c=>c.id===prior.conversation_id&&c.contactId===prior.contact_id);
    if(!c)throw new HTTPError(404,'Conversation unavailable.');
    if(c.messages[0]?.text!==b.text)throw new HTTPError(409,'This session already has a conversation. Resume it before sending another message.');
    return reply({token,conversation:project(c)});
   }
   if(!widget.enabled)throw new HTTPError(503,'New conversations are currently paused.');
   if(widget.nameField==='required'&&!b.name)throw new HTTPError(400,'Please enter your name.');
   if(widget.emailField==='required'&&!b.email)throw new HTTPError(400,'Please enter your email.');
   if(b.kind==='ticket'&&(!widget.enableTickets||!b.name||!b.email||!b.subject))throw new HTTPError(400,'Tickets need a name, email and subject, and must be enabled.');
   await enforceEntitlement(billing,row.data,'/conversations','POST');
   const recent=await db.prepare('SELECT COUNT(*) AS n FROM visitor_sessions WHERE workspace_id=? AND created_at>?').bind(row.data.id,new Date(Date.now()-60000).toISOString()).first<{n:number}>();if((recent?.n||0)>=10)throw new HTTPError(429,'The chat is busy. Please try again in a minute.');
   const contactId=crypto.randomUUID(),name=b.name||'Website visitor';
   row.data.contacts.push({id:contactId,name,email:b.email,company:'Website visitor · unverified',plan:'',color:'lilac'});
   const s=visitorSession(row.data,tokenHash,contactId);
   const c=mutate(row.data,s,'/conversations','POST',{subject:b.subject||b.text.slice(0,100).padEnd(3,'.'),text:b.text,channel:b.kind==='ticket'?'portal':'chat'}) as Conversation;
   c.messages[0].clientRequestId=b.requestId;
   // The session and inbox record commit in one transaction, guarded by the same revision.
   const result=await db.batch([
    db.prepare('INSERT OR IGNORE INTO visitor_sessions (id,workspace_id,contact_id,conversation_id,origin,created_at,expires_at) SELECT ?,?,?,?,?,?,? WHERE EXISTS (SELECT 1 FROM workspaces WHERE id=? AND version=?)').bind(tokenHash,row.data.id,contactId,c.id,origin,new Date().toISOString(),new Date(Date.now()+24*3600000).toISOString(),row.data.id,row.version),
    db.prepare('UPDATE workspaces SET data=?,version=version+1 WHERE id=? AND version=? AND EXISTS (SELECT 1 FROM visitor_sessions WHERE id=? AND conversation_id=?)').bind(JSON.stringify(row.data),row.data.id,row.version,tokenHash,c.id),
   ]);
   if(result[1].meta.changes!==1)throw new HTTPError(409,'The inbox changed. Please retry; your message has not been duplicated.');
   await installation(db,row.data.id,origin,true);
   return reply({token,conversation:project(c)});
  }
  const token=request.headers.get('x-visitor-token');if(!token||token.length>100)throw new HTTPError(401,'Start a new conversation.');
  const visitor=await db.prepare('SELECT * FROM visitor_sessions WHERE id=? AND workspace_id=? AND origin=? AND expires_at>?').bind(await hash(token),row.data.id,origin,new Date().toISOString()).first<any>();if(!visitor)throw new HTTPError(401,'This chat session has expired. Start a new conversation.');
  const c=row.data.conversations.find(c=>c.id===visitor.conversation_id&&c.contactId===visitor.contact_id);if(!c)throw new HTTPError(404,'Conversation unavailable.');
  if(route[2]==='thread'&&request.method==='GET')return reply({conversation:project(c)});
  if(request.method!=='POST')throw new HTTPError(405,'Method not allowed.');
  const s=visitorSession(row.data,visitor.id,visitor.contact_id);
  if(route[2]==='messages'){
   const b=messageSchema.parse(await body(request)),prior=b.requestId?c.messages.find(m=>m.clientRequestId===b.requestId):null;
   if(prior){if(prior.text!==b.text)throw new HTTPError(409,'This retry identifier belongs to a different message.');return reply({conversation:project(c)});}
   await enforceEntitlement(billing,row.data,'/widget','POST');
   mutate(row.data,s,`/conversations/${c.id}/messages`,'POST',{text:b.text,kind:'reply'});c.messages[c.messages.length-1].clientRequestId=b.requestId;
  }else if(route[2]==='ticket'){
   if(!widget.enableTickets)throw new HTTPError(403,'Tickets are not enabled.');
   const b=z.object({name:z.string().trim().min(1).max(80),email:z.email().max(200),subject:z.string().trim().min(3).max(150)}).strict().parse(await body(request));
   if(c.channel==='portal')return reply({conversation:project(c)});
   await enforceEntitlement(billing,row.data,'/widget','POST');
   if(row.data.audit.filter(a=>a.actorId===s.subject&&Date.parse(a.at)>Date.now()-60000).length>=60)throw new HTTPError(429,'Please try again in a minute.');
   const contact=row.data.contacts.find(c=>c.id===visitor.contact_id)!;contact.name=b.name;contact.email=b.email;
   c.channel='portal';c.subject=b.subject;c.status='open';c.updatedAt=new Date().toISOString();audit(row.data,s,'ticket.created','Visitor requested a support ticket');
  }else throw new HTTPError(405,'Method not allowed.');
  if(!await repo.save(row.data.id,row.data,row.version))throw new HTTPError(409,'The conversation changed. Please try sending again.');
  await installation(db,row.data.id,origin,true);
  return reply({conversation:project(c)});
 }catch(e:any){return reply({error:e instanceof z.ZodError?e.issues[0]?.message:e.status?e.message:'Unable to complete this chat request.'},e.status||400);}
}
