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

/**
 * Base class for SQL statements.
 */
class DB_SQLStatement
{
    /*! @protectedsection */
    /* ====================================================================== */
    /// Holds a reference to the database manager.
    var $mDbMgr;

    /// Holds the database connection handle.
    var $mDBH;

    /// Holds the result resource from the SQL query.
    var $mResult;

    /*! @privatesection */
    /* ====================================================================== */
    /// Holds the SQL query (with placeholders).
    var $mQuery;

    /*! @publicsection */
    /* ====================================================================== */

    /** 
     * Constructor.
     */
    function DB_SQLStatement(&$dbmgr, $query)
    {
        $this->mDbMgr  = &$dbmgr;
        $this->mDBH    = &$dbmgr->getDBHandle();
        $this->mResult = false;

        $this->mQuery  = $query;
    }

    /*! @protectedsection */
    /* ====================================================================== */

    /** 
     * Returns the query with all placeholders replaced by the passed in
     * args.
     */
    function getQuery($args)
    {
        $binds = array();
        foreach($args as $index => $arg) {
            $binds[$index + 1] = $arg;
        }
        $query = $this->mQuery;
        $lastelem = count($binds);
        for($i = $lastelem; $i > 0; $i--) {
            $query = str_replace(":$i", "'" . $this->mDbMgr->escapeString($binds[$i]) . "'", $query);
        }
        return $query;
    }
}

?>