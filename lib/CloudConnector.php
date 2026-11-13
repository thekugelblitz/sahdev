<?php
namespace Sahdev\Lib;
use WHMCS\Database\Capsule;

/** Outbound-only SaaS transport. It never accepts SQL, code, admin identity or mutations from the cloud. */
class CloudConnector
{
    private $config;
    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $raw = Capsule::table('tbladdonmodules')->where('module','sahdev')->where('setting','cloud_connection')->value('value');
            $config = $raw ? json_decode(decrypt($raw),true) : null;
            if (is_array($config) && isset($config['whmcs_url'])) {
                $current = (string)Capsule::table('tblconfiguration')->where('setting','SystemURL')->value('value');
                if (rtrim($current,'/') !== rtrim($config['whmcs_url'],'/')) throw new \RuntimeException('The WHMCS URL changed. Verify and reconnect this brand before using cloud AI.');
            }
        }
        if (!is_array($config) || empty($config['installation_id']) || empty($config['secret'])) throw new \RuntimeException('Connect this installation to Sahdev Cloud in AI Providers first.');
        self::validateBase($config['url']);
        $this->config = $config;
    }
    public static function validateBase(string $url): void
    {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['query']) || isset($p['fragment']) || !in_array($p['path'] ?? '',['','/'],true) || !preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,63}$/iD',$p['host'])) throw new \InvalidArgumentException('Use the HTTPS root URL of Sahdev Cloud, without credentials, a port or path.');
    }
    public static function enroll(string $url, string $id, string $token): array
    {
        PermissionService::enforce(PermissionService::resolveCurrentAdminId(),PermissionService::PERM_SETTINGS_MANAGE);
        self::validateBase($url);
        if (!preg_match('/^[a-f0-9-]{36}$/D',$id) || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new \InvalidArgumentException('Invalid installation ID or enrollment token.');
        $old = Capsule::table('tbladdonmodules')->where('module','sahdev')->where('setting','cloud_pending_connection')->value('value');
        $old = $old ? json_decode(decrypt($old),true) : [];
        $config = ['url'=>rtrim($url,'/'),'installation_id'=>$id,'secret'=>($old['installation_id'] ?? '') === $id && ($old['url'] ?? '') === rtrim($url,'/') ? $old['secret'] : bin2hex(random_bytes(32))];
        $config['whmcs_url'] = (string)Capsule::table('tblconfiguration')->where('setting','SystemURL')->value('value');
        // Save before the handshake so the same enrollment can be retried after an ambiguous response.
        Capsule::table('tbladdonmodules')->updateOrInsert(['module'=>'sahdev','setting'=>'cloud_pending_connection'],['value'=>encrypt(json_encode($config))]);
        $client = new self($config);
        $whmcs = $config['whmcs_url'];
        $result = $client->request('POST','/api/v1/installations/enroll',['installation_id'=>$id,'token'=>$token,'secret'=>$config['secret'],'whmcs_url'=>$whmcs,'version'=>'saas-0.1'],false);
        if (($result['status'] ?? '') !== 'active') throw new \RuntimeException('Cloud enrollment did not complete. Retry with the same token before it expires.');
        Capsule::connection()->transaction(function () use ($config) {
            Capsule::table('tbladdonmodules')->updateOrInsert(['module'=>'sahdev','setting'=>'cloud_connection'],['value'=>encrypt(json_encode($config))]);
            Capsule::table('tbladdonmodules')->where('module','sahdev')->where('setting','cloud_pending_connection')->delete();
        });
        return $result;
    }
    public static function signature(string $id, string $time, string $nonce, string $method, string $path, string $body, string $secret): string
    {
        return hash_hmac('sha256',implode("\n",['v1',$id,$time,$nonce,strtoupper($method),$path,hash('sha256',$body)]),$secret);
    }
    public function request(string $method, string $path, array $data = [], bool $signed = true): array
    {
        if (!preg_match('~^/api/v1/[a-z0-9/-]+$~D',$path)) throw new \InvalidArgumentException('Invalid cloud API route.');
        $body = $method === 'GET' ? '' : json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $headers = ['Accept: application/json','Content-Type: application/json'];
        if ($signed) {
            $time = (string)time(); $nonce = bin2hex(random_bytes(16)); $id = $this->config['installation_id'];
            $headers[] = 'X-Sahdev-Installation: '.$id; $headers[] = 'X-Sahdev-Timestamp: '.$time; $headers[] = 'X-Sahdev-Nonce: '.$nonce;
            $headers[] = 'X-Sahdev-Signature: '.self::signature($id,$time,$nonce,$method,$path,$body,$this->config['secret']);
        }
        return $this->transport($method,$this->config['url'].$path,$body,$headers);
    }
    protected function transport(string $method,string $url,string $body,array $headers): array
    {
        $host = parse_url($url,PHP_URL_HOST); $ips = gethostbynamel($host);
        if (!$ips) throw new \RuntimeException('Cloud hostname did not resolve.');
        foreach ($ips as $ip) if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) || preg_match('/^(100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])|169\.254|192\.0\.0|198\.(18|19))\./',$ip)) throw new \RuntimeException('Cloud must resolve to a public HTTPS server.');
        $ch = curl_init($url); $response = '';
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],CURLOPT_WRITEFUNCTION=>function ($ch,$chunk) use (&$response) { if (strlen($response)+strlen($chunk)>2097152) return 0; $response .= $chunk; return strlen($chunk); }]);
        if ($method !== 'GET') curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
        $ok = curl_exec($ch); $code = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if (!$ok) throw new \RuntimeException('Cloud transport failed. Retry the same request; its usage state is preserved.');
        $result = json_decode($response,true);
        if ($code < 200 || $code >= 300 || !is_array($result)) {
            $message = [401=>'Cloud credentials were rejected. Reconnect this installation.',402=>'Cloud credits or the brand monthly budget are exhausted.',403=>'This cloud model is not allowed by the plan.',409=>'Cloud operation conflict. Check the request state before retrying.',422=>'Cloud rejected the model or request limits. Check its configuration.',423=>'Cloud AI access is suspended.',429=>'Cloud request limit reached. Retry shortly.',503=>'The cloud brand database or worker is not ready.'];
            throw new \RuntimeException($message[$code] ?? 'Cloud request failed (HTTP '.$code.'). Check Command Station.');
        }
        return $result;
    }
    public function infer(array $payload): array
    {
        SchemaManager::ensureCloudRequestsTable();
        $actor = PermissionService::resolveCurrentAdminId();
        $payload['actor'] = $actor > 0 ? 'admin:'.$actor : (!empty($_SESSION['uid']) ? 'client:'.(int)$_SESSION['uid'] : 'system:module');
        $hash = hash('sha256',$this->config['installation_id'].json_encode($payload));
        $key = Capsule::connection()->transaction(function () use ($hash) {
            Capsule::table('tblsahdev_cloud_requests')->insertOrIgnore(['request_hash'=>$hash,'request_key'=>bin2hex(random_bytes(24)),'created_at'=>date('Y-m-d H:i:s'),'state'=>'pending']);
            $row = Capsule::table('tblsahdev_cloud_requests')->where('request_hash',$hash)->lockForUpdate()->first();
            // Pending/unknown requests never expire into a second paid operation. Completed identical inputs cache for five minutes.
            if (in_array($row->state,['settled','released'],true) && strtotime($row->created_at) < time()-300) {
                $key = bin2hex(random_bytes(24)); Capsule::table('tblsahdev_cloud_requests')->where('request_hash',$hash)->update(['request_key'=>$key,'state'=>'pending','created_at'=>date('Y-m-d H:i:s')]); return $key;
            }
            return $row->request_key;
        });
        $payload['idempotency_key'] = $key;
        $result = $this->request('POST','/api/v1/inference',$payload);
        $deadline = microtime(true)+110;
        while (in_array($result['state'] ?? '',['reserved','dispatched'],true) && microtime(true) < $deadline) {
            usleep(1000000);
            $result = $this->request('GET','/api/v1/inference/'.$result['id']);
        }
        if (in_array($result['state'] ?? '',['settled','released'],true)) Capsule::table('tblsahdev_cloud_requests')->where('request_hash',$hash)->update(['state'=>$result['state']]);
        if (($result['state'] ?? '') !== 'settled') throw new \RuntimeException('Cloud request '.($result['id'] ?? 'unknown').' is '.($result['state'] ?? 'unconfirmed').'. '.(($result['state'] ?? '') === 'released' ? 'Credits released; check the configured model/provider.' : 'Its credit hold is preserved. Retry identical input or review it in Command Station.'));
        return $result['result'];
    }
}
