<?php

/*******************************************************************************
 * Filename    : phydrvt.php
 * Description : islaVista physical drive settings.
 * Created on  : 09/21/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'language.php';
?>
<html>

<head>
<?php
$cssPostFix = "?time=" . time() . ".css";
$jsPostFix = "?time=" . time() . ".js";
echo '
  <link rel="stylesheet" href="css/text1.css' . $cssPostFix . '" type="text/css">
  <script src="js/plib.js' . $jsPostFix . '"></script>';
?>
<title>Confirmation</title>
</head>

<body style="background-color:#D0D0D0">
  <table width=90% border=0 cellspacing=2 cellpadding=0 align=center class=text5>
    <tr>
      <td>&nbsp;</td>
    </tr>
    <tr>
      <td>
      <script>
        document.write(unescape("<?= $_GET['str']; ?>"));
      </script>
      </td>
    </tr>
    <tr>
      <td>&nbsp;</td>
    </tr>
    <tr align=center>
      <td>
        <input maxlength=48 type=text id=edit1 size=30>
      </td>
    </tr>
    <tr>
      <td>&nbsp;</td>
    </tr>
    <tr align=center>
      <td>
        <input class=text5 type=submit value="   OK   " name=ok onclick=onOK()>
        &nbsp;
        <input class=text5 type=submit value=Cancel name=cancel onclick=onCancel()>
      </td>
    </tr>
  </table>

<script>
  function onCancel()
  {
    window.returnValue = 'cancelled';
    window.close();
  }

  function onOK()
  {
    obj = ylib_getObj('edit1');
    window.returnValue = obj.value;
    window.close();
  }
</script>

</body>
</html>
