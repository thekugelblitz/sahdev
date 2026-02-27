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
                    // This is the EXACT message from the user's issue
                    'message' => "Hi Team,\n\nI have made full payment, stil account shows suspended. I have attached the confirmation payment receipt below.\n\nKind regards\nAbhishek\n\n", 
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
            // Mock replies
            if ($this->table === 'tblticketreplies' && $col === 'attachment') {
                return collect([]);
            }
            if ($this->table === 'tblticketreplies' && $col === 'message') {
                return collect([]);
            }
            return collect([]); 
        }
        
        // Mock attached images! The user says "The ticket we tested has two attached images"
        public function value($col) { 
            if ($this->table === 'tblticketdepartments' && $col === 'name') return 'Support';
            if ($this->table === 'tbltickets' && $col === 'attachment') return 'receipt1.jpg|receipt2.png';
            if ($this->table === 'tblconfiguration') return __DIR__;
            return null;
        }
    }
}

namespace {
    error_reporting(E_ALL);
    ini_set("display_errors", 1);
    
    // Create fake files
    file_put_contents('receipt1.jpg', 'fake image data');
    file_put_contents('receipt2.png', 'fake image data');

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

    // Fix a potential undefined variable in extractImageAttachments
    $attachments_dir = __DIR__;
    
    $extractor = new \Sahdev\Lib\TicketDataExtractor(123, 1);
    $context = $extractor->getContext();

    echo "Extracted Images Count: " . count($context['attachments_images']) . "\n";
    foreach ($context['attachments_images'] as $img) {
        echo "- Source: " . $img['source'] . "\n";
        echo "- Length of Base64: " . strlen($img['url']) . "\n";
    }
    
    // Cleanup
    unlink('receipt1.jpg');
    unlink('receipt2.png');
}
