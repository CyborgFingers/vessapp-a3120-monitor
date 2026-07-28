<?php

/*******************************************************************************
 * Filename    : screen.php
 * Description : islaVista top most page.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/

require "language.php";
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html>

<head>
<title><?= gprop('title') ?></title>
</head>

<?php
if (!isset($_SESSION['sessionService'])) {
  ?>
        <meta http-equiv=refresh target=_top content="0; URL=index.php">
<?php
exit();
}

$_SESSION['loginPage'] = 0;
?>

<frameset id=topframeset rows="81,*,1" frameborder=0 framespacing=0 style="background:url(/images/libodybg.gif);">
  <frame src="header.php" name=headfrm noresize target=top scrolling=no>
  <frameset cols="300,*" framespacing=0 border="0">
    <frame src="treeframe.php" name=treefrm target=_self scrolling=auto>
    <frameset id=myframeset rows="*,0" frameborder=0 framespacing=0>
      <frame src="home.php" name=basefrm target=_self scrolling=auto>
      <frame src="event.php" name=eventfrm scrolling=auto>
    </frameset>
  </frameset>
  <frame src="daemon.php" name=daemonfrm noresize>

  <noframes>
    <body>
      <p>This page uses frames, but your browser doesn't support them.</p>
      <p><a href="help.html">Go back to Help Menu</a>
    </body>
  </noframes>
</frameset>

</html>
