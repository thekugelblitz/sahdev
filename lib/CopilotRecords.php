<?php
namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

/** Relational result cards use verified record IDs and never depend on model-generated URLs. */
final class CopilotRecords
{
    public static function url(string $resource, array $row): ?string
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) return null;
        switch ($resource) {
            case 'clients': return 'clientssummary.php?userid=' . $id;
            case 'invoices': return 'invoices.php?action=edit&id=' . $id;
            case 'services': return isset($row['userid']) ? 'clientsservices.php?userid=' . (int) $row['userid'] . '&id=' . $id : null;
            case 'products': return 'configproducts.php?action=edit&id=' . $id;
            case 'tickets': return 'supporttickets.php?action=view&id=' . $id;
            case 'ticket_notes': return isset($row['ticketid']) ? 'supporttickets.php?action=view&id=' . (int) $row['ticketid'] : null;
            case 'client_notes': return isset($row['userid']) ? 'clientsnotes.php?userid=' . (int) $row['userid'] : null;
        }
        return null;
    }

    private static function relatedUrl(int $adminId, string $resource, int $id): ?string
    {
        try {
            $row = CopilotDatabasePolicy::query($adminId, $resource)->where('id', $id)->first(CopilotDatabasePolicy::readFields($adminId, $resource));
            return $row ? self::url($resource, (array) $row) : null;
        } catch (\Throwable $denied) { return null; }
    }

    public static function card(array $result, int $adminId, string $nextPrompt = ''): array
    {
        $resource = $result['resource'];
        $fields = CopilotDatabasePolicy::readFields($adminId, $resource);
        $primary = [
            'clients'=>['id','firstname','lastname','companyname','email','status','credit','currency'],
            'invoices'=>['id','invoicenum','status','total','duedate','userid'],
            'services'=>['id','domain','packageid','domainstatus','amount','billingcycle','nextduedate','userid'],
            'products'=>['id','name','type','hidden'],
            'tickets'=>['id','tid','title','status','urgency','flag','userid'],
        ];
        $columns = array_values(array_intersect($primary[$resource] ?? $fields, $fields));
        $labels = ['id'=>'ID','userid'=>'Client','clientid'=>'Client','packageid'=>'Product','ticketid'=>'Ticket','tid'=>'Ticket number',
            'firstname'=>'First name','lastname'=>'Last name','companyname'=>'Company','invoicenum'=>'Invoice number',
            'domainstatus'=>'Status','duedate'=>'Due date','datepaid'=>'Paid on','paymentmethod'=>'Payment method',
            'nextduedate'=>'Next due date','billingcycle'=>'Billing cycle','urgency'=>'Priority','flag'=>'Assigned admin',
            'adminid'=>'Created by admin','lastreply'=>'Last reply','did'=>'Department','suspendreason'=>'Suspension reason'];
        $rows = [];
        foreach ($result['rows'] as $record) {
            $record = array_intersect_key((array) $record, array_flip($fields));
            $cells = [];
            foreach ($columns as $field) {
                $url = null;
                if ($field === 'id') $url = self::url($resource, $record);
                elseif (in_array($field, ['userid','clientid'], true)) $url = self::relatedUrl($adminId, 'clients', (int) ($record[$field] ?? 0));
                elseif ($field === 'packageid') $url = self::relatedUrl($adminId, 'products', (int) ($record[$field] ?? 0));
                elseif ($field === 'ticketid') $url = self::relatedUrl($adminId, 'tickets', (int) ($record[$field] ?? 0));
                $cells[] = ['text'=>(string) ($record[$field] ?? ''), 'url'=>$url, 'badge'=>in_array($field, ['status','domainstatus','urgency'], true)];
            }
            $details = [];
            foreach (array_diff($fields, $columns) as $field) if (isset($record[$field]) && (string) $record[$field] !== '') $details[$labels[$field] ?? ucfirst($field)] = (string) $record[$field];
            $rows[] = ['id'=>(int) $record['id'], 'cells'=>$cells, 'details'=>$details];
        }
        $first = $rows ? (($result['page'] - 1) * $result['page_size'] + 1) : 0;
        $last = $rows ? $first + count($rows) - 1 : 0;
        return ['kind'=>'records', 'resource'=>$resource, 'title'=>CopilotDatabasePolicy::resources()[$resource]['label'],
            'subtitle'=>"Showing {$first}–{$last} of {$result['total']} permitted records", 'columns'=>array_map(function ($field) use ($labels) { return $labels[$field] ?? ucfirst($field); }, $columns), 'rows'=>$rows,
            'next_prompt'=>$result['has_more'] ? $nextPrompt : '', 'page'=>$result['page'], 'total'=>$result['total'],
            'context'=>['resource'=>$resource, 'record_ids'=>array_column($rows, 'id'), 'total'=>$result['total'], 'page'=>$result['page']]];
    }

    public static function read(string $action, array $params, int $adminId): array
    {
        if ($action === 'database_capabilities') return ['success'=>true, 'data'=>CopilotDatabasePolicy::capabilities($adminId)];
        $page = max(1, min(10000, (int) ($params['page'] ?? 1)));
        $next = '';
        if ($action === 'client_lookup') {
            $search = trim((string) ($params['search'] ?? ''));
            if ($search === '') throw new \InvalidArgumentException('A client ID, name, company, or email is required.');
            $fields = CopilotDatabasePolicy::readFields($adminId, 'clients');
            $q = CopilotDatabasePolicy::query($adminId, 'clients');
            if (ctype_digit($search)) $q->where('id', (int) $search);
            else $q->where(function ($nested) use ($search, $fields) {
                $nested->where('id', 0);
                foreach (['email','companyname','firstname','lastname'] as $field) {
                    if (in_array($field, $fields, true)) $nested->orWhere($field, 'LIKE', '%' . $search . '%');
                }
                if (in_array('firstname', $fields, true) && in_array('lastname', $fields, true)) {
                    $words = preg_split('/\s+/', $search, 2);
                    if (count($words) === 2) $nested->orWhere(function ($both) use ($words) { $both->where('firstname', 'LIKE', '%' . $words[0] . '%')->where('lastname', 'LIKE', '%' . $words[1] . '%'); });
                }
            });
            $total = (clone $q)->count();
            $limit = CopilotDatabasePolicy::rule($adminId, 'clients', 'select')['limit'];
            $result = ['resource'=>'clients', 'rows'=>$q->orderBy('id', 'desc')->offset(($page-1)*$limit)->limit($limit)->get($fields)->toArray(), 'total'=>$total, 'page'=>$page, 'page_size'=>$limit, 'has_more'=>$page*$limit<$total];
            $next = 'Find clients matching ' . $search . ', page ' . ($page+1);
        } elseif (in_array($action, ['client_invoices','client_services'], true)) {
            $resource = $action === 'client_invoices' ? 'invoices' : 'services';
            $clientId = (int) ($params['client_id'] ?? 0);
            if (!CopilotDatabasePolicy::query($adminId, 'clients')->where('id', $clientId)->exists()) throw new \RuntimeException('Client is outside your permitted scope.');
            $filters = ['userid'=>$clientId];
            if (!empty($params['status'])) $filters[$resource === 'invoices' ? 'status' : 'domainstatus'] = $params['status'];
            $result = CopilotDatabasePolicy::select($adminId, $resource, $filters, $page);
            $result['client_id'] = $clientId;
            $next = 'Show ' . $resource . ' for client #' . $clientId . ', page ' . ($page+1) . (!empty($params['status']) ? ', status ' . $params['status'] : '');
        } elseif ($action === 'open_tickets') {
            $fields = CopilotDatabasePolicy::readFields($adminId, 'tickets');
            if (!in_array('status', $fields, true)) throw new \RuntimeException('Ticket status is not readable for this role.');
            $q = CopilotDatabasePolicy::query($adminId, 'tickets')->whereIn('status', ['Open','Customer-Reply','In Progress','On Hold']);
            $limit = min(CopilotDatabasePolicy::rule($adminId, 'tickets', 'select')['limit'], max(1, min(50, (int) ($params['limit'] ?? 20))));
            $total = (clone $q)->count();
            $result = ['resource'=>'tickets', 'rows'=>$q->orderBy('id', 'desc')->offset(($page-1)*$limit)->limit($limit)->get($fields)->toArray(), 'total'=>$total, 'page'=>$page, 'page_size'=>$limit, 'has_more'=>$page*$limit<$total];
            $next = 'Show open tickets page ' . ($page+1);
        } else {
            $map = ['invoice_lookup'=>['invoices','invoice_id'], 'service_lookup'=>['services','service_id'], 'product_lookup'=>['products','product_id'], 'ticket_lookup'=>['tickets','ticket_id']];
            if (isset($map[$action])) {
                [$resource, $idKey] = $map[$action]; $filters = ['id'=>(int) ($params[$idKey] ?? 0)];
            } elseif ($action === 'gateway_diagnostics') {
                $resource = 'gateway_logs'; $filters = empty($params['gateway']) ? [] : ['gateway'=>$params['gateway']];
            } elseif ($action === 'database_select') {
                $resource = (string) ($params['resource'] ?? '');
                $filters = json_decode((string) ($params['filters_json'] ?? '{}'), true);
                if (!is_array($filters)) throw new \InvalidArgumentException('filters_json must be an object of equality filters.');
            } else throw new \RuntimeException('Unsupported records tool.');
            $result = CopilotDatabasePolicy::select($adminId, $resource, $filters, $page);
            $next = 'Query ' . $resource . ' with equality filters ' . json_encode($filters) . ', page ' . ($page+1);
        }
        $card = self::card($result, $adminId, $next);
        if (!empty($result['client_id'])) {
            $card['context']['client_id'] = $result['client_id'];
            $card['links'][] = ['text'=>'Open client #' . $result['client_id'], 'url'=>self::relatedUrl($adminId, 'clients', $result['client_id'])];
            $card['subtitle'] .= ' · Client #' . $result['client_id'];
        }
        if ($action === 'client_lookup' && $result['total'] > 1) $result['instruction'] = 'Multiple clients match. Ask the administrator to choose a client ID before accessing related records or proposing changes.';
        return ['success'=>true, 'data'=>$result, 'result_cards'=>[$card]];
    }
}
