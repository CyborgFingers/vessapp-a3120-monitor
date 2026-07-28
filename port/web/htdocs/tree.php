<?php

/*******************************************************************************
 * Filename    : tree.php
 * Description : islaVista object tree.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include_once 'defense.php';
include_once 'language.php';

header('Cache-Control: no-cache');
header('Pragma: no-cache');
header('Expires: 0');

if (isset($_SESSION['sessionService'])) {
  $curtime = time();
  if (!isset($_SESSION['time']))
    $_SESSION['time'] = $curtime;

  $timeout = 24 * 60;
  if (isset($_SESSION['timeout']))
    $timeout = $_SESSION['timeout'];

  if (!isset($_GET['refreshthis'])) {
    if ((int)($curtime - $_SESSION['time']) > $timeout) {
      unset($_SESSION['sessionService']);
      $_SESSION['hastimeout'] = 1;
    } else
      $_SESSION['time'] = $curtime;
  }
}
define("NODE_STATE_OK", 0);
define("NODE_STATE_CRITICAL", 1);
define("NODE_STATE_DEAD", 2);

define("NAMESPACE", "root/Promise/BrandName");
define("PORTTAG", ":5988");

function getCookieSubValue($cookieType, $cookieSubName)
{
  $cookieStr = $_COOKIE[$cookieType];
  $cookieSubStrStartAt = strpos($cookieStr, $cookieSubName);
  if ($cookieSubStrStartAt === null)
    return null;
  else {
    $cookieSubStr = substr($cookieStr, $cookieSubStrStartAt, strlen($cookieStr));
    $cookieSubStrMiddleAt = strpos($cookieSubStr, ':');
    $cookieSubStrEndAt = strpos($cookieSubStr, ';');
    $cookieSubValue = substr($cookieSubStr, $cookieSubStrMiddleAt + 1, $cookieSubStrEndAt - $cookieSubStrMiddleAt - 1);
    return $cookieSubValue;
  }
}
function setCookieSubValue($cookieType, $cookieSubName, $cookieSubValue)
{
  $cookieStr = $_COOKIE[$cookieType];
  $cookieSubStrStartAt = strpos($cookieStr, $cookieSubName);
  if ($cookieSubStrStartAt === null)
    return false;
  else {
    $cookieSubStr = substr($cookieStr, $cookieSubStrStartAt, strlen($cookieStr));
    $cookieSubStrMiddleAt = $cookieSubStrStartAt + strpos($cookieSubStr, ':');
    $cookieSubStrEndAt = $cookieSubStrStartAt + strpos($cookieSubStr, ';');

    if ($cookieSubStrStartAt == 0)
      $cookieStr1 = '';
    else
      $cookieStr1 = substr($cookieStr, 0, $cookieSubStrStartAt);

		// $cookieStr2old=substr($cookieStr,$cookieSubStrStartAt,$cookieSubStrEndAt-$cookieSubStrStartAt+1);
    $cookieStr2 = $cookieSubName . ':' . $cookieSubValue . ';';

    if ($cookieSubStrEndAt == strrpos($cookieStr, ';'))
      $cookieStr3 = '';
    else
      $cookieStr3 = substr($cookieStr, $cookieSubStrEndAt + 1, strlen($cookieStr));

    $cookieNewStr = $cookieStr1 . $cookieStr2 . $cookieStr3;
    setcookie($cookieType, $cookieNewStr, time() + 15552000, null, null, true, true);
    return true;
  }
}

function cmp($a, $b)
{
  /* todo, sort by network bytes */
  $aa = iptonumber($a->ip);
  $bb = iptonumber($b->ip);

  return $aa->ipnumber > $bb->ipnumber ? 1 : 0;
}

function loadLibrary()
{
  if (!extension_loaded('i2')) {
    /*
    if (isset($_GET['i2']) || isset($_SESSION['i2'])) {
      dl('phpi2.so');
      $_SESSION['i2'] = 1;
    } else {
      if (!extension_loaded('pegasus'))
        dl('pegasus.so');
      $_SESSION['pegasus'] = 1;
    }

    dl('phpi2.so');
     */
  }
}

function getServer()
{
  return new CServer();
}

class CSessionService
{
  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CSessionService(...$a); }
  function CSessionService($username, $password, $sid)
  {
    loadLibrary();
    $this->loginex($username, $password, $sid);
  }

  function loginex($username, $password, $sid)
  {
    if (isset($_SESSION['pegasus'])) {
      /*
      $client = CIMClient(60 * 1000);

      $this->client->connect('localhost' . PORTTAG);
      $instanceNames = $this->client->enumerateInstanceNames(namespace, "Promise_SessionService");

      $inParam[] = CIMParamValue("Username", $username);
      $inParam[] = CIMParamValue("Password", $password);
      $inParam[] = CIMParamValue("Interface", 'www');
      settype($outParam, 'array');
      $value = $client->invokeMethod(namespace, $instanceNames[0], 'Login', $inParam, $outParam);

      $this->status = ($value == "0") ? 'passed' : 'failed';
       */
    } else {
      /* Linux/PAM users authenticate with their plaintext password, which the
       * login page POSTs as plainpw ONLY over HTTPS (:8088 redirects to TLS), so
       * it is never exposed on plain HTTP. Local users leave it empty and use the
       * MD5 challenge ($password). php_i2 ignores it unless the user is on the PAM
       * allowlist, and wipes it immediately after. */
      $plainpw = (!empty($_SERVER['HTTPS']) && isset($_POST['plainpw'])) ? $_POST['plainpw'] : '';
      $client = I2ClientEx($username, $password, $sid, $plainpw);
      $this->sid = $client->sessionId;
      $this->status = $client->status;
    }

    $this->username = $username;
    $this->password = $password;

    return $client;
  }

  function connect()
  {
    $client = I2Client($this->username, "", $this->sid);
    return $client;
  }
}

class CServer
{
  var $subsystems;

  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CServer(...$a); }
  function CServer()
  {
    loadLibrary();
    if (isset($_SESSION['sessionService']))
      $this->session = unserialize($_SESSION['sessionService']);
    else {
      ?>
<script>
        top.location.reload();
</script>
<?php
exit;
}
}

function logout()
{
  $this->subsystems = $this->getSubsystems();
  for ($i = 0; $i < count($this->subsystems); $i++)
    $this->subsystems[$i]->logout();
}

function addSubsystem($name)
{
  $i = count($this->subsystems);
  // discover this subsystem, create a session for it, use 'null' session for now
  $this->subsystems[] = new CSubsystem(null, $name, $i);
}

function deleteSubsystem($index)
{
  $subsystem = $this->subsystems[$index];
  array_splice($this->subsystems, $index, 1);
}

function update()
{
  $subsystem = new CSubsystem($this->session, 0);
  $subsystem->updateLatestEvent();

  if ($subsystem->instance)
    $this->subsystems[0] = $subsystem;

  for ($i = 0; $i < count($this->subsystems); $i++)
    $this->subsystems[$i]->update();
}

function getSubsystems()
{
  $subsystem = new CSubsystem($this->session, 0, 1);
  if ($subsystem->instance)
    $this->subsystems[0] = $subsystem;
  return $this->subsystems;
}

function getSubsystem($flag = 0, $ssid = 0)
{
  $subsystem = new CSubsystem($this->session, $ssid, $flag);
  $subsystem->instance->_IP = $subsystem->name;
  return $subsystem->instance;
}

function addMultibox(&$parentNode, $ip, $port, $model)
{
  $node1 = new CNode($parentNode);
  $node1->treeId = "subsys";
  $node1->desc = $ip . ' (' . $model . ')';
  if ($port == "8090")
    $node1->ref = "http://$ip:$port";
  else
    $node1->ref = "https://$ip:$port";
  $node1->icon = "subsystem";
  $node1->className = 'treeStyleSubsystem';
  $node1->isOpen = false;
  $node1->textStyle = 'textTreeSubsystemNode';
  $parentNode->addChildNode($node1);
}

function generateTree(&$parentNode)
{
  $node = new CNode($parentNode);
  $node->treeId = "subsyss";
  $node->desc = gprop('subsystems');
  $node->ref = "subsystems.php";
  $node->icon = "subsystems";
  $node->drawBorder = 1;
  $node->className = 'treeStyleSubsystems';
  $node->textStyle = 'textTreeSubsystemsNode';
  $node->isOpen = true;


  if (isset($_COOKIE['networkview']) && $_COOKIE['networkview']) {
    $multibox = getmultibox();

    $index = 0;
    $match = false;
    for ($i = 0; $i < count($multibox); $i++) {
      if (!strcmp($multibox[$i]->ip, $this->subsystems[0]->name)) {
        $match = true;
        break;
      }
    }
    if (!$match) {
      $tmp = new stdClass();
      $tmp->ip = $this->subsystems[0]->name;
      $multibox[] = $tmp;
    }
    usort($multibox, "cmp");

    $index = 0;
    $match = false;
    for ($i = 0; $i < count($multibox); $i++) {
      if (!strcmp($multibox[$i]->ip, $this->subsystems[0]->name)) {
        $match = true;
        break;
      }
    }
    if ($match) {
      $index = $i;
    }
    for ($i = 0; $i < $index; $i++)
      $this->addMultibox($node, $multibox[$i]->ip, $multibox[$i]->port, $multibox[$i]->model);
  }

  for ($i = 0; $i < count($this->subsystems); $i++) {
    $this->subsystems[$i]->generateTree($node);
  }
  if (isset($_COOKIE['networkview']) && $_COOKIE['networkview']) {
    for ($i = $index + 1; $i < count($multibox); $i++)
      $this->addMultibox($node, $multibox[$i]->ip, $multibox[$i]->port, $multibox[$i]->model);
  }
  $parentNode->addChildNode($node);
}
}

class CSubsystem
{
  var $controllers;
  var $physicalDrives;
  var $logicalDrives;
  var $diskArrays;
  var $enclosures;
  var $upss;
  var $spareDrives;

  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CSubsystem(...$a); }
  function CSubsystem($session, $id, $flag = 0)
  {
    $this->session = $session;
    $this->id = $id;

    if ($session == null)
      return;

    if (isset($_SERVER['HTTP_HOST']))
      $this->name = strtok($_SERVER['HTTP_HOST'], ':'); // take out the port number
    else
      $this->name = $_SERVER['SERVER_ADDR']; // not in thttpd

    if (isset($_SESSION['pegasus'])) {
      /*
      $this->client = $session->login($session->username, $session->password);
      $instances = $this->client->enumerateInstances(namespace, "Promise_BrandName_Subsystem");
      // there should be only on subsystem instance
      $this->instance = $instances[0];
       */
    } else {
      $this->client = $session->connect();
      if (isset($this->client->frontendType) && ($this->client->frontendType != 0)) {
        $this->instance = $this->client->getSubsystem($flag);
        $this->instance->frontendType = $this->client->frontendType;
      } else {
        $this->instance = $this->client->getSubsystem(1);
        $this->instance->frontendType = $this->instance->sysfrontendType;
      }

      if ($this->instance->u32Ret)
        echo '
          <script>
            top.location.href="index.php?logout&note=' . gerror($this->instance->u32Ret) . '";
          </script>';
    }
  }

  function updateLatestEvent()
  {
    if (isset($this->instance)) {
      $events = $this->instance->getLastEvents(1, $GLOBALS["language"]);
      if (count($events) > 0)
      // SetSessionValue('eventLast', $events[0]->evt_SequenceNumber);
      {
        $_SESSION['eventLast'] = $events[0]->evt_SequenceNumber;
        $server = getServer();
        $subsystem = $server->getSubsystem();
        $session = unserialize($_SESSION["sessionService"]);
        setcookie('eventLast', $_SESSION['eventLast'], time() + 15552000, null, null, true, true);
      }
    }
  }

  function update()
  {
    $this->enumerateControllers();
    $this->enumeratePhysicalDrives();
    $this->enumerateLogicalDrives();
    $this->enumerateDiskArrays();
    $this->enumerateEnclosures();
    // $this->enumerateUpss();
    $this->enumerateSpareDrives();
  }

  function logout()
  {
    if (!isset($this->client))
      return;

    if (isset($_SESSION['pegasus'])) {
      /*
      $instanceNames = $this->client->enumerateInstanceNames(namespace, "Promise_SessionService");
      settype($inParam, 'array');
      settype($outParam, 'array');
      $this->client->invokeMethod(namespace, $instanceNames[0], 'Logout', $inParam, $outParam);
      $this->client->disconnect();
       */
    } else {
      $this->client = I2Client($this->session->username, "", $this->session->sid);
      $this->client->logout();
    }
  }

  function enumerateControllers()
  {
    $server = getServer();
    $subsystem = $server->getSubsystem();
    $supportDualCtrl = $subsystem->supportdualcontroller();
    if (isset($_SESSION['pegasus'])) {
      /*
      $instances = $this->client->enumerateInstances(namespace, "Promise_BrandName_SubsystemController");
      for ($i = 0; $i < count($instances); $i++) {
        $controller = new CController($this->client, $instances[$i], $this->id, $i);
        $this->controllers[$i] = $controller;
      }
       */
    } else {
      $instances = $this->instance->enumerateControllers();
      if (count($instances) == 2) {
        for ($i = 0; $i < count($instances); $i++) {
          $controller = new CController($this->client, $instances[$i], $this->id, $i);
          $this->controllers[$i] = $controller;
        }
      } else if (count($instances) == 1) {
        if ($supportDualCtrl->DualCtrlSupport) {
          if ($instances[0]->ci_DeviceID == 0) {
            $controller0 = new CController($this->client, $instances[0], $this->id, 0);
            $controller1 = new CController($this->client, null, $this->id, 1);
            $this->controllers[0] = $controller0;
            $this->controllers[1] = $controller1;
          } else {
            $controller0 = new CController($this->client, null, $this->id, 0);
            $controller1 = new CController($this->client, $instances[0], $this->id, 1);
            $this->controllers[0] = $controller0;
            $this->controllers[1] = $controller1;
          }
        } else {
          for ($i = 0; $i < count($instances); $i++) {
            $controller = new CController($this->client, $instances[$i], $this->id, $i);
            $this->controllers[$i] = $controller;
          }
        }
      } else if (count($instances) == 0) {
        $controller0 = new CController($this->client, null, $this->id, 0);
        $controller1 = new CController($this->client, null, $this->id, 1);
        $this->controllers[0] = $controller0;
        $this->controllers[1] = $controller1;
      }

    }
  }

  function getController($devId)
  {
    if (isset($_SESSION['pegasus']))
      return $this->controllers[$devId]->instance;
    else
      return $this->instance->getController($devId);
  }

  function enumeratePhysicalDrives()
  {
    if (isset($_SESSION['pegasus'])) {
      /*
      $instances = $this->client->enumerateInstances(namespace, "Promise_BrandName_SATADiskDrive");
      for ($i = 0; $i < count($instances); $i++) {
        $physicalDrive = new CPhysicalDrive($this->client, $instances[$i], $this->id, $instances[$i]->getProperty('DriveId'));
        $this->physicalDrives[$i] = $physicalDrive;
      }
       */
    } else {
      $i2physicaldrives = $this->instance->enumeratePhysicalDrives();
      for ($i = 0; $i < count($i2physicaldrives); $i++) {
        $physicalDrive = new CPhysicalDrive($this->client, $i2physicaldrives[$i], $this->id, $i2physicaldrives[$i]->pdi_FlatId);
        $this->physicalDrives[$i] = $physicalDrive;
      }
    }
  }

  function enumerateLogicalDrives()
  {
    if (isset($_SESSION['pegasus'])) {
      /*
      $instances = $this->client->enumerateInstances(namespace, "Promise_BrandName_StorageVolume");
      for ($i = 0; $i < count($instances); $i++) {
        $logicalDrive = new CLogicalDrive($this->client, $instances[$i], $this->id, $instances[$i]->getProperty('VolumeId'));
        $this->logicalDrives[$i] = $logicalDrive;
      }
       */
    } else {
      $i2logicaldrives = $this->instance->enumerateLogicalDrives();
      for ($i = 0; $i < count($i2logicaldrives); $i++) {
        $logicalDrive = new CLogicalDrive($this->client, $i2logicaldrives[$i], $this->id, $i2logicaldrives[$i]->ldi_FlatId);
        $this->logicalDrives[$i] = $logicalDrive;
      }
    }
  }

  function enumerateDiskArrays()
  {
    $i2diskArrays = $this->instance->enumerateDiskArrays();
    for ($i = 0; $i < count($i2diskArrays); $i++) {
      $diskArray = new CDiskArray($this->client, $i2diskArrays[$i], $this, $i2diskArrays[$i]->acd_ArrayID);
      $this->diskArrays[$i] = $diskArray;
    }
  }

  function enumerateEnclosures()
  {
    // $i2enclosures=$this->instance->getEnclosures();
    $server = getServer();
    $subsystem = $server->getSubsystem(1);
    $numEncl = $subsystem->ssi_JOBExpSSPresent + 1;
    for ($i = 0; $i < $numEncl; $i++) {
      $i2enclosures[] = $this->instance->getEnclosures($i);
    }

    for ($i = 0; $i < count($i2enclosures); $i++) {
      $enclosure = new CEnclosure($this->client, $i2enclosures[$i], $this, $i);
      $this->enclosures[$i] = $enclosure;
    }
    if (count($i2enclosures) == 0)
      $this->enclosures[0] = new CEnclosure($this->client, null, $this, 0);
  }

  function enumerateUpss()
  {
    $i2upss = $this->instance->enumerateUpss();
    for ($i = 0; $i < count($i2upss); $i++) {
      $ups = new CUps($this->client, $i2upss[$i], $this, $i2upss[$i]->ups_Id);
      $this->upss[$i] = $ups;
    }
  }

  function enumerateSpareDrives()
  {
    $i2sparedrives = $this->instance->enumerateSpareDrives();
    for ($i = 0; $i < count($i2sparedrives); $i++) {
      $spareDrive = new CSpareDrive($this->client, $i2sparedrives[$i], $this, $i2sparedrives[$i]->acd_ArrayID, $i2sparedrives[$i]->acd_ArrayID);
      $this->spareDrives[$i] = $spareDrive;
    }
  }

  function generateAdminTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->id . "admin";
    $node->desc = gprop('administrativetools');;
    $node->ref = "admin.php";
    $node->icon = "admin";

    $admin = new CAdmin($this->client, $this->id);
    $admin->generateTree($node);

    $parentNode->addChildNode($node);
  }

  function generateControllerTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->id . "ctrls";
    $node->desc = gprop('controllers');
    $node->ref = "controllers.php?ssid=" . $this->id;
    $node->icon = "controllers";

    for ($i = 0; isset($this->controllers) && $i < count($this->controllers); $i++)
      $this->controllers[$i]->generateTree($node);

    $parentNode->addChildNode($node);
  }

  function generateEnclosureTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->id . "encls";
    $node->desc = gprop('enclosures');
    $node->ref = "encls.php?ssid=" . $this->id;
    $node->icon = "enclosures";

    for ($i = 0; $i < count($this->enclosures); $i++)
      $this->enclosures[$i]->generateTree($node);

    $parentNode->addChildNode($node);
  }

  function generateUPSTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->id . "upss";
    $node->desc = gprop('upss');
    $node->ref = "upss.php?ssid=" . $this->id;
    $node->icon = "upsnone";

    for ($i = 0; isset($this->upss) && $i < count($this->upss); $i++)
      $this->upss[$i]->generateTree($node);

    $parentNode->addChildNode($node);
  }

  function generateLogicalDriveTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->id . "lds";
    $node->desc = gprop('logicaldrivesummary');
    $node->ref = "logdrvs.php?ssid=" . $this->id;
    $node->icon = "logdrvs";
      //$node->isOpen=true;

    for ($i = 0; isset($this->logicalDrives) && $i < count($this->logicalDrives); $i++)
      $this->logicalDrives[$i]->generateTree($node);

    $parentNode->addChildNode($node);
  }

  function generateDiskArrayTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->id . "das";
    $node->desc = gprop('diskarrays');
    $node->ref = "arrays.php?ssid=" . $this->id;
    $node->icon = "arrays";
    $node->isOpen = true;

    for ($i = 0; isset($this->diskArrays) && $i < count($this->diskArrays); $i++)
      $this->diskArrays[$i]->generateTree($node);

    $parentNode->addChildNode($node);
  }

  function generateSpareDriveTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->id . "sds";
    $node->desc = gprop('sparedrives');
    $node->ref = "spares.php?ssid=" . $this->id;
    $node->icon = "spares";

    for ($i = 0; isset($this->spareDrives) && $i < count($this->spareDrives); $i++)
      $this->spareDrives[$i]->generateTree($node);

    $parentNode->addChildNode($node);
  }

  function generateTree(&$parentNode)
  {
    $server = getServer();
    $subsystem = $server->getSubsystem(1);
    if (!isset($this->client->model) || ($this->client->model == ''))
      $this->client->model = $subsystem->ssi_CustModel;
    $node = new CNode($parentNode);
    $node->treeId = "subsys" . $this->id;
    if (isset($this->client->model) && ($this->client->model != ''))
      $node->desc = $this->name . ' (' . $this->client->model . ')';
    else
      $node->desc = $this->name;
    $node->ref = "subsystem.php?ssid=" . $this->id;
    $node->icon = "subsystem";
    $node->className = 'treeStyleSubsystem';
    $node->isOpen = true;
    $node->textStyle = 'textTreeSubsystemNode';

    $this->generateAdminTree($node);
    $this->generateControllerTree($node);
    $this->generateEnclosureTree($node);
    $this->generateDiskArrayTree($node);
    $this->generateSpareDriveTree($node);
    $this->generateLogicalDriveTree($node);

    $parentNode->addChildNode($node);
  }
}
function errorOnInvalidSession()
{
  if (isset($_SESSION['sessionService']))
    unset($_SESSION['sessionService']);
  ?>
       <script>
top.location.href="index.php?logout&note=<?=galert('invalidsession')?>";
       </script>';
<?php
exit;
}
?>
