<?php

/*******************************************************************************
 * Filename    : tree2.php
 * Description : islaVista object tree.
 * Created on  : 05/26/04
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'common2.php';

class CAdmin
{
  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CAdmin(...$a); }
  function CAdmin($client, $ssid)
  {
    $this->client = $client;
    $this->ssid = $ssid;
  }

  function generateTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "user";
    $node->desc = gprop('usermanagement');
    $node->ref = "users.php?ssid=" . $this->ssid;
    $node->icon = "user";
    $parentNode->addChildNode($node);

    unset($node);
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "net";
    $node->desc = gprop('networkmanagement');
    $node->ref = "net.php?act=ether&ssid=" . $this->ssid;
    $node->icon = "net";
    $parentNode->addChildNode($node);
    if (!isset($this->client->frontendType) || ($this->client->frontendType == 0)) {
      $server = getServer();
      $subsystem = $server->getSubsystem(1);
      $this->client->frontendType = $subsystem->sysfrontendType;
    }
   
    //**********************************************
    //* NAS Tree Node
    //**********************************************

    $server = getServer();
    $subsystem = $server->getSubsystem();

    $pv = getUserPrivilege($subsystem);

    unset($node);
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "perfStats";
    $node->desc = gprop('performancestatistics');
    $node->ref = "perfStats.php";
    $node->icon = "stat";
    $parentNode->addChildNode($node);

    unset($node);
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "sw";
    $node->desc = gprop('softwaremanagement');
    $node->ref = "services.php";
    $node->icon = "sw";
    $parentNode->addChildNode($node);
  }
}

class CNas
{
  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CNas(...$a); }
  function CNas($client, $ssid)
  {
    $this->client = $client;
    $this->ssid = $ssid;
  }

  function generateTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "user";
    $node->desc = gprop('nasuser');
    $node->ref = "nas_useri.php?ssid=" . $this->ssid;
    $node->icon = "user";
    $parentNode->addChildNode($node);

    unset($node);
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "user";
    $node->desc = gprop('nasgroup');
    $node->ref = "nas_groupi.php?ssid=" . $this->ssid;
    $node->icon = "group_mgt";
    $parentNode->addChildNode($node);

    unset($node);
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "user";
    $node->desc = gprop('protocolcontrol');
    $node->ref = "nas_protoinfo.php?ssid=" . $this->ssid;
    $node->icon = "protocol";
    $parentNode->addChildNode($node);

    unset($node);
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "net";
    $node->desc = gprop('filesharing');
    $node->ref = "nas_sharei.php?act=ether&ssid=" . $this->ssid;
    $node->icon = "file_share";
    $parentNode->addChildNode($node);

    unset($node);
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "net";
    $node->desc = gprop('sharingsetup');
    $node->ref = "nas_shareset.php?act=ether&ssid=" . $this->ssid;
    $node->icon = "share_setup";
    $parentNode->addChildNode($node);

    unset($node);
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "net";
    $node->desc = gprop('filesystem');
    $node->ref = "nas_filesys.php?act=ether&ssid=" . $this->ssid;
    $node->icon = "storage";
    $parentNode->addChildNode($node);

    unset($node);
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "net";
    $node->desc = gprop('nasrep');
    $node->ref = "nas_repinfo.php?act=ether&ssid=" . $this->ssid;
    $node->icon = "replication";
    $parentNode->addChildNode($node);

    unset($node);
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "net";
    $node->desc = gprop('mountpoint');
    $node->ref = "mountpoints.php?&ssid=" . $this->ssid;
    $node->icon = "logdrv";
    $parentNode->addChildNode($node);
  }
}

class CController
{
  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CController(...$a); }
  function CController($client, $instance, $ssid, $id)
  {
    $this->client = $client;
    $this->ssid = $ssid;
    $this->id = $id;
    $this->instance = $instance;
  }

  function generateTree(&$parentNode)
  {
    $server = getServer();
    $subsys = $server->getSubsystem(1);
    $actualNum = $subsys->ssi_NumberOfCtlPresent;
    $supportDualCtrl = $subsys->supportdualcontroller();
    if (($supportDualCtrl->DualCtrlSupport) && ($this->instance != null)) {
      $node = new CNode($parentNode);
      $node->treeId = $this->ssid . "ctrl" . $this->id;
      $node->desc = gprop('controller') . ' ' . ($this->id + 1);
      $node->ref = "controller.php?ssid=" . $this->ssid . "&ctid=" . $this->id;
      $node->icon = "controller";

      if ($this->instance->ci_ReadinessStatus == 'Active' || $this->instance->ci_ReadinessStatus == 'Standby') {
        $node->state = NODE_STATE_OK;
      } else if ($this->instance->ci_ReadinessStatus == 'Maintenance') {
        $node->desc = gprop('controller') . ' ' . ($this->id + 1) . ' (' . gprop('maintenance') . ')';
        $node->icon = "controller-grey";
        $node->ref = "controller.php?ssid=" . $this->ssid . "&ctid=" . $this->id . "&status=maintenance";
        $node->state = NODE_STATE_CRITICAL;
      } else if ($this->instance->ci_ReadinessStatus == 'Not accessible' || $this->instance->ci_ReadinessStatus == 'Missing') {
        $node->icon = "controller-grey";
        if ($actualNum == 2) {
          $node->desc = gprop('controller') . ' ' . ($this->id + 1) . ' (' . gprop('notaccessible') . ')';
          $node->ref = "controller.php?ssid=" . $this->ssid . "&ctid=" . $this->id . "&status=notaccessible";
          $node->state = NODE_STATE_CRITICAL;
        } else if ($actualNum == 1) {
          $node->desc = gprop('controller') . ' ' . ($this->id + 1) . ' (' . gprop('notpresent') . ')';
          $node->ref = "controller.php?ssid=" . $this->ssid . "&ctid=" . $this->id . "&status=notpresent";
          $node->state = NODE_STATE_CRITICAL;
        }
      } else if ($this->instance->ci_ReadinessStatus == 'Shutdown') {
        $node->icon = "controller-grey";
        $node->desc = gprop('controller') . ' ' . ($this->id + 1) . ' (' . gprop('shutdown') . ')';
        $node->ref = "controller.php?ssid=" . $this->ssid . "&ctid=" . $this->id . "&status=notaccessible";
        $node->state = NODE_STATE_CRITICAL;
      } else {
        $node->icon = "controller-grey";
        if ($actualNum == 2) {
          $node->desc = gprop('controller') . ' ' . ($this->id + 1) . ' (' . gprop('notaccessible') . ')';
          $node->ref = "controller.php?ssid=" . $this->ssid . "&ctid=" . $this->id . "&status=notaccessible";
          $node->state = NODE_STATE_CRITICAL;
        } else if ($actualNum == 1) {
          $node->desc = gprop('controller') . ' ' . ($this->id + 1) . ' (' . gprop('notpresent') . ')';
          $node->ref = "controller.php?ssid=" . $this->ssid . "&ctid=" . $this->id . "&status=notpresent";
          $node->state = NODE_STATE_OK;
        }
      }
    } else if (($supportDualCtrl->DualCtrlSupport) && ($this->instance == null)) {
      $node = new CNode($parentNode);
      $node->treeId = $this->ssid . "ctrl" . $this->id;
      $node->desc = gprop('controller') . ' ' . ($this->id + 1) . ' (' . gprop('notpresent') . ')';
      $node->ref = "controller.php?ssid=" . $this->ssid . "&ctid=" . $this->id . "&status=notpresent";
      $node->icon = "controller-grey";
    }
    else {
      $node = new CNode($parentNode);
      $node->treeId = $this->ssid . "ctrl" . $this->id;
      $node->desc = gprop('controller') . ' ' . ($this->id + 1);
      $node->ref = "controller.php?ssid=" . $this->ssid . "&ctid=" . $this->id;
      $node->icon = "controller";
    }

    $parentNode->addChildNode($node);
  }
}

class CPhysicalDrive
{
  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CPhysicalDrive(...$a); }
  function CPhysicalDrive($client, $instance, $ssid, $id)
  {
    $this->client = $client;
    $this->ssid = $ssid;
    $this->id = $id;
    $this->instance = $instance;
  }

  function generateTree(&$parentNode, $withEnclosure = true)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "pd" . $this->id;
    $node->desc = '';
    if ($withEnclosure && isset($this->instance->pdi_EnclosureId))
      $node->desc = 'Encl' . ($this->instance->pdi_EnclosureId + 1) . ' ';
    $node->desc .= isset($this->instance->pdi_SlotId) ? (gprop('slot') . ' ' . ($this->instance->pdi_SlotId) . ': ') : ('Unknown ' . gprop('slot'));
    $node->desc .= $this->instance->pdi_DriveModelNo;
    $node->ref = "phydrv.php?ssid=" . $this->ssid . "&pdid=" . $this->id;
    $node->icon = "phydrv";
    $node->state = NODE_STATE_OK;

    if (($this->instance->pdi_Flags & DEVICE_CONFIG_MASK) == PHYDRV_CONFIG_UNCONFIGURED) {

      if ($this->instance->pdi_Flags & PHYDRV_COND_PFA) {
        $node->state = NODE_STATE_DEAD;
      }
    } else if (($this->instance->pdi_Flags & DEVICE_CONFIG_MASK) == PHYDRV_CONFIG_STALE) {
      $node->state = NODE_STATE_DEAD;
    } else if (($this->instance->pdi_Flags & PHYDRV_STATE_ONLINE) == 0)
      $node->state = ($this->instance->pdi_Flags & PHYDRV_STATE_REBUILD) ? NODE_STATE_CRITICAL : NODE_STATE_DEAD;
    if (!($this->instance->pdi_Flags & PHYDRV_COND_ACCESSIBLE))
      $node->icon = "phydrvNotUsable";
    else
      $node->icon = "phydrv";

    $parentNode->addChildNode($node);
  }
}

class CLogicalDrive
{
  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CLogicalDrive(...$a); }
  function CLogicalDrive($client, $instance, $ssid, $id)
  {
    $this->client = $client;
    $this->ssid = $ssid;
    $this->id = $id;
    $this->instance = $instance;
  }

  function generateTree(&$parentNode, $daid = -1)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "ld" . $this->id;
    $node->desc = gprop('logicaldrive') . ' ' . $this->id;
    $node->ref = "logdrv.php?ssid=" . $this->ssid . "&ldid=" . $this->id;
    $node->icon = "logdrv";

    if ($this->instance->ldi_Flags & LOGDRV_STATE_CRITICAL)
      $node->state = NODE_STATE_CRITICAL;
    else if ($this->instance->ldi_Flags & LOGDRV_STATE_DEGRADED)
      $node->state = NODE_STATE_CRITICAL;
    else if (($this->instance->ldi_Flags & LOGDRV_STATE_ONLINE) == 0)
      $node->state = NODE_STATE_DEAD;

    if ($daid != -1) {
      $node->treeId = $this->ssid . "dald" . $this->id;
      $node->ref .= ("&daid=" . $daid);
    }

    $parentNode->addChildNode($node);
  }
}

class CDiskArray
{
  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CDiskArray(...$a); }
  function CDiskArray($client, $instance, $subsystem, $id)
  {
    $this->client = $client;
    $this->subsystem = $subsystem;
    $this->ssid = $this->subsystem->id;
    $this->id = $id;
    $this->instance = $instance;
  }

  function getPDFromId($id)
  {
    $physicalDrives = $this->subsystem->physicalDrives;
    for ($i = 0; $i < count($physicalDrives); $i++)
      if ($id == $physicalDrives[$i]->instance->pdi_FlatId)
      return $physicalDrives[$i];
    return null;
  }

  function generatePhysicalDriveTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "dapds" . $this->id;
    $node->desc = gprop('physicaldrives');
    $node->ref = "phydrvs.php?ssid=" . $this->ssid . "&daid=" . $this->id;
    $node->icon = "phydrvs";

    $PDs = $this->instance->acd_PDs;
    for ($i = 0; $i < count($PDs); $i++) {
      $physicalDrive = $this->getPDFromId($PDs[$i]->pdd_PDFlatId);
      if ($physicalDrive)
        $physicalDrive->generateTree($node);
    }

    $parentNode->addChildNode($node);
  }

  function generateLogicalDriveTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "dalds" . $this->id;
    $node->desc = gprop('logicaldrives');
    $node->ref = "logdrvs.php?ssid=" . $this->ssid . "&daid=" . $this->id;
    $node->icon = "logdrvs";

    $logicalDrives = $this->subsystem->logicalDrives;
    for ($i = 0; $i < count($logicalDrives); $i++) {
      if ($logicalDrives[$i]->instance->ldi_ArrayId == $this->id)
        $logicalDrives[$i]->generateTree($node, $this->id);
    }

    $parentNode->addChildNode($node);
  }

  function generateTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "da" . $this->id;
    $node->desc = gprop('diskarray') . ' ' . $this->id;
    $node->ref = "array.php?ssid=" . $this->ssid . "&daid=" . $this->id;
    $node->icon = "array";
    $node->isOpen = true;

    $this->generateLogicalDriveTree($node);

    $parentNode->addChildNode($node);
  }
}

class CEnclosure
{
  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CEnclosure(...$a); }
  function CEnclosure($client, $instance, $subsystem, $id)
  {
    $this->client = $client;
    $this->subsystem = $subsystem;
    $this->ssid = $this->subsystem->id;
    $this->id = $id;
    $this->instance = $instance;
  }

  function generatePhysicalDriveTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "pds";//.$this->id;
    $node->desc = gprop('physicaldrives');
    $node->ref = "phydrvs.php?ssid=" . $this->id;
    $node->icon = "phydrvs";

    $physicalDrives = $this->subsystem->physicalDrives;
    if (isset($physicalDrives)) {
      for ($i = 0; $i < count($physicalDrives); $i++) {
        if (isset($physicalDrives[$i]->instance->pdi_EnclosureId) &&
          $physicalDrives[$i]->instance->pdi_EnclosureId == $this->id) {
          $physicalDrives[$i]->generateTree($node, false);
        }
      }
    }
    $parentNode->addChildNode($node);
  }

  function generateTree(&$parentNode)
  {
    $server = getServer();
    $subsystem = $server->getSubsystem(1);
    $numEncl = $subsystem->ssi_JOBExpSSPresent + 1;
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "encl" . $this->id;
    $node->icon = "enclosure";
    $node->ref = "encl.php?ssid=" . $this->ssid . "&ecid=" . $this->id;
    if (($this->id == 0) && ($numEncl > 1))
      $node->desc = gprop('enclosure') . ' ' . ($this->id + 1) . ' (' . gprop('enclosureheadunit') . ')';
    else
      $node->desc = gprop('enclosure') . ' ' . ($this->id + 1);
    $this->generatePhysicalDriveTree($node);

    $parentNode->addChildNode($node);
  }
}

class CUps
{
  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CUps(...$a); }
  function CUps($client, $instance, $subsystem, $id)
  {
    $this->client = $client;
    $this->subsystem = $subsystem;
    $this->ssid = $this->subsystem->id;
    $this->id = $id;
    $this->instance = $instance;
  }

  function generateTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "ups" . $this->id;
    $node->desc = gprop('ups') . ' ' . ($this->id + 1);
    $node->ref = "ups.php?ssid=" . $this->ssid . "&upsid=" . $this->id;
    $node->icon = "upsnone";
    if (($this->instance->usd_Info0 & 0x0F) == 0x02) {
      $node->state = NODE_STATE_DEAD;
    } else if (($this->instance->usd_Info1 & 0x03) == 0x1) {
      $node->state = NODE_STATE_CRITICAL;
    } else
      $node->state = NODE_STATE_OK;
    $parentNode->addChildNode($node);
  }
}

class CSpareDrive
{
  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CSpareDrive(...$a); }
  function CSpareDrive($client, $instance, $subsystem, $id, $seq)
  {
    $this->client = $client;
    $this->subsystem = $subsystem;
    $this->ssid = $this->subsystem->id;
    $this->id = $id;
    $this->instance = $instance;
    $this->seq = $seq;
  }

  function generateTree(&$parentNode)
  {
    $node = new CNode($parentNode);
    $node->treeId = $this->ssid . "sd" . $this->id;
    $node->desc = gprop('sparedrive') . ' ' . ($this->seq);
    $node->ref = "spare.php?ssid=" . $this->ssid . "&spid=" . $this->id;
    $node->icon = "spare";

    if (($this->instance->pdd_PDFlags & PHYDRV_STATE_ONLINE) == 0)
      $node->state = ($this->instance->pdd_PDFlags & PHYDRV_STATE_REBUILD) ? NODE_STATE_CRITICAL : NODE_STATE_DEAD;

    $parentNode->addChildNode($node);
  }
}

class CNode
{
  var $childNodes;

  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CNode(...$a); }
  function CNode($parentNode)
  {
    static $nodeId = 0;

    // set default values
    $this->folder = "node" . $nodeId;
    $this->uniqueId = $nodeId++;
    $this->parentNode = $parentNode;
    $this->level = 0;
    $this->drawBorder = 0;
    $this->className = 'treeStyleNode';
    $this->textStyle = 'textTreeNode';
    $this->isOpen = false;
    $this->state = NODE_STATE_OK;
  }

  function addChildNode(&$node)
  {
    if ($this->treeId != "home")
      $node->level = $this->level + 1;

    // add the node reference to the end of the array
    $this->childNodes[] = &$node;

    if ($node->state == NODE_STATE_CRITICAL || $node->state == NODE_STATE_DEAD)
      $this->state = NODE_STATE_CRITICAL;
  }
}

class CTree
{
  /* PHP 8: method-name (PHP4) constructors are plain methods; forward. */
  function __construct(...$a) { $this->CTree(...$a); }
  function CTree($server)
  {
    $this->server = $server;

    // root node
    $this->rootNode = new CNode(null);

    // home node
    $this->rootNode->treeId = "home";
    $this->rootNode->desc = gprop('home') . ' (' . gprop('user') . ': ' . $_SESSION['username'] . ')';
    $this->rootNode->ref = "home.php?home";
    $this->rootNode->folder = "foldersTree";
    $this->rootNode->drawBorder = 1;
    $this->rootNode->backgroundColor = '#000000';
    $this->rootNode->textStyle = 'textTreeHomeNode';
  }

  function printNodes($node)
  {
    $header = 'icons/';
    if ($node->treeId == "home") {
      $tmp = $header . 'home.gif';
      $this->buffer .= $node->folder . "=gFld('" . $node->desc . "','" . $node->ref . "','" . $tmp . "',0," . $node->uniqueId . ",'treeStyleHome',1,'textTreeHomeNode',false,'home');\n";
    } else if ($node->treeId == "subsyss") {
      $isOpen = $node->isOpen ? 'true' : 'false';
      $this->buffer .= $node->folder . "=insFld(" . $node->parentNode->folder . ",gFld('" . $node->desc . "','" . $node->ref . "','" .
        $header . $node->icon . ".gif'," . $node->state . "," . $node->uniqueId . ",'" . $node->className . "'," .
        $node->drawBorder . ",'" . $node->textStyle . "'," . $isOpen . ",'" . $node->treeId . "'));\n";
    } else {
      $isOpen = $node->isOpen ? 'true' : 'false';
      $this->buffer .= $node->folder . "=insFld(" . $node->parentNode->folder . ",gFld('" . $node->desc . "','" . $node->ref . "','" .
        $header . $node->icon . ".gif'," . $node->state . "," . $node->uniqueId . ",'" . $node->className . "'," .
        $node->drawBorder . ",'" . $node->textStyle . "'," . $isOpen . ",'" . $node->treeId . "'));\n";
    }

    // print child nodes
    if (isset($node->childNodes)) {
      for ($i = 0; $i < count($node->childNodes); $i++) {
        $this->printNodes($node->childNodes[$i]);
      }
    }
    return $this->buffer;
  }

  function printTree()
  {
    $this->buffer = "<script language='JavaScript'>\n";
    $this->printNodes($this->rootNode);
    $this->buffer .= "\n</script>";
    return $this->buffer;
  }

  function generateTree()
  {
    $this->server->generateTree($this->rootNode);
    return $this->printTree();
  }
}

?>
