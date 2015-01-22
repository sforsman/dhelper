<?php

namespace Dhelper;

class Deployer
{
  public function __construct()
  {
  }

  static public function getRoot()
  {
    return "/app";
  }


  // This should be called in composer.json on post-install-cmd
  static public function postInstall()
  {
    // TODO: Dev-version (this supports Heroku-buildpack style only)
    if(!getenv('PW_DB_HOST'))
      return;

    $installLock = self::getRoot().'/.installed';
    if(!file_exists($installLock))
    {
      echo "First-time installation detected\n";
      $pdo = self::setupDatabase();
      self::setupAdminUser($pdo);
      touch($installLock);
    }
    self::createAppRoot();
  }

  static protected function createAppRoot()
  {
    $appRoot = self::getRoot().'/';
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

  static protected function setupDatabase()
  {
    $host = getenv('PW_DB_HOST');
    $db = getenv('PW_DB_NAME');
    $appRoot = self::getRoot().'/';

    $pdo = new \PDO("mysql:host={$host};charset=utf8", getenv('PW_DB_ADMIN_USER'), getenv('PW_DB_ADMIN_PASS'));
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    $query = "SHOW DATABASES LIKE '{$db}'";
    $result = $pdo->query($query)->fetch();

    if($result !== false)
      throw new \Exception("Database '{$db}' exists");

    $pdo->exec("CREATE DATABASE {$db}");
    $pdo->exec("GRANT ALL PRIVILEGES ON {$db}.* TO '".getenv('PW_DB_USER')."'@'%' IDENTIFIED BY '".getenv('PW_DB_PASS')."'");

    unset($pdo);

    $pdo = new \PDO("mysql:dbname={$db};host={$host};charset=utf8", getenv('PW_DB_USER'), getenv('PW_DB_PASS'));
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    $wireRoot = $appRoot.'vendor/sforsman/dpw/wire/';
    $siteRoot = $appRoot.'vendor/sforsman/dsite/';

    require $wireRoot.'core/WireDatabaseBackup.php';

    $backup = new \WireDatabaseBackup();
    $backup->setDatabase($pdo);
    $options = [
      'findReplaceCreateTable'=>[
        'ENGINE=MyISAM' => 'ENGINE=InnoDB'
      ],
    ];

    if(!$backup->restoreMerge($wireRoot.'core/install.sql', $siteRoot.'install/install.sql', $options)) {
      foreach($backup->errors() as $error) {
        echo $error."\n";
      }
      throw new \Exception("Database creation failed");
    }

    echo "-> Database created\n";

    return $pdo;
  }

  static protected function setupAdminUser($pdo)
  {
    $user = getenv('PW_ADMIN_USER');
    $pass = getenv('PW_ADMIN_PASS');

    $superId = 41;

    // The template IDs etc are imported from site/install/install.sql, so currently
    // we can just count on them
    $query = "REPLACE INTO pages VALUES (?, 29, 3, ?, 1, NOW(), 2, NOW(), 2, 0)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$superId, $user]);

    // $page_id = $pdo->lastInsertId();
    $page_id = $superId;

    list($salt,$hash) = self::getHash($pass);

    $query = "REPLACE INTO field_pass VALUES(?,?,?)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$page_id, $hash, $salt]);

    $query = "REPLACE INTO field_email VALUES (?,?)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$page_id, "example@example.com"]);

    $query = "DELETE FROM field_roles WHERE pages_id=?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$page_id]);

    $query = "INSERT INTO field_roles VALUES (?,?,?)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$superId,37,0]);
    $stmt->execute([$superId,38,1]);

    echo "-> Admin user created\n";
  }

  static protected function getHash($pass)
  {
    $site_salt = getenv('PW_SALT');
    $salt = self::getSalt();
    $hash = crypt($pass . $site_salt, $salt);
    return [substr($hash, 0, 29), substr($hash, 29)];
  }

  static protected function getSalt()
  {
    $salt = '$2y$11$';
    $salt.= self::randomBase64String(22);
    $salt.= '$';
    return $salt;
  }

  static protected function randomBase64String($requiredLength = 22) {
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

