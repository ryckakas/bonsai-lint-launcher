<?php

// Stands in for the binary: reports what it was given, then exits with HELPER_EXIT.

fwrite(STDOUT, json_encode(['args' => array_slice($argv, 1), 'stdin' => stream_get_contents(STDIN)]));
exit((int) getenv('HELPER_EXIT'));
