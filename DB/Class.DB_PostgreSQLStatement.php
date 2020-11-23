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

require_once(RWE_DIR . 'DB' . DIRECTORY_SEPARATOR . 'Class.DB_SQLStatement.php');

if(!function_exists('pg_fetch_assoc')) {
    function pg_fetch_assoc($result)
    {
        return @pg_fetch_array($result, NULL, PGSQL_ASSOC);
    }
}

/**
 * A class holding a reusable query prepared with DB_PostgreSQL::prepare.
 *
 * @remarks See the
 * <a href="http://www.rakkis.net/web/rwe/manual/">RWE User manual</a>
 * chapter
 * <a href="http://www.rakkis.net/web/rwe/manual/chap4/">4. Database managers</a>
 * for details on this class and the database managers in general.
 *
 * @author This class is originally based on the DB_MySQLStatement class
 * found in "Advanced PHP Programming" by George Schlossnagle.
 *
 * @sa DB_PostgreSQL, DB
 */
class DB_PostgreSQLStatement extends DB_SQLStatement
{
    /*! @publicsection */
    /* ====================================================================== */

    /** 
     * Constructor.
     *
     * @remarks Never called directly by the user; use DB_PostgreSQL::prepare
     * to prepare a query and return an instance of this class.
     * 
     * @param dbmgr The database manager object that constructed this
     * statement.
     * @param query The query string.
     *
     * @sa DB_PostgreSQL::prepare
     */
    function DB_PostgreSQLStatement(&$dbmgr, $query)
    {
        $this->DB_SQLStatement($dbmgr, $query);
    }

    /** 
     * Executes this statement.
     *
     * @remarks Accepts variable amount of arguments; the arguments passed
     * in should correspond to the "placeholder" variables in the query.
     * 
     * @return $this is returned on success, false on failure.
     *
     * @sa DB_PostgreSQL
     */
    function execute()
    {
        $args  = func_get_args();
        $query = parent::getQuery($args);

        // Capture time now
        $mtime  = explode(' ', microtime());
        $tstart = (float)$mtime[1] + (float)$mtime[0];

        // Execute query
        $this->mResult = @pg_query($this->mDBH, $query);
        if(!$this->mResult) {
            return false;
        }

        // Calc time the query took to execute
        $mtime    = explode(' ', microtime());
        $tend     = (float)$mtime[1] + (float)$mtime[0];
        $exectime = (float)$tend - (float)$tstart;

        $this->mDbMgr->_incrementSQLTime($exectime);
        $this->mDbMgr->_incrementExecutedQueries();

        return $this;
    }

    /** 
     * Fetch the next row from this statement (DB_PostgreSQLStatement::execute must
     * be called first!).
     * 
     * @remarks Actually just a wrapper around PHP's
     * <a href="http://www.php.net/pg_fetch_row">pg_fetch_row()</a>.
     *
     * @return The next row from the query, or false if there are no more rows
     * or if this statement doesn't have a valid result resource (ie. it hasn't
     * been executed yet).
     */
    function fetchRow() { return pg_fetch_row($this->mResult); }

    /** 
     * Fetch the next row from this statement as an associative array
     * (DB_PostgreSQLStatement::execute must be called first!).
     * 
     * @remarks Actually just a wrapper around PHP's
     * <a href="http://www.php.net/pg_fetch_assoc">pg_fetch_assoc()</a>.
     *
     * @return The next row from the query, or false if there are no more rows
     * or if this statement doesn't have a valid result resource (ie. it hasn't
     * been executed yet).
     */
    function fetchAssoc() { return pg_fetch_assoc($this->mResult); }

    /** 
     * Fetch all rows from this statement as an associative array
     * (DB_PostgreSQLStatement::execute must be called first!).
     * 
     * @remarks Calls PHP's
     * <a href="http://www.php.net/pg_fetch_assoc">pg_fetch_assoc()</a>,
     * collects each resulting row into an array and returns that array.
     *
     * @return The rows from the query, or an empty array if there are no more
     * rows (ie. if DB_PostgreSQLStatement::fetchRow or
     * DB_PostgreSQLStatement::fetchAssoc has already been called to fetch all rows)
     * or if this statement doesn't have a valid result resource (ie. it hasn't
     * been executed yet).
     */
    function fetchAllAssoc()
    {
        $retval = array();
        while($row = $this->fetchAssoc()) {
            $retval[] = $row;
        }
        return $retval;
    }

    /** 
     * Get the number of rows returned by this statement.
     *
     * @note The statement must be an executed SELECT statement!
     */
    function numRows() { return pg_num_rows($this->mResult); }

    /** 
     * Get the number of rows affected by the last INSERT, UPDATE or DELETE
     * query executed with this statement object.
     */
    function numAffectedRows() { return pg_affected_rows($this->mResult); }
}

?>