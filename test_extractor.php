<?php
namespace WHMCS\Database {
    class Capsule {
        public static function table($table) {
            return new CapsuleQueryBuilder($table);
        }
    }
    
    class CapsuleQueryBuilder {
        private $table;
        public function __construct($table) { $this->table = $table; }
        public function where() { return $this; }
        public function orderBy() { return $this; }
        public function limit() { return $this; }
        public function join() { return $this; }
        
        public function first() { 
            if ($this->table === 'tbltickets') {
                return (object)[
                    'id' => 123, 'tid' => '12345', 'did' => 1, 'userid' => 1, 'contactid' => 0, 
                    'name' => 'John Doe', 'email' => 'test@test.com', 'title' => 'Test', 
                    'message' => 'Here is a screenshot: https://prnt.sc/MatFeFEuOUgz and a direct link: https://via.placeholder.com/150.png', 
                    'status' => 'Open', 'urgency' => 'High', 'lastreply' => '', 'date' => '2023-01-01'
                ];
            }
            if ($this->table === 'tblclients') return (object)['firstname' => 'John', 'lastname' => 'Doe'];
            if ($this->table === 'tblsahdev_settings') return (object)[];
            return null;
        }
        
        public function select() { return $this; }
        
        public function get() { 
            return collect([]); 
        }
        
        public function pluck($col) { 
            return collect([]); 
        }
        
        public function value($col) { 
            if ($this->table === 'tblticketdepartments' && $col === 'name') return 'Support';
            if ($this->table === 'tbltickets' && $col === 'attachment') return '';
            if ($this->table === 'tblconfiguration') return '/tmp';
            return null;
        }
    }
}

namespace {
    error_reporting(E_ALL);
    ini_set("display_errors", 1);
    
    // Mock Laravel collect handler
    if (!function_exists('collect')) {
        function collect($items) {
            return new class($items) {
                private $items;
                public function __construct($i) { $this->items = $i; }
                public function isEmpty() { return empty($this->items); }
                public function reverse() { return $this; }
                public function getIterator() { return new ArrayIterator($this->items); }
            };
        }
    }

    require_once __DIR__ . '/lib/TicketDataExtractor.php';

    $extractor = new \Sahdev\Lib\TicketDataExtractor(123, 1);
    $context = $extractor->getContext();

    echo "Extracted Images Count: " . count($context['attachments_images']) . "\n";
    foreach ($context['attachments_images'] as $img) {
        echo "- Source: " . $img['source'] . "\n";
        echo "- Length of Base64: " . strlen($img['url']) . "\n";
    }
}
