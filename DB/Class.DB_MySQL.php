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

require_once(RWE_DIR . 'DB' . DIRECTORY_SEPARATOR . 'Class.DB_Common.php');
require_once(RWE_DIR . 'DB' . DIRECTORY_SEPARATOR . 'Class.DB_mysqlstmt.php');

/**
 * Implements a MySQL database manager.
 *
 * @remarks See the
 * <a href="http://www.rakkis.net/web/rwe/manual/">RWE User manual</a>
 * chapter
 * <a href="http://www.rakkis.net/web/rwe/manual/chap4/">4. Database managers</a>
 * for details on this class and the database managers in general.
 *
 * @author This class is originally based on the DB_mysql class found in
 * "Advanced PHP Programming" by George Schlossnagle. Additional bits & pieces
 * have been borrowed from the PEAR DB classes.
 *
 * @sa DB_mysqlstmt, DB_mysql::prepare
 */
class DB_mysql extends DB_common
{
    /*! @publicsection */
    /* ====================================================================== */

    /** 
     * Construct a new MySQL database manager.
     * 
     * @param dsninfo Parsed DSN info array from DB::get.
     * @param persistent Whether to open a persistent or a normal connection to
     * the database. You'll most likely want to create a normal connection
     * (false, which is also the default value) unless you know what you are
     * doing.
     */
    function DB_mysql($dsninfo, $persistent = false)
    {
        $this->DB_common($dsninfo, $persistent);
    }

    /**
     * Establishes a connection to the database.
     *
     * @return False on failure, true on success.
     */
    function connect()
    {
        $dsninfo = $this->mDSNInfo;

        if($dsninfo['protocol'] && $dsninfo['protocol'] == 'unix') {
            $dbhost = ':' . $dsninfo['socket'];
        } else {
            $dbhost = $dsninfo['hostspec'] ? $dsninfo['hostspec'] : 'localhost';
            if($dsninfo['port']) {
                $dbhost .= ':' . $dsninfo['port'];
            }
        }

	$this->mDBH = new mysqli($dbhost, $dsninfo['username'], $dsninfo['password'], $dsninfo['database']);

	if($this->mDBH->connect_errno) {
		return false;
	}

        return true;
    }

    /** 
     * Disconnect from the database.
     * 
     * @return True on success, false on failure.
     *
     * @note Will not close persistent links.
     */
    function disconnect() { return @mysql_close($this->mDBH); }

    /**
     * Switches the active database and stores the new database to be selected
     * when subsequently calling connect().
     *
     * @return True on success, false on failure.
     */
    function selectDB($db)
    {
        $this->mDSNInfo['database'] = $db;
        return @mysql_select_db($this->mDSNInfo['database'], $this->mDBH);
    }

    /**
     * Prepares the given query.
     * 
     * @return If the query was prepared succesfully, a reference to a
     * DB_mysqlstmt for the query is returned. Otherwise false is returned
     * and the last error string is set (use DB::getLastError to get it).
     *
     * @note This method will automatically try to establish a database connection
     * if not connected already.
     *
     * @sa DB_mysqlstmt
     */
    function &prepare($query)
    {
        parent::_prePrepare();

        // Check if we need to connect first
        if(!parent::isDBHandleValid()) {
            if(!$this->connect()) {
                return false;
            }
        }
        $stmt = new DB_mysqlstmt($this, $query);
        return $stmt;
    }

    /**
     * Returns the text of the error message from previous MySQL operation,
     * or '' (empty string) if no error occurred.
     */
    function getLastError() { return mysql_error(); }

    /**
     * Returns the numerical value of the error message from previous MySQL
     * operation, or 0 (zero) if no error occurred.
     */
    function getLastErrno() { return mysql_errno(); }

    /**
     * Returns both the numerical value and the text of the error message from
     * previous MySQL operation as a single string, or '' (empty string) if no
     * error occurred.
     */
    function getLastErrorFullString()
    {
        $errno = $this->getLastErrno();
        $error = $this->getLastError();
        return ($errno != 0) ? "${errno}: ${error}" : '';
    }

    /**
     * Get the last occurred database error as a database-independant RWE
     * error code.
     *
     * @return Returns an RWE database error constant. See
     * @ref db_error_constants "Database error constants"
     */
    function getLastErrorRWECode() {
        $errno = $this->getLastErrno();

        // errno 0 means no errors ever occurred
        if($errno === 0) {
            return RWE_DBERR_NO_ERROR;
        }

        switch($errno) {
        case 1062:
            return RWE_DBERR_DUPLICATE_ENTRY_FOR_KEY;
        case 1216:
            return RWE_DBERR_CHILD_FOREIGN_KEY_FAILURE;
        case 1217:
            return RWE_DBERR_PARENT_FOREIGN_KEY_FAILURE;
        default:
            return RWE_DBERR_UNKNOWN;
        }

        // Never reached
        return RWE_DBERR_UNKNOWN;
    }

    /** Escapes a string so it can be safely included in a query. */
    function escapeString($string) { return mysql_escape_string($string); }

    /** 
     * Returns a string that can be included in a query to insert/update
     * the provided timestamp into this database system's native time/date
     * column.
     *
     * @remarks For MySQL, returns a string that can be used as a value for
     * a DATE, TIME, DATETIME and/or TIMESTAMP column.
     */
    function formatTimestampToSQLColumn($unixtimestamp)
    {
        $ts = $this->escapeString($unixtimestamp);
        return "FROM_UNIXTIME(${ts})";
    }

    /** 
     * Gets the last inserted row ID for the given column in the given table.
     * 
     * @remarks The MySQL version of this method uses the LAST_INSERT_ID() function
     * to obtain the last inserted id.
     */
    function getLastInsertedRowID($table, $column)
    {
        $query = "SELECT LAST_INSERT_ID(${column}) as lastid FROM ${table} ORDER BY lastid DESC LIMIT 0,1";
        $stmt  = $this->prepare($query);
        $stmt->execute();
        $row   = $stmt->fetchAssoc();
        return $row['lastid'];
    }

    /** 
     * Returns a LIMIT clause in this database system's SQL syntax, constructed
     * from the passed-in variables.
     */
    function getLimitClause($limit, $offset) { return "LIMIT ${offset}, ${limit}"; }

    /** 
     * Set transaction isolation level, either for this session only (default),
     * or globally for all subsequent connections.
     *
     * @param level The transaction isolation level. See
     * @ref transaction_isolation_level_constants "Transaction Isolation Level constants"
     * for possible values.
     * @param global If true, then the isolation level is set globally for all
     * subsequent connections (but not necessarily this connection; you might
     * have to reconnect for the global isolation level change to take effect).
     * Note that you might need superuser privileges in the database for this
     * to work. Defaults to false (set only for this session). 
     */
    function setTransactionIsolationLevel($level, $global = false)
    {
        $scope = $global ? 'GLOBAL' : 'SESSION';
        switch($level) {
        case RWE_DB_TRANSACTION_ISOLATION_LEVEL_READ_UNCOMMITTED:
            @mysql_query("SET ${scope} TRANSACTION ISOLATION LEVEL
                          READ UNCOMMITTED");
            break;
        case RWE_DB_TRANSACTION_ISOLATION_LEVEL_READ_COMMITTED:
            @mysql_query("SET ${scope} TRANSACTION ISOLATION LEVEL
                          READ COMMITTED");
            break;
        case RWE_DB_TRANSACTION_ISOLATION_LEVEL_REPEATABLE_READ:
            @mysql_query("SET ${scope} TRANSACTION ISOLATION LEVEL
                          REPEATABLE READ");
            break;
        case RWE_DB_TRANSACTION_ISOLATION_LEVEL_SERIALIZABLE:
            @mysql_query("SET ${scope} TRANSACTION ISOLATION LEVEL
                          SERIALIZABLE");
            break;
        default:
            break;
        }
    }

    /** 
     * Begin a transaction.
     * 
     * @remarks Simply sends the 'BEGIN' statement to the server; calling
     * this function is equivalent to calling:
     * @code
     * $dbmgr->prepare('BEGIN')->execute();
     * @endcode
     */
    function beginTransaction() { @mysql_query('BEGIN', $this->mDBH); }

    /** 
     * Commit a transaction.
     * 
     * @remarks Simply sends the 'COMMIT' statement to the server; calling
     * this function is equivalent to calling:
     * @code
     * $dbmgr->prepare('COMMIT')->execute();
     * @endcode
     */
    function commitTransaction() { @mysql_query('COMMIT', $this->mDBH); }

    /** 
     * Rolls back a transaction.
     * 
     * @remarks Simply sends the 'ROLLBACK' statement to the server; calling
     * this function is equivalent to calling:
     * @code
     * $dbmgr->prepare('ROLLBACK')->execute();
     * @endcode
     */
    function rollBackTransaction() { @mysql_query('ROLLBACK', $this->mDBH); }
}

?>
