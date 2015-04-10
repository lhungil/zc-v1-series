<?php

/**
 * Updates the order status history in the database.
 *
 * The order status history will not be updated if the specified comment is
 * empty (or null) and the order is already the specified order status.
 *
 * If an error occurs while performing the update a message will be written to
 * the configured error log (usually the Zen Cart debug logs).
 *
 * Two (read only) notifiers are called by this method:
 *   NOTIFY_UPDATE_ORDER_STATUS indicates the order's status has been updated.
 *   NOTIFY_UPDATE_ORDER_STATUS_HISTORY indicates the order status history has
 *     been updated and the specified order status history exists in the database.
 *
 * @param int $orders_id the associated order for the order status history.
 * @param string $comment a comment to add to the order status history.
 * @param int $orders_status_id the order status of the order status history.
 *   By default the current order status of the associated order is used.
 *
 * @param int $notify -1 to hide the order status history from the customer,
 *   0 to allow the customer to see the order status history, and 1 to allow
 *   the customer to see the order status history and indicate the customer
 *   was notified (via email). By default this is set to -1 (hidden).
 *
 * @param string $updated_by the name of the module or admin updating the order
 *   status history. Modules updating the order status history programatically
 *   (updates not initiated directly by a customer or admin) should provide
 *   their name. When not specified the first item found in the following
 *   list will be used: the name and id of the current admin, the contents of
 *   ORDER_STATUS_HISTORY_CUSTOMER if triggered by a customer, or the contents
 *   of ORDER_STATUS_HISTORY_UNKNOWN_MODULE.
 *
 * @return boolean|int false if an error occurs while updating the order status
 *   history, otherwise the id of the order status history in the databse
 *   corresponding to the update.
 */
function zen_order_status_history_update($orders_id, $comment, $orders_status_id = null, $notify = -1, $updated_by = null) {
  global $db, $zco_notifier;

  // Verify the order exists
  $sql =
    'SELECT `orders_id`, `orders_status`, `customers_name`, ' .
    '`customers_email_address` FROM `' . TABLE_ORDERS . '` ' .
    'WHERE `orders_id`=\':orders_id:\'';
  $sql = $db->bindVars($sql, ':orders_id:', $orders_id, 'integer');

  $order = $db->Execute($sql);
  if($order->EOF) {
    // Return false to indicate failure
    $e = new Exception();
    error_log(sprintf(
      ORDER_STATUS_HISTORY_MISSING_ORDER,
      $orders_id,
      $e->getTraceAsString()
    ));
    unset($e);
    return false;
  }

  // Verify the order status
  if($orders_status_id === null || $orders_status_id == -1) {
    // None specified (or no change specified)
    $orders_status_id = (int)$order->fields['orders_status'];
  }
  else {
    // Status specified, verify
    $sql =
      'SELECT `orders_status_id` FROM `' . TABLE_ORDERS_STATUS . '` ' .
      'WHERE `language_id`=\':language_id:\' ' .
      'AND `orders_status_id`=\':status_id:\'';
    $sql = $db->bindVars($sql, ':language_id:', $_SESSION['languages_id'], 'integer');
    $sql = $db->bindVars($sql, ':status_id:', $orders_status_id, 'integer');

    $check = $db->Execute($sql);
    if($check->EOF) {
      // Return false to indicate failure
      $e = new Exception();
      error_log(sprintf(
        ORDER_STATUS_HISTORY_MISSING_ORDER_STATUS,
        $orders_status_id,
        $_SESSION['languages_id'],
        $e->getTraceAsString()
      ));
      unset($e);
      return false;
    }
  }

  // Update the order status and last modified timestamp on the order
  $sql_data_array = array(
    'orders_status' => $orders_status_id,
    'last_modified' => 'now()'
  );
  $sql = '`orders_id`=\':orders_id:\'';
  $sql = $db->bindVars($sql, ':orders_id:', $orders_id, 'integer');
  zen_db_perform(TABLE_ORDERS, $sql_data_array, 'update', $sql);

  // Notify any observers letting them know the order status was updated
  if($orders_status_id != $order->fields['orders_status']) {
    $zco_notifier->notify(
      'NOTIFY_UPDATE_ORDER_STATUS',
      array(
        'orders_id' => $orders_id,
        'prev_orders_status_id' => $order->fields['orders_status'],
        'next_orders_status_id' => $orders_status_id,
        'updated_by' => $updated_by
      )
    );
  }
  // If the order status did not change and no comment was entered
  else if(!zen_not_null($comment)) {
    $sql =
      'SELECT `orders_status_history_id` ' .
      'FROM `' . TABLE_ORDERS_STATUS_HISTORY . '` ' .
      'WHERE `orders_id`=\':orders_id:\' ' .
      'AND `orders_status_id`=\':orders_status_id:\' ' .
      'ORDER BY `date_added` DESC LIMIT 1';
    $sql = $db->bindVars($sql, ':orders_id:', $orders_id, 'integer');
    $sql = $db->bindVars($sql, ':orders_status_id:', $orders_status_id, 'integer');
    $check = $db->Execute($sql);
    if(!$check->EOF) {
      // We found the matching order status history, Return the id.
      return (int)$check->fields['orders_status_history_id'];
    }

    // No matching Order Status History entry was found for the order and status
    // Continue with processing to create a new Order Status History
  }

  // If not specified, generate the updated_by field
  if($updated_by === null) {
    $updated_by = ORDER_STATUS_HISTORY_CUSTOMER;
    // Called by an administrative user
    if(array_key_exists('admin_id', $_SESSION)) {
      $sql =
        'SELECT `admin_name` FROM `' . TABLE_ADMIN . '` ' .
        'WHERE `admin_id`=\':admin_id:\' LIMIT 1';
      $sql = $db->bindVars($sql, ':admin_id:', $_SESSION['admin_id'], 'integer');
      $check = $db->Execute($sql);
      if(!$check->EOF) {
        $updated_by = $check->fields['admin_name'] . ' [' . (int)$_SESSION['admin_id'] . ']';
      }
    }
    // Not called by an administrative user and no customer is present
    else if(!array_key_exists('customer_id', $_SESSION)) {
      $updated_by = ORDER_STATUS_HISTORY_UNKNOWN_MODULE;
    }
  }
  unset($check, $sql);

  // Validate notification status
  // TODO: Move to static variables on a class in the future
  switch($notify) {
    case 1:
    case 0:
      break;
    default:
      // Default to no notification / hidden from customer
      $notify = -1;
  }

  // Save the updated order status history
  $sql_data_array = array(
    'orders_id' => $orders_id,
    'orders_status_id' => $orders_status_id,
    'updated_by' => $updated_by,
    'date_added' => 'now()',
    'customer_notified' => $notify,
    'comments' => zen_not_null($comment) ? $comment : 'null'
  );
  zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
  $orders_status_history_id = $db->insert_ID();

  // Verify entry was written to the database (return false to indicate error)
  if($orders_status_history_id == 0) {
    $e = new Exception();
    error_log(sprintf(
      ORDER_STATUS_HISTORY_NOT_UPDATED,
      $sql_data_array,
      $e->getTraceAsString()
    ));
    unset($e);
    return false;
  }

  // Notify any observers the order status history was updated
  $sql_data_array['orders_status_history_id'] = $orders_status_history_id;
  $zco_notifier->notify(
      'NOTIFY_UPDATE_ORDER_STATUS_HISTORY',
      $sql_data_array
  );

  return $orders_status_history_id;
}
