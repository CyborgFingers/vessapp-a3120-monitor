<?php
/*******************************************************************************
 * Filename    : help.php
 * Description : islaVista common functions for showing table.
 * Created on  : 12/12/03
 * CVS Version : $Id: help.php,v 1.14 2006/05/19 07:49:39 xinc Exp $
 *
 * (C) Copyright Promise Technology Inc., 2003
 * All Rights Reserved
 ******************************************************************************/

    $file = '/opt/flash/promise/sw/php/promise/helpmap.php';
    if ( file_exists($file))
        include $file;
    else
    	include '../helpmap.php';

    $helptext = 'Help not found.';
    if (isset($_GET['file']))
    {
        $file = getFileName($_GET['file'], $_GET['specific']);
        if (!file_exists($file))
        {
            header('Location: ../home.php?home');
            exit;
        }

        // generate help file
        header('Content-type: text/html;charset=iso-8859-1');
        $handle = fopen($file, 'r');
        $helptext = fread($handle, filesize($file));
        fclose($handle);
        echo $helptext;
        exit;
    }
?>
