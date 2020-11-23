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

require_once(RWE_DIR . 'Class.RWE.php');
require_once(RWE_DIR . 'Class.Module.php');

/**
 * A base class for "op" based modules.
 */
class OpBasedModule extends Module
{
    /*! @protectedsection */
    /* ==================================================================== */
    /// Holds valid ops.
    var $mValidOps;

    /*! @publicsection */
    /* ==================================================================== */

    /**
     * Constructor.
     */
    function OpBasedModule(&$rwe, $varprefix, &$defsettings, $validops = array())
    {
        $this->mValidOps = $validops;

        $this->Module($rwe, $varprefix, $defsettings);
    }

    /** Set valid ops. */
    function setValidOps($validops) { $this->mValidOps = $validops; }

    /** Get valid ops as array. */
    function getValidOps() { return $this->mValidOps; }

    /** Get valid ops as comma separated string. */
    function getValidOpsStr() { return implode(', ', $this->mValidOps); }

    /** Check if an op is valid. */
    function isValidOp($op) { return in_array($op, $this->mValidOps); }
}

?>
