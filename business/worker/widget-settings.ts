import {z} from 'zod';
import {defaultWidget} from '../src/lib/widget';
const defaults=defaultWidget();
const safeUrl=z.string().max(2000).refine(v=>{if(!v)return true;try{const u=new URL(v);return u.protocol==='https:'&&!u.username&&!u.password;}catch{return false;}},'Use an HTTPS URL without credentials.');
export const widgetSchema=z.object({
 enabled:z.boolean().default(defaults.enabled),title:z.string().trim().min(1).max(80),subtitle:z.string().max(180),
 theme:z.enum(['orb','bloom','bold','glass','paper','midnight','enterprise','forest','indigo','coral']),
 appearance:z.enum(['light','dark','auto']).default('light'),accent:z.string().regex(/^#[0-9a-fA-F]{6}$/).default(defaults.accent),font:z.enum(['system','rounded','mono']).default('system'),
 position:z.enum(['left','right']),radius:z.number().int().min(0).max(28),width:z.number().int().min(320).max(480).default(390),offset:z.number().int().min(8).max(64).default(24),
 launcher:z.string().trim().min(1).max(60),launcherStyle:z.enum(['pill','orb']).default('pill'),avatar:z.enum(['orb','logo']).default('orb'),logoUrl:safeUrl.default(''),
 orbPalette:z.enum(['aurora','ocean','sunset','mono']).default('aurora'),orbMotion:z.boolean().default(true),
 welcome:z.string().trim().min(1).max(500).default(defaults.welcome),placeholder:z.string().trim().min(1).max(80).default(defaults.placeholder),
 quickReplies:z.array(z.string().trim().min(1).max(80)).max(4).default(defaults.quickReplies),availability:z.enum(['available','away']).default('available'),awayMessage:z.string().trim().min(1).max(500).default(defaults.awayMessage),
 nameField:z.enum(['off','optional','required']).default('optional'),emailField:z.enum(['off','optional','required']).default('optional'),privacyUrl:safeUrl.default(''),
 showBranding:z.boolean(),showTimestamps:z.boolean().default(true),enableKnowledge:z.boolean().default(true),enableTickets:z.boolean().default(true),
 allowedOrigins:z.array(z.url().refine(v=>{try{const u=new URL(v);return u.origin===v&&(u.protocol==='https:'||u.protocol==='http:'&&['localhost','127.0.0.1'].includes(u.hostname));}catch{return false;}},'Use an exact HTTPS origin without a path or trailing slash.')).max(20),
}).strict();
