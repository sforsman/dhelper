<?php

namespace Dhelper;

class Deployer
{
  public function __construct()
  {
  }

  static public function getRoot()
  {
    if(!file_exists("/app"))
      return ".";
    else
      return "/app";
  }


  // This should be called in composer.json on post-install-cmd
  static public function postInstall()
  {
    // TODO: Dev-version (this supports Heroku-buildpack style only)
    if(!getenv('PW_DB_HOST'))
      return;

    $master = self::getMasterDb();

    if(self::databaseExists($master)) {
      echo "Connecting to existing instance\n";
    }
    else {
      echo "Setting up a new instance\n";
      $pdo = self::setupDatabase($master);
      self::setupAdminUser($pdo);
    }
    echo "Creating app root\n";
    self::createAppRoot();
  }

  static protected function getMasterDb()
  {
    $host = getenv('PW_DB_HOST');

    $pdo = new \PDO("mysql:host={$host};charset=utf8", getenv('PW_DB_ADMIN_USER'), getenv('PW_DB_ADMIN_PASS'));
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    return $pdo;
  }

  static protected function databaseExists($master)
  {
    $db = getenv('PW_DB_NAME');

    $query = "SHOW DATABASES LIKE '{$db}'";
    $result = $master->query($query)->fetch();

    if($result !== false)
      return true;
    else
      return false;
  }

  static protected function createAppRoot()
  {
    $appRoot = self::getRoot().'/';
    $componentRoot = $appRoot.'vendor/sforsman/';
    $cmds = [
      "cp -rp {$componentRoot}dpw/wire {$componentRoot}dpw/index.php {$componentRoot}dpw/.htaccess {$appRoot}",
      "cp -rp {$componentRoot}dsite/ {$appRoot}site",
      "mkdir {$appRoot}site/assets/logs",
      "cp -rp {$appRoot}wire/modules/Inputfield/InputfieldCKEditor {$appRoot}site/modules/",
    ];
    foreach($cmds as $cmd)
    {
      passthru($cmd,$retval);
      if($retval != 0)
        throw new \Exception("Command {$cmd} failed");
    }
  }

  static protected function setupDatabase($master)
  {
    $appRoot = self::getRoot().'/';

    $host = getenv('PW_DB_HOST');
    $db = getenv('PW_DB_NAME');

    $master->exec("CREATE DATABASE {$db}");
    $master->exec("GRANT ALL PRIVILEGES ON {$db}.* TO '".getenv('PW_DB_USER')."'@'%' IDENTIFIED BY '".getenv('PW_DB_PASS')."'");

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

    $backup->restoreMerge($wireRoot.'core/install.sql', $siteRoot.'install/install.sql', $options);
    $errors = false;

    foreach($backup->errors() as $error) {
      echo $error."\n";
      $errors = true;
    }

    if($errors)
      throw new \Exception("Database creation failed");

    echo "-> Database created\n";

    return $pdo;
  }

  static public function deployProfile()
  {
    if(!file_exists(self::getRoot() . '/.profile.d'))
    {
      passthru("cp -rp ".self::getRoot()."/vendor/sforsman/dhelper/contrib/profile.d ".self::getRoot()."/.profile.d/", $retval);
      if($retval != 0)
        throw new \Exception("Failed installing profile");
    }
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

