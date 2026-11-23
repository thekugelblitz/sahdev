export type WidgetSettings = {
  enabled:boolean; title:string; subtitle:string; theme:'orb'|'bloom'|'bold'|'glass'|'paper'|'midnight'|'enterprise'|'forest'|'indigo'|'coral';
  appearance:'light'|'dark'|'auto'; accent:string; font:'system'|'rounded'|'mono';
  position:'left'|'right'; radius:number; width:number; offset:number; launcher:string; launcherStyle:'pill'|'orb';
  avatar:'orb'|'logo'; logoUrl:string; orbPalette:'aurora'|'ocean'|'sunset'|'mono'; orbMotion:boolean;
  welcome:string; placeholder:string; quickReplies:string[]; availability:'available'|'away'; awayMessage:string;
  nameField:'off'|'optional'|'required'; emailField:'off'|'optional'|'required'; privacyUrl:string;
  showBranding:boolean; showTimestamps:boolean; enableKnowledge:boolean; enableTickets:boolean; allowedOrigins:string[];
};
export const defaultWidget=():WidgetSettings=>({enabled:true,title:'Sahdev',subtitle:'Your support companion',theme:'orb',appearance:'light',accent:'#7c3aed',font:'system',position:'right',radius:20,width:390,offset:24,launcher:'How can we help?',launcherStyle:'pill',avatar:'orb',logoUrl:'',orbPalette:'aurora',orbMotion:true,welcome:'Hello! How can we help you today?',placeholder:'Write your message…',quickReplies:['I have a question','I need technical support'],availability:'available',awayMessage:'Leave us a message. Our team will reply here when they return.',nameField:'optional',emailField:'optional',privacyUrl:'',showBranding:true,showTimestamps:true,enableKnowledge:true,enableTickets:true,allowedOrigins:[]});
export function normalizeWidget(value?:Partial<WidgetSettings>):WidgetSettings{return {...defaultWidget(),...value};}
export const widgetThemes=[
 {id:'orb',name:'Sahdev Orb',description:'Fluid orb · floating controls',accent:'#7c3aed',radius:20,appearance:'light',font:'system'},
 {id:'bloom',name:'Bloom',description:'Playful · soft speech bubbles',accent:'#e45182',radius:28,appearance:'light',font:'rounded'},
 {id:'bold',name:'Spotlight',description:'Colorful header · bold contrast',accent:'#df624b',radius:20,appearance:'light',font:'system'},
 {id:'glass',name:'Aurora Glass',description:'Frosted layers · luminous edges',accent:'#735fe7',radius:24,appearance:'dark',font:'system'},
 {id:'paper',name:'Editorial',description:'Warm paper · quiet typography',accent:'#9a5c37',radius:8,appearance:'light',font:'system'},
 {id:'midnight',name:'Midnight',description:'Obsidian · precise geometry',accent:'#9d8bff',radius:12,appearance:'dark',font:'mono'},
 {id:'enterprise',name:'Precision',description:'Clean hairlines · compact forms',accent:'#3276cc',radius:8,appearance:'light',font:'system'},
] as const;
