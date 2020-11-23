<?php

/*

RWE

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

/*! @defgroup transaction_isolation_level_constants Transaction isolation level constants.
 * Transaction isolation level constants that can be passed to
 * the setSessionTransactionIsolationLevel methods of the database
 * managers.
 * @{
 */

/// Read uncommitted.
define('RWE_DB_TRANSACTION_ISOLATION_LEVEL_READ_UNCOMMITTED', 1);
/// Read committed.
define('RWE_DB_TRANSACTION_ISOLATION_LEVEL_READ_COMMITTED',   2);
/// Repeatable read.
define('RWE_DB_TRANSACTION_ISOLATION_LEVEL_REPEATABLE_READ',  3);
/// Serializable.
define('RWE_DB_TRANSACTION_ISOLATION_LEVEL_SERIALIZABLE',     4);

/** @} */

/*! @defgroup db_error_codes Database error constants.
 * These database error constants can be passed to the isLastError() method
 * of the database managers to check if the last occurred database error
 * is of certain type.
 * @{
 */

/// Duplicate entry for key.
/*!
 * @remarks Last INSERT or UPDATE failed because a duplicate key violated an
 * UNIQUE constraint.
 */
define('RWE_DBERR_DUPLICATE_ENTRY_FOR_KEY',    1000);

// Child foreign key failure.
/*!
 * @remarks Last INSERT or UPDATE on a child row failed because it violated
 * a FOREIGN KEY constraint (ie. the referenced parent table didn't have the
 * value that was being inserted/updated to the child row).
 */
define('RWE_DBERR_CHILD_FOREIGN_KEY_FAILURE',  1010);

// Parent foreign key failure.
/*!
 * @remarks Last UPDATE or DELETE on a parent row failed because one or more
 * child rows referenced it and didn't allow the action.
 */
define('RWE_DBERR_PARENT_FOREIGN_KEY_FAILURE', 1011);

// No error.
define('RWE_DBERR_NO_ERROR',                   8000);

// Unknown error.
define('RWE_DBERR_UNKNOWN',                    9999);

/** @} */

/**
 * A factory class for database managers.
 */
class DB
{
    /*! @publicsection */
    /* ====================================================================== */

    /**
     * Create a new database manager object.
     *
     * @remarks Use this static method to create a database manager object.
     * Its usage is very similiar to the PEAR DB class:
     * @code
     * require_once('DB.php');
     *
     * $dsn = 'MySQL://user:password@host/database'
     *
     * $dbmgr = &DB::get($dsn);
     * ...
     * @endcode
     * @par
     * Some differences:
     *   - This method only creates the database manager class, and does not
     *     connect to a database. When you finally need to connect, just call
     *     connect() on the database manager object.
     *   - The dbtype part (phptype in PEAR DB) is a full, case-sensitive name
     *     for the database backend to use. The name should be the same as the
     *     relevant part from the backend file name, e.g. for
     *     DB/Class.DB_MySQL.php dbtype should be 'MySQL'.
     *   - The 'options' array has been removed, instead the 'persistent' and
     *     'debug' parameters can be passed alone, separately.
     *
     * @param dsninfo A "data source name" describing what database manager to
     * create and how to connect to the database. See DB::parseDSN for details.
     *
     * @param persistent Whether to open a persistent or a normal connection to
     * the database. You'll most likely want to create a normal connection
     * (false, which is also the default value) unless you know what you are
     * doing.
     *
     * @param debug For debugging set to 'true' (defaults to false). When true,
     * PHP errors from require_once won't be suppressed.
     *
     * @author Most of this class comes from the PEAR DB class.
     */
    public static function &get($dsn, $persistent = false, $debug = false)
    {
        $dsninfo = DB::parseDSN($dsn);
        $type = $dsninfo['phptype'];

        if($debug) {
            require_once(RWE_DIR . "DB/Class.DB_${type}.php");
        } else {
            @require_once(RWE_DIR . "DB/Class.DB_${type}.php");
        }

        $classname = "DB_${type}";

        if(!class_exists($classname)) {
            die("DB::get: Can't create database manager of type '${type}'; " .
                'File exists but either 1) doesn\'t contain the class ' .
                'implementation or 2) the module class is invalid/contains ' .
                'syntax error(s) (try setting debug mode on with the third ' .
                'parameter to DB::get).');
        }

        return eval(sprintf(" return new %s(\$dsninfo, \$persistent);", $classname));
    }

    /**
     * Parse a data source name.
     *
     * Additional keys can be added by appending a URI query string to the
     * end of the DSN.
     *
     * The format of the supplied DSN is in its fullest form:
     * @code
     *  dbtype://username:password@protocol+hostspec/database?option=8&another=true
     * @endcode
     *
     * Most variations are allowed:
     * @code
     *  dbtype://username:password@protocol+hostspec:110//usr/db_file.db?mode=0644
     *  dbtype://username:password@hostspec/database_name
     *  dbtype://username:password@hostspec
     *  dbtype://username@hostspec
     *  dbtype://hostspec/database
     *  dbtype://hostspec
     *  dbtype
     * </code>
     *
     * @param string $dsn Data Source Name to be parsed
     *
     * @return array an associative array with the following keys:
     *  - phptype:  Database backend used in PHP (mysql, odbc etc.)
     *  - protocol: Communication protocol to use (tcp, unix etc.)
     *  - hostspec: Host specification (hostname[:port])
     *  - database: Database to use on the DBMS server
     *  - username: User name for login
     *  - password: Password for login
     *
     * @author Tomas V.V.Cox <cox@idecnet.com>
     *
     * @note This method comes almost verbatim from the PEAR DB class. It has
     * been modified not to include the 'dbsyntax' parsing and the phptype
     * key in the returned array has been changed to 'dbtype' (more descriptive,
     * since RWE doesn't use PHP names for the database backends).
     */
    private static function parseDSN($dsn)
    {
        $parsed = array(
            'phptype'  => false,
            'dbsyntax' => false,
            'username' => false,
            'password' => false,
            'protocol' => false,
            'hostspec' => false,
            'port'     => false,
            'socket'   => false,
            'database' => false,
            );

        if (is_array($dsn)) {
            $dsn = array_merge($parsed, $dsn);
            if (!$dsn['dbsyntax']) {
                $dsn['dbsyntax'] = $dsn['phptype'];
            }
            return $dsn;
        }

        // Find phptype and dbsyntax
        if (($pos = strpos($dsn, '://')) !== false) {
            $str = substr($dsn, 0, $pos);
            $dsn = substr($dsn, $pos + 3);
        } else {
            $str = $dsn;
            $dsn = null;
        }

        // Get phptype and dbsyntax
        // $str => phptype(dbsyntax)
        $arr = array();
        if (preg_match('|^(.+?)\((.*?)\)$|', $str, $arr)) {
            $parsed['phptype']  = $arr[1];
            $parsed['dbsyntax'] = !$arr[2] ? $arr[1] : $arr[2];
        } else {
            $parsed['phptype']  = $str;
            $parsed['dbsyntax'] = $str;
        }

        if (!count($dsn)) {
            return $parsed;
        }

        // Get (if found): username and password
        // $dsn => username:password@protocol+hostspec/database
        if (($at = strrpos($dsn,'@')) !== false) {
            $str = substr($dsn, 0, $at);
            $dsn = substr($dsn, $at + 1);
            if (($pos = strpos($str, ':')) !== false) {
                $parsed['username'] = rawurldecode(substr($str, 0, $pos));
                $parsed['password'] = rawurldecode(substr($str, $pos + 1));
            } else {
                $parsed['username'] = rawurldecode($str);
            }
        }

        // Find protocol and hostspec
        $match = array();
        if (preg_match('|^([^(]+)\((.*?)\)/?(.*?)$|', $dsn, $match)) {
            // $dsn => proto(proto_opts)/database
            $proto       = $match[1];
            $proto_opts  = $match[2] ? $match[2] : false;
            $dsn         = $match[3];

        } else {
            // $dsn => protocol+hostspec/database (old format)
            if (strpos($dsn, '+') !== false) {
                list($proto, $dsn) = explode('+', $dsn, 2);
            }
            if (strpos($dsn, '/') !== false) {
                list($proto_opts, $dsn) = explode('/', $dsn, 2);
            } else {
                $proto_opts = $dsn;
                $dsn = null;
            }
        }

        // process the different protocol options
        $parsed['protocol'] = (!empty($proto)) ? $proto : 'tcp';
        $proto_opts = rawurldecode($proto_opts);
        if ($parsed['protocol'] == 'tcp') {
            if (strpos($proto_opts, ':') !== false) {
                list($parsed['hostspec'],
                     $parsed['port']) = explode(':', $proto_opts);
            } else {
                $parsed['hostspec'] = $proto_opts;
            }
        } elseif ($parsed['protocol'] == 'unix') {
            $parsed['socket'] = $proto_opts;
        }

        // Get dabase if any
        // $dsn => database
        if ($dsn) {
            if (($pos = strpos($dsn, '?')) === false) {
                // /database
                $parsed['database'] = rawurldecode($dsn);
            } else {
                // /database?param1=value1&param2=value2
                $parsed['database'] = rawurldecode(substr($dsn, 0, $pos));
                $dsn = substr($dsn, $pos + 1);
                if (strpos($dsn, '&') !== false) {
                    $opts = explode('&', $dsn);
                } else { // database?param1=value1
                    $opts = array($dsn);
                }
                foreach ($opts as $opt) {
                    list($key, $value) = explode('=', $opt);
                    if (!isset($parsed[$key])) {
                        // don't allow params overwrite
                        $parsed[$key] = rawurldecode($value);
                    }
                }
            }
        }

        return $parsed;
    }
}

?>
