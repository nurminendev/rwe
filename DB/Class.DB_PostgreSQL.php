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
require_once(RWE_DIR . 'DB' . DIRECTORY_SEPARATOR . 'Class.DB_PostgreSQLStatement.php');

/**
 * Implements a PostgreSQL database manager.
 *
 * @remarks See the
 * <a href="http://www.rakkis.net/web/rwe/manual/">RWE User manual</a>
 * chapter
 * <a href="http://www.rakkis.net/web/rwe/manual/chap4/">4. Database managers</a>
 * for details on this class and the database managers in general.
 *
 * @author This class is originally based on the DB_MySQL class found in
 * "Advanced PHP Programming" by George Schlossnagle. Additional bits & pieces
 * have been borrowed from the PEAR DB classes.
 *
 * @sa DB_PostgreSQLStatement, DB_PostgreSQL::prepare
 */
class DB_PostgreSQL extends DB_Common
{
    /*! @publicsection */
    /* ====================================================================== */

    /** 
     * Construct a new DB_PostgreSQL instance.
     * 
     * @param dsninfo Parsed DSN info array from DB::get.
     * @param persistent Whether to open a persistent or a normal connection to
     * the database. You'll most likely want to create a normal connection
     * (false, which is also the default value) unless you know what you are
     * doing.
     */
    function DB_PostgreSQL($dsninfo, $persistent = false)
    {
        $this->DB_Common($dsninfo, $persistent);
    }

    /**
     * Establishes a connection to the database.
     *
     * @return False on failure, true on success.
     */
    function connect()
    {
        $dsninfo = $this->mDSNInfo;

        $protocol = $dsninfo['protocol'] ? $dsninfo['protocol'] : 'tcp';
        $connstr = '';

        if($protocol == 'tcp') {
            if($dsninfo['hostspec']) {
                $connstr .= 'host=' . $dsninfo['hostspec'];
            }
            if($dsninfo['port']) {
                $connstr .= ' port=' . $dsninfo['port'];
            }
        } else if($protocol == 'unix') {
            // Allow for pg socket in non-standard locations.
            if($dsninfo['socket']) {
                $connstr .= 'host=' . $dsninfo['socket'];
            }
            if($dsninfo['port']) {
                $connstr .= ' port=' . $dsninfo['port'];
            }
        }

        if($dsninfo['database']) {
            $connstr .= ' dbname=\'' . addslashes($dsninfo['database']) . '\'';
        }
        if($dsninfo['username']) {
            $connstr .= ' user=\'' . addslashes($dsninfo['username']) . '\'';
        }
        if($dsninfo['password']) {
            $connstr .= ' password=\'' . addslashes($dsninfo['password']) . '\'';
        }
        if(!empty($dsninfo['options'])) {
            $connstr .= ' options=' . $dsninfo['options'];
        }
        if(!empty($dsninfo['tty'])) {
            $connstr .= ' tty=' . $dsninfo['tty'];
        }

        $connect_function = $this->mPersistent ? 'pg_pconnect' : 'pg_connect';
        $this->mDBH = $connect_function($connstr);

        if(!parent::isDBHandleValid()) {
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
    function disconnect() { return @pg_close($this->mDBH); }

    /**
     * Switches the active database and stores the new database to be selected
     * when subsequently calling connect().
     *
     * @note With PostgreSQL this method disconnects and re-connects the
     * database connection to switch the database.
     *
     * @return True on success, false on failure.
     */
    function selectDB($db) {
        $this->mDSNInfo['database'] = $db;
        if(!$this->disconnect()) {
            return false;
        }
        return $this->connect();
    }

    /**
     * Prepares the given query.
     * 
     * @return If the query was prepared succesfully, a reference to a
     * DB_PostgreSQLStatement for the query is returned. Otherwise false is returned
     * and the last error string is set (use DB::getLastError to get it).
     *
     * @note This method will automatically try to establish a database connection
     * if not connected already.
     *
     * @sa DB_PostgreSQLStatement
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
        $stmt = &new DB_PostgreSQLStatement($this, $query);
        return $stmt;
    }

    /**
     * Returns the text of the error message from previous PostgreSQL
     * operation, or '' (empty string) if no error occurred.
     */
    function getLastError() { return pg_last_error(); }

    /**
     * The PostgreSQL version of this method always returns 0 (zero).
     */
    function getLastErrno() { return 0; }

    /**
     * Returns the text of the error message from previous PostgreSQL
     * operation, or '' (empty string) if no error occurred.
     */
    function getLastErrorFullString() { return pg_last_error(); }

    /**
     * Get the last occurred database error as a database-independant RWE
     * error code.
     *
     * @return Returns an RWE database error constant. See
     * @ref db_error_constants "Database error constants"
     */
    function getLastErrorRWECode() {
        // Must use error messages for matching with PG since it doesn't
        // provide nice error codes like MySQL does..
        $lasterr = $this->getLastError();

        if(empty($lasterr)) {
            return RWE_DBERR_NO_ERROR;
        }

        if(strstr($lasterr, 'duplicate key')) {
            return RWE_DBERR_DUPLICATE_ENTRY_FOR_KEY;
        }
        if(preg_match("/insert\sor\supdate(.*)violates\sforeign\skey\sconstraint/i", $lasterr)) {
            return RWE_DBERR_CHILD_FOREIGN_KEY_FAILURE;
        }
        if(preg_match("/update\sor\sdelete(.*)violates\sforeign\skey\sconstraint/i", $lasterr)) {
            return RWE_DBERR_PARENT_FOREIGN_KEY_FAILURE;
        }

        return RWE_DBERR_UNKNOWN;
    }

    /** Escapes a string so it can be safely included in a query. */
    function escapeString($string) { return pg_escape_string($string); }

    /** 
     * Returns a string that can be included in a query to insert/update
     * the provided timestamp into this database system's native time/date
     * column.
     *
     * @remarks For PostgreSQL, returns a string that can be used as a value
     * for a DATE, TIME and/or TIMESTAMP column.
     */
    function formatTimestampToSQLColumn($unixtimestamp)
    {
        $ts = $this->escapeString($unixtimestamp);
        return "(SELECT 'epoch'::timestamp WITH TIME ZONE + ${ts} * '1 second'::interval)";
    }

    /** 
     * Gets the last inserted row ID for the given column in the given table.
     * 
     * @remarks The PostgreSQL version of this method uses the currval() function
     * to obtain the current sequence number. The sequence name used is
     * @c {table}_{column}_seq, which is the default format for sequences created
     * with the SERIAL type. If you create your own sequences, make sure they adhear
     * to that naming scheme!
     *
     * @todo Dynamically fetch the sequence name based on the table and column,
     * instead of assuming the SERIAL format.
     */
    function getLastInsertedRowID($table, $column)
    {
        $query = "SELECT currval('${table}_${column}_seq') AS lastid";
        $stmt  = $this->prepare($query);
        $stmt->execute();
        $row   = $stmt->fetchAssoc();
        return $row['lastid'];
    }

    /** 
     * Returns a LIMIT clause in this database system's SQL syntax, constructed
     * from the passed-in variables.
     */
    function getLimitClause($limit, $offset) { return "LIMIT ${limit} OFFSET ${offset}"; }

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
     * 
     * @return Returns nothing.
     */
    function setTransactionIsolationLevel($level, $global = false)
    {
        switch($level) {
        case RWE_DB_TRANSACTION_ISOLATION_LEVEL_READ_COMMITTED:
            if($global) {
                @pg_query("SET default_transaction_isolation = 'READ COMMITTED'");
            } else {
                @pg_query('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL
                           READ COMMITTED');
            }
        break;
        case RWE_DB_TRANSACTION_ISOLATION_LEVEL_SERIALIZABLE:
            if($global) {
                @pg_query("SET default_transaction_isolation = 'SERIALIZABLE'");
            } else {
                @pg_query('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL
                           SERIALIZABLE');
            }
            break;
        case RWE_DB_TRANSACTION_ISOLATION_LEVEL_READ_UNCOMMITTED:
        case RWE_DB_TRANSACTION_ISOLATION_LEVEL_REPEATABLE_READ:
        default:
            // Not supported by PostgreSQL
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
    function beginTransaction() { @pg_query($this->mDBH, 'BEGIN'); }

    /** 
     * Commit a transaction.
     * 
     * @remarks Simply sends the 'COMMIT' statement to the server; calling
     * this function is equivalent to calling:
     * @code
     * $dbmgr->prepare('COMMIT')->execute();
     * @endcode
     */
    function commitTransaction() { @pg_query($this->mDBH, 'COMMIT'); }

    /** 
     * Rolls back a transaction.
     * 
     * @remarks Simply sends the 'ROLLBACK' statement to the server; calling
     * this function is equivalent to calling:
     * @code
     * $dbmgr->prepare('ROLLBACK')->execute();
     * @endcode
     */
    function rollBackTransaction() { @pg_query($this->mDBH, 'ROLLBACK'); }
}

?>