import type { Bootstrap } from './model';
import { createAuth0Client, type Auth0Client } from '@auth0/auth0-spa-js';
let auth:Auth0Client|undefined;let ready=false;
export let configuration:{authMode:string;domain?:string;clientId?:string;audience?:string;organization?:string}={authMode:'loading'};
export async function initializeAuth(){
  if(ready)return;configuration=await fetch('/api/config').then(r=>r.json());
  if(configuration.authMode==='auth0'&&configuration.domain&&configuration.clientId){
    auth=await createAuth0Client({domain:configuration.domain,clientId:configuration.clientId,cacheLocation:'memory',authorizationParams:{audience:configuration.audience,organization:configuration.organization,redirect_uri:window.location.origin+'/app/'}});
    if(location.search.includes('code=')&&location.search.includes('state=')){await auth.handleRedirectCallback();history.replaceState({},'',location.pathname);}
  }ready=true;
}
export async function login(){await initializeAuth();if(configuration.authMode==='cloud')location.assign('/business/open');else if(auth)await auth.loginWithRedirect();else if(configuration.authMode==='sites')location.assign('/signin-with-chatgpt?return_to='+encodeURIComponent(location.pathname));else throw new Error('Configure your Auth0 application to sign in.');}
export async function logout(){if(configuration.authMode==='cloud'){location.assign('/business/signout');return;}if(auth)await auth.logout({logoutParams:{returnTo:location.origin}});else if(configuration.authMode==='sites')location.assign('/signout-with-chatgpt?return_to=/');else location.assign('/');}
export async function api<T=any>(path:string,options:{method?:string;body?:unknown;workspace?:string;version?:number;portal?:boolean}={}):Promise<T>{
  await initializeAuth();const headers:Record<string,string>={};
  if(options.workspace)headers['X-Workspace-Id']=options.workspace;
  if(options.version!==undefined)headers['If-Match']=String(options.version);
  if(options.portal)headers['X-Portal-Preview']='true';
  if(options.body!==undefined)headers['Content-Type']='application/json';
  if(auth&&await auth.isAuthenticated())headers.Authorization='Bearer '+await auth.getTokenSilently();
  const response=await fetch('/api'+path,{method:options.method||'GET',headers,body:options.body!==undefined?JSON.stringify(options.body):undefined});
  const data:any=await response.json();if(!response.ok){const error=new Error(data.error||'Request failed') as Error&{status:number};error.status=response.status;throw error;}return data as T;
}
export async function bootstrap(workspace?:string,portal=false):Promise<Bootstrap>{return api('/bootstrap',{workspace,portal});}
export async function attachmentRequest(path:string,options:{workspace:string;version?:number;portal?:boolean;file?:File;kind?:string}){
 await initializeAuth();const headers:Record<string,string>={'X-Workspace-Id':options.workspace};if(options.portal)headers['X-Portal-Preview']='true';if(options.version!==undefined)headers['If-Match']=String(options.version);if(auth&&await auth.isAuthenticated())headers.Authorization='Bearer '+await auth.getTokenSilently();
 if(options.file){headers['Content-Type']=options.file.type||'application/octet-stream';headers['X-File-Name']=encodeURIComponent(options.file.name);headers['X-Message-Kind']=options.kind||'reply';}
 const response=await fetch('/api'+path,{method:options.file?'POST':'GET',headers,body:options.file});if(!response.ok){const data:any=await response.json();throw new Error(data.error||'File request failed');}return response;
}
export const initials=(name:string)=>name.split(' ').slice(0,2).map(s=>s[0]).join('').toUpperCase();
export const relative=(date:string)=>{const minutes=Math.max(0,Math.floor((Date.now()-Date.parse(date))/60000));return minutes<1?'Just now':minutes<60?`${minutes}m`:minutes<1440?`${Math.floor(minutes/60)}h`:`${Math.floor(minutes/1440)}d`;};
