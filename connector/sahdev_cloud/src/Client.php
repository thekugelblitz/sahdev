<?php
namespace SahdevConnector;
use WHMCS\Database\Capsule;

class Client
{
    private $connection;
    public function __construct(?array $connection = null)
    {
        if ($connection === null) {
            $stored = Capsule::table('tbladdonmodules')->where('module','sahdev_cloud')->where('setting','connection')->value('value');
            $connection = $stored ? json_decode(decrypt($stored),true) : null;
        }
        if (!is_array($connection) || empty($connection['secret']) || empty($connection['installation_id'])) throw new \RuntimeException('Enroll this connector from its addon page first.',422);
        $this->connection = $connection;
        self::base($connection['url']);
        if (rtrim((string)Capsule::table('tblconfiguration')->where('setting','SystemURL')->value('value'),'/') !== rtrim($connection['whmcs_url'],'/')) throw new \RuntimeException('The WHMCS URL changed. Verify and reconnect this brand.',403);
    }
    public static function base(string $url): void
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,63}$/iD',$parts['host'] ?? '') || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '',['','/'],true)) throw new \InvalidArgumentException('Use the HTTPS root URL of Sahdev Cloud.',422);
    }
    public static function enroll(string $url, string $id, string $token): void
    {
        self::base($url);
        if (!preg_match('/^[a-f0-9-]{36}$/D',$id) || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new \InvalidArgumentException('Invalid installation ID or enrollment token.',422);
        $settings = Capsule::table('tbladdonmodules')->where('module','sahdev_cloud');
        $pending = (clone $settings)->where('setting','pending_connection')->value('value');
        $pending = $pending ? json_decode(decrypt($pending),true) : [];
        $connection = ['url'=>rtrim($url,'/'),'installation_id'=>$id,'whmcs_url'=>rtrim((string)Capsule::table('tblconfiguration')->where('setting','SystemURL')->value('value'),'/'),
            'secret'=>($pending['installation_id'] ?? '') === $id && ($pending['url'] ?? '') === rtrim($url,'/') ? $pending['secret'] : bin2hex(random_bytes(32))];
        self::save('pending_connection',$connection);
        $client = new self($connection);
        $response = $client->request('POST','/api/v1/installations/enroll',['installation_id'=>$id,'token'=>$token,'secret'=>$connection['secret'],'whmcs_url'=>$connection['whmcs_url'],'version'=>'thin-0.2.0-dev'],false);
        if (($response['status'] ?? '') !== 'active') throw new \RuntimeException('Enrollment was not confirmed. Retry with the same token.',502);
        Capsule::connection()->transaction(function () use ($connection) {
            self::save('connection',$connection);
            Capsule::table('tbladdonmodules')->where('module','sahdev_cloud')->where('setting','pending_connection')->delete();
        });
    }
    private static function save(string $key, array $value): void
    {
        Capsule::table('tbladdonmodules')->updateOrInsert(['module'=>'sahdev_cloud','setting'=>$key],['value'=>encrypt(json_encode($value,JSON_THROW_ON_ERROR))]);
    }
    public function request(string $method, string $path, array $data = [], bool $signed = true): array
    {
        if (!in_array($method,['GET','POST'],true) || !preg_match('~^/api/v1/[a-z0-9/-]+$~D',$path)) throw new \InvalidArgumentException('Invalid bridge request.',422);
        $body = $method === 'GET' ? '' : json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $headers = ['Accept: application/json','Content-Type: application/json'];
        if ($signed) {
            $time = (string)time(); $nonce = bin2hex(random_bytes(16)); $id = $this->connection['installation_id'];
            $signature = hash_hmac('sha256',implode("\n",['v1',$id,$time,$nonce,$method,$path,hash('sha256',$body)]),$this->connection['secret']);
            $headers = array_merge($headers,['X-Sahdev-Installation: '.$id,'X-Sahdev-Timestamp: '.$time,'X-Sahdev-Nonce: '.$nonce,'X-Sahdev-Signature: '.$signature]);
        }
        $host = parse_url($this->connection['url'],PHP_URL_HOST); $ips = gethostbynamel($host);
        if (!$ips) throw new \RuntimeException('Cloud hostname did not resolve.',502);
        foreach ($ips as $ip) if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) || preg_match('/^(100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])|169\.254|192\.0\.0|198\.(18|19))\./',$ip)) throw new \RuntimeException('The cloud must resolve to public HTTPS addresses.',422);
        $curl = curl_init($this->connection['url'].$path); $response = '';
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],CURLOPT_WRITEFUNCTION=>function ($handle,$chunk) use (&$response) { if (strlen($response)+strlen($chunk)>1048576) return 0; $response .= $chunk; return strlen($chunk); }]);
        if ($method !== 'GET') curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
        $success = curl_exec($curl); $code = (int)curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        if ($success === false) throw new \RuntimeException('Cloud response is unconfirmed. Retry the same workflow; its request key has been preserved.',502);
        $result = json_decode($response,true);
        if ($code < 200 || $code >= 300 || !is_array($result)) {
            $messages = [401=>'Reconnect the installation.',402=>'Credits or the brand budget are exhausted.',403=>'Access or model permission denied.',404=>'This request is unavailable to your account.',409=>'Request conflict. Review the existing request before starting another.',422=>'Check the workflow, model and request limits.',423=>'Cloud access is suspended.',429=>'Request limit reached. Retry shortly.',503=>'This cloud feature is not ready for use.'];
            throw new \RuntimeException($messages[$code] ?? 'Cloud request failed. Contact the platform operator.',isset($messages[$code]) ? $code : 502);
        }
        return $result;
    }
}
