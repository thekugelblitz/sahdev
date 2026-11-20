<?php
namespace App\Domain\Workflows;

use Illuminate\Support\Facades\Validator;

/** Convert bounded connector observations into record cards. No provider inference is needed. */
final class CopilotRecords
{
    private const PAGE_SIZE = 15;

    public function validate(array $input): array
    {
        $resource = $input['resource'] ?? null;
        $fields = $resource === 'invoices'
            ? ['number'=>'nullable|string|max:50', 'total'=>'required|string|max:30', 'due'=>'nullable|string|max:30']
            : ['domain'=>'nullable|string|max:253', 'product'=>'nullable|string|max:160',
                'product_id'=>'nullable|integer|min:1|max:4294967295', 'cycle'=>'nullable|string|max:50'];
        $rules = [
            'actor'=>'required|string|regex:/^admin:[1-9][0-9]{0,9}$/D',
            'resource'=>'required|in:invoices,services',
            'client'=>'required|array:id,name,currency',
            'client.id'=>'required|integer|min:1|max:4294967295',
            'client.name'=>'required|string|max:201',
            'client.currency'=>'nullable|string|regex:/^[A-Z]{3}$/D',
            'page'=>'required|integer|min:1|max:10000',
            'has_more'=>'required|boolean',
            'rows'=>'present|array|max:'.self::PAGE_SIZE,
            'rows.*'=>'required|array:'.implode(',', array_merge(['id','status'], array_keys($fields))),
            'rows.*.id'=>'required|integer|min:1|max:4294967295|distinct',
            'rows.*.status'=>'required|string|max:50',
        ];
        foreach ($fields as $field => $rule) {
            $rules['rows.*.'.$field] = $rule;
        }
        return Validator::make($input, $rules)->validate();
    }

    public function card(array $data): array
    {
        $client = $data['client'];
        $clientId = (int) $client['id'];
        $invoices = $data['resource'] === 'invoices';
        $rows = [];
        foreach ($data['rows'] as $row) {
            $id = (int) $row['id'];
            if ($invoices) {
                $rows[] = [
                    $this->link((string) (($row['number'] ?? '') ?: '#'.$id), 'invoice', $id),
                    ['text'=>$row['status']],
                    ['text'=>trim($row['total'].' '.($client['currency'] ?? ''))],
                    ['text'=>($row['due'] ?? '') ?: '—'],
                ];
                continue;
            }
            $product = ($row['product'] ?? '') ?: '—';
            $rows[] = [
                $this->link(($row['domain'] ?? '') ?: 'Service #'.$id, 'service', $id, $clientId),
                empty($row['product_id']) ? ['text'=>$product] : $this->link($product, 'product', (int) $row['product_id']),
                ['text'=>$row['status']],
                ['text'=>($row['cycle'] ?? '') ?: '—'],
            ];
        }
        return [
            'title'=>$invoices ? 'Invoices' : 'Services',
            'client'=>$this->link($client['name'], 'client', $clientId),
            'columns'=>$invoices ? ['Invoice','Status','Total','Due date'] : ['Service','Product','Status','Billing cycle'],
            'rows'=>$rows,
            'page'=>(int) $data['page'],
            'has_more'=>(bool) $data['has_more'],
        ];
    }

    private function link(string $text, string $type, int $id, ?int $clientId = null): array
    {
        return ['text'=>$text, 'record'=>['type'=>$type, 'id'=>$id, 'client_id'=>$clientId]];
    }
}
