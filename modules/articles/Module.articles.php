<?php

/*

RWE articles module.

Copyright (C) 2004, 2005 Riku Nurminen

This library is free software; you can redistribute it and/or
modify it under the terms of the GNU Lesser General Public
License as published by the Free Software Foundation; either
version 2.1 of the License, or (at your option) any later version.

This library is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public
License along with this library; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/

require_once(RWE_DIR . 'Class.RWE.php');
require_once(RWE_DIR . 'Class.OpBasedModule.php');

/**
 * A module for publishing and editing articles online (news, plogs, blogs,
 * comments, etc).
 */
class Module_articles extends OpBasedModule
{
    /*! @privatesection */
    /* ==================================================================== */
    /// Used by Module_articles::_checkInputData to store converted timeposted timestamp.
    var $mTimestamp;

    /*! @publicsection */
    /* ==================================================================== */

    function Module_articles(&$rwe)
    {
        // List of valid settings accepted by this module
        $vs = array(// Common
                    //--------------------------------------------------------
                    'tableName'           => null,
                    'op'                  => null,

                    // submit, update, preview
                    //--------------------------------------------------------
                    'columns'             => array('titleColumn'      => null,
                                                   'bodyColumn'       => null,
                                                   // submit, update only
                                                   'modifiedColumn'   => null,
                                                   // submit only
                                                   'idColumn'         => null,
                                                   'timepostedColumn' => null,
                                                   'posterIDColumn'   => null,
                                                   ),
                    'data'                => array('title'      => null,
                                                   'body'       => null,
                                                   // submit only
                                                   'timeposted' => null,
                                                   'posterID'   => null,
                                                   ),
                    'extraData'           => null,
                    'addToQuery'          => null, // update only
                    'charset'             => 'ISO-8859-1',
                    'niceQuotes'          => false,
                    'forceErrors'         => null,
                    'transTable'          =>
                    array('[p]'                 => '<p>',
                          '[p class="%VAL%"]'   => '<p class="%VAL%">',
                          '[/p]'                => '</p>',
                          '[br]'                => '<br />',
                          '[ul]'                => '<ul>',
                          '[ul class="%VAL%"]'  => '<ul class="%VAL%">',
                          '[/ul]'               => '</ul>',
                          '[ol]'                => '<ol>',
                          '[ol class="%VAL%"]'  => '<ol class="%VAL%">',
                          '[/ol]'               => '</ol>',
                          '[li]'                => '<li>',
                          '[li class="%VAL%"]'  => '<li class="%VAL%">',
                          '[/li]'               => '</li>',
                          '[b]'                 => '<b>',
                          '[/b]'                => '</b>',
                          '[i]'                 => '<i>',
                          '[/i]'                => '</i>',
                          '[blockquote]'        => '<blockquote>',
                          '[/blockquote]'       => '</blockquote>',
                          '[a link="%VAL%" title="%VAL%"]' => '<a href="%VAL%" title="%VAL%">',
                          '[a link="%VAL%"]'    => '<a href="%VAL%">',
                          '[/a]'                => '</a>',
                          '[img src="%VAL%" width="%VAL%" height="%VAL%" alt="%VAL%" class="%VAL%"]' =>
                              '<img src="%VAL%" width="%VAL%" height="%VAL%" alt="%VAL%" class="%VAL%" />',
                          '[img src="%VAL%" width="%VAL%" height="%VAL%" alt="%VAL%"]' =>
                              '<img src="%VAL%" width="%VAL%" height="%VAL%" alt="%VAL%" />',
                          ),

                    // get
                    //--------------------------------------------------------
                    'selectColumns'       => null,
                    'startpos'            => null,
                    'limit'               => null,
                    'forEditingColumns'   => null,
                    'orderBy'             => null,
                    'order'               => null,
                    'condition'           => null,

                    // checkstate
                    //--------------------------------------------------------
                    'unsetData'           => true,
                    );

        $validops = array('submit',
                          'update',
                          'preview',
                          'get',
                          'checkstate',
                          );

        $this->OpBasedModule($rwe, 'articles', $vs, $validops);
    }

    /** Overridden from Module. */
    function reset()
    {
        $this->mTimestamp = null;
        parent::reset();
    }

    /*! @protectedsection */
    /* ==================================================================== */

    /** Checks common settings. */
    function _checkSettings()
    {
        $op = $this->mSettings['op'];
        if(!parent::isValidOp($op)) {
            parent::exception("_checkSettings: invalid op (${op})" .
                              ", valid ops are: " . parent::getValidOpsStr());
        }

        if($op != 'preview' && $op != 'checkstate') {
            if(!parent::hasValidDbMgr()) {
                parent::exception('_checkSettings: no database manager present');
            }

            // Check and reformat tableName so it can be included in a query
            $t = null;
            if(($t = parent::settingToString('tableName', false)) === false) {
                parent::exception('_checkSettings: invalid tableName');
            }
            $this->mSettings['tableName'] = $t;
        }
    }

    /*! @publicsection */
    /* ==================================================================== */

    /**
     * Executes this module.
     */
    function executeModule($settings)
    {
        parent::preExecuteModule();
        parent::setSettings($settings);

        // Check required settings
        $this->_checkSettings();

        $op = $this->mSettings['op'];

        if($op != 'preview' && $op != 'checkstate') {
            /* Connect to database; will reuse existing link if already
               connected */
            $dbmgr = &parent::getDbMgr();
            if(!$dbmgr->connect()) {
                $e = array('executeModule: failed to establish database connection:',
                           '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
                parent::exception($e);
            }
        }

        switch($op) {
        case 'submit':
            return $this->_op_submit();
        case 'update':
            return $this->_op_update();
        case 'preview':
            return $this->_op_preview();
        case 'get':
            return $this->_op_get();
        case 'checkstate':
            return $this->_op_checkstate();
        default:
            // Should never happen
            return false;
        }
    }

    /*! @protectedsection */
    /* ==================================================================== */

    /**
     * Gets called on op submit.
     */
    function _op_submit()
    {
        if(empty($this->mSettings['columns']['posterIDColumn']) ||
           empty($this->mSettings['columns']['titleColumn']) ||
           empty($this->mSettings['columns']['timepostedColumn']) ||
           empty($this->mSettings['columns']['bodyColumn']))
        {
            $e = array('_op_submit: missing one or more of required column',
                       'names from \'columns\'');
            parent::exception($e);
        }

        $this->_setSessionData();

        // Check input data
        //--------------------------------------------------------------------
        if(!$this->_checkInputData()) {
            return false;
        }
        // Data is ok, don't need it in session anymore
        session_unset();

        // Build, prepare & execute query
        //--------------------------------------------------------------------
        $dbmgr  = &parent::getDbMgr();
        $cols   = $this->_buildColumnList();
        $phvals = $this->_getInputData();
        $phstr  = $dbmgr->getQueryPlaceHolderString(1, count($phvals));
        $t      = $this->mSettings['tableName'];
        $tpcol  = $this->mSettings['columns']['timepostedColumn'];
        $tpval  = $dbmgr->formatTimestampToSQLColumn($this->mTimestamp);
        $query  = "INSERT INTO ${t} (${tpcol}, ${cols})
                   VALUES (${tpval}, ${phstr})";
        // modifiedColumn
        $modifcol = $this->mSettings['columns']['modifiedColumn'];
        if(!empty($modifcol)) {
            // Modifying time is same as timeposted when submitting
            $modiftime = $dbmgr->formatTimestampToSQLColumn(time());
            $query = "INSERT INTO ${t} (${tpcol}, ${modifcol}, ${cols})
                      VALUES (${tpval}, ${modiftime}, ${phstr})";
        }
        $stmt = $dbmgr->prepare($query);
        $ret  = call_user_func_array(array(&$stmt, 'execute'), $phvals);

        // Check errors
        //--------------------------------------------------------------------
        if(!$ret) {
            if(($err = $dbmgr->getLastErrorRWECode()) !== RWE_DBERR_UNKNOWN) {
                return $err;
            }
            // Unknown error, except
            $e = array('_op_submit: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }

        // Success, return true unless we have idColumn, in which case return the
        // just-inserted entry id
        $idcol = $this->mSettings['columns']['idColumn'];
        if(!empty($idcol)) {
            return $dbmgr->getLastInsertedRowID($t, $idcol);
        }

        return true;
    }

    /**
     * Gets called on op update.
     */
    function _op_update()
    {
        if(empty($this->mSettings['columns']['titleColumn']) ||
           empty($this->mSettings['columns']['bodyColumn']))
        {
            $e = array('_op_update: missing one or more required settings:',
                       'titleColumn, bodyColumn',
                       );
            parent::exception($e);
        }

        $this->_setSessionData();

        // Check input data
        //--------------------------------------------------------------------
        if(!$this->_checkInputData()) {
            return false;
        }
        // Data is ok, don't need it in session anymore
        session_unset();

        // Build, prepare & execute query
        //--------------------------------------------------------------------
        $dbmgr  = &parent::getDbMgr();
        // 2nd param == forupdate (no posterIDColumn)
        $cols   = $this->_buildColumnList(true, true);
        $setstr = $dbmgr->getSetColumnsPlaceHolderString($cols);
        // Add modifiedColumn if we have it
        $modifcol = $this->mSettings['columns']['modifiedColumn'];
        if(!empty($modifcol)) {
            $modiftime = $dbmgr->formatTimestampToSQLColumn(time());
            $setstr   .= ", ${modifcol} = ${modiftime}";
        }
        // true == forupdate (no posterIDColumn)
        $phvals   = $this->_getInputData(true);
        $t        = $this->mSettings['tableName'];
        $addtoqry = parent::settingToString('addToQuery');
        $query    = "UPDATE ${t} SET ${setstr} ${addtoqry}";
        $stmt     = $dbmgr->prepare($query);
        $ret      = call_user_func_array(array(&$stmt, 'execute'), $phvals);

        // Check errors
        //--------------------------------------------------------------------
        if(!$ret) {
            if(($err = $dbmgr->getLastErrorRWECode()) !== RWE_DBERR_UNKNOWN) {
                return $err;
            }
            // Unknown error, except
            $e = array('_op_update: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }
        return true;
    }

    /**
     * Gets called on op preview.
     */
    function _op_preview()
    {
        $this->_setSessionData();

        // Check input data
        //--------------------------------------------------------------------
        if(!$this->_checkInputData()) {
            return false;
        }

        // startSessionSafe already called by _setSessionData
        $_SESSION['RWE_Module_articles__doPreview'] = true;

        // Return false so whoever called us will go back to do a checkstate
        return false;
    }

    /**
     * Gets called on op get.
     */
    function _op_get()
    {
        // Check what settings we have that affect the query to build
        //--------------------------------------------------------------------
        // Defaults
        $startpos  = 0;
        $limit     = 10;
        $condition = '';
        // Check for 'startpos'
        $spos = $this->mSettings['startpos'];
        if(is_numeric($spos) && $spos >= 0) {
            $startpos = $spos;
        }
        // Check for 'limit'
        $l = $this->mSettings['limit'];
        if(is_numeric($l) && $l >= 0) {
            $limit = $l;
        }
        // selectColumns
        $selectcols = parent::settingToString('selectColumns', '*');
        // orderBy
        $orderby = parent::settingToString('orderBy', '', true);
        if(!empty($orderby)) {
            $orderby = "ORDER BY ${orderby}";
        }
        // condition
        $cond = $this->mSettings['condition'];
        if(!empty($cond)) {
            $condition = "WHERE ${cond}";
        }

        // Build, prepare & execute query based on gathered settings
        //--------------------------------------------------------------------
        $dbmgr  = &parent::getDbMgr();
        $t      = $this->mSettings['tableName'];
        $query  = "SELECT ${selectcols} FROM ${t} ${condition} ${orderby}";
        $query .= ' ' . $dbmgr->getLimitClause($limit, $startpos);
        $stmt   = $dbmgr->prepare($query);
        if(!$stmt->execute()) {
            $e = array('_op_get: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }

        $rows = $stmt->fetchAllAssoc();

        // Assign forEditingColumns if requested
        //--------------------------------------------------------------------
        $assignfecols = parent::settingToString('forEditingColumns', false);
        if($assignfecols !== false) {
            foreach($rows as $row) {
                $this->_assignForEditingColumns($row);
            }
        }
        parent::assign('numRows', $stmt->numRows());
        parent::assign('rows',    $rows);
        return true;
    }

    /**
     * Gets called on op checkstate.
     */
    function _op_checkstate()
    {
        RWEUtil::startSessionSafe();

        // Check if we should run at all
        if(!array_key_exists('RWE_Module_articles__hasSessionData', $_SESSION)) {
            return true;
        }

        $unsetdata  = $this->mSettings['unsetData'];
        $title      = null;
        $tposted    = null;
        $body       = null;
        $extradata  = null;
        $nicequotes = false;

        // First assign data back to user
        //--------------------------------------------------------------------
        // This is the same data as user supplied in. Send it back so it can
        // be applied back into <input> or <textarea> fields.
        if(array_key_exists('RWE_Module_articles__title', $_SESSION)) {
            $title = unserialize($_SESSION['RWE_Module_articles__title']);
        }
        if(array_key_exists('RWE_Module_articles__timeposted', $_SESSION)) {
            $tposted = unserialize($_SESSION['RWE_Module_articles__timeposted']);
        }
        if(array_key_exists('RWE_Module_articles__body', $_SESSION)) {
            $body = unserialize($_SESSION['RWE_Module_articles__body']);
        }
        if(array_key_exists('RWE_Module_articles__extraData', $_SESSION)) {
            $extradata = unserialize($_SESSION['RWE_Module_articles__extraData']);
        }
        if(array_key_exists('RWE_Module_articles__niceQuotes', $_SESSION)) {
            $nicequotes = unserialize($_SESSION['RWE_Module_articles__niceQuotes']);
        }

        // We clean it a bit by converting htmlentities and un-escaping
        // certain characters so they get sent back as they were sent in.
        //--------------------------------------------------------------------
        $title   = $this->_htmlentities($title);
        $title   = $this->_deEscapeString($title);
        $body    = $this->_htmlentities($body);
        $body    = $this->_deEscapeString($body);

        // UNIX newlines
        $body = str_replace("\r\n", "\n", $body);

        // Assign back to user
        parent::assign('title',      $title);
        parent::assign('timeposted', $tposted);
        parent::assign('body',       $body);
        parent::assign('extraData',  $extradata);

        // Check for errors on last run and assign error variables
        //--------------------------------------------------------------------
        $iserrors = false;
        if(array_key_exists('RWE_Module_articles__isErrors', $_SESSION)) {
            $iserrors = $_SESSION['RWE_Module_articles__isErrors'];
        }
        if($iserrors) {
            $errors      = unserialize($_SESSION['RWE_Module_articles__errors']);
            $forceerrors = unserialize($_SESSION['RWE_Module_articles__forceErrors']);
            parent::assign('isErrors', 1);
            parent::assign('errors', $errors);
            parent::assign('forceErrors', $forceerrors);
            if($unsetdata) {
                session_unset();
            }
            return false;
        }

        // No errors, check for preview
        //--------------------------------------------------------------------
        $dopreview = false;
        if(array_key_exists('RWE_Module_articles__doPreview', $_SESSION)) {
            $dopreview = $_SESSION['RWE_Module_articles__doPreview'];
        }
        if($dopreview) {
            // Preview data is the same as assigned back above, except here we
            // also convert nicequotes (if enabled) and bracket tags in body
            parent::assign('prev_title', $nicequotes ? $this->_niceQuotes($title) : $title);
            $timestamp = time();
            $ret       = null;
            // timeposted to timestamp
            if(strlen($tposted) > 0 && ($ret = strtotime($tposted)) != -1) {
                $timestamp = $ret;
            }
            parent::assign('prev_timestamp', $timestamp);
            if($nicequotes) {
                $body = $this->_niceQuotes($body);
            }
            $body = RWEUtil::bracketToHtmlTags($body, $this->mSettings['transTable'], $nicequotes);
            parent::assign('prev_body', $body);
            parent::assign('doPreview', 1);
        }
        if($unsetdata) {
            session_unset();
        }
        return true;
    }

    /** 
     * Internal method used by <b>submit</b>, <b>update</b> and <b>preview</b>
     * for checking the input data for validness.
     */
    function _checkInputData()
    {
        $iserrors = false;
        $errors   = array();

        // title
        if(strlen($this->mSettings['data']['title']) < 1) {
            $errors[] = 'empty_title';
            $iserrors = true;
        }

        // body
        if(strlen($this->mSettings['data']['body']) < 1) {
            $errors[] = 'empty_body';
            $iserrors = true;
        }

        // timeposted
        $this->mTimestamp = time();
        if(strlen($this->mSettings['data']['timeposted']) > 0 &&
           ($this->mTimestamp = strtotime($this->mSettings['data']['timeposted'])) == -1)
        {
            $errors[] = 'invalid_timeposted';
            $iserrors = true;
        }

        // forceErrors
        $forceerrors = array();
        $fes         = $this->mSettings['forceErrors'];
        if(is_array($fes) && count($fes) > 0) {
            foreach($fes as $error) {
                $forceerrors[] = $error;
            }
            $iserrors = true;
        } else if(strlen($fes) > 0) {
            $forceerrors[] = $fes;
            $iserrors = true;
        }

        if($iserrors) {
            RWEUtil::startSessionSafe();
            $_SESSION['RWE_Module_articles__isErrors']    = true;
            $_SESSION['RWE_Module_articles__errors']      = serialize($errors);
            $_SESSION['RWE_Module_articles__forceErrors'] = serialize($forceerrors);
            return false;
        }
        return true;
    }

    /** 
     * Internal method for building a column list for SQL statements.
     *
     * @param inarray If true, columns will be returned in an array instead
     * of comma separated list.
     * @param forupdate If true, will return list without posterIDColumn
     * (op update doesn't use it).
     */
    function _buildColumnList($inarray = false, $forupdate = false)
    {
        $colsarr  = $this->mSettings['columns'];
        $titlecol = $colsarr['titleColumn'];
        $bcol     = $colsarr['bodyColumn'];
        $pidcol   = $colsarr['posterIDColumn'];

        // These we have always
        $columns = array($titlecol, $bcol);

        // Add posterIDColumn for 'submit'
        if(!$forupdate) {
            $columns[] = $pidcol;
        }

        // Add extraData (if any)
        $extradata = $this->mSettings['extraData'];
        if(is_array($extradata) && count($extradata) > 0) {
            $keys = array_keys($extradata);
            foreach($keys as $extracol) {
                $columns[] = $extracol;
            }
        }

        return $inarray ? $columns : implode(', ', $columns);
    }

    /**
     * Internal method used by <b>submit</b> and <b>update</b> to transfer
     * input data to <b>checkstate</b> via sessions.
     */
    function _setSessionData()
    {
        RWEUtil::startSessionSafe();
        $nicequotes = $this->mSettings['niceQuotes'];
        $data       = $this->mSettings['data'];
        $extradata  = $this->mSettings['extraData'];
        $_SESSION['RWE_Module_articles__title']      = serialize($data['title']);
        $_SESSION['RWE_Module_articles__timeposted'] = serialize($data['timeposted']);
        $_SESSION['RWE_Module_articles__body']       = serialize($data['body']);
        $_SESSION['RWE_Module_articles__niceQuotes'] = $nicequotes ? serialize(true) : serialize(false);
        if(is_array($extradata) && count($extradata) > 0) {
            $_SESSION['RWE_Module_articles__extraData'] = serialize($extradata);
        }
        // Tell checkstate that it actually has some data to chew on
        $_SESSION['RWE_Module_articles__hasSessionData'] = 1;
    }

    /** Wraps htmlentities(). */
    function _htmlentities($str)
    {
        $charset = $this->mSettings['charset'];
        return htmlentities(RWEUtil::unHTMLEntities($str), ENT_QUOTES);
    }

    /** 
     * De-escape certain characters in a string stored in a variable.
     */
    function _deEscapeString($str)
    {
        // PHP seems to automatically escape some characters when assigning to
        // variables (?), fix..
        $str = str_replace('\&', '&', $str);
        $str = str_replace('\\\\', '\\', $str);
        return $str;
    }

    /**
     * Converts single- and double-quote html entities into nicer
     * html-entitied quotes.
     */
    function _niceQuotes($string)
    {
        // Single quotes to nicer single quotes
        $ret = str_replace('&#039;', '&#8217;', $string);
        $ret = str_replace('&apos;', '&#8217;', $ret);

        // Double quotes to nicer double quotes
        $ret = str_replace('&quot;', '"', $ret);
        $ret = preg_replace('/"([^"]*)"/', '&#8220;$1&#8221;', $ret);
        // " .. don't cry doxygen
        return $ret;
    }

    /**
     * Reverse Module_articles::_niceQuotes.
     */
    function _unNiceQuotes($string)
    {
        // Nicer single quotes to normal single quotes
        $ret = str_replace('&#8217;', '&#039;', $string);

        // Nicer double quotes to normal double quotes
        $ret = str_replace('&#8220;', '&quot;', $ret);
        $ret = str_replace('&#8221;', '&quot;', $ret);
        return $ret;
    }

    /**
     * Internal method used by Module::_op_get to assign forEditingColumns
     * for a row.
     */
    function _assignForEditingColumns($row)
    {
        $fecols = $this->mSettings['forEditingColumns'];
        foreach($fecols as $col) {
            if(array_key_exists($col, $row) && !empty($row[$col])) {
                $ec = $row[$col];
                $ec = $this->_unNiceQuotes($ec);
                $ec = RWEUtil::htmlToBracketTags($ec,
                                                 $this->mSettings['transTable']);
                parent::assign("foredit_${col}", $ec);
            }
        }
    }

    function _processTitleForDatabase($title)
    {
        $ret = $this->_htmlentities($title);
        $ret = $this->_deEscapeString($ret);
        if($this->mSettings['niceQuotes']) {
            $ret = $this->_niceQuotes($ret);
        }
        return $ret;
    }

    function _processBodyForDatabase($body)
    {
        $ret = $this->_htmlentities($body);
        // NB: Must de-escape body before converting bracket tags!
        $ret = $this->_deEscapeString($ret);
        // UNIX newlines please
        $ret = str_replace("\r\n", "\n", $ret);
        // NB: Must do niceQuotes before converting bracket tags!
        $nicequotes = $this->mSettings['niceQuotes'];
        if($nicequotes) {
            $ret = $this->_niceQuotes($ret);
        }
        $ret = RWEUtil::bracketToHtmlTags($ret, $this->mSettings['transTable'], $nicequotes);
        return $ret;
    }

    function _getInputData($forupdate = false)
    {
        $data  = $this->mSettings['data'];
        $title = $this->_processTitleForDatabase($data['title']);
        $body  = $this->_processBodyForDatabase($data['body']);

        // These we have always
        $phvals = array($title, $body);

        // Add posterID for 'submit'
        if(!$forupdate) {
            $phvals[] = $data['posterID'];
        }

        // extraData, optional
        $hasextradata = false;
        $extradata    = $this->mSettings['extraData'];
        if(is_array($extradata) && count($extradata) > 0) {
            $hasextradata = true;
        }
        if($hasextradata) {
            foreach($extradata as $extraval) {
                $phvals[] = $extraval;
            }
        }

        return $phvals;
    }
}

?>
