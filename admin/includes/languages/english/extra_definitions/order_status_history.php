<?php

// Internal messages
define('ORDER_STATUS_HISTORY_UNKNOWN_MODULE', '--');
define('ORDER_STATUS_HISTORY_CUSTOMER', 'customer');

// Errors logged if the order status could not be updated
define('ORDER_STATUS_HISTORY_MISSING_ORDER', 'Order \'%s\' was not found in the database.' . PHP_EOL . '%s' . PHP_EOL);
define('ORDER_STATUS_HISTORY_MISSING_ORDER_STATUS', 'Order Status \'%s\' was not found in the database for language \'%s\'.' . PHP_EOL . '%s' . PHP_EOL);
define('ORDER_STATUS_HISTORY_NOT_UPDATED', 'Unable to update the order status history in the database. New Status:'  . PHP_EOL . '%s' . PHP_EOL . 'Stack Trace:' .PHP_EOL . '%s' . PHP_EOL);
