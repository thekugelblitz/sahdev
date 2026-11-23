<script setup lang="ts">
import { computed, ref } from 'vue';
import Icon from './Icon.vue';
import { initials, relative } from '../lib/api';
import type { Bootstrap, Conversation } from '../lib/model';
const props = defineProps<{ state: Bootstrap; canExport: boolean }>();
const emit = defineEmits<{ navigate: [page: string]; open: [conversation: Conversation]; export: [] }>();
const days = ref('1');
const status = ref('all');
const hovered = ref<number | null>(null);
const w = computed(() => props.state.workspace);
const all = computed(() => w.value.conversations);
const inPeriod = computed(() => all.value.filter(c => Date.parse(c.createdAt) >= Date.now() - Number(days.value) * 86400000));
const open = computed(() => all.value.filter(c => c.status === 'open'));
const pending = computed(() => all.value.filter(c => c.status === 'pending'));
const resolved = computed(() => all.value.filter(c => c.status === 'resolved'));
const avg = computed(() => {
  const times = inPeriod.value.flatMap(c => { const reply = c.messages.find(m => m.kind === 'reply'); return reply ? [Math.max(0, (Date.parse(reply.at) - Date.parse(c.createdAt)) / 60000)] : []; });
  return times.length ? Math.round(times.reduce((a,b) => a+b,0)/times.length) + ' min' : '—';
});
const bins = computed(() => {
  const end = Date.now(), interval = Number(days.value) * 86400000 / 12;
  return Array.from({ length: 12 }, (_, i) => {
    const start = end - (12-i) * interval;
    return { start, label: new Date(start).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }),
      incoming: all.value.filter(c => Date.parse(c.createdAt) >= start && Date.parse(c.createdAt) < start+interval).length,
      resolved: all.value.filter(c => c.status === 'resolved' && Date.parse(c.updatedAt) >= start && Date.parse(c.updatedAt) < start+interval).length };
  });
});
const maximum = computed(() => Math.max(2, ...bins.value.map(b => Math.max(b.incoming,b.resolved))));
const point = (i:number, value:number) => `${38+i*36},${128-value/maximum.value*98}`;
const points = computed(() => bins.value.map((b,i) => point(i,b.incoming)).join(' '));
const channels = computed(() => [{id:'chat',label:'Live chat',icon:'chat',color:'coral'},{id:'portal',label:'Support tickets',icon:'file',color:'violet'},{id:'email',label:'Email',icon:'mail',color:'blue'}].map(c=>({...c,count:all.value.filter(x=>x.channel===c.id).length})));
const topCustomers = computed(() => w.value.contacts.map(c => ({ ...c, count: all.value.filter(x => x.contactId === c.id).length })).filter(c=>c.count).sort((a,b) => b.count-a.count).slice(0,4));
const table = computed(() => all.value.filter(c => status.value === 'all' || c.status === status.value).sort((a,b)=>b.updatedAt.localeCompare(a.updatedAt)).slice(0,6));
const customer = (id:string) => w.value.contacts.find(c=>c.id===id);
const dayNames = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
const activity = computed(() => {
  const cells = Array.from({length:7},()=>Array(12).fill(0) as number[]);
  const since = Date.now()-7*86400000;
  for(const c of all.value) for(const m of c.messages) { const date=new Date(m.at); if(date.getTime()>=since) cells[(date.getDay()+6)%7][Math.floor(date.getHours()/2)]++; }
  return cells;
});
const heatMax = computed(()=>Math.max(1,...activity.value.flat()));
const heatLevel = (count:number) => count ? Math.max(1,Math.ceil(count/heatMax.value*4)) : 0;
const heatTotal = computed(()=>activity.value.flat().reduce((a,b)=>a+b,0));
const selectedBin = computed(()=>hovered.value===null ? null : bins.value[hovered.value]);
</script>

<template>
 <div class="analytics-board">
  <div class="analytics-toolbar"><div><span class="record-indicator"></span><span>{{w.sample?'Sample workspace':'Workspace activity'}}</span><span class="toolbar-separator">/</span><span class="muted">{{w.name}}</span></div><div><button class="button secondary small" v-if="canExport" @click="emit('export')"><Icon name="download" :size="14"/>Export report</button><select v-model="days" aria-label="Analytics date range"><option value="1">Last 24 hours</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option></select></div></div>
  <div class="analytics-top-grid">
   <div class="metric-composition">
    <button class="total-metric" @click="emit('navigate','inbox')"><span class="metric-glyph"><Icon name="chat" :size="22"/></span><span>Total conversations</span><div class="coral-orbit" aria-hidden="true"><i></i><i></i><i></i></div><div class="total-value">{{all.length.toLocaleString()}}<Icon name="upRight" :size="25"/></div><small><span class="tiny-live"></span>{{inPeriod.length}} in selected period</small></button>
    <button class="compact-metric" @click="emit('navigate','inbox')"><span><i class="metric-glyph coral"><Icon name="inbox" :size="16"/></i>Open conversations</span><div><strong>{{open.length}}</strong><small class="coral-text">Needs attention <Icon name="upRight" :size="11"/></small></div></button>
    <button class="compact-metric" @click="emit('navigate','tickets')"><span><i class="metric-glyph violet"><Icon name="clock" :size="16"/></i>Waiting on customer</span><div><strong>{{pending.length}}</strong><small class="violet-text">Pending replies</small></div></button>
    <button class="compact-metric" @click="emit('navigate','tickets')"><span><i class="metric-glyph blue"><Icon name="checks" :size="16"/></i>Resolved</span><div><strong>{{resolved.length}}</strong><small class="blue-text">{{all.length?Math.round(resolved.length/all.length*100):0}}% of total <Icon name="checks" :size="12"/></small></div></button>
    <button class="compact-metric" @click="emit('navigate','customers')"><span><i class="metric-glyph apricot"><Icon name="users" :size="16"/></i>Customer profiles</span><div><strong>{{w.contacts.length}}</strong><small class="apricot-text">All contacts <Icon name="upRight" :size="11"/></small></div></button>
   </div>
   <section class="analytics-card volume-card">
    <div class="a-card-heading"><h2>Conversation activity</h2><span class="chart-key"><i></i>Incoming <i class="dark"></i>Resolved</span></div>
    <div class="volume-summary"><strong>{{inPeriod.length}} <span>conversations</span></strong><span>Avg. first reply <b>{{avg}}</b></span></div>
    <div class="volume-plot" @mouseleave="hovered=null">
     <svg viewBox="0 0 470 152" role="img" :aria-label="`Incoming and resolved conversation counts for the last ${days} days`">
      <g v-for="n in [0,1,2]" :key="n"><line x1="27" x2="454" :y1="30+n*49" :y2="30+n*49" class="plot-grid"/><text x="7" :y="33+n*49" class="axis-text">{{Math.round(maximum*(1-n/2))}}</text></g>
      <g v-for="(bin,i) in bins" :key="i" tabindex="0" role="button" :aria-label="`${bin.label}: ${bin.incoming} incoming, ${bin.resolved} resolved`" @focus="hovered=i" @blur="hovered=null" @mouseenter="hovered=i">
       <rect :x="29+i*36" y="23" width="24" height="107" fill="transparent"/>
       <rect :x="29+i*36" :y="128-bin.incoming/maximum*98" width="17" :height="Math.max(2,bin.incoming/maximum*98)" rx="2" class="volume-bar"/>
       <rect v-if="bin.resolved" :x="43+i*36" :y="128-bin.resolved/maximum*98" width="7" :height="bin.resolved/maximum*98" rx="2" class="resolved-bar"/>
       <text v-if="i%2===0" :x="38+i*36" y="146" class="axis-text" text-anchor="middle">{{days==='1'?new Date(bin.start).getHours()+':00':bin.label}}</text>
      </g>
      <polyline :points="points" fill="none" class="volume-line"/>
      <circle v-for="(bin,i) in bins" :key="i" :cx="38+i*36" :cy="128-bin.incoming/maximum*98" r="2.8" class="volume-point"/>
     </svg>
     <div v-if="selectedBin" class="plot-tooltip"><strong>{{selectedBin.label}}</strong><span>{{selectedBin.incoming}} incoming · {{selectedBin.resolved}} resolved</span></div>
    </div>
   </section>
  </div>
  <div class="analytics-middle-grid">
   <section class="analytics-card channel-card"><div class="a-card-heading"><h2>Top channels</h2><button class="icon-button" aria-label="Open shared inbox" @click="emit('navigate','inbox')"><Icon name="upRight" :size="15"/></button></div><div v-for="channel in channels" :key="channel.id" class="channel-metric"><span :class="['channel-mark',channel.color]"><Icon :name="channel.icon" :size="18"/></span><div><div><strong>{{channel.label}}</strong><small>{{channel.count}} <span>conversations</span></small><b>{{all.length?Math.round(channel.count/all.length*100):0}}%</b></div><div class="channel-track"><i :class="channel.color" :style="{width:(all.length?channel.count/all.length*100:0)+'%'}"></i></div></div></div><div class="card-bottom-note"><Icon name="globe" :size="13"/>Every channel, one workspace</div></section>
   <section class="analytics-card top-customers"><div class="a-card-heading"><h2>Most active customers</h2><button class="icon-button" aria-label="View customers" @click="emit('navigate','customers')"><Icon name="upRight" :size="15"/></button></div><button v-for="c in topCustomers" :key="c.id" class="customer-rank" @click="emit('open',all.find(x=>x.contactId===c.id)!)"><span :class="['avatar',c.color]">{{initials(c.name)}}</span><span><strong>{{c.name}}</strong><small>{{c.company||c.email}}</small></span><span><b>{{c.count}}</b><small>{{c.count===1?'conversation':'conversations'}}</small></span></button><p v-if="!topCustomers.length" class="empty-state compact">Customer activity will appear here.</p></section>
   <section class="analytics-card heatmap-card"><div class="a-card-heading"><h2>Busy periods</h2><span class="period-label">Last 7 days <Icon name="down" :size="12"/></span></div><div class="heatmap-subtitle"><span><b>{{heatTotal}}</b> messages across your workspace</span><span class="heatmap-legend">Less <i v-for="level in [0,1,2,3,4]" :key="level" :class="'heat-'+level"></i> More</span></div><div class="heatmap"><div v-for="(row,d) in activity" :key="d" class="heatmap-row"><span>{{dayNames[d]}}</span><div v-for="(value,h) in row" :key="h" :class="['heat-cell','heat-'+heatLevel(value)]" tabindex="0" :aria-label="`${dayNames[d]}, ${h*2}:00 to ${(h+1)*2}:00: ${value} messages`"><span class="heat-tooltip">{{dayNames[d]}} {{h*2}}:00 · {{value}} messages</span></div></div><div class="heatmap-hours"><span></span><span v-for="h in 12" :key="h">{{(h-1)*2}}h</span></div></div></section>
  </div>
  <section class="analytics-card conversations-card">
   <div class="a-card-heading"><h2>Conversation list <span class="count-bubble">{{all.length}}</span></h2><div class="conversation-table-actions"><div class="table-tabs" aria-label="Conversation status"><button v-for="f in ['all','open','pending','resolved']" :key="f" :class="{selected:status===f}" @click="status=f">{{f==='all'?'All conversations':f}}</button></div><button class="button secondary small" @click="emit('navigate','tickets')">View all <Icon name="upRight" :size="13"/></button></div></div>
   <div class="table-scroll"><table><thead><tr><th>Conversation <span>↕</span></th><th>Customer <span>↕</span></th><th>Channel</th><th>Assigned to</th><th>Last activity <span>↕</span></th><th>Priority</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="c in table" :key="c.id" tabindex="0" @click="emit('open',c)" @keydown.enter="emit('open',c)"><td><div class="conversation-title-cell"><span :class="['table-channel',c.channel]"><Icon :name="c.channel==='chat'?'chat':c.channel==='email'?'mail':'file'" :size="14"/></span><div><strong>{{c.subject}}</strong><small>#{{c.id.slice(-7).toUpperCase()}}</small></div></div></td><td><div class="person-cell"><span :class="['avatar small',customer(c.contactId)?.color]">{{initials(customer(c.contactId)?.name||'?')}}</span><div><strong>{{customer(c.contactId)?.name}}</strong><small>{{customer(c.contactId)?.company}}</small></div></div></td><td><span class="channel-name">{{c.channel==='portal'?'Support ticket':c.channel==='chat'?'Live chat':'Email'}}</span></td><td><span class="table-assignee"><i>{{c.assignee?initials(c.assignee):'—'}}</i>{{c.assignee||'Unassigned'}}</span></td><td><span class="activity-date">{{new Date(c.updatedAt).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}}</span><small class="activity-time">{{relative(c.updatedAt)}} ago</small></td><td><span :class="['priority',c.priority]"><i></i>{{c.priority}}</span></td><td><span :class="['status',c.status]">{{c.status}}</span></td><td><Icon name="right" :size="13"/></td></tr></tbody></table></div><p v-if="!table.length" class="empty-state compact">No {{status==='all'?'':status}} conversations to show.</p>
   <div class="analytics-table-footer"><span>Showing {{table.length}} of {{all.filter(c=>status==='all'||c.status===status).length}} conversations</span><button @click="emit('navigate','inbox')">Open inbox <Icon name="arrow" :size="13"/></button></div>
  </section>
  <footer class="analytics-footer"><span>© {{new Date().getFullYear()}} Sahdev Business</span><span><i class="record-indicator"></i>{{w.sample?'Fictional sample data · explore every view':'Calculated from your workspace records'}}</span><button @click="emit('navigate','activity')">Activity log <Icon name="external" :size="12"/></button></footer>
 </div>
</template>
