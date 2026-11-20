<?php
namespace SahdevConnector;

use RuntimeException;
use WHMCS\Database\Capsule;

/** Local permission checks and fixed projections for the signed cloud formatter. */
final class Records
{
    private const PAGE_SIZE = 15;

    public static function access(object $admin): void
    {
        $enabled = Capsule::table('tbladdonmodules')->where('module','sahdev_cloud')
            ->where('setting','copilot_records')->value('value');
        if ($enabled !== 'on') {
            throw new RuntimeException('Enable Copilot record lookup in the connector settings.',403);
        }
        Access::permission($admin,'List Clients');
        Access::permission($admin,'View Clients Summary');
    }

    public static function search(object $admin, $search): array
    {
        self::access($admin);
        if (!is_string($search) || mb_strlen(trim($search)) < 1 || mb_strlen($search) > 201) {
            throw new RuntimeException('Enter the client ID, full name or exact company name.',422);
        }
        $search = trim($search);
        $query = Capsule::table('tblclients');
        if (ctype_digit($search)) {
            $query->where('id',self::positiveInteger($search,4294967295));
        } else {
            $words = preg_split('/\s+/u',$search,-1,PREG_SPLIT_NO_EMPTY) ?: [];
            $query->where(function ($matches) use ($search,$words) {
                $matches->where('companyname',$search);
                // Split at each possible name boundary, for names such as "Mary Ann van Dyke".
                for ($index = 1; $index < min(count($words),12); $index++) {
                    $first = implode(' ',array_slice($words,0,$index));
                    $last = implode(' ',array_slice($words,$index));
                    $matches->orWhere(function ($name) use ($first,$last) {
                        $name->where('firstname',$first)->where('lastname',$last);
                    });
                }
            });
        }
        $clients = $query->orderBy('id')->limit(self::PAGE_SIZE+1)->get(['id','firstname','lastname','companyname','status']);
        return [
            'clients'=>$clients->take(self::PAGE_SIZE)->map(function ($row) {
                return ['id'=>(int) $row->id,'name'=>self::name($row),
                    'company'=>self::text($row->companyname,160),'status'=>self::text($row->status,50)];
            })->all(),
            'has_more'=>$clients->count() > self::PAGE_SIZE,
        ];
    }

    public static function page(object $admin, array $input, Client $cloud): array
    {
        self::access($admin);
        $resource = $input['resource'] ?? null;
        if ($resource === 'invoices') {
            Access::permission($admin,'View Invoice');
        } elseif ($resource === 'services') {
            Access::permission($admin,'View Clients Products/Services');
        } else {
            throw new RuntimeException('Choose invoices or services.',422);
        }
        $clientId = self::positiveInteger($input['client_id'] ?? null,4294967295);
        $page = self::positiveInteger($input['page'] ?? null,10000);
        $client = Capsule::table('tblclients')->where('id',$clientId)
            ->first(['id','firstname','lastname','currency']);
        if (!$client) {
            throw new RuntimeException('Client record was not found.',404);
        }

        $offset = ($page-1)*self::PAGE_SIZE;
        if ($resource === 'invoices') {
            $currency = Capsule::table('tblcurrencies')->where('id',$client->currency)->value('code');
            $currency = is_string($currency) && preg_match('/^[A-Z]{3}$/D',$currency) ? $currency : null;
            $rows = Capsule::table('tblinvoices')->where('userid',$clientId)
                ->orderByDesc('id')->offset($offset)->limit(self::PAGE_SIZE+1)
                ->get(['id','invoicenum','status','total','duedate'])->map(function ($row) {
                    return ['id'=>(int) $row->id,'number'=>self::text($row->invoicenum,50),
                        'status'=>self::text($row->status,50) ?: 'Unknown','total'=>(string) $row->total,
                        'due'=>$row->duedate === '0000-00-00' ? '' : self::text($row->duedate,30)];
                });
        } else {
            $currency = null;
            $canOpenProduct = Access::allows($admin,'Configure Products/Services');
            $rows = Capsule::table('tblhosting as service')->leftJoin('tblproducts as product','product.id','=','service.packageid')
                ->where('service.userid',$clientId)->orderByDesc('service.id')->offset($offset)->limit(self::PAGE_SIZE+1)
                ->get(['service.id','service.domain','service.domainstatus','service.billingcycle','product.id as product_id','product.name as product_name'])
                ->map(function ($row) use ($canOpenProduct) {
                    return ['id'=>(int) $row->id,'domain'=>self::text($row->domain,253),
                        'status'=>self::text($row->domainstatus,50) ?: 'Unknown','cycle'=>self::text($row->billingcycle,50),
                        'product'=>self::text($row->product_name,160),
                        'product_id'=>$canOpenProduct && $row->product_id ? (int) $row->product_id : null];
                });
        }
        return $cloud->request('POST','/api/v1/copilot/records',[
            'actor'=>'admin:'.$admin->id,'resource'=>$resource,
            'client'=>['id'=>$clientId,'name'=>self::name($client),'currency'=>$currency],
            'page'=>$page,'has_more'=>$rows->count() > self::PAGE_SIZE && $page < 10000,
            'rows'=>$rows->take(self::PAGE_SIZE)->all(),
        ]);
    }

    private static function name(object $row): string
    {
        return self::text($row->firstname.' '.$row->lastname,201) ?: 'Client #'.$row->id;
    }

    private static function text($value, int $max): string
    {
        return mb_substr(trim(strip_tags((string) $value)),0,$max,'UTF-8');
    }

    private static function positiveInteger($value, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string) $value) || (int) $value > $max) {
            throw new RuntimeException('Invalid record or page reference.',422);
        }
        return (int) $value;
    }
}
