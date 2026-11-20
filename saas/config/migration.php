<?php
// Versioned acceptance inventory. Status changes require reviewed evidence, not an environment flag.
return json_decode(file_get_contents(__DIR__.'/release-register.json'), true, 512, JSON_THROW_ON_ERROR);
