<?php

function d($out, $index = 0) {
  $trace = debug_backtrace();
  echo $trace[$index]['file'] . ':' . $trace[$index]['line'];
  if (is_string($out)) echo ' String(' . strlen($out) . ')';
  if (is_int($out)) echo ' Int(' . strlen($out) . ')';
  echo "\n" . print_r($out, true) . "\n";
}

function dd($out) {
  d($out, 1);
  echo "\n--killed by debug--\n";
  exit(1);
}
