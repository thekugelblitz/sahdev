import { createServer } from 'node:http';
import { DatabaseSync } from 'node:sqlite';
import { mkdirSync, readFileSync } from 'node:fs';
import { writeFile,readFile,unlink,mkdir } from 'node:fs/promises';
import path from 'node:path';
import {sqliteD1} from './sqlite-d1';import {Billing} from '../worker/billing';
import{widgetAPI}from'../worker/widget';
import { handleAPI } from '../worker/api';
import { D1Repository } from '../worker/repository';
mkdirSync('.data',{recursive:true});
const {db}=sqliteD1('.data/business.db');const billing=new Billing(db);
const blobs={async put(key:string,data:Uint8Array,type:string){const file=path.join('.data/files',key);await mkdir(path.dirname(file),{recursive:true});await writeFile(file,data);await writeFile(file+'.meta',type);},async get(key:string){try{return {data:await readFile(path.join('.data/files',key)),type:await readFile(path.join('.data/files',key)+'.meta','utf8')};}catch{return null;}},async remove(key:string){await unlink(path.join('.data/files',key));await unlink(path.join('.data/files',key)+'.meta');}};
createServer(async(req,res)=>{try{const buffers=[];for await(const chunk of req){buffers.push(chunk);if(buffers.reduce((n,c)=>n+c.length,0)>5*1024*1024){res.writeHead(413).end();return;}}
  const url='http://127.0.0.1:4321'+req.url;
  if(req.url==='/api/public/plans'){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({plans:(await billing.plans()).filter(p=>p.published),paymentsLive:false}));return;}
  if(req.url==='/api/config'){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({authMode:'local-demo'}));return;}
  if(req.url==='/api/health'){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({status:'ok',runtime:'local-preview',ai:'disabled'}));return;}
  const request=new Request(url,{method:req.method,headers:req.headers as Record<string,string>,body:['GET','HEAD'].includes(req.method||'GET')?undefined:Buffer.concat(buffers)});
  const response=req.url?.startsWith('/api/widget/')?await widgetAPI(request,db):await handleAPI(request,new D1Repository(db),{subject:'local:owner',name:'You',demo:true},blobs,{billing,env:{PLATFORM_ADMIN_SUBS:'local:owner'}});res.writeHead(response.status,Object.fromEntries(response.headers));res.end(Buffer.from(await response.arrayBuffer()));
}catch{res.writeHead(500).end(JSON.stringify({error:'Local preview request failed'}));}}).listen(8080,'127.0.0.1',()=>console.log('Private local sample API: http://127.0.0.1:8080'));
