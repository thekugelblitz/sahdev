import {bindPaymentNamespace,quarantinePayment} from './payment-ledger';
import type { BillingEnv } from './billing';import { Billing } from './billing';import { HTTPError } from './support';
type Gateway='stripe'|'razorpay'|'paypal';
const enc=new TextEncoder();
async function hmac(secret:string,text:string){const key=await crypto.subtle.importKey('raw',enc.encode(secret),{name:'HMAC',hash:'SHA-256'},false,['sign']);return Array.from(new Uint8Array(await crypto.subtle.sign('HMAC',key,enc.encode(text)))).map(x=>x.toString(16).padStart(2,'0')).join('');}
function safeEqual(a:string,b:string){if(a.length!==b.length)return false;let diff=0;for(let i=0;i<a.length;i++)diff|=a.charCodeAt(i)^b.charCodeAt(i);return diff===0;}
async function provider(url:string,init:RequestInit){const r=await fetch(url,{...init,signal:AbortSignal.timeout(15000)});const data:any=await r.json();if(!r.ok)throw new HTTPError(502,'The payment provider could not complete the request. Check the gateway setup.');return data;}
function paypalBase(env:BillingEnv){return env.PAYPAL_ENV==='live'?'https://api-m.paypal.com':'https://api-m.sandbox.paypal.com';}
async function paypalToken(env:BillingEnv){if(!env.PAYPAL_CLIENT_ID||!env.PAYPAL_CLIENT_SECRET)throw new HTTPError(503,'PayPal is not configured.');return (await provider(paypalBase(env)+'/v1/oauth2/token',{method:'POST',headers:{Authorization:'Basic '+btoa(env.PAYPAL_CLIENT_ID+':'+env.PAYPAL_CLIENT_SECRET),'Content-Type':'application/x-www-form-urlencoded'},body:'grant_type=client_credentials'})).access_token as string;}
export function gatewayStatus(env:BillingEnv){if(!/^(test|live):[A-Za-z0-9_-]{3,80}$/.test(env.BILLING_NAMESPACE||''))return {stripe:false,razorpay:false,paypal:false};return {stripe:!!(env.STRIPE_SECRET_KEY&&env.STRIPE_WEBHOOK_SECRET),razorpay:!!(env.RAZORPAY_KEY_ID&&env.RAZORPAY_KEY_SECRET&&env.RAZORPAY_WEBHOOK_SECRET),paypal:!!(env.PAYPAL_CLIENT_ID&&env.PAYPAL_CLIENT_SECRET&&env.PAYPAL_WEBHOOK_ID)};}
async function createCheckout(billing:Billing,env:BillingEnv,invoiceId:string,workspace:string,gateway:Gateway,origin:string){
 const config=(await billing.config()).data;if(!config.gateways[gateway]||!gatewayStatus(env)[gateway])throw new HTTPError(503,`${gateway} checkout is not connected. Your invoice remains unpaid.`);
 const invoice=await billing.db.prepare("SELECT * FROM invoices WHERE id=? AND workspace_id=? AND state='open'").bind(invoiceId,workspace).first<any>();if(!invoice)throw new HTTPError(404,'Open invoice not found.');
 if(gateway==='stripe'){
  const body=new URLSearchParams({mode:'payment',success_url:origin+'/app/#billing',cancel_url:origin+'/app/#billing',client_reference_id:invoice.id,'metadata[invoice_id]':invoice.id,'payment_intent_data[metadata][invoice_id]':invoice.id,'line_items[0][price_data][currency]':invoice.currency.toLowerCase(),'line_items[0][price_data][unit_amount]':String(invoice.amount),'line_items[0][price_data][product_data][name]':invoice.purpose==='credits'?'Sahdev prepaid AI credits':'Sahdev workspace plan','line_items[0][quantity]':'1'});
  const session=await provider('https://api.stripe.com/v1/checkout/sessions',{method:'POST',headers:{Authorization:'Bearer '+env.STRIPE_SECRET_KEY,'Content-Type':'application/x-www-form-urlencoded','Idempotency-Key':'checkout:'+invoice.id},body});return {gateway,url:session.url,providerId:session.id};
 }
 if(gateway==='razorpay'){
  const order=await provider('https://api.razorpay.com/v1/orders',{method:'POST',headers:{Authorization:'Basic '+btoa(env.RAZORPAY_KEY_ID+':'+env.RAZORPAY_KEY_SECRET),'Content-Type':'application/json'},body:JSON.stringify({amount:invoice.amount,currency:invoice.currency,receipt:invoice.id.replaceAll('-','').slice(0,40),notes:{invoice_id:invoice.id,workspace_id:workspace}})});
  return {gateway,providerId:order.id,orderId:order.id,keyId:env.RAZORPAY_KEY_ID,amount:invoice.amount,currency:invoice.currency,invoiceId:invoice.id};
 }
 const token=await paypalToken(env);const order=await provider(paypalBase(env)+'/v2/checkout/orders',{method:'POST',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json','PayPal-Request-Id':invoice.id},body:JSON.stringify({intent:'CAPTURE',purchase_units:[{reference_id:invoice.id,custom_id:invoice.id,invoice_id:invoice.id,amount:{currency_code:invoice.currency,value:(invoice.amount/100).toFixed(2)}}],payment_source:{paypal:{experience_context:{return_url:origin+'/app/?paypal_return=1#billing',cancel_url:origin+'/app/#billing',user_action:'PAY_NOW'}}}})});
 return {gateway,providerId:order.id,url:order.links.find((l:any)=>l.rel==='payer-action'||l.rel==='approve')?.href};
}
async function paymentNamespace(env:BillingEnv,billing:Billing,gateway:Gateway){
 const namespace=env.BILLING_NAMESPACE||'';await bindPaymentNamespace(billing,namespace);const live=namespace.startsWith('live:');
 if(gateway==='paypal'&&(env.PAYPAL_ENV==='live')!==live||gateway==='stripe'&&env.STRIPE_SECRET_KEY&&env.STRIPE_SECRET_KEY.startsWith('sk_live_')!==live||gateway==='razorpay'&&env.RAZORPAY_KEY_ID&&env.RAZORPAY_KEY_ID.startsWith('rzp_live_')!==live)throw new HTTPError(409,'Gateway credentials do not match this billing environment.');
 return namespace;
}
export async function checkout(billing:Billing,env:BillingEnv,invoiceId:string,workspace:string,gateway:Gateway,origin:string){
 const config=(await billing.config()).data;if(!config.gateways[gateway]||!gatewayStatus(env)[gateway])throw new HTTPError(503,gateway+' checkout is not connected.');
 await paymentNamespace(env,billing,gateway);
 const invoice=await billing.db.prepare("SELECT id FROM invoices WHERE id=? AND workspace_id=? AND state='open' AND due_at>?").bind(invoiceId,workspace,new Date().toISOString()).first();if(!invoice)throw new HTTPError(409,'Invoice is not currently payable.');
 const nonce=crypto.randomUUID();await billing.db.prepare("INSERT OR IGNORE INTO checkout_attempts (invoice_id,gateway,nonce,state,created_at) VALUES (?,?,?,'creating',?)").bind(invoiceId,gateway,nonce,new Date().toISOString()).run();
 const attempt=await billing.db.prepare('SELECT * FROM checkout_attempts WHERE invoice_id=?').bind(invoiceId).first<any>();
 if(attempt.gateway!==gateway)throw new HTTPError(409,'This invoice already has a checkout with another gateway. Finish that checkout or contact billing.');
 if(attempt.nonce!==nonce){if(attempt.state==='ready'&&Date.parse(attempt.created_at)>Date.now()-30*60000)return JSON.parse(attempt.data);throw new HTTPError(409,'This checkout is processing or needs reconciliation. No second charge attempt was created.');}
 try{const result=await createCheckout(billing,env,invoiceId,workspace,gateway,origin);await billing.db.prepare("UPDATE checkout_attempts SET state='ready',data=?,provider_id=? WHERE invoice_id=? AND nonce=?").bind(JSON.stringify(result),result.providerId,invoiceId,nonce).run();return result;}
 catch(e){await billing.db.prepare("UPDATE checkout_attempts SET state='uncertain' WHERE invoice_id=? AND nonce=?").bind(invoiceId,nonce).run();throw e;}
}
export async function capturePayPal(env:BillingEnv,orderId:string,billing:Billing,workspace:string){
 if(!/^[A-Z0-9]{10,40}$/.test(orderId))throw new HTTPError(400,'Invalid PayPal order.');const namespace=await paymentNamespace(env,billing,'paypal');
 const invoice=await billing.db.prepare("SELECT i.* FROM invoices i JOIN checkout_attempts a ON a.invoice_id=i.id WHERE a.provider_id=? AND a.gateway='paypal' AND i.workspace_id=?").bind(orderId,workspace).first<any>();
 if(!invoice)throw new HTTPError(403,'This checkout does not belong to your workspace.');if(invoice.state==='paid')return {status:'COMPLETED'};if(invoice.state!=='open'||Date.parse(invoice.due_at)<Date.now())throw new HTTPError(409,'Invoice is no longer payable.');
 const token=await paypalToken(env);const order=await provider(paypalBase(env)+'/v2/checkout/orders/'+orderId,{headers:{Authorization:'Bearer '+token}});
 if(order.purchase_units?.[0]?.custom_id!==invoice.id)throw new HTTPError(409,'Checkout identity mismatch.');
 const captured=await provider(paypalBase(env)+'/v2/checkout/orders/'+orderId+'/capture',{method:'POST',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json','PayPal-Request-Id':'capture:'+orderId},body:'{}'});
 const capture=captured.purchase_units?.[0]?.payments?.captures?.[0];if(capture?.status==='COMPLETED')await billing.confirmPayment(invoice.id,'paypal',capture.id,Math.round(Number(capture.amount.value)*100),capture.amount.currency_code,namespace);return {status:captured.status};
}

export async function webhook(request:Request,env:BillingEnv,billing:Billing,gateway:Gateway){
 const raw=await request.text();if(raw.length>1024*1024)throw new HTTPError(413,'Webhook too large.');let event:any;try{event=JSON.parse(raw);}catch{throw new HTTPError(400,'Invalid event.');}
 let id='';
 if(gateway==='stripe'){
  if(!env.STRIPE_WEBHOOK_SECRET)throw new HTTPError(503,'Stripe webhook is not configured.');const signature=request.headers.get('stripe-signature')||'',parts=signature.split(',').map(p=>p.split('=')),timestamp=parts.find(p=>p[0]==='t')?.[1];if(!timestamp||Math.abs(Date.now()/1000-Number(timestamp))>300)throw new HTTPError(400,'Expired webhook signature.');
  const expected=await hmac(env.STRIPE_WEBHOOK_SECRET,timestamp+'.'+raw);if(!parts.some(p=>p[0]==='v1'&&safeEqual(p[1],expected)))throw new HTTPError(400,'Invalid webhook signature.');id=event.id;
 }else if(gateway==='razorpay'){
  if(!env.RAZORPAY_WEBHOOK_SECRET)throw new HTTPError(503,'Razorpay webhook is not configured.');if(!safeEqual(request.headers.get('x-razorpay-signature')||'',await hmac(env.RAZORPAY_WEBHOOK_SECRET,raw)))throw new HTTPError(400,'Invalid webhook signature.');id=request.headers.get('x-razorpay-event-id')||'';
 }else{
  if(!env.PAYPAL_WEBHOOK_ID)throw new HTTPError(503,'PayPal webhook is not configured.');const token=await paypalToken(env);const result=await provider(paypalBase(env)+'/v1/notifications/verify-webhook-signature',{method:'POST',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json'},body:JSON.stringify({auth_algo:request.headers.get('paypal-auth-algo'),cert_url:request.headers.get('paypal-cert-url'),transmission_id:request.headers.get('paypal-transmission-id'),transmission_sig:request.headers.get('paypal-transmission-sig'),transmission_time:request.headers.get('paypal-transmission-time'),webhook_id:env.PAYPAL_WEBHOOK_ID,webhook_event:event})});if(result.verification_status!=='SUCCESS')throw new HTTPError(400,'Invalid webhook signature.');id=event.id;
 }
 const namespace=await paymentNamespace(env,billing,gateway);if(gateway==='stripe'&&typeof event.livemode==='boolean'&&event.livemode!==namespace.startsWith('live:'))throw new HTTPError(400,'Webhook environment mismatch.');
 if(!id||id.length>200)throw new HTTPError(400,'Missing event identity.');const hash=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',enc.encode(raw)))).map(x=>x.toString(16).padStart(2,'0')).join('');
 const previous=await billing.db.prepare('SELECT body_hash FROM webhook_events WHERE id=?').bind(namespace+':'+gateway+':'+id).first<{body_hash:string}>();if(previous){if(previous.body_hash!==hash)throw new HTTPError(409,'Event ID reused with different data.');return {received:true,duplicate:true};}
 if(gateway==='stripe'&&['checkout.session.completed','checkout.session.async_payment_succeeded'].includes(event.type)){const o=event.data?.object;if(o?.payment_status==='paid')await billing.confirmPayment(o.metadata?.invoice_id||o.client_reference_id,'stripe',o.payment_intent,o.amount_total,o.currency.toUpperCase(),namespace);}
 if(gateway==='razorpay'&&event.event==='payment.captured'){const p=event.payload?.payment?.entity;if(p?.captured) {const order=await provider('https://api.razorpay.com/v1/orders/'+encodeURIComponent(p.order_id),{headers:{Authorization:'Basic '+btoa(env.RAZORPAY_KEY_ID+':'+env.RAZORPAY_KEY_SECRET)}});await billing.confirmPayment(order.notes?.invoice_id,'razorpay',p.id,p.amount,p.currency,namespace);}}
 if(gateway==='paypal'&&event.event_type==='PAYMENT.CAPTURE.COMPLETED'){const c=event.resource;if(c.status==='COMPLETED'){const a=await billing.db.prepare("SELECT invoice_id FROM checkout_attempts WHERE gateway='paypal' AND provider_id=?").bind(c.supplementary_data?.related_ids?.order_id||'').first<any>();await billing.confirmPayment(c.custom_id||c.invoice_id||a?.invoice_id,'paypal',c.id,Math.round(Number(c.amount.value)*100),c.amount.currency_code,namespace);}}
 if(gateway==='stripe'&&['charge.refunded','charge.dispute.created','charge.dispute.funds_withdrawn'].includes(event.type)){const o=event.data?.object;let payment=o?.payment_intent;if(!payment&&o?.charge){const charge=await provider('https://api.stripe.com/v1/charges/'+encodeURIComponent(o.charge),{headers:{Authorization:'Bearer '+env.STRIPE_SECRET_KEY}});payment=charge.payment_intent;}await quarantinePayment(billing,gateway,payment,id,event.type,namespace);}
 if(gateway==='razorpay'&&['refund.processed','payment.dispute.created'].includes(event.event)){const ref=event.payload?.refund?.entity?.payment_id||event.payload?.dispute?.entity?.payment_id;await quarantinePayment(billing,gateway,ref,id,event.event,namespace);}
 if(gateway==='paypal'&&['PAYMENT.CAPTURE.REFUNDED','PAYMENT.CAPTURE.REVERSED','CUSTOMER.DISPUTE.CREATED'].includes(event.event_type)){
  const c=event.resource;let refs:string[]=[];
  if(event.event_type==='CUSTOMER.DISPUTE.CREATED')refs=(c.disputed_transactions||[]).map((t:any)=>t.seller_transaction_id).filter(Boolean);
  else {let capture=c.supplementary_data?.related_ids?.capture_id;if(!capture){for(const link of c.links||[]){try{const u=new URL(link.href);if(['api.paypal.com','api-m.paypal.com','api.sandbox.paypal.com','api-m.sandbox.paypal.com'].includes(u.hostname)){const m=u.pathname.match(/^\/v2\/payments\/captures\/([A-Z0-9]+)$/);if(m)capture=m[1];}}catch{}}}refs=[capture||(event.event_type==='PAYMENT.CAPTURE.REVERSED'?c.id:undefined)].filter(Boolean);}
  if(!refs.length)throw new HTTPError(409,'Recovery requires a verified capture reference.');for(const ref of refs)await quarantinePayment(billing,gateway,ref,id,event.event_type,namespace);
 }

 await billing.db.prepare('INSERT OR IGNORE INTO webhook_events (id,gateway,body_hash,received_at) VALUES (?,?,?,?)').bind(namespace+':'+gateway+':'+id,gateway,hash,new Date().toISOString()).run();return {received:true};
}
