<?php function test() { $var = "hello"; ob_start(); ?>var x = "<?php echo $var; ?>";<?php return ob_get_clean(); } echo test();
