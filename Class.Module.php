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

/**
 * A base class for RWE modules.
 *
 * @remarks Provides common and core functionality for RWE modules. Every
 * module should extend this class.
 */
class Module
{
    /*! @protectedsection */
    /* ====================================================================== */
    /// Holds a reference to the user's RWE instance.
    var $mRWE;
    /// The module's settings.
    var $mSettings;

    /*! @privatesection */
    /* ====================================================================== */
    /// "variable prefix" key name.
    var $mVariablePrefixKey;
    /// Initial settings (used with Module::reset()).
    var $mInitialSettings;
    /// Initial variablePrefix (used with Module::reset()).
    var $mInitialVariablePrefix;
    /// Holds whether data has yet been assigned with Module::assign.
    var $mIsData;

    /*! @publicsection */
    /* ====================================================================== */

    /**
     * Constructor.
     * 
     * @param rwe Reference to the user's RWE object (passed to your module's
     * constructor by RWE::executeModule).
     * @param varprefix The initial variablePrefix for this module (should
     * default to the module name, ie. 'dbdata', 'users', etc). This can be
     * overridden by the user by providing the variablePrefix setting.
     * @param defsettings An array of valid settings and their default values
     * (see Module::setSettings).
     */
    function Module(&$rwe, $varprefix, $defsettings)
    {
        $this->mRWE = &$rwe;

        $this->mVariablePrefixKey     = 'variablePrefix';
        $this->mInitialSettings       = $defsettings;
        $this->mInitialVariablePrefix = $varprefix;

        $this->reset();
    }

    /*! @protectedsection */
    /* ====================================================================== */

    /** 
     * Resets this module.
     * 
     * @remarks This method ensures that when a cached module is re-executed
     * it'll be on the same state as if the module had just been constructed
     * (this method will be called just before executing an already-cached
     * module). You should override this from your extending module to reset
     * its own variables as needed (just remember to also call this baseclass
     * method!).
     */
    function reset()
    {
        $this->mSettings = $this->mInitialSettings;
        $this->mSettings[$this->mVariablePrefixKey] = $this->mInitialVariablePrefix;
        $this->mIsData = false;
    }

    /** Check whether there is a valid RWE object stored in this module. */
    function hasValidRWEObject()
    {
        return (is_object($this->mRWE) ||
                is_callable(array($this->mRWE, 'getVersion'))) ? true : false;
    }

    /**
     * Check whether there is a valid database manager set in the RWE object
     * stored in this module
     */
    function hasValidDbMgr()
    {
        return $this->hasValidRWEObject() ?
            $this->mRWE->hasValidDbMgr() : false;
    }

    /** 
     * Returns the database manager from the RWE object through which this
     * module was executed, or null if the RWE object or the database manager
     * in it isn't valid.
     */
    function &getDbMgr()
    {
        $dbmgr = null;
        if($this->hasValidDbMgr()) {
            $dbmgr = &$this->mRWE->getDbMgr();
        }
        return $dbmgr;
    }

    /**
     * Initializes a module for execution. Always call this from your deriving
     * module's execute method!
     */
    function preExecuteModule()
    {
        // Sanity check the RWE object
        if(!$this->hasValidRWEObject()) {
            // Can't use RWE::exception here
            die('preExecuteModule: invalid or bogus RWE object ' .
                '(not a class or doesn\'t implement getVersion)');
        }

        $this->assign('isData', 0);
    }

    /**
     * Use to assign variables from modules.
     *
     * @remarks This method properly prefixes the assigned variables with
     * 'RWE_Module_&lt;variablePrefix&gt;'.
     *
     * @note Does nothing if the RWE object in this module or the template
     * engine in the RWE object is not valid.
     * 
     * @param var Variable name.
     * @param val Variable value.
     */
    function assign($var, $val)
    {
        if(!$this->hasValidRWEObject() || !$this->mRWE->hasValidTplEngine()) {
            return;
        }
        $tplengine        = &$this->mRWE->getTplEngine();
        $tplenginemethods = $this->mRWE->getTplEngineMethods();
        $assignmethod     = $tplenginemethods['assignMethod'];
        $varpre           = $this->mSettings[$this->mVariablePrefixKey];
        $tplengine->{$assignmethod}("RWE_Module_${varpre}__${var}", $val);
        if(!$this->mIsData) {
            $tplengine->{$assignmethod}("RWE_Module_${varpre}__isData", 1);
            $this->mIsData = true;
        }
    }

    /**
     * Applies the user's settings (passed to your module's execute-method, so
     * you'll most likely call this method from there, after
     * Module::preExecuteModule) to this module's valid settings.
     *
     * @remarks Only sets those settings (ie. keys in the user-provided
     * setting-array) that are already found in the "validsettings" array
     * of this module. The validsettings array should be initialized by each
     * module at construction time and passed to Module::Module.
     *
     * @todo Make this method recursive, so that multiple-levels of subkeys
     * can be supported more easily (currently only supports two-levels of
     * "sub setting" arrays; ie. a top-level key containing assoc-array value).
     */
    function setSettings($usersettings)
    {
        if(!is_array($usersettings)) {
            return false;
        }

        $validsettings = array_keys($this->mSettings);

        // Loop through our valid settings array and assign all keys
        // that are found in user's settings array
        foreach($validsettings as $validkey) {
            // Check for keys that contain array values
            if(is_array($this->mSettings[$validkey]) &&
               array_key_exists($validkey, $usersettings))
            {
                $validsubkeys = array_keys($this->mSettings[$validkey]);
                // Same as above, loop and assign those that are found in
                // user's settings array
                foreach($validsubkeys as $validsubkey) {
                    if(array_key_exists($validsubkey, $usersettings[$validkey])) {
                        $this->mSettings[$validkey][$validsubkey] = $usersettings[$validkey][$validsubkey];
                    }
                }
            } else if(array_key_exists($validkey, $usersettings)) {
                $this->mSettings[$validkey] = $usersettings[$validkey];
            }
        }

        /* variablePrefix is always applied even if not in a module's
           validsettings array */
        $varprekey = &$this->mVariablePrefixKey;
        if(array_key_exists($varprekey, $usersettings) &&
           strlen($usersettings[$varprekey]) > 0)
        {
            $this->mSettings[$varprekey] = $usersettings[$varprekey];
        }

    }

    /** RWE::exception wrapper to be used by modules. */
    function exception($msgs = null) { $this->mRWE->exception($msgs); }

    /**
     * Format a module setting into a string (be it array or a string), or
     * return a default value if the setting hasn't been provided by the user.
     *
     * @param setting The setting to format (just the key name). <b>Do not</b>
     * pass the setting variable itself.
     *
     * @param defaultreturn What to return if 'setting' is empty.
     *
     * @param keystoo   See RWEUtil::arrayToString.
     * @param keyvalsep See RWEUtil::arrayToString.
     * @param elemsep   See RWEUtil::arrayToString.
     */
    function settingToString($setting, $defaultreturn = '', $keystoo = false,
                             $keyvalsep = ' ', $elemsep = ', ')
    {
        if(!is_string($setting) ||
           !array_key_exists($setting, $this->mSettings))
        {
            return $defaultreturn;
        }
        $ret = false;
        $val = $this->mSettings[$setting];
        if(!empty($val)) {
            if(is_array($val)) {
                $ret = RWEUtil::arrayToString($val, $keystoo, $keyvalsep, $elemsep);
            } else if(!is_array($val)) {
                $ret = $val;
            }
        }
        return ($ret !== false) ? $ret : $defaultreturn;
    }
}

?>