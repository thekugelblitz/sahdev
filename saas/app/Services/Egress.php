<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;
final class Egress
{
    public static function validate(string $url, array $hosts): string
    {
        $p = parse_url($url);
        abort_unless($p && ($p['scheme'] ?? '') === 'https' && in_array($p['host'] ?? '',$hosts,true) && !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']) && (!isset($p['port']) || $p['port'] === 443),422,'Endpoint must use HTTPS on an approved provider hostname.');
        return $p['host'];
    }
    public static function publicIp(string $ip): bool
    {
        return (bool)filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) && !IpUtils::checkIp($ip,['100.64.0.0/10','169.254.0.0/16','192.0.0.0/24','198.18.0.0/15','224.0.0.0/4','::ffff:0:0/96','2001:db8::/32','fc00::/7','fe80::/10','ff00::/8']);
    }
    public function request(string $url, array $hosts)
    {
        $host = self::validate($url,$hosts); $addresses = app(DnsResolver::class)->addresses($host);
        abort_unless($addresses && collect($addresses)->every(fn ($ip) => self::publicIp($ip)),422,'Provider DNS must resolve only to public addresses.');
        $ip = $addresses[0]; if (str_contains($ip,':')) $ip = '['.$ip.']';
        // Pin the validated address; disable redirects and environment-provided HTTP proxies.
        return Http::acceptJson()->connectTimeout(10)->timeout(90)->withoutRedirecting()->withOptions(['proxy'=>'','curl'=>[CURLOPT_RESOLVE=>[$host.':443:'.$ip]],'on_headers'=>function ($response) { if ((int)$response->getHeaderLine('Content-Length') > 2097152) throw new \RuntimeException('Response is too large.'); }]);
    }
}
