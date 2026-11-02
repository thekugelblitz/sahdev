<?php
namespace Sahdev\Lib;
use WHMCS\Database\Capsule;

/** Bounded, read-only WHMCS context; writes only to Sahdev-owned tables. */
class CaseTimeline
{
    public static function layouts(): array
    {
        return ['case_timeline'=>'Case Timeline — preserved direction','timeline_plus'=>'T01 · Timeline Plus','timeline_lanes'=>'T02 · Three-Lane Case Desk','timeline_ledger'=>'T03 · Evidence Ledger','timeline_split'=>'T04 · Split Chronicle','timeline_ribbon'=>'T05 · Event Ribbon','timeline_journal'=>'T06 · Case Journal','timeline_milestones'=>'T07 · Case Milestones','timeline_map'=>'T08 · Evidence to Action'];
    }
    public static function schema(): void
    {
        if (!Capsule::schema()->hasTable('tblsahdev_case_profiles')) Capsule::schema()->create('tblsahdev_case_profiles', function($t){$t->increments('id');$t->string('name',100);$t->boolean('active')->default(false);$t->text('policy');});
        if (!Capsule::schema()->hasTable('tblsahdev_case_snapshots')) Capsule::schema()->create('tblsahdev_case_snapshots', function($t){$t->increments('id');$t->integer('ticket_id')->index();$t->string('source',80);$t->string('event_key',64)->unique();$t->text('payload');$t->dateTime('observed_at')->index();});
    }
    public static function validate(array $p): array
    {
        $out=[];
        foreach(['enabled','services','servers','domains','tools','messages','ai'] as $k) $out[$k]=filter_var($p[$k]??false,FILTER_VALIDATE_BOOLEAN);
        $out['limit']=max(5,min(100,(int)($p['limit']??30)));
        $out['days']=max(1,min(90,(int)($p['days']??14)));
        return $out;
    }
    public static function policy(): array
    {
        try { $r=Capsule::table('tblsahdev_case_profiles')->where('active',1)->orderBy('id')->first();return self::validate($r?(json_decode($r->policy,true)?:[]):[]); }
        catch(\Throwable $e){return self::validate([]);}
    }
    public static function clean(string $value): string
    {
        $value=strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value=preg_replace('/\bBearer\s+[^\s,;"}]+/i','Bearer [redacted]',$value);
        $value=preg_replace('/\b(password|passwd|secret|token|authorization|api[_ -]?key)["\x27]?\s*[:=]\s*(?:"[^"\r\n]*"|\x27[^\x27\r\n]*\x27|[^\s,;}]+)/i','$1=[redacted]',$value);
        $value=preg_replace('/-----BEGIN [^-]*PRIVATE KEY-----[\s\S]*?(?:-----END [^-]*PRIVATE KEY-----|$)/','[private key redacted]',$value);
        $value=preg_replace('~https?://[^\s/@]+:[^\s/@]+@~i','https://[redacted]@',$value);
        return mb_substr($value,0,1500);
    }
    public static function capture(int $ticketId,string $source,string $eventKey,array $data): void
    {
        try {
            $p=self::policy();if(!$p['enabled']||!$p['tools']||$ticketId<1)return;
            $payload=['status'=>self::clean((string)($data['status']??'unknown')),'summary'=>self::clean((string)($data['summary']??''))];
            $evidence=json_decode((string)($data['summary']??''),true);
            if(is_array($evidence)&&!empty($evidence['observed_at']))$data['observed_at']=$evidence['observed_at'];
            $observed=!empty($data['observed_at'])?strtotime((string)$data['observed_at']):false;
            if($observed!==false&&$observed<time()-$p['days']*86400)return;
            // insertOrIgnore preserves the original timestamp when a cached run is read again.
            Capsule::table('tblsahdev_case_snapshots')->insertOrIgnore(['ticket_id'=>$ticketId,'source'=>substr($source,0,80),'event_key'=>hash('sha256',$ticketId.':'.$source.':'.$eventKey),'payload'=>json_encode($payload,JSON_INVALID_UTF8_SUBSTITUTE),'observed_at'=>date('Y-m-d H:i:s',$observed===false?time():min(time(),$observed))]);
            self::prune($p['days']);
        }catch(\Throwable $e){ /* Timeline storage must not break support or tools. */ }
    }
    /** Bounded cleanup, also available manually when collection is disabled. */
    public static function prune(int $days): int
    {
        $ids=Capsule::table('tblsahdev_case_snapshots')->where('observed_at','<',date('Y-m-d H:i:s',time()-max(1,min(90,$days))*86400))->limit(500)->pluck('id')->all();
        return $ids ? Capsule::table('tblsahdev_case_snapshots')->whereIn('id',$ids)->delete() : 0;
    }
    /** Complete JSON within a byte budget; never cut through an event or JSON string. */
    public static function promptBlock(int $ticketId, bool $includeTools = true): string
    {
        $p=self::policy();if(!$p['enabled']||!$p['ai'])return '';
        $data=self::read($ticketId, $includeTools);
        do {
            $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
            if(strlen($json)<=12000)return $json;
            $data['truncated']=true;
        } while(array_shift($data['events'])!==null);
        return json_encode(['notice'=>'Timeline context exceeds the size budget; inspect the ticket timeline.']);
    }
    public static function read(int $ticketId, bool $includeTools = true): array
    {
        $p=self::policy();$out=['enabled'=>$p['enabled'],'events'=>[],'context'=>[],'notice'=>''];
        if(!$includeTools)$p['tools']=false;
        if(!$p['enabled']||$ticketId<1)return $out;
        try {
            $ticketFields=['id','userid','date','status','urgency','service'];
            if($p['messages'])$ticketFields[]='message';
            $ticket=Capsule::table('tbltickets')->where('id',$ticketId)->first($ticketFields);
            if(!$ticket)return $out;
            $out['context']=['status'=>$ticket->status,'priority'=>$ticket->urgency,'read_at'=>date('Y-m-d H:i:s')];
            $out['events'][]=['at'=>$ticket->date,'source'=>'WHMCS ticket','kind'=>'client','status'=>'reported','summary'=>$p['messages']?self::clean((string)$ticket->message):'Ticket opened'];
            $replyFields=['id','date','admin'];if($p['messages'])$replyFields[]='message';
            foreach(Capsule::table('tblticketreplies')->where('tid',$ticketId)->orderBy('date','desc')->orderBy('id','desc')->limit($p['limit'])->get($replyFields) as $r)$out['events'][]=['at'=>$r->date,'source'=>'WHMCS reply #'.$r->id,'kind'=>empty($r->admin)?'client':'staff','status'=>'reported','summary'=>$p['messages']?self::clean((string)$r->message):(empty($r->admin)?'Client reply received':'Staff reply recorded')];
            try { if($p['services']&&(int)$ticket->userid>0){
                $q=Capsule::table('tblhosting')->where('userid',$ticket->userid);
                // Only the explicitly linked service, not arbitrary services belonging to another client.
                $service=(string)$ticket->service;
                if(preg_match('/^(?:S)?([0-9]+)$/',$service,$m)){
                    $h=$q->where('id',(int)$m[1])->first(['id','domain','domainstatus','server','dedicatedip']);
                    if($h){$out['context']['hosting']=['id'=>$h->id,'domain'=>$h->domain,'status'=>$h->domainstatus,'recorded_ip'=>$h->dedicatedip];
                        if($p['servers']){$s=Capsule::table('tblservers')->where('id',$h->server)->first(['name','hostname','ipaddress','nameserver1','nameserver2','nameserver3','nameserver4']);if($s)$out['context']['server']=(array)$s;}
                    }
                }
            }}catch(\Throwable $e){$out['notice']='Some account context is unavailable; tool observations are still listed separately.';}
            try {
                if($p['domains']&&(int)$ticket->userid>0){
                    $q=Capsule::table('tbldomains')->where('userid',$ticket->userid);$d=null;
                    if(preg_match('/^D([0-9]+)$/',(string)$ticket->service,$m))$d=$q->where('id',(int)$m[1])->first(['domain','status','expirydate']);
                    elseif(isset($out['context']['hosting']['domain']))$d=$q->where('domain',$out['context']['hosting']['domain'])->first(['domain','status','expirydate']);
                    if($d)$out['context']['domain']=(array)$d;
                }
            }catch(\Throwable $e){$out['notice']='Domain registration context is unavailable; other dated observations are retained.';}
            if($p['tools'])foreach(Capsule::table('tblsahdev_case_snapshots')->where('ticket_id',$ticketId)->where('observed_at','>=',date('Y-m-d H:i:s',time()-$p['days']*86400))->orderBy('observed_at','desc')->orderBy('id','desc')->limit($p['limit'])->get() as $r){$d=json_decode($r->payload,true)?:[];$out['events'][]=['at'=>$r->observed_at,'source'=>$r->source,'kind'=>'tool','status'=>$d['status']??'unknown','summary'=>$d['summary']??'','snapshot_id'=>$r->id];}
            usort($out['events'],static function($a,$b){return strcmp($a['at'],$b['at']);});
            $out['events']=array_slice($out['events'],-$p['limit']);
        }catch(\Throwable $e){$out['notice']='Some timeline sources are unavailable; missing evidence is not a successful check.';}
        return $out;
    }
}
