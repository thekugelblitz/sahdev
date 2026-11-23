import { confirmPayment as applyPayment } from './payment-ledger';
import { z } from 'zod';
import { defaultConfig, initialPlans,type Plan,type PlatformConfig } from '../src/lib/commercial';
import { HTTPError } from './support';
export type BillingEnv={BILLING_NAMESPACE?:string;PLATFORM_ADMIN_SUBS?:string;CREDENTIAL_ENCRYPTION_KEY?:string;STRIPE_SECRET_KEY?:string;STRIPE_WEBHOOK_SECRET?:string;RAZORPAY_KEY_ID?:string;RAZORPAY_KEY_SECRET?:string;RAZORPAY_WEBHOOK_SECRET?:string;PAYPAL_CLIENT_ID?:string;PAYPAL_CLIENT_SECRET?:string;PAYPAL_WEBHOOK_ID?:string;PAYPAL_ENV?:string};
export const planSchema=z.object({id:z.string().max(100),key:z.string().regex(/^[a-z][a-z0-9-]{1,30}$/),version:z.number().int().positive(),name:z.string().trim().min(2).max(60),description:z.string().max(300),currency:z.enum(['USD','INR']),priceMinor:z.number().int().min(0).max(10000000),intervalDays:z.number().int().min(1).max(366),published:z.boolean(),limits:z.object({seats:z.number().int().min(1).max(1000),conversations:z.number().int().min(1).max(100000),sources:z.number().int().min(0).max(10000),storageMB:z.number().int().min(0).max(1000000),requestsPerMinute:z.number().int().min(1).max(10000),concurrency:z.number().int().min(1).max(1000)}).strict(),features:z.object({byok:z.boolean(),memory:z.boolean(),databaseRead:z.boolean(),databaseWrite:z.boolean(),customBranding:z.boolean()}).strict(),includedCreditMicros:z.number().int().min(0).max(1000000000)}).strict();
const model=z.object({id:z.string().min(1).max(80),name:z.string().min(1).max(80),provider:z.enum(['openai','anthropic','gemini','openrouter','custom']),model:z.string().min(1).max(150),currency:z.enum(['USD','INR']),inputMicrosPerMillion:z.number().int().min(0).max(1e9),outputMicrosPerMillion:z.number().int().min(0).max(1e9),cachedInputMicrosPerMillion:z.number().int().min(0).max(1e9),actionMicros:z.number().int().min(0).max(1e8),maxOutputTokens:z.number().int().min(1).max(32768),enabled:z.boolean(),credentialRef:z.string().regex(/^[A-Z][A-Z0-9_]{0,100}$/)}).strict();
const promotion=z.object({code:z.string().regex(/^[A-Z0-9_-]{3,30}$/),percentOff:z.number().int().min(1).max(100),expiresAt:z.iso.datetime(),maxRedemptions:z.number().int().min(1).max(100000),planKeys:z.array(z.string()).max(20),active:z.boolean()}).strict();
export const platformSchema=z.object({models:z.array(model).max(100),promos:z.array(promotion).max(100),graceDays:z.number().int().min(0).max(30),billingLabel:z.string().min(2).max(100),supportEmail:z.union([z.email(),z.literal('')]),gateways:z.object({stripe:z.boolean(),razorpay:z.boolean(),paypal:z.boolean()}).strict()}).strict();
export class Billing {
 constructor(public db:D1Database){}
 async initialize(){await this.db.batch([this.db.prepare('INSERT OR IGNORE INTO platform_config (id,data,version) VALUES (?, ?, 1)').bind('main',JSON.stringify(defaultConfig())),...initialPlans().map(p=>this.db.prepare('INSERT OR IGNORE INTO plan_versions (id,data,created_at) VALUES (?,?,?)').bind(p.id,JSON.stringify(p),new Date().toISOString()))]);}
 async config():Promise<{data:PlatformConfig;version:number}>{const row=await this.db.prepare('SELECT data,version FROM platform_config WHERE id=?').bind('main').first<{data:string;version:number}>();if(!row){await this.initialize();return this.config();}return {data:JSON.parse(row.data) as PlatformConfig,version:row.version};}
 async plans():Promise<Plan[]>{const rows=await this.db.prepare('SELECT data FROM plan_versions ORDER BY created_at,id').all<{data:string}>();if(!rows.results.length){await this.initialize();return this.plans();}return rows.results.map(r=>JSON.parse(r.data) as Plan);}
 async plan(id:string){return (await this.plans()).find(p=>p.id===id);}
 async entitlement(workspaceId:string){await this.db.prepare('INSERT OR IGNORE INTO subscriptions (workspace_id,plan_id,state,period_end) VALUES (?,?,?,?)').bind(workspaceId,'free_v1','active',new Date(Date.now()+30*86400000).toISOString()).run();let sub=await this.db.prepare('SELECT * FROM subscriptions WHERE workspace_id=?').bind(workspaceId).first<any>();const conf=(await this.config()).data;
  if(Date.parse(sub.period_end)<Date.now()&&sub.state!=='suspended'){
   if(sub.pending_plan_id){const target=await this.plan(sub.pending_plan_id);if(target&&target.priceMinor===0){await this.db.prepare("UPDATE subscriptions SET plan_id=?,state='active',pending_plan_id=NULL,period_end=? WHERE workspace_id=? AND pending_plan_id=?").bind(target.id,new Date(Date.now()+target.intervalDays*86400000).toISOString(),workspaceId,target.id).run();}}
   sub=await this.db.prepare('SELECT * FROM subscriptions WHERE workspace_id=?').bind(workspaceId).first<any>();const p=await this.plan(sub.plan_id);
   if(p&&p.priceMinor>0&&Date.parse(sub.period_end)+conf.graceDays*86400000<Date.now())await this.db.prepare("UPDATE subscriptions SET state='suspended',suspension_reason='Payment required' WHERE workspace_id=? AND state!='suspended'").bind(workspaceId).run();
   else if(p&&p.priceMinor>0&&Date.parse(sub.period_end)<Date.now())await this.db.prepare("UPDATE subscriptions SET state='past_due' WHERE workspace_id=? AND state='active'").bind(workspaceId).run();
   sub=await this.db.prepare('SELECT * FROM subscriptions WHERE workspace_id=?').bind(workspaceId).first<any>();
  }
  return {subscription:{...sub,billing_state:sub.state,state:sub.admin_hold||sub.recovery_hold?'suspended':sub.state,suspension_reason:sub.admin_hold||sub.recovery_hold||sub.suspension_reason},plan:(await this.plan(sub.plan_id))!};
 }
 async summary(workspaceId:string){const entitlement=await this.entitlement(workspaceId);const invoices=await this.db.prepare('SELECT * FROM invoices WHERE workspace_id=? ORDER BY created_at DESC LIMIT 100').bind(workspaceId).all();const wallets=await this.db.prepare('SELECT currency,balance,reserved FROM wallets WHERE workspace_id=?').bind(workspaceId).all();const ledger=await this.db.prepare('SELECT * FROM ledger WHERE workspace_id=? ORDER BY created_at DESC LIMIT 100').bind(workspaceId).all();return {...entitlement,invoices:invoices.results,wallets:wallets.results,ledger:ledger.results};}
 async createInvoice(workspaceId:string,input:{planId?:string;amountMinor?:number;currency?:string;promoCode?:string}){
  await this.entitlement(workspaceId);
  let amount:number,currency:string,purpose:string,planId:string|null=null,promoCode:string|null=null,promoLimit=0;
  if(input.planId){const plan=await this.plan(input.planId);if(!plan?.published)throw new HTTPError(400,'Choose a published plan.');if(plan.priceMinor===0)throw new HTTPError(400,'Use the free-plan change action.');amount=plan.priceMinor;currency=plan.currency;purpose='plan';planId=plan.id;
   if(input.promoCode){const promo=(await this.config()).data.promos.find(p=>p.code===input.promoCode?.toUpperCase()&&p.active&&Date.parse(p.expiresAt)>Date.now()&&(!p.planKeys.length||p.planKeys.includes(plan.key)));if(!promo)throw new HTTPError(400,'This promotion is unavailable.');const count=await this.db.prepare("SELECT COUNT(*) as n FROM invoices WHERE promo_code=? AND state IN ('open','paid')").bind(promo.code).first<{n:number}>();if((count?.n||0)>=promo.maxRedemptions)throw new HTTPError(409,'This promotion has reached its limit.');amount=Math.ceil(amount*(100-promo.percentOff)/100);promoCode=promo.code;promoLimit=promo.maxRedemptions;}
  }else{amount=input.amountMinor||0;currency=input.currency||'USD';purpose='credits';if(!Number.isSafeInteger(amount)||amount<100||amount>1000000||!['USD','INR'].includes(currency))throw new HTTPError(400,'Choose a credit amount between 100 and 1,000,000 minor currency units.');}
  if(amount===0)throw new HTTPError(400,'A zero-total promotion requires an explicit promotional grant. It cannot create a payable invoice.');
  const invoice={id:'inv_'+crypto.randomUUID(),workspace_id:workspaceId,plan_id:planId,purpose,amount,currency,state:'open',created_at:new Date().toISOString(),due_at:new Date(Date.now()+7*86400000).toISOString(),promo_code:promoCode};
  const statements=[];
  statements.push(this.db.prepare("INSERT INTO invoices (id,workspace_id,plan_id,purpose,amount,currency,state,created_at,due_at,promo_code,transition) SELECT ?,?,?,?,?,?,?,?,?,?,billing_revision+1 FROM subscriptions WHERE workspace_id=? AND (? IS NULL OR (SELECT COUNT(*) FROM invoices WHERE promo_code=? AND state IN ('open','paid','review'))<?)").bind(invoice.id,workspaceId,planId,purpose,amount,currency,'open',invoice.created_at,invoice.due_at,promoCode,workspaceId,promoCode,promoCode,promoLimit));
  if(purpose==='plan') statements.push(this.db.prepare('UPDATE subscriptions SET billing_revision=billing_revision+1 WHERE workspace_id=? AND EXISTS(SELECT 1 FROM invoices WHERE id=?)').bind(workspaceId,invoice.id));
  const results=await this.db.batch(statements);
  if(results[0].meta.changes!==1)throw new HTTPError(409,'This promotion has reached its limit.');
  return invoice;
 }
 async confirmPayment(invoiceId:string,gateway:string,paymentId:string,amount:number,currency:string,namespace='test:local'){return applyPayment(this,invoiceId,gateway,paymentId,amount,currency,namespace);}

 async reserve(workspace:string,currency:string,id:string,amount:number){
  if(!Number.isSafeInteger(amount)||amount<0)throw new HTTPError(400,'Invalid reservation.');
  const wallet=workspace+':'+currency;const now=new Date().toISOString();
  await this.db.batch([
   this.db.prepare('UPDATE wallets SET reserved=reserved WHERE id=?').bind(wallet),
   this.db.prepare("INSERT OR IGNORE INTO reservations (id,workspace_id,currency,amount,state,created_at) SELECT ?,?,?,?,'new',? FROM wallets WHERE id=? AND balance-reserved>=?").bind(id,workspace,currency,amount,now,wallet,amount),
   this.db.prepare("UPDATE wallets SET reserved=reserved+? WHERE id=? AND EXISTS(SELECT 1 FROM reservations WHERE id=? AND state='new' AND workspace_id=? AND amount=?)").bind(amount,wallet,id,workspace,amount),
   this.db.prepare("UPDATE reservations SET state='reserved' WHERE id=? AND workspace_id=? AND state='new'").bind(id,workspace)
  ]);const row=await this.db.prepare('SELECT * FROM reservations WHERE id=? AND workspace_id=?').bind(id,workspace).first<any>();if(!row)throw new HTTPError(402,'Add prepaid credits before using a hosted model.');if(row.amount!==amount||row.currency!==currency)throw new HTTPError(409,'Idempotency key was reused with different usage.');return row;
 }
 async settle(workspace:string,id:string,actual:number){const r=await this.db.prepare('SELECT * FROM reservations WHERE id=? AND workspace_id=?').bind(id,workspace).first<any>();if(!r)throw new HTTPError(404,'Reservation not found.');if(!Number.isSafeInteger(actual)||actual<0||actual>r.amount)throw new HTTPError(409,'Actual usage exceeds the reserved maximum.');if(r.state==='settled'){if(r.settled!==actual)throw new HTTPError(409,'Usage was already settled differently.');return;}
  await this.db.batch([
   this.db.prepare('UPDATE reservations SET state=state WHERE id=?').bind(id),
   this.db.prepare("UPDATE wallets SET balance=balance-?,reserved=reserved-? WHERE id=? AND EXISTS(SELECT 1 FROM reservations WHERE id=? AND state='reserved')").bind(actual,r.amount,workspace+':'+r.currency,id),
   this.db.prepare("INSERT OR IGNORE INTO ledger (id,workspace_id,currency,kind,amount,applied,reference,created_at) SELECT ?,?,?,?,-?,1,?,? WHERE EXISTS(SELECT 1 FROM reservations WHERE id=? AND state='reserved')").bind('usage:'+id,workspace,r.currency,'usage',actual,id,new Date().toISOString(),id),
   this.db.prepare("UPDATE reservations SET state='settled',settled=? WHERE id=? AND workspace_id=? AND state='reserved'").bind(actual,id,workspace)
  ]);
  const settled=await this.db.prepare('SELECT state,settled FROM reservations WHERE id=? AND workspace_id=?').bind(id,workspace).first<any>();
  if(settled?.state!=='settled'||settled.settled!==actual)throw new HTTPError(409,'Usage was already settled differently.');
 }
}
