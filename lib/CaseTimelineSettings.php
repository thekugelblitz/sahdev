<?php
namespace Sahdev\Lib;
use WHMCS\Database\Capsule;
class CaseTimelineSettings
{
    public static function render(): string
    {
        $admin=PermissionService::resolveCurrentAdminId();
        if(!$admin||!PermissionService::hasPermission($admin,PermissionService::PERM_SETTINGS_MANAGE))return '<div class="alert alert-danger">Settings permission required.</div>';
        try { CaseTimeline::schema(); } catch (\Throwable $e) { return '<div class="alert alert-danger">Case Timeline storage could not be initialized. Check module database schema permissions and retry this page. Ticket tools remain available.</div>'; }
        $message='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            check_token('WHMCS.admin.default');$id=(int)($_POST['profile_id']??0);
            if(isset($_POST['delete_profile'])){Capsule::table('tblsahdev_case_profiles')->where('id',$id)->delete();$message='Profile deleted. With no active profile timeline collection is disabled.';}
            elseif(isset($_POST['delete_snapshot'])){Capsule::table('tblsahdev_case_snapshots')->where('id',(int)$_POST['delete_snapshot'])->delete();$message='Snapshot deleted.';}
            elseif(isset($_POST['purge_ticket'])){Capsule::table('tblsahdev_case_snapshots')->where('ticket_id',(int)$_POST['purge_ticket'])->delete();$message='Ticket snapshots deleted.';}
            elseif(isset($_POST['prune_snapshots'])){$count=CaseTimeline::prune(CaseTimeline::policy()['days']);$message=$count.' expired snapshots deleted (up to 500 per cleanup).';}
            elseif(isset($_POST['save_profile'])){
                $p=CaseTimeline::validate($_POST);$name=substr(trim((string)($_POST['name']??'')),0,100);if($name==='')$name='Timeline policy';
                if($id>0&&!Capsule::table('tblsahdev_case_profiles')->where('id',$id)->exists())$message='This policy no longer exists. Reload before saving.';
                else{Capsule::connection()->transaction(function()use($id,$p,$name){$data=['name'=>$name,'active'=>!empty($_POST['active'])?1:0,'policy'=>json_encode($p)];if($data['active'])Capsule::table('tblsahdev_case_profiles')->update(['active'=>0]);if($id>0)Capsule::table('tblsahdev_case_profiles')->where('id',$id)->update($data);else Capsule::table('tblsahdev_case_profiles')->insert($data);});$message='Policy saved. Existing custom prompts were not changed.';}
            }
        }
        $esc=static function($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');};
        $edit=Capsule::table('tblsahdev_case_profiles')->where('id',(int)($_GET['edit']??0))->first();$p=CaseTimeline::validate($edit?(json_decode($edit->policy,true)?:[]):[]);
        $html='<h2>Case Timeline &amp; context policies</h2><p>WHMCS sources are read-only. Tool snapshots retain their observation time; editing a policy never rewrites a snapshot. AI context is opt-in. WHMCS credential fields, billing tables and private notes are never selected. Optional public excerpts and normalized tool summaries can contain customer-provided information; enable the sources your team needs.</p>';
        if($message)$html.='<div class="alert alert-info">'.$esc($message).'</div>';
        $html.='<p><a href="addonmodules.php?module=sahdev&action=my_preferences">Choose a staff layout</a> · <a href="addonmodules.php?module=sahdev&action=timeline_settings">New policy</a></p><form method="post">'.generate_token('form').'<input type="hidden" name="profile_id" value="'.(int)($edit->id??0).'"><label>Policy name <input class="form-control" name="name" maxlength="100" value="'.$esc($edit->name??'').'"></label><p><label><input type="checkbox" name="active" value="1" '.(!empty($edit->active)?'checked':'').'> Make this the active policy</label></p>';
        foreach(['enabled'=>'Enable timeline collection and display','services'=>'Allow linked hosting service context','servers'=>'Allow server hostname, IP and nameservers (requires hosting context)','domains'=>'Allow linked domain or hosting-matched registration status','messages'=>'Show bounded public ticket/reply excerpts (can contain personal information; private notes excluded)','tools'=>'Store normalized tool observations','ai'=>'Include bounded timeline data in AI ticket context'] as $key=>$label)$html.='<p><label><input type="checkbox" name="'.$key.'" value="1" '.($p[$key]?'checked':'').'> '.$esc($label).'</label></p>';
        foreach(['limit'=>['Maximum events',5,100],'days'=>['Snapshot retention days',1,90]] as $k=>$v)$html.='<p><label>'.$v[0].' <input type="number" name="'.$k.'" min="'.$v[1].'" max="'.$v[2].'" value="'.$p[$k].'"></label></p>';
        $html.='<button class="btn btn-primary" name="save_profile" value="1">Save policy</button></form><hr><h3>Policies</h3>';
        foreach(Capsule::table('tblsahdev_case_profiles')->orderBy('id')->get() as $r)$html.='<form method="post" style="margin:10px 0">'.generate_token('form').'<input type="hidden" name="profile_id" value="'.$r->id.'"><a href="addonmodules.php?module=sahdev&action=timeline_settings&edit='.$r->id.'">'.$esc($r->name).'</a> '.($r->active?'(active)':''). ' <button name="delete_profile" value="1" class="btn btn-xs btn-danger">Delete policy</button></form>';
        $html.='<h3>Recent snapshots (latest 50)</h3><p>Deleting a snapshot removes it from future timeline reads. Earlier AI replies and the separate tool execution history are not rewritten. Observations are immutable; manage collection using the policy above. Expired observations are hidden immediately and removed in bounded batches on capture or cleanup.</p><form method="post">'.generate_token('form').'<button class="btn btn-default" name="prune_snapshots" value="1">Clean up expired snapshots</button></form>';
        foreach(Capsule::table('tblsahdev_case_snapshots')->orderBy('id','desc')->limit(50)->get() as $r)$html.='<form method="post" class="well well-sm">'.generate_token('form').'<strong>Ticket '.$r->ticket_id.' · '.$esc($r->source).' · '.$esc($r->observed_at).'</strong><pre>'.$esc($r->payload).'</pre><button name="delete_snapshot" value="'.$r->id.'" class="btn btn-xs btn-danger">Delete snapshot</button> <button name="purge_ticket" value="'.$r->ticket_id.'" class="btn btn-xs btn-danger">Delete all snapshots for this ticket</button></form>';
        return $html;
    }
}
