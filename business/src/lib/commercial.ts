export type Plan = {id:string;key:string;version:number;name:string;description:string;currency:'USD'|'INR';priceMinor:number;intervalDays:number;published:boolean;limits:{seats:number;conversations:number;sources:number;storageMB:number;requestsPerMinute:number;concurrency:number};features:{byok:boolean;memory:boolean;databaseRead:boolean;databaseWrite:boolean;customBranding:boolean};includedCreditMicros:number};
export type ModelOffer={id:string;name:string;provider:string;model:string;currency:'USD'|'INR';inputMicrosPerMillion:number;outputMicrosPerMillion:number;cachedInputMicrosPerMillion:number;actionMicros:number;maxOutputTokens:number;enabled:boolean;credentialRef:string};
export type Promotion={code:string;percentOff:number;expiresAt:string;maxRedemptions:number;planKeys:string[];active:boolean};
export type PlatformConfig={models:ModelOffer[];promos:Promotion[];graceDays:number;billingLabel:string;supportEmail:string;gateways:{stripe:boolean;razorpay:boolean;paypal:boolean}};
export const defaultConfig=():PlatformConfig=>({models:[],promos:[],graceDays:3,billingLabel:'Sahdev Business',supportEmail:'',gateways:{stripe:false,razorpay:false,paypal:false}});
export const initialPlans=():Plan[]=>[
 {id:'free_v1',key:'free',version:1,name:'Free',description:'A thoughtful starting point for small teams.',currency:'USD',priceMinor:0,intervalDays:30,published:true,limits:{seats:2,conversations:100,sources:5,storageMB:100,requestsPerMinute:20,concurrency:1},features:{byok:false,memory:false,databaseRead:false,databaseWrite:false,customBranding:false},includedCreditMicros:0},
 {id:'growth_v1',key:'growth',version:1,name:'Growth',description:'More context, more control, and room to grow.',currency:'USD',priceMinor:2900,intervalDays:30,published:false,limits:{seats:5,conversations:2000,sources:50,storageMB:1000,requestsPerMinute:60,concurrency:3},features:{byok:true,memory:true,databaseRead:false,databaseWrite:false,customBranding:true},includedCreditMicros:0},
 {id:'business_v1',key:'business',version:1,name:'Business',description:'Connected intelligence for service businesses.',currency:'USD',priceMinor:7900,intervalDays:30,published:false,limits:{seats:20,conversations:10000,sources:250,storageMB:5000,requestsPerMinute:120,concurrency:8},features:{byok:true,memory:true,databaseRead:true,databaseWrite:true,customBranding:true},includedCreditMicros:0}
];
export const money=(minor:number,currency='USD')=>new Intl.NumberFormat('en-US',{style:'currency',currency,maximumFractionDigits:2}).format(minor/100);
export function priceUsage(model:ModelOffer,input:number,output:number,cached=0,actions=1){
 for(const value of [input,output,cached,actions])if(!Number.isSafeInteger(value)||value<0)throw new Error('Usage must be a nonnegative integer.');
 if(cached>input)throw new Error('Cached input cannot exceed total input.');
 const numerator=BigInt(input-cached)*BigInt(model.inputMicrosPerMillion)+BigInt(cached)*BigInt(model.cachedInputMicrosPerMillion)+BigInt(output)*BigInt(model.outputMicrosPerMillion);
 const amount=(numerator+999999n)/1000000n+BigInt(actions)*BigInt(model.actionMicros);if(amount>BigInt(Number.MAX_SAFE_INTEGER))throw new Error('Usage exceeds accounting range.');return Number(amount);
}
