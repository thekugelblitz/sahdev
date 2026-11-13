<?php
namespace SahdevConnector;
use WHMCS\Database\Capsule;

/** Fixed record extraction only. No prompt templates, scoring, model choice or tool planning. */
final class Tickets
{
    public static function context(object $ticket): array
    {
        $messages = [['author'=>'client','date'=>(string)$ticket->date,'message'=>self::text($ticket->message,6000)]];
        $replies = Capsule::table('tblticketreplies')->where('tid',$ticket->id)->orderByDesc('id')->limit(20)->get(['admin','date','message'])->reverse();
        foreach ($replies as $reply) $messages[] = ['author'=>$reply->admin ? 'staff' : 'client','date'=>(string)$reply->date,'message'=>self::text($reply->message,6000)];
        return ['ticket'=>['id'=>(int)$ticket->id,'subject'=>self::text($ticket->title,500),'status'=>(string)$ticket->status,
            'department'=>(string)Capsule::table('tblticketdepartments')->where('id',$ticket->did)->value('name'),'client_id'=>(int)$ticket->userid,'client_name'=>self::text($ticket->name ?: 'Client',100)],
            'messages'=>$messages,'services'=>[],'invoices'=>[]];
    }
    private static function text($value, int $limit): string
    {
        return mb_substr(trim(strip_tags((string)$value)),0,$limit,'UTF-8');
    }
    public static function submit(object $admin, object $ticket, array $input, Client $client): array
    {
        $options = array_intersect_key($input,array_flip(['workflow','model_id','tone','draft','instruction','max_output_tokens']));
        $hash = hash('sha256',json_encode([(int)$admin->id,(int)$ticket->id,$options],JSON_THROW_ON_ERROR));
        $row = Capsule::connection()->transaction(function () use ($admin,$ticket,$options,$hash) {
            $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15)|64); $bytes[8] = chr((ord($bytes[8]) & 63)|128);
            $hex = bin2hex($bytes); $id = substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
            $payload = $options+['actor'=>'admin:'.$admin->id,'idempotency_key'=>$id,'context'=>self::context($ticket)];
            Capsule::table('mod_sahdev_cloud_requests')->insertOrIgnore(['fingerprint'=>$hash,'admin_id'=>$admin->id,'ticket_id'=>$ticket->id,'payload'=>encrypt(json_encode($payload,JSON_THROW_ON_ERROR)),'state'=>'pending','created_at'=>date('Y-m-d H:i:s')]);
            $row = Capsule::table('mod_sahdev_cloud_requests')->where('fingerprint',$hash)->lockForUpdate()->first();
            // Unconfirmed requests never expire into a second paid request.
            if (in_array($row->state,['settled','released'],true) && strtotime($row->created_at) < time()-300) {
                Capsule::table('mod_sahdev_cloud_requests')->where('fingerprint',$hash)->update(['payload'=>encrypt(json_encode($payload,JSON_THROW_ON_ERROR)),'remote_id'=>null,'state'=>'pending','created_at'=>date('Y-m-d H:i:s')]);
                $row = Capsule::table('mod_sahdev_cloud_requests')->where('fingerprint',$hash)->first();
            }
            return $row;
        });
        if ($row->remote_id) return ['id'=>$row->remote_id,'state'=>$row->state];
        $result = $client->request('POST','/api/v1/workflows',json_decode(decrypt($row->payload),true));
        if (!preg_match('/^[a-f0-9-]{36}$/D',$result['id'] ?? '')) throw new \RuntimeException('The cloud did not return a valid request reference.',502);
        Capsule::table('mod_sahdev_cloud_requests')->where('fingerprint',$hash)->update(['remote_id'=>$result['id'],'state'=>$result['state'],'payload'=>'']);
        return $result;
    }
    public static function result(object $admin, object $ticket, string $id, Client $client): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/D',$id) || !Capsule::table('mod_sahdev_cloud_requests')->where('remote_id',$id)->where('admin_id',$admin->id)->where('ticket_id',$ticket->id)->exists()) throw new \RuntimeException('Unknown workflow request.',404);
        $result = $client->request('POST','/api/v1/workflows/'.$id.'/result',['actor'=>'admin:'.$admin->id,'ticket_id'=>(int)$ticket->id]);
        Capsule::table('mod_sahdev_cloud_requests')->where('remote_id',$id)->where('admin_id',$admin->id)->update(['state'=>$result['state']]);
        return $result;
    }
}
