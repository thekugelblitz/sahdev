import { HTTPError } from './support';
export interface BlobStore {put(key:string,data:Uint8Array,type:string):Promise<void>;get(key:string):Promise<{data:Uint8Array;type:string}|null>;remove(key:string):Promise<void>}
export class R2BlobStore implements BlobStore {
 constructor(private bucket:R2Bucket){}
 async put(key:string,data:Uint8Array,type:string){await this.bucket.put(key,data,{httpMetadata:{contentType:type}});}
 async get(key:string){const obj=await this.bucket.get(key);return obj?{data:new Uint8Array(await obj.arrayBuffer()),type:obj.httpMetadata?.contentType||'application/octet-stream'}:null;}
 async remove(key:string){await this.bucket.delete(key);}
}
export async function readUpload(request:Request){
 const reader=request.body?.getReader();if(!reader)throw new HTTPError(400,'Choose a file.');let size=0;const parts:Uint8Array[]=[];
 while(true){const {done,value}=await reader.read();if(done)break;size+=value.length;if(size>5*1024*1024){await reader.cancel();throw new HTTPError(413,'Files must be 5 MB or smaller.');}parts.push(value);}
 if(size===0)throw new HTTPError(400,'Empty files cannot be attached.');const data=new Uint8Array(size);let offset=0;for(const part of parts){data.set(part,offset);offset+=part.length;}
 let name:string;try{name=decodeURIComponent(request.headers.get('x-file-name')||'attachment');}catch{throw new HTTPError(400,'Invalid filename.');}
 name=name.replace(/[\x00-\x1f\x7f<>:"/\\|?*]/g,'_').slice(0,120);
 const ext=name.split('.').at(-1)?.toLowerCase();let type='';
 if(ext==='png'&&[137,80,78,71,13,10,26,10].every((v,i)=>data[i]===v))type='image/png';
 if(['jpg','jpeg'].includes(ext||'')&&data[0]===255&&data[1]===216&&data[2]===255)type='image/jpeg';
 if(ext==='pdf'&&new TextDecoder().decode(data.slice(0,5))==='%PDF-')type='application/pdf';
 if(ext==='txt'){try{new TextDecoder('utf-8',{fatal:true}).decode(data);if(!data.includes(0))type='text/plain';}catch{}}
 if(!type)throw new HTTPError(415,'Attach a valid PNG, JPEG, PDF, or UTF-8 text file.');return {data,name,type,size};
}
