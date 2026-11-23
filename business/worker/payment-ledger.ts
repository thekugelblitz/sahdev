import type { Billing } from './billing';
import { HTTPError } from './support';

export async function bindPaymentNamespace(billing:Billing, namespace:string) {
  if(!/^(test|live):[A-Za-z0-9_-]{3,80}$/.test(namespace)) throw new HTTPError(503,'Set a billing namespace for this merchant and environment. Use a separate database for test and live payments.');
  await billing.db.prepare('INSERT OR IGNORE INTO billing_runtime (id,namespace) VALUES (?,?)').bind('main',namespace).run();
  const stored=await billing.db.prepare('SELECT namespace FROM billing_runtime WHERE id=?').bind('main').first<{namespace:string}>();
  if(stored?.namespace!==namespace) throw new HTTPError(409,'This database belongs to a different billing environment.');
}

export async function confirmPayment(billing:Billing,invoiceId:string,gateway:string,paymentId:string,amount:number,currency:string,namespace='test:local') {
  if(!paymentId||paymentId.length>200||!Number.isSafeInteger(amount)||amount<=0) throw new HTTPError(400,'Invalid payment identity or amount.');
  await bindPaymentNamespace(billing,namespace);
  currency=currency.toUpperCase();
  const invoice=await billing.db.prepare('SELECT * FROM invoices WHERE id=?').bind(invoiceId).first<any>();
  if(!invoice||invoice.amount!==amount||invoice.currency!==currency) throw new HTTPError(400,'Payment does not match the invoice.');
  const receiptId=`${namespace}:${gateway}:${paymentId}`;
  const prior=await billing.db.prepare('SELECT * FROM payment_receipts WHERE id=?').bind(receiptId).first<any>();
  if(prior&&(prior.invoice_id!==invoiceId||prior.amount!==amount||prior.currency!==currency)) throw new HTTPError(409,'This payment was already assigned to a different invoice.');
  const plan=invoice.purpose==='plan'?await billing.plan(invoice.plan_id):undefined;
  if(invoice.purpose==='plan'&&!plan) throw new HTTPError(409,'Invoice plan is unavailable.');
  const credit=plan?plan.includedCreditMicros:amount*10000;
  const now=new Date().toISOString(),walletId=invoice.workspace_id+':'+currency;
  const statements=[
    billing.db.prepare('UPDATE invoices SET state=state WHERE id=?').bind(invoiceId),
    billing.db.prepare(`INSERT OR IGNORE INTO payment_receipts (id,invoice_id,workspace_id,gateway,payment_id,namespace,amount,currency,credit,state,created_at)
      SELECT ?,?,?,?,?,?,?,?,?,CASE WHEN i.state='open' AND (i.purpose='credits' OR i.transition=s.billing_revision)
      AND NOT EXISTS(SELECT 1 FROM payment_recovery WHERE payment_ref=?) THEN 'new' ELSE 'reconciliation' END,?
      FROM invoices i JOIN subscriptions s ON s.workspace_id=i.workspace_id WHERE i.id=?`).bind(receiptId,invoiceId,invoice.workspace_id,gateway,paymentId,namespace,amount,currency,credit,receiptId,now,invoiceId),
    billing.db.prepare('INSERT OR IGNORE INTO wallets (id,workspace_id,currency,balance,reserved) VALUES (?,?,?,0,0)').bind(walletId,invoice.workspace_id,currency),
    billing.db.prepare(`INSERT OR IGNORE INTO ledger (id,workspace_id,currency,kind,amount,applied,reference,created_at)
      SELECT ?,?,?,?, ?,1,?,? WHERE EXISTS(SELECT 1 FROM payment_receipts WHERE id=? AND state='new')`).bind('payment:'+receiptId,invoice.workspace_id,currency,'payment',credit,invoiceId,now,receiptId),
    billing.db.prepare("UPDATE wallets SET balance=balance+? WHERE id=? AND EXISTS(SELECT 1 FROM payment_receipts WHERE id=? AND state='new')").bind(credit,walletId,receiptId),
    billing.db.prepare("UPDATE invoices SET state='paid',paid_at=?,gateway=?,payment_id=? WHERE id=? AND EXISTS(SELECT 1 FROM payment_receipts WHERE id=? AND state='new')").bind(now,gateway,paymentId,invoiceId,receiptId),
    billing.db.prepare("UPDATE invoices SET state='review' WHERE id=? AND state='open' AND EXISTS(SELECT 1 FROM payment_receipts WHERE id=? AND state='reconciliation')").bind(invoiceId,receiptId)
  ];
  if(plan) statements.push(billing.db.prepare(`UPDATE subscriptions SET plan_id=?,state='active',period_end=?,pending_plan_id=NULL,suspension_reason=NULL
    WHERE workspace_id=? AND billing_revision=? AND EXISTS(SELECT 1 FROM payment_receipts WHERE id=? AND state='new')`).bind(plan.id,new Date(Date.now()+plan.intervalDays*86400000).toISOString(),invoice.workspace_id,invoice.transition,receiptId));
  statements.push(billing.db.prepare("UPDATE payment_receipts SET state='applied' WHERE id=? AND state='new'").bind(receiptId));
  await billing.db.batch(statements);
  const receipt=await billing.db.prepare('SELECT * FROM payment_receipts WHERE id=?').bind(receiptId).first<any>();
  if(!receipt||receipt.invoice_id!==invoiceId||receipt.amount!==amount||receipt.currency!==currency) throw new HTTPError(409,'Payment identity conflict.');
  return {duplicate:!!prior,reconciliation:receipt.state!=='applied',receiptId};
}

// Conservatively quarantine all credits from the disputed/refunded funding source.
// A platform operator reconciles the refund externally before releasing the hold.
// Negative balances retain consumed-credit debt; they are never silently floored to zero.
export async function quarantinePayment(billing:Billing,gateway:string,paymentId:string,eventId:string,reason:string,namespace:string) {
  await bindPaymentNamespace(billing,namespace);
  if(!paymentId||paymentId.length>200||!eventId||eventId.length>200) throw new HTTPError(400,'Recovery event is missing its payment identity.');
  const ref=`${namespace}:${gateway}:${paymentId}`,now=new Date().toISOString();
  await billing.db.batch([
    billing.db.prepare('INSERT OR IGNORE INTO payment_recovery (id,payment_ref,reason,created_at) VALUES (?,?,?,?)').bind(namespace+':'+gateway+':'+eventId+':'+paymentId,ref,reason.slice(0,200),now),
    billing.db.prepare(`INSERT OR IGNORE INTO ledger (id,workspace_id,currency,kind,amount,applied,reference,created_at)
      SELECT ?,workspace_id,currency,'funding_hold',-credit,1,id,? FROM payment_receipts WHERE id=? AND state='applied'`).bind('recovery:'+ref,now,ref),
    billing.db.prepare(`UPDATE wallets SET balance=balance-(SELECT credit FROM payment_receipts WHERE id=?)
      WHERE id=(SELECT workspace_id||':'||currency FROM payment_receipts WHERE id=? AND state='applied')`).bind(ref,ref),
    billing.db.prepare("UPDATE subscriptions SET recovery_hold=? WHERE workspace_id=(SELECT workspace_id FROM payment_receipts WHERE id=?)").bind('Payment review: '+reason.slice(0,160),ref),
    billing.db.prepare("UPDATE payment_receipts SET state='recovery' WHERE id=? AND state='applied'").bind(ref)
  ]);
}
