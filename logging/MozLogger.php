<?php

class MozLogger extends Phobject {

  public function log($message, $type='Unspecified', $detail=array()) {

    $mozlog = self::merge_arrays(
      $detail,
      array(
        'Type' => $type,
        'Fields' => array('msg' => $message)
      )
    );
    $json = json_encode($merged, JSON_FORCE_OBJECT);

    error_log($json."\n", 3, '/var/log/moz_log');

    // Return the $message so it can be used in exception calls
    return $message;
  }

  public function merge_arrays($detailArray, $messageArray) {
    $defaults = array(
      'Timestamp' => time(),
      'Type' => '',
      'Logger' => 'MozPhab',
      'Hostname' => 'phabricator.services.mozilla.com',
      'EnvVersion' => '1.0',
      'Severity' => '3',
      'Pid' => '0', // Not sure how to get this
      'Fields' => array(
        'agent' => '',
        'errno' => '0',
        'method' => 'GET',
        'msg' => 'Message not provided',
        'path' => '',
        't' => '',
        'uid' => ''
      )
    );

    $merged = array_merge_recursive(
      $defaults,
      $detailArray,
      $messageArray
    );

    return $merged;
  }
}
