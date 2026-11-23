import type {Identity} from './auth';

/** Only called by the self-hosted adapter. Never follow redirects with a session cookie. */
export async function cloudIdentity(request:Request):Promise<Identity|null>{
  const cookie=request.headers.get('cookie');
  if(!cookie)return null;
  const response=await fetch('http://sahdev-business-gateway/business/session',{
    headers:{cookie,accept:'application/json'},redirect:'manual',signal:AbortSignal.timeout(5000)
  });
  if(!response.ok)return null;
  const data=await response.json() as Record<string,unknown>;
  if(typeof data.subject!=='string'||!/^cloud:[a-f0-9-]{36}:[a-f0-9-]{36}$/i.test(data.subject))return null;
  return {subject:data.subject,name:String(data.name||'Team member').slice(0,100),demo:false};
}
