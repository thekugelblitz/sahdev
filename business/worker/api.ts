import { z } from 'zod';
import type { Role, Session } from '../src/lib/model';
import type { Identity } from './auth';
import type { Repository } from './repository';
import { makeWorkspace } from './sample';
import { readUpload, type BlobStore } from './attachments';
import { Billing,type BillingEnv } from './billing';
import { masterAPI,billingAPI,enforceEntitlement,isPlatformAdmin } from './commercial-api';
import { defaultIntelligence } from '../src/lib/intelligence';
import {normalizeWidget} from '../src/lib/widget';
import { updateIntelligence,collectResolvedMemory } from './intelligence';
import {sealSecret}from'./vault';
import { HTTPError, mutate, suggest, visibleWorkspace, audit } from './support';
const json=(value:unknown,status=200)=>new Response(JSON.stringify(value),{status,headers:{'Content-Type':'application/json','Cache-Control':'no-store','X-Content-Type-Options':'nosniff'}});
export async function handleAPI(request:Request,repo:Repository,principal:Identity|null,blobs?:BlobStore,commercial?:{billing:Billing;env:BillingEnv}):Promise<Response>{
  try{
    if(!principal)throw new HTTPError(401,'Sign in to open your workspace.');
    const url=new URL(request.url);const route=url.pathname.slice(4).replace(/\/$/,'');
    if(!['GET','HEAD'].includes(request.method)){
      const origin=request.headers.get('origin');if((origin||principal.subject.startsWith('cloud:'))&&origin!==url.origin)throw new HTTPError(403,'Cross-origin writes are not allowed.');
      if(!request.headers.get('content-type')?.startsWith('application/json')&&!/^\/conversations\/[^/]+\/attachments$/.test(route))throw new HTTPError(415,'Use application/json.');
    }
    let memberships=await repo.memberships(principal.subject);
    if(route.startsWith('/master')){if(!commercial)throw new HTTPError(503,'Platform billing storage is not configured.');return json(await masterAPI(request,commercial.billing,commercial.env,principal));}
    if(!memberships.length){
      const hash=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(principal.subject));
      const id='ws_'+Array.from(new Uint8Array(hash)).map(x=>x.toString(16).padStart(2,'0')).join('').slice(0,24);
      const sample=principal.subject.startsWith('sites:')||principal.demo;
      await repo.create(makeWorkspace(id,sample?'Acme Studio':'My workspace',principal.subject,sample),principal.subject);memberships=await repo.memberships(principal.subject);
    }
    if(route==='/workspaces'&&request.method==='POST'){
      if(memberships.length>=10)throw new HTTPError(409,'Workspace limit reached.');
      const b=z.object({name:z.string().trim().min(2).max(60)}).strict().parse(await readBody(request));
      const data=makeWorkspace('ws_'+crypto.randomUUID(),b.name,principal.subject,false);await repo.create(data,principal.subject);return json({id:data.id},201);
    }
    const workspaceId=request.headers.get('x-workspace-id')||memberships[0]?.workspaceId;
    const membership=memberships.find(m=>m.workspaceId===workspaceId);
    if(!membership)throw new HTTPError(403,'You are not a member of this workspace.');
    const stored=await repo.get(membership.workspaceId);if(!stored)throw new HTTPError(404,'Workspace not found.');
    stored.data.intelligence??=defaultIntelligence();
    stored.data.intelligence.widget=normalizeWidget(stored.data.intelligence.widget);
    const role=stored.data.members.find(m=>m.id===principal.subject)?.role;
    if(!role)throw new HTTPError(403,'Your membership is not active in this workspace.');
    if(!['owner','admin','agent','viewer','client'].includes(role))throw new HTTPError(403,'Unknown workspace role.');
    const session:Session={subject:principal.subject,name:stored.data.members.find(m=>m.id===principal.subject)?.name||principal.name,role:role as Role,workspaceId:stored.data.id,contactId:membership.contactId,demo:stored.data.sample};
    // A sample customer preview can narrow owner permissions; it cannot elevate them.
    if(request.headers.get('x-portal-preview')==='true'&&stored.data.sample&&['owner','admin'].includes(session.role)){session.role='client';session.contactId='c1';session.name='Olivia Rhye';}
    if(route==='/bootstrap'&&request.method==='GET'){
      const workspaces=await Promise.all(memberships.map(async m=>({id:m.workspaceId,name:(await repo.get(m.workspaceId))?.data.name||'Workspace'})));
      const entitlement=commercial?await commercial.billing.entitlement(stored.data.id):null;
      return json({workspace:visibleWorkspace(stored.data,session),version:stored.version,session,workspaces,platformAdmin:commercial?isPlatformAdmin(principal,commercial.env):false,entitlement});
    }
    if(route.startsWith('/billing')){if(!commercial)throw new HTTPError(503,'Billing is not configured.');return json(await billingAPI(request,commercial.billing,commercial.env,session));}
    if(route==='/intelligence/widget/installations'&&request.method==='GET'){
      if(!['owner','admin','agent','viewer'].includes(session.role))throw new HTTPError(403,'Staff access required.');
      const rows=commercial?await commercial.billing.db.prepare('SELECT origin,last_seen_at,last_message_at FROM widget_installations WHERE workspace_id=?').bind(stored.data.id).all():{results:[]};
      return json({installations:rows.results});
    }
    if(!['GET','HEAD'].includes(request.method)&&commercial)await enforceEntitlement(commercial.billing,stored.data,route,request.method);
    if(route==='/suggest'&&request.method==='GET')return json(suggest(stored.data,session,(url.searchParams.get('q')||'').slice(0,2000)));
    const attachmentRoute=route.match(/^\/conversations\/([^/]+)\/attachments(?:\/([^/]+))?$/);
    if(attachmentRoute){
      if(!blobs)throw new HTTPError(503,'File storage is not configured.');
      const c=stored.data.conversations.find(c=>c.id===attachmentRoute[1]&&(session.role!=='client'||c.contactId===session.contactId));if(!c)throw new HTTPError(404,'Conversation not found.');
      if(request.method==='GET'&&attachmentRoute[2]){
        const message=c.messages.find(m=>m.attachment?.id===attachmentRoute[2]&&(session.role!=='client'||m.kind!=='note'));if(!message?.attachment)throw new HTTPError(404,'Attachment not found.');
        const file=await blobs.get(`${stored.data.id}/${c.id}/${message.attachment.id}`);if(!file)throw new HTTPError(404,'Attachment not found.');
        return new Response(file.data as BodyInit,{headers:{'Content-Type':file.type,'Content-Disposition':`attachment; filename*=UTF-8''${encodeURIComponent(message.attachment.name)}`,'X-Content-Type-Options':'nosniff','Cache-Control':'private, no-store','Content-Security-Policy':"default-src 'none'; sandbox"}});
      }
      if(request.method==='POST'&&!attachmentRoute[2]){
        if(!['owner','admin','agent','client'].includes(session.role))throw new HTTPError(403,'Your role cannot attach files.');
        if(request.headers.get('if-match')!==String(stored.version))throw new HTTPError(409,'The conversation changed. Refresh and attach again.');
        const kind=request.headers.get('x-message-kind')==='note'?'note':'reply';if(session.role==='client'&&kind==='note')throw new HTTPError(403,'Clients cannot create private notes.');
        if(c.messages.filter(m=>m.attachment).length>=100)throw new HTTPError(409,'Attachment limit reached for this conversation.');
        const file=await readUpload(request),id=crypto.randomUUID(),key=`${stored.data.id}/${c.id}/${id}`;
        if(commercial&&!stored.data.sample){const limits=(await commercial.billing.entitlement(stored.data.id)).plan.limits;const used=stored.data.conversations.reduce((n,c)=>n+c.messages.reduce((sum,m)=>sum+(m.attachment?.size||0),0),0);if(used+file.size>limits.storageMB*1024*1024)throw new HTTPError(402,'Your attachment storage allowance is full.');}
        await blobs.put(key,file.data,file.type);
        c.messages.push({id:crypto.randomUUID(),text:`Shared ${file.name}`,author:session.name,kind:session.role==='client'?'customer':kind,at:new Date().toISOString(),attachment:{id,name:file.name,type:file.type,size:file.size}});c.updatedAt=new Date().toISOString();if(session.role==='client')c.status='open';audit(stored.data,session,'attachment.added',file.name);
        if(!await repo.save(stored.data.id,stored.data,stored.version)){await blobs.remove(key);throw new HTTPError(409,'The conversation changed. Please attach again.');}return json({version:stored.version+1});
      }
      throw new HTTPError(405,'Method not allowed.');
    }
    if(route==='/ai/respond')throw new HTTPError(503,'Live AI is disabled. Connect and validate a provider before enabling automatic replies.');
    if(route==='/export'&&request.method==='GET'){
      if(!['owner','admin'].includes(session.role))throw new HTTPError(403,'Only administrators can export workspace data.');
      return json({exportedAt:new Date().toISOString(),workspace:stored.data});
    }
    if(!['POST','PATCH','DELETE'].includes(request.method))throw new HTTPError(404,'Endpoint not found.');
    if(request.headers.get('if-match')!==String(stored.version))throw new HTTPError(409,'This workspace has changed. Refresh and try again.');
    if(route.startsWith('/intelligence')){
      const keyRoute=route.match(/^\/intelligence\/providers\/([^/]+)\/key$/);
      if(keyRoute&&request.method==='POST'){
        if(!['owner','admin'].includes(session.role))throw new HTTPError(403,'Administrator access required.');
        if(!commercial)throw new HTTPError(503,'Credential storage is not configured.');
        const provider=stored.data.intelligence.providers.find(p=>p.id===keyRoute[1]);if(!provider)throw new HTTPError(404,'Provider connection not found.');
        const b=z.object({key:z.string().min(8).max(5000)}).strict().parse(await readBody(request));
        const scope=stored.data.id+'/'+provider.id;const encrypted=await sealSecret(b.key,commercial.env.CREDENTIAL_ENCRYPTION_KEY||'',scope);
        const plan=(await commercial.billing.entitlement(stored.data.id)).plan;if(!stored.data.sample&&!plan.features.byok)throw new HTTPError(402,'BYOK requires an eligible plan.');
        provider.credentialSet=true;audit(stored.data,session,'credential.saved','Encrypted provider credential updated');
        const saved=await commercial.billing.db.batch([
          commercial.billing.db.prepare('INSERT INTO credential_vault (id,workspace_id,data) SELECT ?,?,? WHERE EXISTS(SELECT 1 FROM workspaces WHERE id=? AND version=?) ON CONFLICT(id) DO UPDATE SET data=excluded.data').bind(scope,stored.data.id,JSON.stringify(encrypted),stored.data.id,stored.version),
          commercial.billing.db.prepare('UPDATE workspaces SET data=?,version=version+1 WHERE id=? AND version=?').bind(JSON.stringify(stored.data),stored.data.id,stored.version)
        ]);
        if(saved[1].meta.changes!==1)throw new HTTPError(409,'Workspace changed. The credential was not saved.');return json({saved:true,version:stored.version+1});
      }else{
        const features=commercial?(await commercial.billing.entitlement(stored.data.id)).plan.features:{byok:false,memory:false,customBranding:false};
        updateIntelligence(stored.data,session,route,request.method,await readBody(request),features);
      }
      if(!await repo.save(stored.data.id,stored.data,stored.version))throw new HTTPError(409,'Workspace changed. Refresh and retry.');return json({saved:true,version:stored.version+1});
    }
    if(route==='/members'&&request.method==='POST'){
      if(session.role!=='owner')throw new HTTPError(403,'Only workspace owners can grant access.');
      const b=z.object({subject:z.string().regex(/^(auth0\||cloud:|sites:)[^\s]{1,230}$/),name:z.string().trim().min(2).max(100),email:z.email(),role:z.enum(['admin','agent','viewer','client']),contactId:z.string().max(100).default('')}).strict().parse(await readBody(request));
      if(principal.subject.startsWith('cloud:')&&(!b.subject.startsWith(principal.subject.split(':').slice(0,2).join(':')+':')))throw new HTTPError(403,'Choose a user in this organization.');
      if(stored.data.members.some(m=>m.id===b.subject))throw new HTTPError(409,'This identity is already a workspace member.');
      if(b.role==='client'&&!stored.data.contacts.some(c=>c.id===b.contactId))throw new HTTPError(400,'Select the customer profile this client owns.');
      stored.data.members.push({id:b.subject,name:b.name,email:b.email,role:b.role,status:'active'});audit(stored.data,session,'member.added',`${b.name}: ${b.role}`);
      // Index first. An index without an active member grants no access (checked above).
      await repo.attach(stored.data.id,b.subject,b.role,b.role==='client'?b.contactId:undefined);
      if(!await repo.save(stored.data.id,stored.data,stored.version))throw new HTTPError(409,'The workspace changed. Refresh and retry the grant.');
      return json({version:stored.version+1});
    }
    const result=mutate(stored.data,session,route,request.method,await readBody(request));
    if(route.startsWith('/conversations/')&&request.method==='PATCH'&&(stored.data.sample||commercial&&(await commercial.billing.entitlement(stored.data.id)).plan.features.memory))collectResolvedMemory(stored.data,route.split('/')[2]);
    if(!await repo.save(stored.data.id,stored.data,stored.version))throw new HTTPError(409,'Another teammate just made a change. Refresh and try again.');
    const safeResult=session.role==='client'&&result&&typeof result==='object'&&'messages' in result?{...result,messages:(result as {messages:{kind:string}[]}).messages.filter(m=>m.kind!=='note')}:result;
    return json({result:safeResult,version:stored.version+1});
  }catch(error){
    if(error instanceof HTTPError)return json({error:error.message},error.status);
    if(error instanceof z.ZodError)return json({error:error.issues.map(x=>`${x.path.join('.')}: ${x.message}`).join('; ')},400);
    if(error instanceof SyntaxError)return json({error:'Invalid JSON.'},400);
    console.error('api_failure',error instanceof Error?error.name:'unknown');return json({error:'The request could not be completed. Please try again.'},500);
  }
}
async function readBody(request:Request){const content=await request.text();if(content.length>32768)throw new HTTPError(413,'Request exceeds 32 KB.');return JSON.parse(content||'{}');}
