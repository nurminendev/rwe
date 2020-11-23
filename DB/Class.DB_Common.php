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

require_once(RWE_DIR . 'Class.DB.php');

/**
 * Base class for database managers.
 *
 * @remarks See the
 * <a href="http://www.rakkis.net/web/rwe/manual/">RWE User manual</a>
 * chapter
 * <a href="http://www.rakkis.net/web/rwe/manual/chap4/">4. Database managers</a>
 * for details on this class and the database managers in general.
 */
class DB_common
{
    /*! @protectedsection */
    /* ====================================================================== */
    /// Parsed DSN info array from DB::get.
    var $mDSNInfo;

    /// Whether to open a persistent or a normal connection to the database.
    var $mPersistent;

    /// Database connection handle.
    var $mDBH;

    /*! @privatesection */
    /* ====================================================================== */
    /// Holds the number of prepared queries.
    var $mNumPreparedQueries;

    /// Holds the number of executed queries.
    var $mNumExecutedQueries;

    /// Float value holding the total time (in seconds) spent executing SQL queries.
    var $mTotalSQLTime;

    /*! @publicsection */
    /* ====================================================================== */

    /** 
     * Constructor.
     *
     * @param dsninfo Parsed DSN info array from DB::get.
     * @param persistent Whether to open a persistent or a normal connection to
     * the database.
     */
    function DB_Common($dsninfo, $persistent)
    {
        $this->mDSNInfo = $dsninfo;
        $this->mPersistent = $persistent;

        $this->mDBH = null;

        $this->mNumPreparedQueries = 0;
        $this->mNumExecutedQueries = 0;
        $this->mTotalSQLTime = (float) 0.0;
    }

    /**
     * Set a new DSN string to be used for subsequent calls to connect().
     *
     * @remarks See DB::parseDSN for details on the DSN format.
     */
    function setDSN($dsn) { $this->mDSNInfo = $dsn; }

    /** 
     * Set persistent connection on/off for subsequent connections via this
     * database manager.
     */
    function setPersistent($toggle) { $this->mPersistent = $toggle; }

    /** 
     * Get whether persistent connections are currently set on or off in
     * this database manager.
     */
    function getPersistent() { return $this->mPersistent; }

    /**
     * Get the number of <b>prepared</b> SQL queries so far.
     */
    function getNumPreparedQueries() { return $this->mNumPreparedQueries; }

    /**
     * Get the number of <b>executed</b> SQL queries so far.
     * @remarks Should always be equal or more than the number of prepared queries.
     */
    function getNumExecutedQueries() { return $this->mNumExecutedQueries; }

    /**
     * Get total time spent executing SQL queries.
     * @note Only queries prepared with this database manager are counted.
     */
    function getTotalSQLTime() { return ((float)$this->mTotalSQLTime); }

    /**
     * Checks if the database handle is a valid resource.
     * 
     * @return True if DB::mDBH is a valid database resource
     * (tested with PHP's
     * <a href="http://www.php.net/is_resource">is_resource()</a>),
     * false if not.
     */
    function isDBHandleValid()
    {
        if(!is_resource($this->mDBH)) {
            return false;
        }
        return true;
    }

    /**
     * Returns a reference to the database connection handle.
     */
    function &getDBHandle() { return $this->mDBH; }

    /** Fallback method. */
    function getLastError() { return ''; }
    /** Fallback method. */
    function getLastErrno() { return ''; }
    /** Fallback method. */
    function getLastErrorFullString() { return ''; }

    /** 
     * Last resorts method for databases that don't have native escape method.
     *
     * @remarks Filters the given string through addslashes().
     * @sa DB_MySQL::escapeString, DB_PostgreSQL::escapeString
     */
    function escapeString($string) { return addslashes($string); }

    /** 
     * From the passed-in string returns the real column name that is
     * returned by the SQL server.
     *
     * @remarks For 'column' returns 'column'. For 'c.column AS mycol'
     * returns 'mycol'. For 'p.column' returns 'column'.
     */
    function getRealColumnName($col)
    {
        $matches = array();
        if(preg_match("/.*\s+AS\s+(.+)/i", $col, $matches)) {
            return $matches[1];
        }
        // Check for '.'
        if(preg_match("/.+\.(.*)/", $col, $matches)) {
            return $matches[1];
        }
        return $col;
    }

    /**
     * Get a "placeholder" string for an SQL query.
     *
     * @return A placeholder string starting at 'start' and ending at 'end',
     * e.g. ":N1[, :N, :N, ...], :N2".
     */
    function getQueryPlaceHolderString($start, $end)
    {
        $r = range($start, $end);
        $r[0] = ":${start}";
        return implode(", :", $r);
    }

    /**
     * Formats the values (ie. columns) from the passed in array so that the
     * resulting string can be used with a "UPDATE .. SET &lt;columns&gt;" SQL
     * statement (the returned string from this method can be used in place of
     * &lt;columns&gt;).
     *
     * @remarks For array('column1', 'column2') returns:
     * "column1 = :1, column2 = :2".
     */
    function getSetColumnsPlaceHolderString($cols, $start = 1)
    {
        $cnt = count($cols);
        $phs = explode(', ', $this->getQueryPlaceHolderString($start, $cnt));
        for($i = 0; $i < $cnt; $i++) {
            $phs[$i] = $cols[$i] . ' = ' . $phs[$i];
        }
        return implode(', ', $phs);
    }

    /**
     * Internal method used to increment the total time spent executing queries.
     */
    function _incrementSQLTime($addsec) { $this->mTotalSQLTime += (float)$addsec; }

    /**
     * Internal method used to increment the number of executed queries.
     */
    function _incrementExecutedQueries() { $this->mNumExecutedQueries++; }

    /*! @protectedsection */
    /* ====================================================================== */

    /**
     * Should be called from deriving database managers in their prepare()-method.
     */
    function _prePrepare()
    {
        $this->mNumPreparedQueries++;
    }

}

?>