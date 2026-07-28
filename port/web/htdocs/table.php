<?php

/*******************************************************************************
 * Filename    : table.php
 * Description : islaVista common functions for showing table.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include_once 'defense.php';

function table_title($title, $hideHelp = 0, $note = null, $error = null, $specific = 'empty')
{
  if (($note&&!preg_match('/^[^<>\/"]+$/', $note)) || 
      ($error&&!preg_match('/^[^<>\/"]+$/', $error)) ||
      ($specific&&!preg_match('/^[^<>\/"]+$/', $specific))) {
    echo '<script>window.location.replace(\'https://'.$_SERVER['HTTP_HOST'].'/screen.php\');</script>';
    return;
  }
  $_SESSION['title'] = $title;
  echo '
  <tr>
    <td >
      <table class=tableTitle align=center>
        <tr>
          <td>
            <span class=text1>' . $title . '</span>';
  if ($note)
    echo '
            <span class=text6> - ' . $note . '</span>';
  if ($error)
    echo '
            <span class=text6red>&nbsp;' . (is_int((int)$error) ? gerror($error) : $error) . '</span>';
  if ($hideHelp != 1)
    echo '
          </td>
            <td align=right>
            <table cellpadding=0 cellspacing=0 class="tableTitleHelp1" id="HELP_01"
              onMouseOver=javascript:changeImages("tableTitleHelp2")
              onMouseOut=javascript:changeImages("tableTitleHelp1")
              onclick=javascript:openHelpWindow("help/help.php?file=' . htmlspecialchars($_SERVER['PHP_SELF']) . '&specific=' . $specific . '")><tr><td></td><td>' . gprop('help') . '</td></tr></table>';
    echo '
          </td>
        </tr>
      </table>
    </td>
  </tr>';
}

function table_header($header, $span = 0)
{
  echo '
  <tr>
    <td class=tableHeader' . ($span ? (' colspan=' . $span) : '') . '><span class=text3>
      <img src="images/ball-1.gif" align=absmiddle> ' . $header . '</span>
    </td>
  </tr>';
}

function table_header0($header, $span = 0)
{
  echo '
  <tr>
    <td class=text3' . ($span ? (' colspan=' . $span) : '') . '>' . $header . '
    </td>
  </tr>';
}

function table_row($left, $right, $leftWidth = 30, $color = 0, $id = null)
{
  echo '
  <tr width=100%>
    <td class=tableRowCol1 width=' . $leftWidth . '% ><span class=text4  style=float:left>' . $left . '</span>
    </td>
    <td class=tableRowColOther><span class=text5 ' . ($id ? (' id=' . $id) : '') . '>' . $right . '</span>
    </td>
  </tr>';
}

function table_row2($left, $column1, $column2)
{
  echo '
  <tr>
    <td class=tableRowCol1 width=30%><span class=text4>' . $left . '</span></td>
    <td class=tableRowColOther width=35%><span class=text5>' . $column1 . '</span></td>
    <td class=tableRowColOther width=35%><span class=text5>' . $column2 . '</span></td>
  </tr>';
}
function table_row4($left, $column1, $column2, $column3, $column4)
{
  echo '
  <tr>
    <td class=tableRowCol1 width=12%><span class=text4>' . $left . '</span></td>
    <td class=tableRowColOther width=22%><span class=text5>' . $column1 . '</span></td>
    <td class=tableRowColOther width=22%><span class=text5>' . $column2 . '</span></td>
    <td class=tableRowColOther width=22%><span class=text5>' . $column3 . '</span></td>
    <td class=tableRowColOther width=22%><span class=text5>' . $column4 . '</span></td>
  </tr>';
}
function table_row_header($left, $right)
{
  echo '
  <tr class=tableRowHeader>
    <td width=30% class=text4b>' . $left . '</td>
    <td width=70% class=text4b>' . $right . '</td>
  </tr>';
}

function table_row2_header($left, $column1, $column2)
{
  echo '
  <tr class=tableRowHeader>
      <td width=30% class=text4b>' . $left . '</td>
      <td width=35% class=text4b><a href=' . $column1[1] . '  >' . $column1[0] . '</a></td>
      <td width=35% class=text4b><a href=' . $column2[1] . '  >' . $column2[0] . '</a></td>
  </tr>';
}
function table_row2_header2($left, $column1, $column2)
{
  echo '
  <tr class=tableRowHeader>
      <td width=30% class=text4b>' . $left . '</td>
      <td width=35% class=text4b>' . $column1 . '</td>
      <td width=35% class=text4b>' . $column2 . '</td>
  </tr>';
}
function table_row4_header($left, $column1, $column2, $column3, $column4)
{
  echo '
  <tr class=tableRowHeader>
      <td width=12% class=text4b>' . $left . '</td>
      <td width=22% class=text4b><a href=' . $column1[1] . '  >' . $column1[0] . '</a></td>
      <td width=22% class=text4b><a href=' . $column2[1] . '  >' . $column2[0] . '</a></td>
      <td width=22% class=text4b><a href=' . $column3[1] . '  >' . $column3[0] . '</a></td>
      <td width=22% class=text4b><a href=' . $column4[1] . '  >' . $column4[0] . '</a></td>
  </tr>';
}
function table_row4_header2($left, $column1, $column2, $column3, $column4)
{
  echo '
  <tr class=tableRowHeader>
      <td width=12% class=text4b>' . $left . '</td>
      <td width=22% class=text4b>' . $column1 . '</td>
      <td width=22% class=text4b>' . $column2 . '</td>
      <td width=22% class=text4b>' . $column3 . '</td>
      <td width=22% class=text4b>' . $column4 . '</td>    

  </tr>';
}
function table_row_edit($left, $right, $fieldname, $maxlength = 48, $leftWidth = 30, $unit = null, $spanid = null, $disabled = 'false')
{
  if (isset($spanid))
    $spanTags = " <span id=$spanid class=warning nowrap></span>";
  else
    $spanTags = '';
  $right = str_replace("\"", "&#x22;", $right);
  echo '
  <tr>
    <td width=' . $leftWidth . '% class=tableRowCol1>
      <span class=text4>' . $left . '</span>
    </td>
    <td class=tableRowColOther>
      <span class=text5>';
  if ($disabled == 'false')
    echo '<input maxlength=' . $maxlength . ' class=editField type=text name=' . $fieldname . ' size=20 value="' . $right . '">' . ($unit ? $unit : '') . $spanTags;
  else
    echo '<input disabled style="" maxlength=' . $maxlength . ' class=editField type=text name=' . $fieldname . ' size=20 value="' . $right . '">' . ($unit ? $unit : '') . $spanTags;

  echo '</span>
    </td>
  </tr>';
}
function table_row_textarea($left, $right, $fieldname, $leftWidth = 30, $columns = 40, $lines = 4)
{
  $right = str_replace("\"", "&#x22;", $right);
  echo '
  <tr>
    <td width=' . $leftWidth . '% class=tableRowCol1>
      <span class=text4>' . $left . '</span>
    </td>
    <td class=tableRowColOther>
      <span class=text5>
	    <textarea name="' . $fieldname . '" id="' . $fieldname . '" cols="' . $columns . '" rows="' . $lines . '">' . $right . '</textarea>
      </span>
    </td>
  </tr>';
}

function table_row_pwd_edit($left,$right,$fieldname,$maxlength=48,$leftWidth=30,$spanid=null)
{
  if (isset($spanid))
    $spanTags = " <span id=$spanid class=warning nowrap></span>";
  else
    $spanTags = '';
  echo '
  <tr>
    <td width=' . $leftWidth . '% class=tableRowCol1>
      <span class=text4>' . $left . '</span>
    </td>
    <td class=tableRowColOther>
      <span class=text5>
        <input maxlength='.$maxlength.' class=editField type=password name='.$fieldname.' size=20  value="'.$right.'">'.$spanTags.'
      </span>
    </td>
  </tr>';
}
function table_row_combo($left, $right, $fieldname, $value, $disabled = 'false', $leftWidth = 30, $spanid = '', $spanval = '')
{
  echo '
  <tr>
    <td width=' . $leftWidth . '% class=tableRowCol1>
      <span class=text4>' . $left . '</span>
    </td>
    <td class=tableRowColOther>
      <span class=text5>';
  if ($disabled == 'false')
    echo '<select class=editField name=' . $fieldname . ' >';
  else
    echo '<select class=editField name=' . $fieldname . ' disabled >';

  for ($i = 0; $i < count($right ?? array()); $i++)
    echo '
        <option value=' . $right[$i][0] . ($value == $right[$i][0] ? ' selected' : '') . '>' . $right[$i][1] . '</option>';
  echo '
      </select>';
  if ($spanid)
    echo " <span class=warning  id=$spanid nowrap>$spanval</span>";
  echo '
      </span>
    </td>
  </tr>';
}
function table_row_combo_onchange($left,$right,$fieldname,$value,$changemethod,$disabled='false',$leftWidth=30,$spanid='',$spanval='')
{
  echo '
  <tr>
    <td width='.$leftWidth.'% class=tableRowCol1>
      <span class=text4>'.$left.'</span>
    </td>
    <td class=tableRowColOther>
      <span class=text5>';
	  if($disabled=='false')
        echo '<select class=editField name='.$fieldname.' onChange="'.$changemethod.'()">';
	  else
	    echo '<select class=editField name='.$fieldname.' disabled onChange="'.$changemethod.'()">';

      for($i=0;$i<count($right);$i++)
        echo '
        <option value='.$right[$i][0].($value==$right[$i][0]?' selected':'').'>'.$right[$i][1].'</option>';
  echo '
      </select>';
  if ($spanid)
    echo " <span class=warning  id=$spanid nowrap>$spanval</span>";
  echo '
      </span>
    </td>
  </tr>';
}
function table_row_radio($left, $right, $fieldname, $value, $disabled='false', $leftWidth=30, $spanid=null) {
  if (isset($spanid))
    $spanTags = " <span id=$spanid class=warning nowrap></span>";
  else
    $spanTags = '';
  echo '<tr>';
  echo '  <td width="'.$leftWidth.'%" class="tableRowCol1"><span class="text4">'.$left.'</span></td>';
  echo '  <td class="tableRowColOther"><span class="text5">';
  foreach($right as $r) {
    $opt_c = ($r[0] == $value) ? ' checked' : '';
    $opt_d = ($disabled == 'true') ? ' disabled' : '';
    echo '    <input type="radio" name="' . $fieldname . '" value="' . $r[0] . '" ' . $opt_c . $opt_d . '/>' . $r[1] . ' &nbsp;&nbsp;';
  }
  echo '  </span>'.$spanTags.'</td>';
  echo '</tr>';
}
function table_select($options, $fieldname, $current)
{
  echo '
  <select class=editField0 name=' . $fieldname . ' style="margin-right:8px">';
  for ($i = 0; $i < count($options); $i++)
    echo '
    <option value=' . $options[$i][0] . ($current == $options[$i][0] ? ' selected' : '') . '>' . $options[$i][1] . '&nbsp;</option>';
  echo '
  </select>';
}

function table_row_checkbox($left, $value, $fieldname, $leftWidth = 30, $disabled = null, $event = null)
{
  echo '
  <tr>
    <td width=' . $leftWidth . '% class=tableRowCol1>
      <span class=text4>' . $left . '</span>
    </td>
    <td class=tableRowColOther>
      <span class=text5> 
        <input ' . $disabled . ' type=checkbox name=' . $fieldname . ($value ? ' checked' : '') . ' onchange =' . ($event ? $event : '') . '>
      </span>
    </td>
  </tr>';
}

function table_row_anchor($left, $href, $right, $leftWidth = 30)
{
  echo '
  <tr>
    <td width=' . $leftWidth . '% class=tableRowCol1>
      <span class=textAnchor>
      <a href=' . $href . '>' . $left . '</a>
      </span>
    </td>
    <td class=tableRowColOther>
      <span class=text5>' . $right . '</span>
    </td>
  </tr>';
}

function table_row_browse($left, $maxFileSize, $leftWidth = 30)
{
  echo '
  <tr>
    <td width=' . $leftWidth . '% class=tableRowCol1>
      <span class=text4>' . $left . '</span>
    </td>
    <td class=tableRowColOther>
      <span class=text5>
        <input type=hidden name=MAX_FILE_SIZE value=' . $maxFileSize . '>
        <input class=editField4 type=file name=userfile>
      </span>
    </td>
  </tr>';
}
function table_row_submit2(
  $b = array('submit' => 1, 'reset' => 1, 'cancel' => 1, 'back' => 0, 'next' => 0, 'submitClick' => null, 'nextClick' => null, 'resetClick' => null),
  $submit = null
) {
  if (empty($submit)) $submit = gprop('submit');
  echo '
  <table border=0 cellspacing=2 cellpadding=0 align=center>
    <tr>
      <td>';
  if (isset($b['back']) && $b['back'])
    echo '
        <input class=input-p type=submit value="<- ' . gprop('back') . '" name=back>';
  if (!isset($b['reset']) || $b['reset']) {
    echo '
        <input class=input-p type=reset value=' . gprop('reset') . ' name=reset';
    if (isset($b['resetClick']) && $b['resetClick'])
      echo ' onclick="return ' . $b['resetClick'] . '"';
    echo '>';
  }
  if (!isset($b['submit']) || $b['submit']) {
    echo '
          <input class=input-p type=submit value=' . $submit . ' name=submit2';
    if (isset($b['submit']) && $b['submit'] == 2) echo ' disabled';
    if (isset($b['submitClick']) && $b['submitClick'])
      echo ' onclick="return ' . $b['submitClick'] . '"';
    echo '>';
  }
  if (!isset($b['cancel']) || $b['cancel'])
    echo '
        <input class=input-p type=submit value=' . gprop('cancel') . ' name=cancel>';
  if (isset($b['next']) && $b['next']) {
    echo '
        <input class=input-p type=submit value="' . gprop('next') . ' ->" name=next';
    if (isset($b['nextClick']) && $b['nextClick'])
      echo ' onclick="return ' . $b['nextClick'] . '"';
    echo '>';
  }
  echo '
      </td>
    </tr>
  </table>';
}


function table_row_submit(
  $b = array('submit' => 1, 'reset' => 1, 'cancel' => 1, 'back' => 0, 'next' => 0, 'submitClick' => null, 'nextClick' => null, 'resetClick' => null, 'cancelClick' => null),
  $submit = null
) {
  if (empty($submit)) $submit = gprop('submit');
  echo '
  <table border=0 cellspacing=2 cellpadding=0 align=center>
    <tr>
      <td>';
  if (isset($b['back']) && $b['back'])
    echo '
        <input class=input-p type=submit value="<- ' . gprop('back') . '" name=back>';
  if (!isset($b['reset']) || $b['reset']) {
    echo '
        <input class=input-p type=reset value=' . gprop('reset') . ' name=reset';
    if (isset($b['resetClick']) && $b['resetClick'])
      echo ' onclick="return ' . $b['resetClick'] . '"';
    echo '>';
  }
  if (!isset($b['submit']) || $b['submit']) {
    echo '
          <input class=input-p type=submit value=' . $submit . ' name=submit';
    if (isset($b['submit']) && $b['submit'] == 2) echo ' disabled';
    if (isset($b['submitClick']) && $b['submitClick'])
      echo ' onclick="return ' . $b['submitClick'] . '"';
    echo '>';
  }
  if (!isset($b['cancel']) || $b['cancel']) {
    if (!isset($b['cancelClick']) || $b['cancelClick'] == null) {
      echo '
        <input class=input-p type=submit value=' . gprop('cancel') . ' name=cancel>';
    } else {
      echo '
        <input class=input-p type=button value=' . gprop('cancel') . ' name=cancel onclick="return ' . $b['cancelClick'] . '">';
    }
  }
  if (isset($b['next']) && $b['next']) {
    echo '
        <input class=input-p type=submit value="' . gprop('next') . ' ->" name=next';
    if (isset($b['nextClick']) && $b['nextClick'])
      echo ' onclick="return ' . $b['nextClick'] . '"';
    echo '>';
  }
  echo '
      </td>
    </tr>
  </table>';
}

function table_row_start($b = array('start' => 0, 'stop' => 1, 'pause' => 0, 'resume' => 0, 'cancel' => 0))
{
  echo '
  <table border=0 cellspacing=2 cellpadding=0 align=center>
    <tr>
      <td>';
  if (isset($b['start']) && $b['start'])
    echo '
        <input class=input-p type=submit value="  ' . gprop('start') . '  " name=start>';
  if (!isset($b['stop']) || $b['stop'])
    echo '
        <input class=input-p type=submit value="  ' . gprop('stop') . '  " name=stop>';
  if (isset($b['pause']) && $b['pause'])
    echo '
        <input class=input-p type=submit value=" ' . gprop('pause') . ' " name=pause>';
  if (isset($b['resume']) && $b['resume'])
    echo '
        <input class=input-p type=submit value="' . gprop('resume') . '" name=resume>';
  echo '
      </td>
  </tr>';
}
function table_row_nocaption($content, $color = 0, $id = null)
{
  echo '
  <tr width=100%>
    <td class=tableRowCol1><span class=text4 style="padding: 0">' . $content . '</span>
    </td>
  </tr>';
}
function table_header_cols($columns, $widths = null, $id = null)
{
  echo '
  <tr class=tableRowHeader' . ($id ? (' id=' . $id) : '') . '>';
  for ($i = 0; $i < count($columns); $i++)
    echo '
      <td' . ($widths ? (' width=' . $widths[$i] . '%') : '') . '><span class=text4b>' . $columns[$i] . '</span></td>';
  echo '
    
  </tr>';
  $_SESSION['row'] = 0;
}
function table_header_cols_one($columns)
{
  echo '
  <tr class=tableRowHeader>';
  for ($i = 0; $i < count($columns); $i++)
    echo '
      <td colspan=2 align=center ><span class=text4b>' . $columns[$i] . '</span></td>';
  echo '
    
  </tr>';
  $_SESSION['row'] = 0;
}

function table_row_cols($columns, $widths = null, $id = null, $native = false)
{
  $class = mod($_SESSION['row'], 2) ? 'tableRow2Cols' : 'tableRow1Cols';
  echo '
  <tr class=' . $class . ' ' . ($id ? (' id=' . $id) : '') . '>';

  for ($i = 0; $i < count($columns); $i++) {
    if (is_array($columns[$i])) {
      echo '
      <td' . ($columns[$i][1] ? ' class=textAnchor' : '') . (isset($columns[$i][2]) ? (' style="color:' . $columns[$i][2] . '"') : '') . ($widths ? (' width=' . $widths[$i] . '%') : '') . '><span ' . (isset($columns[$i][2]) ? (' style="color:' . $columns[$i][2] . '"') : '') . '>';
      $text = $native ? $columns[$i][0] : $columns[$i][0];
      echo $columns[$i][1] ? ('<a class=text5 href=' . $columns[$i][1] . '>' . $text . '</a>') : $text;
      echo '
      </span></td>';
    } else {
      $text = $native ? $columns[$i] : $columns[$i];
      echo '
      <td' . ($widths ? (' width=' . $widths[$i] . '%') : '') . '><span class=text5>' . $text . '</span></td>';
    }
  }
  echo '
    </span>
  </tr>';
  $_SESSION['row']++;
}

function table_row_cols_nested($columns, $nestedheadercolumns, $nestedheaderwidths, $nestedrows, $id = null, $nestedid = null)
{
  $class = 'tableRow1Cols';
  echo '
  <tr class=' . $class . ' ' . ($id ? (' id=' . $id) : '') . '>';

  for ($i = 0; $i < count($columns); $i++) {
    if (is_array($columns[$i])) {
      echo '
      <td' . ($columns[$i][1] ? ' class=textAnchor' : '') . (isset($columns[$i][2]) ? (' style="color:' . $columns[$i][2] . '"') : '') .
        '><span ' . (isset($columns[$i][2]) ? (' style="color:' . $columns[$i][2] . '"') : '') . 'class=text5>';
      $text = $columns[$i][0];
      echo $columns[$i][1] ? ('<a href=' . $columns[$i][1] . '>' . $text . '</a>') : $text;
      echo '
      </span></td>';
    } else {
      $text = $columns[$i];
      echo '
      <td><span class=text5>' . $text . '</span></td>';
    }
  }
  echo '
    <td>';
  echo '
    <table width=100% cellpadding=2 cellspacing=1 border=0' . ($nestedid ? (' id=' . $nestedid) : '') . '>
    ';
  echo '
		<tr class=tableRowHeader >';
  for ($i = 0; $i < count($nestedheadercolumns); $i++)
    echo '
		  <td' . ($nestedheaderwidths ? (' width=' . $nestedheaderwidths[$i] . '%') : '') . '><span class=text4b>' . $nestedheadercolumns[$i] . '</span></td>';
  echo '
		</tr>';
  $_SESSION['nestedrow'] = 0;

  for ($j = 0; $j < count($nestedrows); $j++) {
    $nestedclass = mod($_SESSION['nestedrow'], 2) ? 'tableRow2Cols' : 'tableRow1Cols';
    echo '
		  <tr class=' . $nestedclass . ' >';
    if (is_array($nestedrows[$j])) {
      for ($k = 0; $k < count($nestedrows[$j]); $k++) {
        $nestedtext = $nestedrows[$j][$k];
        echo '
		        <td><span class=text5>' . $nestedtext . '</span></td>';
      }
    } else {
      $nestedtext = $nestedrows[$j];
      echo '
		      <td colspan=2 ><span class=text5>' . $nestedtext . '</span></td>';
    }
    echo '
		  </tr>';
    $_SESSION['nestedrow']++;
  }

  echo '
    </table>';
  echo '
    </td>';
  echo '
  </tr>';
  $_SESSION['row']++;
}

function table_header_cb_cols($columns, $widths)
{
  echo '
  <tr class=tableRowHeader>
    <td width=' . $widths[0] . '% align=middle>
      <input type=checkbox name=selectall onclick=selectAll()>' . $columns[0] . '</td>';
  for ($i = 1; $i < count($columns); $i++)
    echo '
    <td width=' . $widths[$i] . '%><span class=text4b>' . $columns[$i] . '
    </span></td>';
  echo '
  </tr>';
  $_SESSION['row'] = 0;
}

function table_row_cb_cols($columns, $disable = false, $name = 'items[]', $value = null)
{
  $class = mod($_SESSION['row'], 2) ? 'tableRow2Cols' : 'tableRow1Cols';
  if (!isset($value))
    $value = $_SESSION['row'];
  echo '
  <tr class=' . $class . ' >
    <td align=middle>
      <span class=text5>
        <input type=checkbox ' . ($disable ? ' disabled=true ' : '') . 'name=' . $name . '  value=' . $value . ($columns[0] ? ' checked' : '') . ' >
    </span>
    </td>';
  for ($i = 1; $i < count($columns); $i++) {
    if (is_array($columns[$i]))
      echo '
      <td' . ($columns[$i][1] ? ' class=textAnchor' : '') . (isset($columns[$i][2]) ? (' style="color:' . $columns[$i][2] . '"') : '') . '>
        <span class=text5>' . ($columns[$i][1] ? ('<a href=' . $columns[$i][1] . '>' . $columns[$i][0] . '</a>') : $columns[$i][0]) . '
         </span>
      </td>';
    else
      echo '
      <td><span class=text5>' . $columns[$i] . '</span></td>';
  }
  echo '
    </span>
  </tr>';
  $_SESSION['row']++;
}

function table_row_name_cols($columns, $widths)
{
  echo '
  <tr>
    <td width=' . $widths[0] . '% class=tableRowCol1><span class=text4>' . $columns[0] . '</span></td>';
  for ($i = 1; $i < count($columns); $i++)
    echo '
    <td width=' . $widths[$i] . '% class=tableRowColOther><span class=text5>' . $columns[$i] . '</span></td>';
  echo '
  </tr>';
}

function table_row_progress_href($name, $href, $percentage, $leftwidth)
{
  $percentage1 = 100 - $percentage;
  if (!isset($leftwidth)) $leftwidth = 30;
  $rightwidth = 100 - $leftwidth;
  echo '
  <tr>
    <td width=' . $leftwidth . '% class=tableRowCol1><span class=text4>
    <a class=text5 href=' . $href . '>' . $name . '</a></span></td>
    <td width=' . $rightwidth . '% class=tableRowColOther>
      <span class=text5 style="padding:0;">
      <table border=0 width=100% cellpadding="0" cellspacing="0">
        <tr class=text5>';

  echo '
          <td width=' . $percentage . '% class=tableProgressCol1>' . $percentage . '%</td>
          <td width=' . $percentage1 . '% class=tableProgressColOther></td>';
  echo '
        </tr>
      </table>
      </span>
    </td>
  </tr>';
}


function table_row_progress($name, $percentage, $leftwidth = 30)
{
  $percentage1 = 100 - $percentage;
  $rightwidth = 100 - $leftwidth;
  echo '
  <tr>
    <td width=' . $leftwidth . '% class=tableRowCol1><span class=text4>' . $name . '</span></td>
    <td width=' . $rightwidth . '% class=tableRowColOther>
      <span class=text5 style="padding:0;">
      <table border=0 width=100% cellpadding="0" cellspacing="0">
        <tr class=text5>';

  echo '
          <td width=' . $percentage . '% class=tableProgressCol1>' . $percentage . '%</td>
          <td width=' . $percentage1 . '% class=tableProgressColOther></td>';
  echo '
        </tr>
      </table>
      </span>
    </td>
  </tr>';
}

function add_tabPosition(&$ref, $i)
{
  if ($ref == null) return;
  if (strpos($ref, 'tab=') == false)
    $ref .= (strpos($ref, '?') ? '&' : '?') . 'tab=' . $i;
}

function table_tab($tabs, $current = 0)
{
  echo '
  <tr>
  <td>
  <table width=100% cellpadding=0 cellspacing=0 border=0 align=center>
  <tr>';

  for ($i = 0; $i < count($tabs); $i++) {
    $tooltip = '';
    if (is_array($tabs[$i][0])) {
      $name = $tabs[$i][0][0];
      $ref = $tabs[$i][0][1];
      if (isset($tabs[$i][0][2])) $tooltip = $tabs[$i][0][2];
    } else {
      $name = $tabs[$i][0];
      $ref = $tabs[$i][1];
      if (isset($tabs[$i][2])) $tooltip = $tabs[$i][2];
    }
    add_tabPosition($ref, $i);

    echo '
    <td width=1 class=' . ($_SESSION['tab'] == $i ? 'tabhi' : 'tablo') . '>
      <table cellpadding=0 cellspacing=0 border=0 id=t' . $i . 'Tb height=28>
      <tr class=textTab>
        <td id=t' . $i . '1 nowrap title="' . $tooltip . '" style="tab0">' . ($ref ? ('<a id=t' . $i . 'a href=' . $ref . ' class=\'tab1\'>') : '') . space(2) . $name . space(2) . ($ref ? '</a>' : '') . '
        </td>' . (is_array($tabs[$i][0]) ? ('
        <td valign=middle id=t' . $i . 'Btn>
          <img src="images/arrowdn.gif">
        </td>') : '') . '
      </tr>
      </table>';

    if (is_array($tabs[$i][0])) {
      echo '
      <table style="z-index:99" cellspacing="0" cellpadding="0" border="0" class=pulldown id=t' . $i . 'BtnM>';
      for ($j = 1; $j < count($tabs[$i]); $j++) {
        $ref = $tabs[$i][$j][1];
        $tooltip = '';
        if (isset($tabs[$i][$j][2])) $tooltip = $tabs[$i][$j][2];
        add_tabPosition($ref, $i);
        echo '
        <tr class=textTab>
          <td nowrap title="' . $tooltip . '" style="cursor:pointer">' . ($ref ? ('<a class=' . (isset($tabs[$i][$j][3]) ? 'tab2' : 'tab1') . ' href=' . (isset($tabs[$i][$j][3]) ? '#' : $ref) . '>') : '') . space(2) . $tabs[$i][$j][0] . space(2) . ($ref ? '</a>' : '') . '
          </td>
        </tr>';
      }
      echo '
      </table>';
    }
    echo '
    </td>';
  }

  echo '
    <td width=95% align=right class=tabnone>&nbsp;
    </td>
  </tr>
  </table>
  </td>
  </tr>';
}

function table_head($s = array('pad' => 0, 'space' => 0, 'border' => 0, 'class' => ''), $id = null)
{
  if (!isset($s['space'])) $s['space'] = 0;
  if (!isset($s['pad'])) $s['pad'] = 0;
  if (!isset($s['border'])) $s['border'] = 0;

  if (!isset($s['class'])) $s['class'] = '';
  $align = '';
  if ($s['class'] == 'tableContentInner') $align = 'center';
  echo '
  <tr width=100% class=tableContent>
    <td >
      <table width=100% cellpadding=' . $s['pad'] . ' cellspacing=' . $s['space'] . ' border=' . $s['border'] . ($id ? (' id=' . $id) : '') . ' class=' . $s['class'] . ' align=' . $align . '>
  ';
}
function table_head_usage($s = array('pad' => 0, 'space' => 0, 'border' => 0, 'class' => ''), $id = null)
{
  if (!isset($s['space'])) $s['space'] = 0;
  if (!isset($s['pad'])) $s['pad'] = 0;
  if (!isset($s['border'])) $s['border'] = 0;

  if (!isset($s['class'])) $s['class'] = '';
  $align = '';
  if ($s['class'] == 'tableContentInner') $align = 'center';
  echo '
  <tr width=100% class="tableContent usage">
    <td >
      <table width=100% cellpadding=' . $s['pad'] . ' cellspacing=' . $s['space'] . ' border=' . $s['border'] . ($id ? (' id=' . $id) : '') . ' class=' . $s['class'] . ' align=' . $align . '>
  ';
}


function table_foot()
{
  echo '
      </table>
    </td>
  </tr>
  ';
}

function table_tab_head()
{
  table_head(array('pad' => 0));
}

function table_tab_foot()
{
  table_foot();
}

function _headFoldTop($header, $id, $right = null, $tip = null)
{
  table_head(array('pad' => 0, 'class' => 'tableBorder'));
  table_head(array('pad' => 1));
  echo '
  <tr width=100% class=tableHeader>
    <td>&nbsp;
      <span class=text3>
      <img src="images/ball-dn.gif" align=absmiddle style="cursor:pointer" onclick=foldTable("' . $id . '") name=' . $id . 'i>' . space(2) . (is_array($header) ? ('
      <a class=folder href=' . $header[1] . ' title="' . $tip . '" >' . $header[0] . '</a>') : $header) . '
      </span></td>' . (is_array($right) ? ('
    <td align=right>
      <a href=' . $right[1] . '>' . $right[0] . '</a>' . space(2) . '
    </td>') : '') . '
  </tr>';
  table_foot();
}

function table_head_fold($header, $id, $right = null, $tip = null, $s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'))
{
  _headFoldTop($header, $id, $right, $tip);
  table_head($s, $id);
}
function table_head_fold_usage($header, $id, $right = null, $tip = null, $s)
{
  _headFoldTop($header, $id, $right, $tip);
  table_head_usage($s, $id);
}
function table_head_fold_list($header, $id, $s)
{
  _headFoldTop($header, $id);
  table_head($s, $id);
}

function table_foot_fold($id = null)
{
  table_foot();
  table_foot();

  // hide the table
  if ($id)
    echo '
    <script>
      foldTable("' . $id . '");
    </script>';
}

function table_list_head()
{
  echo '
  <tr >
    <td>
      <div id=listDiv style="overflow:auto">
        <table width=100% cellpadding=0 cellspacing=1 border=0>
  ';
}

function table_list_head_param($id = 'listDiv')
{
  echo '
  <tr class=tableHeader>
    <td>
      <div id=' . $id . ' style="overflow:auto">
        <table width=100% cellpadding=0 cellspacing=0 border=0>
  ';
}

function table_list_foot()
{
  echo '
        </table>
      </div>
    </td>
  </tr>
  ';
}

function table_row_empty($note, $cols = 1)
{
  echo '
  <tr class=tableRow1Cols>
    <td colspan=' . $cols . ' class=text5><span>' . $note . '</span></td>
  </tr>';
}
function table_row_radio_nas($left, $right, $lastld, $name)
{
  if (isset($lastld)) ($right == $lastld) ? $right = 'DAS' : $right = 'NAS';
  echo ' <tr>
          <td width=30% class=tableRowCol1>' . gprop('ldtype') . '</td>
          <td width=70% class=tableRowColOther>
          <input type=radio onclick="check_radiovalue()"';

  if ($right == 'DAS')
    echo ' checked ';
  echo '
                 name=' . $name . ' value=DAS>
                  DAS or SAN&nbsp;&nbsp;
                <input type=radio onclick="check_radiovalue()"';

  if ($right == 'NAS')
    echo ' checked ';
  echo '
                 name=' . $name . '  value=NAS>
                  NAS
              </td>
        </tr>';
}
function table_row_support($left, $support, $right)
{
  if (!$support)
    $right = getNotSupportString();
  table_row($left, $right);
}

function table_wait()
{
  echo '
  <html>
  <head>';
  page_head();
  echo '
  </head>
  <body>
    <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
      class=tableMain>';
  table_title(gwaitmsg('pleasewait'), 1);
  table_head();
  echo '
      <tr class=tableRow1Cols align=center>
        <td class=text4b>
          <br>'.gwaitmsg('pleasewait').'<br><br>
        </td>
      </tr>';
  table_foot();
  echo '
    </table>
  </body>
  </html>';
  flush();
}

function table_hidden($name, $value = -1)
{
  echo '
    <input type=hidden value=' . $value . ' name=' . $name . '>';
}

function space($n = 1)
{
  $r = '';
  for ($i = 0; $i < $n; $i++)
    $r .= '&nbsp;';
  return $r;
}

function table_row_start_stop_restart($b = array('start' => 1, 'stop' => 1, 'restart' => 1))
{
  echo '
    <table border=0 cellspacing=2 cellpadding=0 align=center>
      <tr>
        <td>';
  if (!isset($b['start']) || $b['start'])
    echo '
        <input class=input-p type=submit value="  ' . gprop('start') . '  " name=start>';
  if (!isset($b['stop']) || $b['stop'])
    echo '
        <input class=input-p type=submit value="  ' . gprop('stop') . '  " name=stop>';
  if (!isset($b['restart']) || $b['restart'])
    echo '
        <input class=input-p type=submit value="  ' . gprop('restart') . '  " name=restart>';
  echo '
      </td>
    </tr>';
}

function table_swmgt($daemon, $privilege, $s)
{
  table_head_fold(gprop('servicestatus') . '  --  ' . $daemon->daemonname, 'servicestatus', null, null, $s);
  table_row(gprop('runningstatus'), $daemon->daemonstatus);
			
	// Hide the START / STOP / RESTART button on SNMP.PHP
	// if (strcasecmp($daemon->daemonname,"SNMP") != 0)
	// {
  echo '
			<tr class=tableSwmgtStartStop>';

  if ($privilege >= USER_GROUP_SUPER) {
    if ($daemon->daemonstatus == 'Started') {
      echo "
					  <td colspan=2 class=text3 align=center>
						<input type=submit class=input-p value='  " . gprop('stop') . "  ' name=stop onclick=" . '"return validateStop()"';
      echo ">
						&nbsp;
						<input type=submit class=input-p value='  " . gprop('restart') . "  ' name=restart onclick=" . '"return validateRestart()"';
      echo ">
					  </td>";

    } else if ($daemon->daemonstatus == 'Stopped') {
      echo "
						<td colspan=2 class=text3 align=center>
						<input type=submit class=input-p value='  " . gprop('start') . "  ' name=start>
					  </td>";
    }
  }

  echo '
			</tr>';
	//} // end if(strcasecmp(SNMP))

  table_foot_fold();
  if (strcasecmp($daemon->daemonname, "SNMP") != 0) {
    table_tab_foot();
    table_head_fold(gprop('servicesetting') . '  --  ' . $daemon->daemonname, 'servicesetting', null, null, $s);
    echo '
		<tr>
			  <td width=30% class=tableRowCol1><span>' . gprop('startuptype') . '</span></td>
			  <td width=70% class=tableRowColOther><span>
			  <input type=radio';

    if ($daemon->startuptype == "Automatic")
      echo ' checked ';

    echo '
			 name=startuptype value=Auto>
			  Automatic&nbsp;&nbsp;
			<input type=radio';

    if ($daemon->startuptype == "Manual")
      echo ' checked ';

    echo '
				 name=startuptype value=Manual>
				  Manual
			  </span></td>
		</tr>';
    table_foot_fold();
  }
  //table_tab_foot();
}
