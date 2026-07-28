<?php
    include 'tree.php';
    include 'common.php';
	
	if (!isset($_SESSION['sessionService'])) {
        echo '
        <script>
          alert("Last session has timed out");
          top.location.href = "index.php";
        </script>';
        exit;
    }

    $sid = ((array)unserialize($_SESSION['sessionService']))['sid'];
    if (isset($_POST['sid']) && (int)$_POST['sid']!=(int)$sid) {
        echo '
        <script>
          alert("Last session has timed out");
          top.location.href = "screen.php";
        </script>';
        exit;
    }

    SetSessionValue('loginPage',1);

    $server = getServer();
    $subsystem = $server->getSubsystem();
    $users = $subsystem->getUsers();
    $result = $users->errorcode;
    if ($result == PIERR_INACTIVE_SESSION) {
        errorOnInvalidSession();
    }

    if ($result == PIERR_NO_ERROR) {
        $users=$users->userlist;
        if (!isset($_SESSION['userid'])) {
            for ($i = 0; $i < count($users); $i++) {
                $user = $users[$i];
                if ($user->username == $_SESSION['username']) {
                    $_SESSION['userid'] = $i;
                    break;
                }
            }
        }
        $user = $users[$_SESSION['userid']];
        if (!isset($password)) {
            $password=new stdclass();
        }
        $password->username = $user->username;
    }
?>

<html>

<head>
<meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1">
<meta http-equiv="X-UA-Compatible" content="IE=edge" />
<?php
    include_once 'header.php';
    page_head();
?>
</head>

<body>
<?php
    if (isset($_POST['cancel'])) {
?>
        <script>
            top.location.href="index.php?logout";
        </script>
<?php
        exit;
    }
    else if (isset($_POST['submit'])) {
        if (isset($_POST['oldpassword'])) {
            $password->oldpassword = $_POST['oldpassword'];
        }
        $password->newpassword = $_POST['newpassword'];
        $status = $subsystem->setPwd($password);
        $note = ($status)?gresult('passwordchangefail'):gresult('passwordchangeok');
?>
        <script>
            top.location.href="index.php?logout&note=<?=$note?>";
        </script>
<?php
        exit;
    }
?>

<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=2 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>

<?php
    $warning = gjsalert('strongerpassword');
    table_title(gprop('changepassword'), 1, $warning, null, $result);
    table_head_fold(gprop('passwordsettings'), 'pwd', null, null, array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner'));
    if ($result == PIERR_NO_ERROR) {
        table_row(gprop('username'), $password->username, 'username');
        if ( $_SESSION['username'] == $user->username)
            table_row_pwd_edit(gprop('oldpassword'), '', 'oldpassword');
        table_row_pwd_edit(gprop('newpassword'), '', 'newpassword', 31);
        table_row_pwd_edit(gprop('retypepassword'), '', 'tmppassword', 31);
        table_foot_fold();
        table_row_submit(array('submitClick'=>'validateSubmit()'));
    }
    else {
        table_row_empty(gerror('displaypasswordsetting'));
        table_foot_fold();
    }
    page_foot();
?>
	<input type="hidden" id="sid" value="<?php echo $sid;?>" name="sid" />
  </table>
</form>

<script>
    form = document.forms[0];
    function validateSubmit() {
		if (form.newpassword.value.length < 8) {
            alert('<?=gjsalert('invalidpassword')?>');
            return false;
        }
        if (form.newpassword.value == "password") {
            alert('<?=gjsalert('strongerpassword')?>');
            return false;
        }
        if (form.newpassword.value == form.tmppassword.value) {
            return true;
        }
        alert('<?=gjsalert('retypenewpassword')?>');
        return false;
    }
</script>

</body>
</html>
