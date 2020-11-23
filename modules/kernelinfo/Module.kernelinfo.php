<?php

/*

RWE kernelinfo module

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
 * A kernel.org version info module for RWE.
 *
 * @remarks Parses kernel tree versions from a file in the format of
 * http://www.kernel.org/kdist/finger_banner and assigns the resulting
 * tree/version pairs to templates.
 *
 * @remarks For available settings see the accompanying README file.
 *
 * @note The fingerBanner setting should be a path to a local version
 * of the finger_banner file. You can make a cronjob that fetches the
 * file every once a hour or so. You should NOT make this module use
 * the finger_banner directly from kernel.org, as then every visitor
 * will make traffic to kernel.org, too.
 *
 * @author Riku Nurminen
 */
class Module_kernelinfo extends Module
{
    /*! @publicsection */
    /* ==================================================================== */

    function Module_kernelinfo(&$rwe)
    {
        // List of valid settings accepted by this module
        $vs = array('fingerBanner' => null,
                    'treeRegexps'  => null,
                    );

        $this->Module($rwe, 'kernelinfo', $vs);
    }

    /*! @protectedsection */
    /* ==================================================================== */

    /** Checks common settings. */
    function _checkSettings()
    {
        if(empty($this->mSettings['fingerBanner'])) {
            parent::exception('_checkSettings: fingerBanner file not given');
        }
        if(empty($this->mSettings['treeRegexps'])) {
            parent::exception('_checkSettings: treeRegexps not given!');
        }
    }

    /*! @publicsection */
    /* ====================================================================== */

    /**
     * Executes this module.
     */
    function executeModule($settings)
    {
        parent::preExecuteModule();
        parent::setSettings($settings);

        // Check required settings
        $this->_checkSettings();

        return $this->_runModule();
    }

    /*! @protectedsection */
    /* ====================================================================== */

    function _runModule()
    {
        $bannerfile = $this->mSettings['fingerBanner'];
        $fh = null;
        if($this->mRWE->getDebug()) {
            $fh = fopen($bannerfile, 'r');
        } else {
            $fh = @fopen($bannerfile, 'r');
        }
        if($fh === false) {
            return false;
        }

        $trees = array('treename' => array(),
                       'version'  => array());

        $hasmatches  = false;
        $nummatches  = 0;
        $treeregexps = $this->mSettings['treeRegexps'];

        while(false === feof($fh)) {
            $buffer = fgets($fh, 128);
            foreach($treeregexps as $tree => $pattern) {
                $matches  = array();
                $_pattern = '/' . $pattern . '/';
                if(preg_match($_pattern, $buffer, $matches)) {
                    $trees['treename'][] = $tree;
                    $trees['version'][]  = $matches[1];
                    $hasmatches = true;
                    $nummatches++;
                }
            }
        }

        fclose($fh);

        if($hasmatches) {
            parent::assign('trees',      $trees);
            parent::assign('numMatches', $nummatches);
        }

        return true;
    }
}

?>