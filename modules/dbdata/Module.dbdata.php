<?php

/*

RWE dbdata module

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
 * A module for doing custom SELECT, INSERT, UPDATE and DELETE queries.
 */
class Module_dbdata extends OpBasedModule
{
    /*! @publicsection */
    /* ==================================================================== */

    function Module_dbdata(&$rwe)
    {
        // List of valid settings accepted by this module
        $vs = array(// Common
                    //--------------------------------------------------------
                    'tableName'     => null,
                    'op'            => null,
                    'addToQuery'    => null,

                    // select, insert, update, delete
                    //--------------------------------------------------------
                    'selectColumns' => null, // select only
                    'values'        => null, // insert, update only
                    );

        $validops = array('select', 'insert', 'update', 'delete');

        $this->OpBasedModule($rwe, 'dbdata', $vs, $validops);
    }

    /*! @protectedsection */
    /* ==================================================================== */

    /** Checks common settings. */
    function _checkSettings()
    {
        // Default op: select
        if(empty($this->mSettings['op'])) {
            $this->mSettings['op'] = 'select';
        }

        $op = $this->mSettings['op'];
        if(!parent::isValidOp($op)) {
            parent::exception("_checkSettings: invalid op (${op})" .
                              ", valid ops are: " . parent::getValidOpsStr());
        }

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

        // Connect to database; will reuse existing link if already connected
        $dbmgr = &parent::getDbMgr();
        if(!$dbmgr->connect()) {
            $e = array('executeModule: failed to establish database connection:',
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }

        $op = $this->mSettings['op'];

        switch($op) {
        case 'select':
            return $this->_op_select();
        case 'insert':
            return $this->_op_insert();
        case 'update':
            return $this->_op_update();
        case 'delete':
            return $this->_op_delete();
        default:
            // Should never happen
            return false;
        }
    }

    /*! @protectedsection */
    /* ==================================================================== */

    /**
     * Gets called on op select.
     */
    function _op_select()
    {
        $t        = $this->mSettings['tableName'];
        $dbmgr    = &parent::getDbMgr();
        $selcols  = parent::settingToString('selectColumns', '*');
        $addtoqry = parent::settingToString('addToQuery');
        $query    = "SELECT ${selcols} FROM ${t} ${addtoqry}";
        $stmt     = &$dbmgr->prepare($query);
        if(!$stmt->execute()) {
            $e = array('_op_select: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }
        parent::assign('rows',    $stmt->fetchAllAssoc());
        parent::assign('numRows', $stmt->numRows());
        return true;
    }

    /**
     * Gets called on op insert.
     */
    function _op_insert()
    {
        // Check 'values'
        //--------------------------------------------------------------------
        $vals = $this->mSettings['values'];
        $cnt = 0;
        if(!is_array($vals) || ($cnt = count($vals)) < 1) {
            parent::exception('_op_insert: no columns/values given');
        }

        // Build, prepare & execute query
        //--------------------------------------------------------------------
        $dbmgr    = &parent::getDbMgr();
        $t        = $this->mSettings['tableName'];
        $cols     = RWEUtil::arrayToString(array_keys($vals));
        $phstr    = $dbmgr->getQueryPlaceHolderString(1, $cnt);
        $addtoqry = parent::settingToString('addToQuery');
        $query    = "INSERT INTO ${t} (${cols}) VALUES (${phstr}) ${addtoqry}";
        $stmt     = $dbmgr->prepare($query);
        $ret      = call_user_func_array(array(&$stmt, 'execute'), $vals);

        // Check errors
        //--------------------------------------------------------------------
        if(!$ret) {
            if(($err = $dbmgr->getLastErrorRWECode()) !== RWE_DBERR_UNKNOWN) {
                return $err;
            }
            // Unknown error, except
            $e = array('_op_insert: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }

        return true;
    }

    /**
     * Gets called on op update.
     */
    function _op_update()
    {
        // Check 'values'
        //--------------------------------------------------------------------
        $vals = $this->mSettings['values'];
        $cnt  = 0;
        if(!is_array($vals) || ($cnt = count($vals)) < 1) {
            parent::exception('_op_update: no columns/values given');
        }

        // Build, prepare & execute query
        //--------------------------------------------------------------------
        $dbmgr    = &parent::getDbMgr();
        $t        = $this->mSettings['tableName'];
        $setstr   = $dbmgr->getSetColumnsPlaceHolderString(array_keys($vals));
        $addtoqry = parent::settingToString('addToQuery');
        $query    = "UPDATE ${t} SET ${setstr} ${addtoqry}";
        $stmt     = $dbmgr->prepare($query);
        $ret      = call_user_func_array(array(&$stmt, 'execute'), $vals);

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
     * Gets called on op delete.
     */
    function _op_delete()
    {
        // Build, prepare & execute query
        //--------------------------------------------------------------------
        $dbmgr    = &parent::getDbMgr();
        $t        = $this->mSettings['tableName'];
        $addtoqry = parent::settingToString('addToQuery');
        $query    = "DELETE FROM ${t} ${addtoqry}";
        $stmt     = $dbmgr->prepare($query);
        if(!$stmt->execute()) {
            if(($err = $dbmgr->getLastErrorRWECode()) !== RWE_DBERR_UNKNOWN) {
                return $err;
            }
            // Unknown error, except
            $e = array('_op_delete: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }
        return true;
    }
}

?>
