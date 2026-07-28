<?php

/*******************************************************************************
 * Filename    : export.php
 * Description : islaVista export file
 * Created on  : 12/16/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/

include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
    $_SESSION['ssid'] = $_GET['ssid'];

$server = getServer();
$subsystem = $server->getSubsystem();
$supportDualCtrl = $subsystem->supportdualcontroller();

$note = null;
$result = null;
?>
<html>

<head>
    <?php
    page_head();
    ?>
</head>

<body>
    <?php
    if (isset($_POST['save'])) {
        if (!isset($export)) {
            $export = new stdclass();
        }

        $export->method = IMEXPORT_HTTP;
        if (isset($_POST['encryption'])) {
            $export->encryption = true;
        } else {
            $export->encryption = false;
        }

        if ($_POST['type'] == IMEXPORT_USERDATABASE) {
            $export->type = IMEXPORT_USERDATABASE;
            $export->encryption = true;
            if (PHP_OS == "WINNT") {
                $export->tempFileName = apache_getenv('TMP') . "\\export";
            }
        } else {
            $export->type = IMEXPORT_CONFIGSCRIPT;
            $export->username = unserialize($_SESSION['sessionService'])->username;
            $export->tempFileName = apache_getenv('TMP') . '\\configscript';
        }
        $result = $subsystem->export($export);
        if ($result == PIERR_INACTIVE_SESSION) {
            errorOnInvalidSession();
        }
        if ($result == PIERR_NO_ERROR) {
            if ($export->type == IMEXPORT_CONFIGSCRIPT) {
                header('Location: download.php?file=cfgscr');
                $note = gresult('fileexportok');
            } else {
                header('Location: download.php?file=export');
                $note = gresult('fileexportok');
            }
        } else
            $note = gerror('exportfile');
    }
    ?>

    <?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
    <form method=post action="<?php echo $action_url; ?>">
  <?php form_csrf(); ?>
        <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>

            <?php
            table_title(gprop('export'), 'export', $note, $result);

            table_tab_head();
            $tabTitle = tabTitleAdminTool($subsystem);
            table_tab($tabTitle);

            table_head_fold(gprop('exportinfo'), 'exportinfo', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
            $type = array(array(IMEXPORT_USERDATABASE, gprop('userdatabase')), array(IMEXPORT_CONFIGSCRIPT, gprop('configurationscript')));
            table_row_combo(gprop('type'), $type, 'type', 0);

            table_row_checkbox(gprop('encryption'), True, 'encryption');
            table_foot_fold();
            table_tab_foot();
            ?>
            <table border=0 cellspacing=2 cellpadding=0 align=center>
                <tr>
                    <td>
                        <input class=input-p type=submit value="<?= gprop('export') ?>" name=save>
                    </td>
                </tr>
            </table>
            <?php
            page_foot();
            ?>
        </table>
    </form>
    <script>
        var form = document.forms[0];
        if (form.type.options[form.type.selectedIndex].text == '<?= gprop('userdatabase') ?>')
            form.encryption.disabled = true;
        elements = document.forms[0].elements;
        type = elements['type'];
        encryption = elements['encryption'];
        if (type) {
            type.onchange = adjencry;
            //adjencry();
        }

        function adjencry() {
            if (type.value == 4)
                encryption.checked = false;
            else
                encryption.checked = true;
        }
    </script>
</body>

</html>