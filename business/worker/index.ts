import {cloudIdentity} from './cloud-identity';
import { identity, type AuthEnvironment } from './auth';
import { handleAPI } from './api';
import { D1Repository } from './repository';
import { R2BlobStore } from './attachments';
import { Billing,type BillingEnv } from './billing';import {webhook} from './payments';
import {widgetAPI}from'./widget';
// Replaced by the build script with the Astro output. No filesystem access at runtime.
import assets from './assets.generated.json';
type Env=AuthEnvironment & BillingEnv & {DB:D1Database;BUCKET:R2Bucket;AUTH_MODE?:string};
export default {async fetch(request:Request,env:Env):Promise<Response>{
  const url=new URL(request.url);
  const billing=new Billing(env.DB);
  if(url.pathname.startsWith('/api/widget/'))return widgetAPI(request,env.DB);
  if(url.pathname==='/api/public/plans')return Response.json({plans:(await billing.plans()).filter(p=>p.published),paymentsLive:false});
  const hook=url.pathname.match(/^\/api\/webhooks\/(stripe|razorpay|paypal)$/);if(hook){try{if(request.method!=='POST')return new Response('Method not allowed',{status:405});return Response.json(await webhook(request,env,billing,hook[1] as 'stripe'|'razorpay'|'paypal'));}catch(e:any){return Response.json({error:e.status?e.message:'Webhook processing failed'},{status:e.status||500});}}
  if(url.pathname==='/api/health')return Response.json({status:'ok',runtime:'sites-worker',ai:'disabled'});
  if(url.pathname==='/api/config')return Response.json({authMode:env.AUTH_MODE==='cloud'?'cloud':env.AUTH_MODE==='auth0'?'auth0':'sites',domain:env.AUTH0_DOMAIN||'',clientId:env.AUTH0_CLIENT_ID||'',audience:env.AUTH0_AUDIENCE||'',organization:env.AUTH0_ORGANIZATION||''});
  if(url.pathname.startsWith('/api/'))return handleAPI(request,new D1Repository(env.DB),env.AUTH_MODE==='cloud'?await cloudIdentity(request):await identity(request,env,{trustSitesHeaders:env.AUTH_MODE!=='auth0'}),env.BUCKET?new R2BlobStore(env.BUCKET):undefined,{billing,env});
  const key=url.pathname.endsWith('/')?url.pathname+'index.html':url.pathname;
  const entry=(assets as Record<string,{body:string;type:string}>)[key]||(assets as Record<string,{body:string;type:string}>)[key+'/index.html'];
  if(!entry)return new Response('Not found',{status:404});
  return new Response(entry.body,{headers:{'Content-Type':entry.type,'X-Content-Type-Options':'nosniff','Referrer-Policy':'strict-origin-when-cross-origin','Permissions-Policy':'camera=(), microphone=(), geolocation=()','Cache-Control':key.includes('/_astro/')?'public, max-age=31536000, immutable':'no-cache'}});
}};
