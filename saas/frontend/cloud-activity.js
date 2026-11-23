import {createApp, h, ref} from 'vue';

const root = document.querySelector('[data-usage-chart]');
if (root) {
  const requests = JSON.parse(root.dataset.requests);
  createApp({
    setup() {
      const state = ref('all');
      return () => {
        const buckets = Array(7).fill(0);
        const midnight = new Date().setUTCHours(0,0,0,0);
        for (const row of requests) {
          const timestamp = Date.parse(row.at.replace(' ', 'T') + (/Z|[+-]\d\d:\d\d$/.test(row.at) ? '' : 'Z'));
          const day = 6 - Math.floor((midnight - new Date(timestamp).setUTCHours(0,0,0,0)) / 86400000);
          if (day >= 0 && day < 7 && (state.value === 'all' || state.value === row.state)) buckets[day]++;
        }
        const max = Math.max(...buckets,1);
        return [h('div',{class:'usage-summary'},[h('h2','Request activity'),h('select',{'aria-label':'Filter request activity',value:state.value,onChange:e=>state.value=e.target.value},['all',...new Set(requests.map(r=>r.state))].map(v=>h('option',{value:v},v==='all'?'All statuses':v)))]),
          h('div',{class:'usage-chart',role:'img','aria-label':'Requests per UTC day, oldest first: '+buckets.join(', ')},buckets.map((count,i)=>h('i',{title:new Date(midnight-(6-i)*86400000).toISOString().slice(0,10)+' · '+count,class:count?'':'empty',style:{height:Math.max(3,60*count/max)+'px'}}))),
          h('small',{class:'muted'},'Last seven UTC days · based on the latest '+requests.length+' visible requests')];
      };
    }
  }).mount(root);
}
