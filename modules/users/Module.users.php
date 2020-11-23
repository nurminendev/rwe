<?php

/*

RWE users module

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
 * A module for authenticating, logging-in/out, adding, removing and editing
 * users in a database.
 */
class Module_users extends OpBasedModule
{
    /*! @privatesection */
    /* ==================================================================== */
    /// Valid sessionTypes.
    var $mValidSessionTypes;

    /// Name of username cookie for sessionType == cookie.
    var $mUsernameCookieName;
    /// Name of password cookie for sessionType == cookie.
    var $mPasswordCookieName;
    /// Name of session cookie for sessionType == db.
    var $mSessionCookieName;

    /*! @publicsection */
    /* ==================================================================== */

    /** 
     * Constructor.
     */
    function Module_users(&$rwe)
    {
        // List of valid settings accepted by this module
        $vs = array(// Common
                    //--------------------------------------------------------
                    'tableName'       => null,
                    'op'              => null,
                    'sessionType'     => 'cookie', // auth, login, logout

                    //--------------------------------------------------------
                    'sessionTable'               => null, // auth, login, logout
                    'sessionIDColumn'            => null, // auth, login, logout
                    'sessionUserIDColumn'        => null, // auth, login, logout
                    'sessionBrowserStringColumn' => null, // auth, login, logout
                    'sessionLastAuthColumn'      => null, // auth, login, logout
                    'sessionExpireSecsColumn'    => null, // auth, login, logout

                    //--------------------------------------------------------
                    'userIDColumn'    => null,     // auth, 
                    'usernameColumn'  => null,     // auth, login
                    'passwordColumn'  => null,     // auth, login
                    'lastauthColumn'  => null,     // auth, login
                    'fromColumn'      => null,     // auth, login

                    'startSession'    => true,     // login

                    'expireTime'      => null,     // auth, login, logout
                    'cookiePath'      => null,     // auth, login, logout
                    'cookieDomain'    => null,     // auth, login, logout
                    'cookiePrefix'    => null,     // auth, login, logout

                    'username'        => null,     // login
                    'password'        => null,     // login
                    'values'          => null,     // add, update
                    'renewLogin'      => false,    // auth
                    'doDNSLookup'     => true,     // auth, login
                    'selectColumns'   => null,     // auth
                    'addToQuery'      => null,     // all except logout
                    );

        $validops = array('auth',   'add',    'login',
                          'logout', 'remove', 'update',
                          );

        $this->OpBasedModule($rwe, 'users', $vs, $validops);

        $this->mValidSessionTypes = array('cookie', 'db');

        $this->mUsernameCookieName = 'un';
        $this->mPasswordCookieName = 'pw';
        $this->mSessionCookieName  = 'ls';
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

        if($op == 'auth' || $op == 'login' || $op == 'logout') {
            $sestype = $this->mSettings['sessionType'];
            if(!in_array($sestype, $this->mValidSessionTypes)) {
                $validtypes = implode(', ', $this->mValidSessionTypes);
                parent::exception("_checkSettings: invalid sessionType (${sestype})" .
                                  ", valid types are: ${validtypes}");
            }
        }

        $sestype = $this->mSettings['sessionType'];
        if($sestype != 'cookie' || $op != 'logout') {
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

        $sestype = $this->mSettings['sessionType'];
        if($sestype != 'cookie' || $op != 'logout') {
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
        case 'auth':
            return $this->_op_auth();
        case 'add':
            return $this->_op_add();
        case 'login':
            return $this->_op_login();
        case 'logout':
            return $this->_op_logout();
        case 'remove':
            return $this->_op_remove();
        case 'update':
            return $this->_op_update();
        default:
            // Should never happen
            return false;
        }
    }

    /*! @protectedsection */
    /* ==================================================================== */

    /**
     * Gets called on op auth.
     *
     * @return (bool)true if the auth is succesful, (bool)false if not, and
     * exception (never returns) if one of the SQL queries fail.
     */
    function _op_auth()
    {
        if(empty($this->mSettings['usernameColumn']) ||
           empty($this->mSettings['passwordColumn']) ||
           empty($this->mSettings['cookiePrefix']))
        {
            $e = array('_op_auth: missing one or more of required settings:',
                       'usernameColumn, passwordColumn, cookiePrefix',
                       );
            parent::exception($e);
        }

        $userdata = $this->_isLoggedIn();

        if($userdata !== false) {
            $username = $userdata[0];
            $password = $userdata[1];

            // Assign user-requested db data (if any)
            $selcols = parent::settingToString('selectColumns');
            if($selcols != '') {
                parent::assign('authdata', $this->_getSelectCols($username, $selcols));
            }

            // Renew the login if requested by user
            if($this->mSettings['renewLogin']) {
                $this->_renewLogin($username, $password);
            }

            // Update lastauth / fromColumn if given
            if(!empty($this->mSettings['lastauthColumn']) ||
               !empty($this->mSettings['fromColumn']))
            {
                return $this->_updateLast($username);
            }

            parent::assign('isLogged', 1);
            return true;
        }

        parent::assign('isLogged', 0);
        return false;
    }

    /**
     * Gets called on op add.
     */
    function _op_add()
    {
        // Check 'values'
        //--------------------------------------------------------------------
        $vals = $this->mSettings['values'];
        $cnt = 0;
        if(!is_array($vals) || ($cnt = count($vals)) < 1) {
            parent::exception('_op_add: no columns/values given');
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
            $e = array('_op_add: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }

        return true;
    }

    /**
     * Gets called on op login.
     */
    function _op_login()
    {
        if(empty($this->mSettings['usernameColumn']) ||
           empty($this->mSettings['passwordColumn']) ||
           empty($this->mSettings['expireTime']) ||
           empty($this->mSettings['cookiePath']) ||
           empty($this->mSettings['cookieDomain']) ||
           empty($this->mSettings['cookiePrefix']))
        {
            $e = array('_op_login: missing one or more of required settings:',
                       'usernameColumn, passwordColumn, expireTime, cookiePath,',
                       'cookieDomain, cookiePrefix',
                       );
            parent::exception($e);
        }

        $username = $this->mSettings['username'];
        $password = $this->mSettings['password'];

        if($this->_checkUserPassAgainstDatabase($username, $password)) {
            /* Only start a login session if requested (default: yes)
             * This allows op 'login' to be used to simply check if a
             * username/password pair is correct without doing an actual
             * login
             */
            if($this->mSettings['startSession']) {
                $this->_activateLoginSession($username, $password);
            }

            // Update lastauth / fromColumn if given
            if(!empty($this->mSettings['lastauthColumn']) ||
               !empty($this->mSettings['fromColumn']))
            {
                return $this->_updateLast($username);
            }

            return true;
        }
        return false;
    }

    /**
     * Gets called on op logout.
     */
    function _op_logout()
    {
        if(empty($this->mSettings['expireTime']) ||
           empty($this->mSettings['cookiePath']) ||
           empty($this->mSettings['cookieDomain']) ||
           empty($this->mSettings['cookiePrefix']))
        {
            $e = array('_op_logout: missing one or more of required settings:',
                       'expireTime, cookiePath, cookieDomain, cookiePrefix',
                       );
            parent::exception($e);
        }

        if($this->_isLoggedIn() !== false) {
            $this->_deactivateLoginSession();
            return true;
        }

        return false;
    }

    /**
     * Gets called on op remove.
     */
    function _op_remove()
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
            $e = array('_op_remove: failed to execute query',
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



    /* ==================================================================== */
    /* ==================================================================== */
    /* ==================================================================== */



    /**
     * Check if a user is logged in based on some login data (cookies,
     * db session id, ...).
     *
     * @return Returns the username and password of the user if logged-in,
     * otherwise returns false.
     */
    function _isLoggedIn()
    {
        $logindata = $this->_getLoginDataFromCookies();
        if($logindata === false) {
            return false;
        }

        switch($this->mSettings['sessionType'])
        {
        case 'cookie':
            if($this->_checkUserPassAgainstDatabase($logindata[0], $logindata[1])) {
                return array($logindata[0], $logindata[1]);
            }
            break;
        case 'db':
            if($this->_isSessionActive($logindata[0])) {
                return $this->_getSessionUserData($logindata[0]);
            }
            break;
        default:
            break;
        }
        return false;
    }

    /**
     * Returns login data from cookies if the user is logged in, false
     * otherwise.
     */
    function _getLoginDataFromCookies()
    {
        switch($this->mSettings['sessionType'])
        {
        case 'cookie':
            if($this->_cookieSessionCookiesExist()) {
                return $this->_getCookieSessionLoginData();
            }
            break;
        case 'db':
            if($this->_dbSessionCookieExists()) {
                return $this->_getDBSessionLoginData();
            }
            break;
        default:
            break;
        }

        return false;
    }


    /**
     * Checks if a session is active.
     */
    function _isSessionActive($session_id)
    {
        $dbmgr  = &parent::getDbMgr();
        $st     = $this->mSettings['sessionTable'];
        $sidcol = $this->mSettings['sessionIDColumn'];

        // XXX FIXME XXX
        $query  = "SELECT ${sidcol} FROM ${st}
                   WHERE ${sidcol} = :1";
        // XXX FIXME XXX

        // XXX check browserstring

        $stmt   = $dbmgr->prepare($query);
        if(!$stmt->execute($session_id)) {
            $e = array('_isSessionActive: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }
        return $stmt->fetchAssoc();
    }

    /**
     * Returns the username and password for a user's active login session.
     *
     * @remarks Be sure to check that the session is active first with
     * _isSessionActive!
     */
    function _getSessionUserData($session_id)
    {
        $dbmgr   = &parent::getDbMgr();
        $st      = $this->mSettings['sessionTable'];
        $sidcol  = $this->mSettings['sessionIDColumn'];
        $suidcol = $this->mSettings['sessionUserIDColumn'];
        $ut      = $this->mSettings['tableName'];
        $uidcol  = $this->mSettings['userIDColumn'];
        $uncol   = $this->mSettings['usernameColumn'];
        $pwcol   = $this->mSettings['passwordColumn'];
        $query   = "SELECT u.${uncol}, u.${pwcol}
                    FROM ${ut} AS u, ${st} AS s
                    WHERE s.${sidcol} = :1
                    AND u.${uidcol} = s.${suidcol}";
        $stmt    = $dbmgr->prepare($query);
        if(!$stmt->execute($session_id)) {
            $e = array('_getSessionUserData: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }

        $row = $stmt->fetchAssoc();
        if(!$row) {
            $e = array('_getSessionUserData: expected 1 row but got none',
                       "<b>QUERY</b>: ${query}");
            parent::exception($e);
        }
        return array($row[$uncol], $row[$pwcol]);
    }

    /**
     * Activates a login session.
     */
    function _activateLoginSession($username, $password)
    {
        switch($this->mSettings['sessionType'])
        {
        case 'cookie':
            $this->_setCookieSessionCookies($username, $password);
            break;
        case 'db':
            $userid     = $this->_getUserIDByUsername($username);
            $browserstr = $_SERVER['HTTP_USER_AGENT'];
            $expsecs    = $this->mSettings['expireTime'];
            $session_id = $this->_startDBSession($userid, $browserstr, $expsecs);
            $this->_setDBSessionCookie($session_id);
            break;
        default:
            // Should never happen
            break;
        }
    }

    /**
     * Deactivates a login session.
     */
    function _deactivateLoginSession()
    {
        switch($this->mSettings['sessionType'])
        {
        case 'cookie':
            $this->_deleteCookieSessionCookies();
            break;
        case 'db':
            // Fetch session_id before deleting cookies
            $logindata = $this->_getDBSessionLoginData();
            $this->_deleteDBSessionCookie();
            $this->_endDBSession($logindata[0]);
            break;
        default:
            break;
        }
    }

    /**
     * Adds a row to the database for a user-login.
     */
    function _startDBSession($userid, $browserstring, $expsecs)
    {
        $dbmgr   = &parent::getDbMgr();
        $st      = $this->mSettings['sessionTable'];
        $sidcol  = $this->mSettings['sessionIDColumn'];
        $suidcol = $this->mSettings['sessionUserIDColumn'];
        $sbscol  = $this->mSettings['sessionBrowserStringColumn'];
        $slacol  = $this->mSettings['sessionLastAuthColumn'];
        $sescol  = $this->mSettings['sessionExpireSecsColumn'];
        $query   = "INSERT INTO ${st} (${sidcol}, ${suidcol}, ${sbscol}, ${slacol}, ${sescol})
                    VALUES ('', :1, :2, NOW(), :3)";
        $stmt    = $dbmgr->prepare($query);
print "<br />" . $browserstring . "<br />";
        if(!$stmt->execute($userid, $browserstring, $expsecs)) {
            $e = array('_startDBSession: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }
        return $dbmgr->getLastInsertedRowID($st, $sidcol);
    }

    /**
     * Deletes a session-row from the database.
     */
    function _endDBSession($session_id)
    {
        $dbmgr   = &parent::getDbMgr();
        $st      = $this->mSettings['sessionTable'];
        $sidcol  = $this->mSettings['sessionIDColumn'];
        $query   = "DELETE FROM ${st} WHERE ${sidcol} = :1";
        $stmt    = $dbmgr->prepare($query);
        if(!$stmt->execute($session_id)) {
            $e = array('_endDBSession: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }
    }



    /* ==================================================================== */
    /* ==================================================================== */
    /* ==================================================================== */



    /** Called by op_auth if user requested to renew the login (renewLogin). */
    function _renewLogin($username, $password)
    {
        if(empty($this->mSettings['expireTime']) ||
           empty($this->mSettings['cookiePath']) ||
           empty($this->mSettings['cookieDomain']) ||
           empty($this->mSettings['cookiePrefix']))
        {
            $e = array('_renewLogin: missing one or more of required settings:',
                       'expireTime, cookiePath, cookieDomain, cookiePrefix',
                       );
            parent::exception($e);
        }

        $cpath  = $this->mSettings['cookiePath'];
        $cdom   = $this->mSettings['cookieDomain'];
        $cpre   = $this->mSettings['cookiePrefix'];
        $uncook = $this->mUsernameCookieName;
        $pwcook = $this->mPasswordCookieName;
        // Renew cookies, delete previous first
        //--------------------------------------------------------------------
        $exp = time() - $this->mSettings['expireTime'];
        setcookie("${cpre}__${uncook}", '', $exp, $cpath, $cdom, 0);
        setcookie("${cpre}__${pwcook}", '', $exp, $cpath, $cdom, 0);


        $exp = time() + $this->mSettings['expireTime'];
        setcookie("${cpre}__${uncook}", $username, $exp, $cpath, $cdom, 0);
        setcookie("${cpre}__${pwcook}", $password, $exp, $cpath, $cdom, 0);
    }

    /**
     * Updates lastauthColumn / fromColumn for a user.
     *
     * @note Must return (bool) true on success, or except (never return)
     * on failure.
     */
    function _updateLast($username)
    {
        // Build query
        //--------------------------------------------------------------------
        $dbmgr   = &parent::getDbMgr();
        $t       = $this->mSettings['tableName'];
        $llcol   = $this->mSettings['lastauthColumn'];
        $fromcol = $this->mSettings['fromColumn'];
        $query   = "UPDATE ${t} SET ";
        $cnt     = 1;
        // fromColumn
        if(!empty($fromcol)) {
            $query .= "${fromcol} = :${cnt}";
            $cnt++;
        }
        // lastauthColumn
        if(!empty($llcol)) {
            $query .= ($cnt == 2) ? ', ' : '';
            $ll     = $dbmgr->formatTimestampToSQLColumn(time());
            $query .= "${llcol} = ${ll}";
        }
        $ucol   = $this->mSettings['usernameColumn'];
        $query .= " WHERE ${ucol} = :${cnt}";

        // Prepare & execute query
        //--------------------------------------------------------------------
        $stmt = $dbmgr->prepare($query);
        $ret  = true;
        if(!empty($fromcol)) {
            $from = $_SERVER['REMOTE_ADDR'];
            if($this->mSettings['doDNSLookup']) {
                $from = gethostbyaddr($_SERVER['REMOTE_ADDR']);
            }
            $ret = $stmt->execute($from, $username);
        } else {
            $ret = $stmt->execute($username);
        }

        // Check errors
        //--------------------------------------------------------------------
        if(!$ret) {
            $e = array('_updateLast: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }
        return true;
    }



    /* ==================================================================== */
    /* ==================================================================== */
    /* ==================================================================== */



    /**
     * Check a username/password pair against the database.
     *
     * @return True if the username and password match a user in the database,
     * false if not.
     */
    function _checkUserPassAgainstDatabase($username, $password)
    {
        $dbmgr = &parent::getDbMgr();
        $t     = $this->mSettings['tableName'];
        $uncol = $this->mSettings['usernameColumn'];
        $pwcol = $this->mSettings['passwordColumn'];
        // addToQuery
        $addtoqry = parent::settingToString('addToQuery');
        $query    = "SELECT ${pwcol} FROM ${t}
                     WHERE ${uncol} = :1
                     AND ${pwcol} = :2 ${addtoqry}";
        $stmt     = $dbmgr->prepare($query);
        if(!$stmt->execute($username, $password)) {
            $e = array('_checkUserPassAgainstDatabase: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }
        return $stmt->fetchAssoc() ? true : false;
    }

    /**
     * Returns selectColumns for a user.
     */
    function _getSelectCols($username, $selcols)
    {
        $dbmgr = &parent::getDbMgr();
        $t     = $this->mSettings['tableName'];
        $uncol = $this->mSettings['usernameColumn'];
        // addToQuery
        $addtoqry = parent::settingToString('addToQuery');
        $query    = "SELECT ${selcols} FROM ${t} WHERE ${uncol} = :1 ${addtoqry}";
        $stmt     = $dbmgr->prepare($query);
        if(!$stmt->execute($username)) {
            $e = array('_getSelectCols: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }
        return $stmt->fetchAssoc();
    }

    /**
     * Returns selectColumns for a user.
     */
    function _getUserIDByUsername($username)
    {
        $dbmgr  = &parent::getDbMgr();
        $t      = $this->mSettings['tableName'];
        $uidcol = $this->mSettings['userIDColumn'];
        $uncol  = $this->mSettings['usernameColumn'];
        $query  = "SELECT ${uidcol} FROM ${t} WHERE ${uncol} = :1";
        $stmt   = $dbmgr->prepare($query);
        if(!$stmt->execute($username)) {
            $e = array('_getUserIDByUsername: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }
        $row = $stmt->fetchAssoc();
        if(!$row) {
            $e = array('_getUserIDByUsername: expected 1 row but got none',
                       "<b>QUERY</b>: ${query}");
            parent::exception($e);
        }
        return $row[$uidcol];
    }



    /* ==================================================================== */
    /* ==================================================================== */
    /* ==================================================================== */



    // Util methods for accessing cookies

    /** 
     * Sets cookies for sessionType == cookie.
     */
    function _setCookieSessionCookies($username, $password)
    {
        $exp    = time() + $this->mSettings['expireTime'];
        $cpath  = $this->mSettings['cookiePath'];
        $cdom   = $this->mSettings['cookieDomain'];
        $cpre   = $this->mSettings['cookiePrefix'];
        $uncook = $this->mUsernameCookieName;
        $pwcook = $this->mPasswordCookieName;
        setcookie("${cpre}__${uncook}", $username, $exp, $cpath, $cdom, 0);
        setcookie("${cpre}__${pwcook}", $password, $exp, $cpath, $cdom, 0);
    }

    /** 
     * Sets cookies for sessionType == cookie.
     */
    function _deleteCookieSessionCookies()
    {
        $exp    = time() - $this->mSettings['expireTime'];
        $cpath  = $this->mSettings['cookiePath'];
        $cdom   = $this->mSettings['cookieDomain'];
        $cpre   = $this->mSettings['cookiePrefix'];
        $uncook = $this->mUsernameCookieName;
        $pwcook = $this->mPasswordCookieName;
        setcookie("${cpre}__${uncook}", '', $exp, $cpath, $cdom, 0);
        setcookie("${cpre}__${pwcook}", '', $exp, $cpath, $cdom, 0);
    }

    /**
     * Check if cookies exist for sessionType == cookie.
     */
    function _cookieSessionCookiesExist()
    {
        $cpre   = $this->mSettings['cookiePrefix'];
        $uncook = $this->mUsernameCookieName;
        $pwcook = $this->mPasswordCookieName;
        return (array_key_exists("${cpre}__${uncook}", $_COOKIE) &&
                array_key_exists("${cpre}__${pwcook}", $_COOKIE));
    }

    /**
     * Returns login data for sessionType == cookie.
     */
    function _getCookieSessionLoginData()
    {
        $cpre = $this->mSettings['cookiePrefix'];
        $uncook = $this->mUsernameCookieName;
        $pwcook = $this->mPasswordCookieName;
        $username = $_COOKIE["${cpre}__${uncook}"];
        $password = $_COOKIE["${cpre}__${pwcook}"];
        return array($username, $password);
    }

    /** 
     * Sets the session ID cookie for sessionType == db.
     */
    function _setDBSessionCookie($session_id)
    {
        $exp    = time() + $this->mSettings['expireTime'];
        $cpath  = $this->mSettings['cookiePath'];
        $cdom   = $this->mSettings['cookieDomain'];
        $cpre   = $this->mSettings['cookiePrefix'];
        $lscook = $this->mSessionCookieName;
        setcookie("${cpre}__${lscook}", $session_id, $exp, $cpath, $cdom, 0);
    }

    /** 
     * Deletes the session ID cookie for sessionType == db.
     */
    function _deleteDBSessionCookie()
    {
        $exp    = time() - $this->mSettings['expireTime'];
        $cpath  = $this->mSettings['cookiePath'];
        $cdom   = $this->mSettings['cookieDomain'];
        $cpre   = $this->mSettings['cookiePrefix'];
        $lscook = $this->mSessionCookieName;
        setcookie("${cpre}__${lscook}", '', $exp, $cpath, $cdom, 0);
    }

    /**
     * Check if cookies exist for sessionType == db.
     */
    function _dbSessionCookieExists()
    {
        $cpre   = $this->mSettings['cookiePrefix'];
        $lscook = $this->mSessionCookieName;
        return array_key_exists("${cpre}__${lscook}", $_COOKIE);
    }

    /**
     * Returns login data for sessionType == db.
     */
    function _getDBSessionLoginData()
    {
        $cpre = $this->mSettings['cookiePrefix'];
        $lscook = $this->mSessionCookieName;
        $session_id = $_COOKIE["${cpre}__${lscook}"];
        return array($session_id);
    }
}

?>
