import { createRemoteJWKSet, jwtVerify } from 'jose';
export type Identity = { subject:string; name:string; demo:boolean; organization?:string };
export type AuthEnvironment = { AUTH0_DOMAIN?:string; AUTH0_AUDIENCE?:string; AUTH0_CLIENT_ID?:string; AUTH0_ORGANIZATION?:string };
const keysets = new Map<string,ReturnType<typeof createRemoteJWKSet>>();
export async function identity(request:Request,env:AuthEnvironment,options={trustSitesHeaders:false}):Promise<Identity|null> {
  const siteUser=options.trustSitesHeaders?request.headers.get('oai-authenticated-user-id'):null;
  // Only the Sites deployment adapter calls this with trusted platform headers.
  if(siteUser)return {subject:'sites:'+siteUser,name:request.headers.get('oai-authenticated-user-email')||'You',demo:false};
  if(!env.AUTH0_DOMAIN||!env.AUTH0_AUDIENCE)return null;
  const bearer=request.headers.get('authorization');
  if(!bearer?.startsWith('Bearer '))return null;
  if(!/^[a-zA-Z0-9.-]+$/.test(env.AUTH0_DOMAIN))return null;
  const issuer=`https://${env.AUTH0_DOMAIN}/`;
  let keys=keysets.get(issuer);if(!keys){keys=createRemoteJWKSet(new URL('.well-known/jwks.json',issuer));keysets.set(issuer,keys);}
  try {const {payload}=await jwtVerify(bearer.slice(7),keys,{issuer,audience:env.AUTH0_AUDIENCE,algorithms:['RS256'],requiredClaims:['sub','exp','iat','org_id']});
    if(!env.AUTH0_ORGANIZATION||payload.org_id!==env.AUTH0_ORGANIZATION)return null;
    return {subject:String(payload.sub),name:typeof payload.name==='string'?payload.name:'You',demo:false,organization:String(payload.org_id)};
  }catch{return null;}
}
