<?php
// Run only against a fresh, disposable database. The fixture never connects to WHMCS.
// SAHDEV_COPILOT_MYSQL_DSN=mysql:host=127.0.0.1;port=3306;dbname=sahdev_copilot_test
if (!getenv('SAHDEV_COPILOT_MYSQL_DSN')) { echo "SKIP: disposable MySQL database not configured.\n"; exit; }
if (($argv[1] ?? '') === 'worker') putenv('SAHDEV_COPILOT_DATABASE_SKIP_SEED=1');
require __DIR__.'/copilot_database_fixture.php';
use WHMCS\Database\Capsule as DB;
use Sahdev\Lib\CopilotDatabasePolicy as Policy;
use Sahdev\Lib\CopilotOperationService as Ops;
use Sahdev\Lib\SafeOpsService as Safe;

if (($argv[1] ?? '') === 'worker') {
    echo "READY\n"; flush();
    if ($argv[2] === 'rollback') $result=Ops::rollback((int)$argv[3],1);
    else {
        $saved=DB::table('tblsahdev_ops_proposals')->where('proposal_uuid',$argv[3])->first();
        $card=json_decode($saved->card_json,true);
        $result=Ops::execute($card['action_key'],$card['params'],1,1,null,$argv[3]);
    }
    echo json_encode($result);exit;
}
error_reporting(E_ALL);
set_error_handler(function($level,$message,$file,$line){if($level & error_reporting())throw new ErrorException($message,0,$level,$file,$line);});
$checks=0;
function expect($ok,$message){global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function credit($amount=2.25){return Safe::handleOperationRequest('invoice_add_credit',['client_id'=>12,'amount'=>$amount,'description'=>'Concurrent test credit'],1,1);}
function executePlan($card){return Ops::execute($card['action_key'],$card['params'],1,1,null,$card['proposal_id']);}
function concurrent($mode,$id) {
    DB::$db->beginTransaction();
    DB::$db->query('SELECT id FROM tblsahdev_settings FOR UPDATE')->fetchAll();
    $workers=[];
    try {
        for($i=0;$i<2;$i++) {
            $process=proc_open([PHP_BINARY,__FILE__,'worker',$mode,(string)$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Cannot start worker');
            fclose($pipes[0]);
            if(trim(fgets($pipes[1]))!=='READY')throw new RuntimeException('Worker did not start: '.stream_get_contents($pipes[2]));
            $workers[]=[$process,$pipes];
        }
        DB::$db->commit();
        $results=[];
        foreach($workers as [$process,$pipes]) {
            $out=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($process)!==0 || $errors)throw new RuntimeException('Worker failed: '.$errors.$out);
            $results[]=json_decode($out,true);
        }
        return $results;
    } finally { if(DB::$db->inTransaction())DB::$db->rollBack(); }
}
$rules=[];
foreach(Policy::resources() as $resource=>$definition)$rules[$resource]=Policy::normalizeRule($resource,['operations'=>array_fill_keys($definition['ops'],true),'read_fields'=>$definition['read'],'write_fields'=>$definition['write']]);
DB::table('tblsahdev_settings')->where('id',1)->update(['copilot_database_policy_json'=>json_encode(['enabled'=>true,'roles'=>[1=>$rules]])]);
$proposal=credit();expect($proposal['success'],'InnoDB engine checks allow a saved credit plan: '.($proposal['error']??''));
[$first,$second]=concurrent('execute',$proposal['proposal_id']);
expect($first['success'] && $second['success'],'Both simultaneous approvals complete safely');
expect($first['journal_id']===$second['journal_id'],'Concurrent approvals return the same journal ID');
expect(DB::table('tblsahdev_ops_journal')->count()===1 && DB::table('tblcredit')->count()===1,'Exactly one mutation and journal survive concurrent approval');
expect(DB::table('tblclients')->where('id',12)->value('credit')==='7.25','Concurrent approval adds credit only once');
[$firstUndo,$secondUndo]=concurrent('rollback',$first['journal_id']);
expect($firstUndo['success'] && $secondUndo['success'],'Simultaneous rollback is idempotent');
expect(DB::table('tblcredit')->count()===0 && DB::table('tblclients')->where('id',12)->value('credit')==='5.00','Concurrent rollback restores balance and exact ledger row once');
expect(DB::table('tblsahdev_ops_events')->where('event','rolled_back')->count()===1,'Only one durable rollback event is recorded');
$failure=credit();
DB::$db->exec("CREATE TRIGGER reject_journal BEFORE INSERT ON tblsahdev_ops_journal FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected journal failure'");
expect(!executePlan($failure)['success'],'Native MySQL journal failure rejects execution');
expect(DB::table('tblcredit')->count()===0 && DB::table('tblclients')->where('id',12)->value('credit')==='5.00','Native InnoDB rollback restores every target row after journal failure');
expect(DB::table('tblsahdev_ops_journal')->count()===1 && Ops::refresh($failure)['status']==='pending','Failed transaction leaves the journal and proposal consistent');
DB::$db->exec('DROP TRIGGER reject_journal');
DB::$db->exec('ALTER TABLE tblcredit ENGINE=MyISAM');
$unsupported=credit();expect(!$unsupported['success'] && strpos($unsupported['error'],'InnoDB')!==false,'A real MyISAM target is refused before a write');
DB::$db->exec('ALTER TABLE tblcredit ENGINE=InnoDB');
DB::$db->exec('ALTER TABLE tblclients ENGINE=MyISAM');
expect(!Safe::handleOperationRequest('database_plan',['resource'=>'client_notes','operation'=>'create','parent_id'=>12,'values_json'=>'{"note":"parent lock test"}'],1,1)['success'],'Nontransactional parent tables cannot participate in a reviewed plan');
DB::$db->exec('ALTER TABLE tblclients ENGINE=InnoDB');
expect(executePlan($failure)['success'],'Retry after an aborted transaction commits once');
echo "PASS: $checks MySQL/InnoDB checks (concurrent approval, concurrent rollback, engine validation, real failed commit).\n";
