<?php

class SiteSync {
  protected static $aliases;
  protected static $alias;
  protected static $args;

  public static function execute() {
    static::$args = array(
      'argv' => $GLOBALS['argv'],
      );

    // shift path off command line
    array_shift(static::$args['argv']);

    if (empty(static::$args['argv'])) {
      static::echoUsage();
      exit(0);
    }

    static::checkRequirements();
    static::loadAliases();
    static::parseArgMode();
    static::parseArgAlias();
    static::parseArgDirection();
    static::parseArgDryRun();

    call_user_func(__CLASS__ . '::doSync_' . static::$args['mode']);

    // exit normally
    exit(0);
  }

  protected static function out($text) {
    if (is_array($text)) {
      echo print_r($text, true) . "\n";
      return;
    }
    echo $text . "\n";
  }

  protected static function dieError($text = '') {
    if (! empty($text)) {
      static::out(ColorCLI::light_red('Error:'));
      static::out($text);
    }
    exit(1);
  }

  protected static function echoUsage() {
    $command = basename($_SERVER['SCRIPT_FILENAME']);
    static::out("     _ _
    (_) |
 ___ _| |_ ___  ___ _   _ _ __   ___
/ __| | __/ _ \/ __| | | | '_ \ / __|
\__ \ | ||  __/\__ \ |_| | | | | (__
|___/_|\__\___||___/\__, |_| |_|\___|
                     __/ |
" . ColorCLI::bold("Usage:") . "              |___/
" . $command . " [filesync] <alias> <direction> [notdry]
" . $command . " dbsync <alias> <direction>

filesync    Sync files. This is the default mode and may be omitted.
dbsync      Sync databases.
alias       May be a key to an alias in the config file, specified as
            @alias_key or a local path that resolves to the local_path
            of an alias.
direction   Which direction to sync, from or to?
notdry      The default operation is a dry run. This will make it wet.
");
  }

  protected static function dieUsage($text = '') {
    static::echoUsage();
    static::dieError($text);
  }

  protected static function checkRequirements() {
    if (version_compare(PHP_VERSION, '5.3.0', '<')) {
        static::dieError('PHP >= 5.3.0 is required. Yours is ' . PHP_VERSION . '.');
    }

    $reqs = array(
      'ssh',
      'mysql',
      'mysqldump',
      'bzip2',
      'bunzip2',
    );

    $out = array();
    foreach ($reqs as $req) {
      $cmd = 'which ' . $req;
      exec($cmd, $out, $exists);
      if ($exists != 0) {
        static::dieError('Required executable not found: ' . $req);
      }
    }
  }

  protected static function loadAliases() {
    $aliases =& static::$aliases;

    $aliases_file = $_SERVER['HOME'] . '/.sitesync-aliases.php';

    if (! is_file($aliases_file)) {
      static::out('Aliases file not found: ' . $aliases_file);
      static::dieError();
    }

    require($aliases_file);
    
    if (empty($aliases) || ! is_array($aliases)) {
      static::dieError('No aliases found in ' . $aliases_file);
    }
  }

  protected static function parseArgMode() {
    $args =& static::$args;

    if (in_array($args['argv'][0], array('filesync', 'dbsync'))) {
      $args['mode'] = array_shift($args['argv']);
    }
    else {
      // default mode
      $args['mode'] = 'filesync';
    }
  }

  protected static function parseArgAlias() {
    $args =& static::$args;
    $aliases =& static::$aliases;
    $alias =& static::$alias;
    
    $target = array_shift($args['argv']);

    if (empty($target)) {
      static::dieUsage('No @alias_key or local_path specified.');
    }
    
    $args['alias_key'] = '';
    if (preg_match('/^@(.+)$/', $target, $m)) {
      // specifying the array key of the alias
      $args['alias_key'] = $m[1];
      if (! isset($aliases[$args['alias_key']])) {
        static::dieUsage("No alias found whose key matches " . $target . ".");
      }
    }
    else {
      // specifying the target path of the alias
      $target_path = realpath($target) . '/';
      if ($target_path == '/' || ! is_dir($target_path)) {
        static::dieUsage($target . ' is not a valid @alias_key or does not resolve to any alias\' local_path.');
      }
      
      foreach ($aliases as $alias_key => $alias) {
        if (! empty($alias['local_path']) && $alias['local_path'] == $target_path) {
          $args['alias_key'] = $alias_key;
          break;
        }
      }

      if (empty($args['alias_key'])) {
        static::dieUsage("No aliases found whose local_path matches " . $target_path);
      }
    }

    $alias = $aliases[$args['alias_key']];
    
    if (empty($alias)) {
      static::dieUsage('The alias @' . $args['alias_key'] . ' is empty.');
    }

    if (empty($alias['local_path'])) {
      static::dieUsage('No local_path in alias @' . $args['alias_key'] . '.');
    }
    if (empty($alias['remote_path'])) {
      static::dieUsage('No remote_path in alias @' . $args['alias_key'] . '.');
    }

    static::out('Using alias @' . $args['alias_key'] . ' ' . $alias['local_path']);
  }

  protected static function parseArgDirection() {
    $direction =& static::$args['direction'];
    $direction = array_shift(static::$args['argv']);
    if (empty($direction) || ! in_array($direction, array('to', 'from'))) {
      static::dieUsage('Direction not specified.');
    }
  }

  protected static function parseArgDryRun() {
    $dryrun =& static::$args['dryrun'];

    $dryrun = array_shift(static::$args['argv']);
    
    if (! empty($dryrun) && $dryrun == 'notdry') {
      static::out(ColorCLI::light_red('This is NOT a dry run!'));
      $dryrun = false;
    }
    else {
      static::out(ColorCLI::light_green('This is a dry run.'));
      $dryrun = true;
    }
  }

  protected static function doSync_filesync() {
    $args =& static::$args;
    $alias =& static::$alias;

    if ($args['direction'] == 'to') {
      $args['from'] = $alias['local_path'];
      $args['to'] = $alias['remote_path'];
    }
    else {
      $args['from'] = $alias['remote_path'];
      $args['to'] = $alias['local_path'];
    }

    static::makeRsyncExcludes();
    
    $rsync_cmd = 'rsync -' . ($args['dryrun'] ? 'n' : '') . 'rtv ' . $args['rsync_excludes'] . ' ' . $args['from'] . ' ' . $args['to'];
    static::out($rsync_cmd);

    if (! $args['dryrun'] && empty($alias['filesync_no_confirm'])) {
      if (static::userConfirm(
          ColorCLI::light_red('THIS CAN DESTROY DATA!') . " " . ColorCLI::dim('(Read the documentation if you want to remove this prompt.)') . "\nAre you sure you want to continue?", 'yes')) {
        static::out(ColorCLI::dim("Okay, it's your funeral."));
      }
      else {
        echo ColorCLI::bold("ABORTED!\n");
        static::dieError();
      }
    }

    passthru($rsync_cmd);

    static::notify('sitesync', 'filesync is done!');
  }

  protected static function makeRsyncExcludes() {
    $args =& static::$args;
    $alias =& static::$alias;
    $rsync_excludes =& $args['rsync_excludes'];
    $rsync_excludes = array();

    if (! empty($alias['rsync-excludes']) && is_array($alias['rsync-excludes'])) {
      foreach ($alias['rsync-excludes'] as $exclude) {
        $rsync_excludes[] = '--exclude="' . $exclude . '"';
      }
    }

    // direction specific excludes
    if (! empty($alias[$args['direction'] . '-rsync-excludes']) && is_array($alias[$args['direction'] . '-rsync-excludes'])) {
      foreach ($alias[$args['direction'] . '-rsync-excludes'] as $exclude) {
        $rsync_excludes[] = '--exclude="' . $exclude . '"';
      }
    }

    $rsync_excludes = implode(' ', $rsync_excludes);
  }

  protected static function doSync_dbsync() {
    $args =& static::$args;
    $alias =& static::$alias;

    static::checkDBInfo();

    $is_from_local = $args['direction'] == 'to';

    if ($is_from_local) {
      $args['from_path_key'] = 'local_path';
      $args['from_db_key'] = 'local_db';
      $args['to_path_key'] = 'remote_path';
      $args['to_db_key'] = 'remote_db';
      $args['post_script_key'] = 'remote_dbsync_post_script';
    }
    else {
      $args['from_path_key'] = 'remote_path';
      $args['from_db_key'] = 'remote_db';
      $args['to_path_key'] = 'local_path';
      $args['to_db_key'] = 'local_db';
      $args['post_script_key'] = 'local_dbsync_post_script';
    }
    
    $from_db =& $alias[$args['from_db_key']];
    $to_db =& $alias[$args['to_db_key']];
    static::makeIgnoreTables($from_db['database']);

    $tmpfname = tempnam($_SERVER['PWD'], 'dbsync-');
    unlink($tmpfname);
    $tmpfbasename = basename($tmpfname);

    static::out('Syncing DB from ' . ColorCLI::yellow($from_db['user'] . '@' . $from_db['host'] . '/' . $from_db['database']) . ' to ' . ColorCLI::yellow($to_db['user'] . '@' . $to_db['host'] . '/' . $to_db['database']) . '');

    if (! $args['dryrun']) {
      if (static::userConfirm(ColorCLI::light_red('THIS CAN DESTROY DATA!') . "\nAre you sure you want to continue?", 'yes')) {
        static::out(ColorCLI::dim("Okay, it's your funeral."));
      }
      else {
        echo ColorCLI::bold("ABORTED!\n");
        static::dieError();
      }
    }
    
    if (strpos($alias[$args['from_path_key']], '@')) {
      $from_filename = $alias['remote_tmp_path'] . $tmpfbasename;
    }
    else {
      $from_filename = $tmpfname;
    }
    if (strpos($alias[$args['to_path_key']], '@')) {
      $to_filename = $alias['remote_tmp_path'] . $tmpfbasename;
    }
    else {
      $to_filename = $tmpfname;
    }

    // export sql structure on local side
    $cmd = 'mysqldump --no-data --host=' . $from_db['host'] . ' --user=' . $from_db['user'] . ' --password=' . $from_db['pass'] . ' ' . $from_db['database'] . ' > ' . $from_filename;
    static::runcmd(array(
      'cmd' => $cmd,
      'local' => $is_from_local,
      'output_command' => true,
      'use_passthru' => true,
      'honor_dryrun' => true,
    ));
    // export sql data on local side
    $cmd = 'mysqldump' . $args['ignore_tables'] . ' --host=' . $from_db['host'] . ' --user=' . $from_db['user'] . ' --password=' . $from_db['pass'] . ' ' . $from_db['database'] . ' >> ' . $from_filename;
    static::runcmd(array(
      'cmd' => $cmd,
      'local' => $is_from_local,
      'output_command' => true,
      'use_passthru' => true,
      'honor_dryrun' => true,
    ));

    // build command to copy sql file from local to remote
    $copy_cmd = static::getCopyCommand(array(
      'src_file' => $from_filename . '.bz2',
      'src_local' => $is_from_local,
      'dest_file' => $to_filename . '.bz2',
      'dest_local' => ! $is_from_local,
      ));
    if ($copy_cmd) {

      // compress sql file on local side
      $cmd = 'bzip2 ' . $from_filename;
      static::runcmd(array(
        'cmd' => $cmd,
        'local' => $is_from_local,
        'output_command' => true,
        'use_passthru' => true,
        'honor_dryrun' => true,
      ));

      // now copy
      static::out($copy_cmd);
      if (! $args['dryrun']) {
        passthru($copy_cmd);
      }
    
      // delete sql file on local side
      $cmd = 'rm ' . $from_filename . '.bz2';
      static::runcmd(array(
        'cmd' => $cmd,
        'local' => $is_from_local,
        'output_command' => true,
        'use_passthru' => true,
        'honor_dryrun' => true,
      ));

      // decompress sql file on remote
      $cmd = 'bunzip2 -f ' . $to_filename . '.bz2';
      static::runcmd(array(
        'cmd' => $cmd,
        'local' => ! $is_from_local,
        'output_command' => true,
        'use_passthru' => true,
        'honor_dryrun' => true,
      ));
    }
    
    // import remote sql file
    $cmd = 'mysql --host=' . $to_db['host'] . ' --user=' . $to_db['user'] . ' --password=' . $to_db['pass'] . ' ' . $to_db['database'] . ' < ' . $to_filename;
    static::runcmd(array(
      'cmd' => $cmd,
      'local' => ! $is_from_local,
      'output_command' => true,
      'use_passthru' => true,
      'honor_dryrun' => true,
    ));

    // delete remote sql file
    $cmd = 'rm ' . $to_filename;
    static::runcmd(array(
      'cmd' => $cmd,
      'local' => ! $is_from_local,
      'output_command' => true,
      'use_passthru' => true,
      'honor_dryrun' => true,
    ));

    // run post scripts
    if (! empty($alias[$args['post_script_key']])) {
      if (! is_array($alias[$args['post_script_key']])) {
        $alias[$args['post_script_key']] = array($alias[$args['post_script_key']]);
      }

      foreach ($alias[$args['post_script_key']] as $cmd) {
        static::runcmd(array(
          'cmd' => $cmd,
          'local' => ! $is_from_local,
          'output_command' => true,
          'use_passthru' => true,
          'honor_dryrun' => true,
          'ssh_controlmaster' => false,
        ));
      }
    }

    static::notify('sitesync', 'dbsync is done!');
  }

  protected static function checkDBInfo() {
    $args =& static::$args;
    $alias =& static::$alias;
    
    if (! isset($alias['local_db'])) {
      static::dieError('local_db is not defined in the alias @' . $args['alias_key'] . '.');
    }
    if (! isset($alias['remote_db'])) {
      static::dieError('remote_db is not defined in the alias @' . $args['alias_key'] . '.');
    }
    
    $required_keys = array('host', 'user', 'pass', 'database');

    foreach ($required_keys as $key) {
      if (empty($alias['local_db'][$key])) {
        static::dieError('local_db is missing \'' . $key . '\' in the alias @' . $args['alias_key'] . '.');
      }
    }
    foreach ($required_keys as $key) {
      if (empty($alias['remote_db'][$key])) {
        static::dieError('remote_db is missing \'' . $key . '\' in the alias @' . $args['alias_key'] . '.');
      }
    }

    if (empty($alias['remote_tmp_path'])) {
      static::dieError('remote_tmp_path is not defined in the alias @' . $args['alias_key'] . '.');
    }

    $path_exists = static::runcmd(array(
      'cmd' => '[ -d ' . $alias['remote_tmp_path'] . ' ]',
      'local' => false,
      'output_command' => false,
      'use_passthru' => false,
      'honor_dryrun' => false,
      ));
    if ($path_exists['return'] !== 0) {
      static::dieError('remote_tmp_path in the alias @' . $args['alias_key'] . ' is not a directory.');
    }
  }

  protected static function makeIgnoreTables($from_db) {
    $args =& static::$args;
    $alias =& static::$alias;
    $args['ignore_tables'] = array();

    if (isset($alias['dbsync_ignore_tables'])) {
      if (! is_array($alias['dbsync_ignore_tables'])) {
        static::dieError('dbsync_ignore_tables in the alias @' . $args['alias_key'] . ' should be an array.');
      }

      foreach ($alias['dbsync_ignore_tables'] as $table) {
        $args['ignore_tables'][] = '--ignore-table=' . $from_db . '.' . $table;
      }
    }

    $args['ignore_tables'] = implode(' ', $args['ignore_tables']);
    if (! empty($args['ignore_tables'])) {
      $args['ignore_tables'] = ' ' . $args['ignore_tables'];
    }
  }

  protected static function userConfirm($prompt, $yes_answer) {
    echo $prompt . '  Type "' . $yes_answer . '" for affirmative: ';
    $handle = fopen ("php://stdin","r");
    $answer = trim(fgets($handle));
    return ($answer == $yes_answer);
  }

  protected static function sshCommand($params = array()) {
    $args =& static::$args;
    $alias =& static::$alias;

    $params = array_merge(array(
        'cmd' => '',
        'cred' => '',
        'output_command' => true,
        'use_passthru' => true,
        'honor_dryrun' => true,
        'ssh_controlmaster' => true,
      ), $params);

    $params['cmd'] = 'ssh' . ($params['ssh_controlmaster'] ? '' : ' -o "ControlMaster no"') . ' ' . $params['cred'] . ' "' . $params['cmd'] . '"';

    if ($params['output_command']) {
      static::out($params['cmd']);
    }

    if ($params['honor_dryrun'] && $args['dryrun']) {
      return;
    }

    if ($params['use_passthru']) {
      return passthru($params['cmd']);
    }
    
    $ret = array(
      'output' => array(),
      'return' => '',
      );
    exec($params['cmd'], $ret['output'], $ret['return']);
    return $ret;
  }

  protected static function getCopyCommand($params) {
    $params = array_merge(array(
        'src_file' => '',
        'src_local' => '',
        'dest_file' => '',
        'dest_local' => '',
      ), $params);

    $params['src_file'] = static::addCredentialsToFile($params['src_file'], $params['src_local']);
    $params['dest_file'] = static::addCredentialsToFile($params['dest_file'], $params['dest_local']);
    $cmd = 'scp ' . $params['src_file'] . ' ' . $params['dest_file'];

    if ($params['src_file'] == $params['dest_file'] && ! strpos($cmd, '@')) {
      // no need to copy locally over itself
      return false;
    }

    return $cmd;
  }

  protected static function getCredentials($local = true) {
    $alias =& static::$alias;

    $path_key = $local ? 'local_path' : 'remote_path';
    if (strpos($alias[$path_key], '@')) {
      return preg_replace('/:.*$/', '', $alias[$path_key]);
    }
    return false;
  }

  protected static function addCredentialsToFile($file, $local = true) {
    if ($cred = static::getCredentials($local)) {
      $file = $cred . ':' . $file;
    }
    return $file;
  }

  protected static function runcmd($params = array()) {
    $args =& static::$args;
    $alias =& static::$alias;

    $params = array_merge(array(
        'cmd' => true,
        'local' => true,
        'output_command' => true,
        'use_passthru' => true,
        'honor_dryrun' => true,
        'ssh_controlmaster' => true,
      ), $params);

    if ($cred = static::getCredentials($params['local'])) {
      // run command over ssh
      return static::sshCommand(array(
        'cmd' => $params['cmd'],
        'cred' => $cred,
        'output_command' => $params['output_command'],
        'use_passthru' => $params['use_passthru'],
        'honor_dryrun' => $params['honor_dryrun'],
        'ssh_controlmaster' => $params['ssh_controlmaster'],
        ));
    }

    if ($params['output_command']) {
      static::out($params['cmd']);
    }

    if ($params['honor_dryrun'] && $args['dryrun']) {
      return;
    }

    if ($params['use_passthru']) {
      return passthru($params['cmd']);
    }

    $ret = array(
      'output' => array(),
      'return' => '',
      );
    exec($params['cmd'], $ret['output'], $ret['return']);
    return $ret;
  }

  protected static function notify($title, $msg) {
    // see if notify-send exists
    $notify_cmd = 'notify-send';
    $out = array();
    exec('which ' . $notify_cmd, $out, $exists);
    if ($exists == 0) {
      $notify_cmd .= ' -t 1000 --hint=string:x-canonical-private-synchronous: --hint=int:transient:1 -i /usr/share/pixmaps/gnome-term.png "' . $title . '" "' . $msg . '"';
      exec($notify_cmd);
    }
  }

}
