export type Role = 'owner' | 'admin' | 'agent' | 'viewer' | 'client';
export type Attachment = {id:string;name:string;type:string;size:number};
export type Message = { id: string; text: string; author: string; kind: 'customer' | 'reply' | 'note'; at: string; clientRequestId?:string; attachment?:Attachment };
export type Conversation = { id: string; subject: string; contactId: string; status: 'open' | 'pending' | 'resolved'; priority: 'low' | 'normal' | 'high' | 'urgent'; channel: 'chat' | 'email' | 'portal'; assignee: string; createdAt: string; updatedAt: string; messages: Message[]; tags: string[] };
export type Contact = { id: string; name: string; email: string; company: string; plan: string; color: string };
export type Article = { id: string; title: string; body: string; category: string; audience: 'public' | 'internal'; status: 'published' | 'draft'; updatedAt: string };
export type Member = { id: string; name: string; email: string; role: Role; status: 'active' | 'sample' };
export type Audit = { id: string; actor: string; actorId?:string; action: string; detail: string; at: string };
export type Workspace = {
  id: string; name: string; sample: boolean; members: Member[]; contacts: Contact[];
  conversations: Conversation[]; articles: Article[]; audit: Audit[];
  settings: { replyName: string; welcome: string; accent: string; officeHours: string; monthlyBudget: number; requireApproval: boolean; autoAssign: boolean; slaMinutes: number; aiMode: 'disabled' };
  intelligence?:import('./intelligence').Intelligence;
};
export type Session = { subject: string; name: string; role: Role; contactId?: string; workspaceId: string; demo: boolean };
export type Bootstrap = { workspace: Workspace; session: Session; workspaces: { id: string; name: string }[]; version: number;platformAdmin?:boolean;entitlement?:{plan:import('./commercial').Plan;subscription:{state:string;period_end:string}} };
