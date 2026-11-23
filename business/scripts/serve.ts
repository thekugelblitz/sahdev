import {createServer} from 'node:http';import path from 'node:path';
import {mkdir,readFile,writeFile,unlink} from 'node:fs/promises';
import {sqliteD1} from './sqlite-d1';
// The built Worker contains the same domain modules and frontend as Sites.
// This adapter changes storage/transport only; there is no second billing implementation.
const {default:worker}=await import('../dist/server/index.js');
const cloud=process.env.SAHDEV_AUTH_MODE==='cloud';
const required=cloud?['SAHDEV_ORIGIN']:['AUTH0_DOMAIN','AUTH0_AUDIENCE','AUTH0_CLIENT_ID','AUTH0_ORGANIZATION','SAHDEV_ORIGIN'];
if(required.some(k=>!process.env[k]))throw new Error('Standalone hosting requires: '+required.join(', '));
const origin=new URL(process.env.SAHDEV_ORIGIN!);
if(origin.protocol!=='https:'&&!['localhost','127.0.0.1'].includes(origin.hostname))throw new Error('Use an HTTPS origin for standalone hosting.');
const dataRoot=path.resolve(process.env.SAHDEV_DATA_DIR||'.data/standalone');await mkdir(dataRoot,{recursive:true});
const {db}=sqliteD1(path.join(dataRoot,'business.db'));
function filePath(key:string){const resolved=path.resolve(dataRoot,'files',key);if(!resolved.startsWith(path.join(dataRoot,'files')+path.sep))throw new Error('Invalid storage key');return resolved;}
const bucket={async put(key:string,bytes:Uint8Array,options:any){const file=filePath(key);await mkdir(path.dirname(file),{recursive:true});await writeFile(file,bytes);await writeFile(file+'.meta',JSON.stringify(options.httpMetadata));},async get(key:string){try{const bytes=await readFile(filePath(key)),httpMetadata=JSON.parse(await readFile(filePath(key)+'.meta','utf8'));return{httpMetadata,arrayBuffer:async()=>bytes.buffer.slice(bytes.byteOffset,bytes.byteOffset+bytes.byteLength)};}catch{return null;}},async delete(key:string){await Promise.all([unlink(filePath(key)).catch(()=>{}),unlink(filePath(key)+'.meta').catch(()=>{})]);}};
const env={...process.env,AUTH_MODE:cloud?'cloud':'auth0',DB:db,BUCKET:bucket};
const server=createServer(async(req,res)=>{try{
 const headers=new Headers();for(const[k,v]of Object.entries(req.headers)){if(k.startsWith('oai-')||k.startsWith('x-forwarded-')||k==='host'||k==='connection'||k==='transfer-encoding')continue;if(v)headers.set(k,Array.isArray(v)?v.join(', '):v);}
 const parts:Buffer[]=[];let size=0;for await(const chunk of req){size+=chunk.length;if(size>5*1024*1024){res.writeHead(413).end();return;}parts.push(chunk);}
 const request=new Request(new URL(req.url||'/',origin),{method:req.method,headers,...(!['GET','HEAD'].includes(req.method||'GET')?{body:Buffer.concat(parts)}:{})});
 const response=await worker.fetch(request,env);res.writeHead(response.status,Object.fromEntries(response.headers));res.end(Buffer.from(await response.arrayBuffer()));
 }catch{res.writeHead(500,{'Content-Type':'application/json'}).end(JSON.stringify({error:'Request could not be completed.'}));}});
server.requestTimeout=30000;server.headersTimeout=10000;server.maxHeadersCount=50;
server.listen(Number(process.env.PORT||8080),process.env.SAHDEV_HOST||'127.0.0.1',()=>console.log('Standalone Sahdev is listening with Auth0 authentication.'));
