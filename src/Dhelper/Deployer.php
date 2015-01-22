<?php

namespace Dhelper;

class Deployer
{
  public function __construct()
  {
  }

  // This should be called in composer.json on post-install-cmd
  public function postInstall()
  {
    // TODO: Dev-version (this supports Heroku-buildpack style only)
    if(!getenv('HEROKU_APP_DIR'))
      return;

    $installLock = getenv('HEROKU_APP_DIR').'/.installed';
    if(!file_exists($installLock))
    {
      echo "First-time installation detected\n";
      $pdo = $this->setupDatabase();
      $this->setupAdminUser($pdo);
      touch($installLock);
    }
    $this->createAppRoot();
  }

  protected function createAppRoot()
  {
    $appRoot = getenv('HEROKU_APP_DIR').'/';
    $componentRoot = $appRoot.'vendor/sforsman/';
    $cmds = [
      "cp -rp {$componentRoot}dpw/wire {$componentRoot}dpw/index.php {$componentRoot}dpw/.htaccess {$appRoot}",
      "cp -rp {$componentRoot}dsite/ {$appRoot}site",
    ];
    foreach($cmds as $cmd)
    {
      passthru($cmd,$retval);
      if($retval != 0)
        throw new \Exception("Command {$cmd} failed");
    }
  }

  protected function setupDatabase()
  {
    $host = getenv('PW_DB_HOST');
    $db = getenv('PW_DB_NAME');
    $appRoot = getenv('HEROKU_APP_DIR').'/';

    $pdo = new PDO("mysql:host={$host};charset=utf8", getenv('PW_DB_ADMIN_USER'), getenv('PW_DB_ADMIN_PASS'));

    $query = "SHOW DATABASES LIKE '{$name}'";
    $result = $pdo->query($query)->fetch();

    if($result !== false)
      throw new \Exception("Database '{$name}' exists");

    $pdo->exec("CREATE DATABASE {$db}");
    $pdo->exec("GRANT ALL PRIVILEGES ON {$db}.* TO '".getenv('PW_DB_USER')."'@'%' IDENTIFIED BY '".getenv('PW_DB_PASS')."'");

    unset($pdo);

    $pdo = new PDO("mysql:dbname={$db};host={$host};charset=utf8", getenv('PW_DB_USER'), getenv('PW_DB_PASS'));

    require $appRoot.'wire/core/WireDatabaseBackup.php';

    $backup = new \WireDatabaseBackup();
    $backup->setDatabase($pdo);
    $options = [
      'findReplaceCreateTable'=>[
        'ENGINE=MyISAM' => 'ENGINE=InnoDB'
      ],
    ];
    $backup->restoreMerge($appRoot.'wire/core/install.sql', $appRoot.'site/install/install.sql', $options);

    echo "-> Database created\n";

    return $pdo;
  }

  protected function setupAdminUser($pdo)
  {
    $user = getenv('PW_ADMIN_USER');
    $pass = getenv('PW_ADMIN_PASS');

    // The template IDs etc are imported from site/install/install.sql, so currently
    // we can just count on them
    $query = "INSERT INTO pages VALUES (NULL, 29, 3, ?, 1, NOW(), 2, NOW(), 2, 0)";
    $stmt = $pdo->prepare($query);
    $stmt->execute($user);

    $page_id = $pdo->lastInsertId();

    list($salt,$hash) = $this->getHash($pass);

    $query = "INSERT INTO field_pass VALUES(?,?,?)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$page_id, $hash, $salt]);

    $query = "INSERT INTO field_email VALUES (?,?)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$page_id, "example@example.com"]);
    echo "-> Admin user created\n";
  }

  protected function getHash($pass)
  {
    $site_salt = getenv('PW_SALT');
    $salt = $this->getSalt();
    $hash = crypt($pass . $site_salt, $salt);
    return [substr($hash, 0, 29), substr($hash, 29)];
  }

  protected function getSalt()
  {
    $salt = '$2y$11$';
    $salt.= $this->randomBase64String(22);
    $salt.= '$'; 
    return $salt;
  }

  public function randomBase64String($requiredLength = 22) {
    $buffer = '';
    $rawLength = (int) ($requiredLength * 3 / 4 + 1);
    $valid = false;
    if(function_exists('mcrypt_create_iv')) {
      $buffer = mcrypt_create_iv($rawLength, MCRYPT_DEV_URANDOM);
      if($buffer) $valid = true;
    }
    if(!$valid && function_exists('openssl_random_pseudo_bytes')) {
      $buffer = openssl_random_pseudo_bytes($rawLength);
      if($buffer) $valid = true;
    }
    if(!$valid && file_exists('/dev/urandom')) {
      $f = @fopen('/dev/urandom', 'r');
      if($f) {
        $read = strlen($buffer);
        while($read < $rawLength) {
          $buffer .= fread($f, $rawLength - $read);
          $read = strlen($buffer);
        }
        fclose($f);
        if($read >= $rawLength) $valid = true;
      }
    }
    if(!$valid || strlen($buffer) < $rawLength) {
      $bl = strlen($buffer);
      for($i = 0; $i < $rawLength; $i++) {
        if($i < $bl) {
          $buffer[$i] = $buffer[$i] ^ chr(mt_rand(0, 255));
        } else {
          $buffer .= chr(mt_rand(0, 255));
        }
      }
    }
    $salt = str_replace('+', '.', base64_encode($buffer));
    $salt = substr($salt, 0, $requiredLength);
    $salt .= $valid; 
    return $salt;
  }
}
