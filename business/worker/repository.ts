import type { Workspace } from '../src/lib/model';
export interface Repository {
  get(id:string):Promise<{data:Workspace;version:number}|null>;
  save(id:string,data:Workspace,version:number):Promise<boolean>;
  create(data:Workspace,subject:string):Promise<void>;
  memberships(subject:string):Promise<{workspaceId:string;role:string;contactId?:string}[]>;
  attach(workspaceId:string,subject:string,role:string,contactId?:string):Promise<void>;
}
export class D1Repository implements Repository {
  constructor(private db:D1Database){}
  async get(id:string):Promise<{data:Workspace;version:number}|null> {const row=await this.db.prepare('SELECT data, version FROM workspaces WHERE id = ?').bind(id).first<{data:string;version:number}>();return row?{data:JSON.parse(row.data),version:row.version}:null;}
  async save(id:string,data:Workspace,version:number) {const result=await this.db.prepare('UPDATE workspaces SET data = ?, version = version + 1 WHERE id = ? AND version = ?').bind(JSON.stringify(data),id,version).run();return result.meta.changes===1;}
  async create(data:Workspace,subject:string){await this.db.batch([this.db.prepare('INSERT OR IGNORE INTO workspaces (id,data,version) VALUES (?,?,1)').bind(data.id,JSON.stringify(data)),this.db.prepare('INSERT OR IGNORE INTO memberships (id,subject,workspace_id,role) VALUES (?,?,?,?)').bind(subject+'|'+data.id,subject,data.id,'owner')]);}
  async memberships(subject:string){const r=await this.db.prepare('SELECT workspace_id, role, contact_id FROM memberships WHERE subject = ?').bind(subject).all<{workspace_id:string;role:string;contact_id:string}>();return r.results.map(x=>({workspaceId:x.workspace_id,role:x.role,contactId:x.contact_id||undefined}));}
  async attach(workspaceId:string,subject:string,role:string,contactId?:string){await this.db.prepare('INSERT INTO memberships (id,subject,workspace_id,role,contact_id) VALUES (?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET role=excluded.role, contact_id=excluded.contact_id').bind(subject+'|'+workspaceId,subject,workspaceId,role,contactId||null).run();}
}
export class MemoryRepository implements Repository {
  rows=new Map<string,{data:Workspace;version:number}>(); members=new Map<string,{workspaceId:string;role:string;contactId?:string}[]>();
  async get(id:string){const x=this.rows.get(id);return x?structuredClone(x):null;}
  async save(id:string,data:Workspace,version:number){const x=this.rows.get(id);if(!x||x.version!==version)return false;this.rows.set(id,{data:structuredClone(data),version:version+1});return true;}
  async create(data:Workspace,subject:string){if(!this.rows.has(data.id))this.rows.set(data.id,{data:structuredClone(data),version:1});const m=this.members.get(subject)||[];if(!m.some(x=>x.workspaceId===data.id))m.push({workspaceId:data.id,role:'owner'});this.members.set(subject,m);}
  async memberships(subject:string){return this.members.get(subject)||[];}
  async attach(workspaceId:string,subject:string,role:string,contactId?:string){const m=(this.members.get(subject)||[]).filter(x=>x.workspaceId!==workspaceId);m.push({workspaceId,role,contactId});this.members.set(subject,m);}
}
