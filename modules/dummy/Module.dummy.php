<?php

/*

A dummy RWE module template

This work is hereby released into the Public Domain. To view a copy
of the public domain dedication, visit
http://creativecommons.org/licenses/publicdomain/ or send a letter to
Creative Commons, 559 Nathan Abbott Way, Stanford, California 94305, USA.

*/

require_once(RWE_DIR . 'Class.RWE.php');
require_once(RWE_DIR . 'Class.Module.php');

/**
 * A dummy module template.
 *
 * @note This RWE module template is released into the Public Domain, thus you
 * can freely (ab)use it any way you like.
 */
class Module_dummy extends Module
{
    /*! @publicsection */
    /* ==================================================================== */

    /** 
     * Constructor.
     */
    function Module_dummy(&$rwe)
    {
        // List of valid settings accepted by this module
        $vs = array('dummysetting0' => null,
                    'dummysetting1' => null,
                    );

        // Call parent class constructor
        $this->Module($rwe, 'dummy', $vs);
    }

    /** 
     * Override Module::reset().
     * 
     * @remarks Here you should reset your module to the _exact_ state it is
     * after first constructing it; all variables and other states should be
     * set to their initial values.
     * @par
     * Note that you don't need to call this from your module's constructor;
     * the Module::Module constructor will call this.
     *
     * @note <b>If you override this, then make sure you call reset() on the
     * superclass (Module) also! (ie. parent::reset()).</b>
     */
    function reset()
    {
        /* reset() in superclass; DON'T FORGET TO DO THIS IF YOU OVERRIDE THIS
           METHOD! */
        parent::reset();
    }

    /*! @protectedsection */
    /* ==================================================================== */

    /** Checks all required settings. */
    function _checkSettings()
    {
        if(empty($this->mSettings['dummysetting0']) ||
           empty($this->mSettings['dummysetting1']))
        {
            $e = array('_checkSettings: missing one or more of required settings:',
                       'dummysetting0, dummysetting1');
            parent::exception($e);
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

        return $this->_runModule();
    }

    /*! @protectedsection */
    /* ==================================================================== */

    /** Runs this module. */
    function _runModule()
    {
        /* This is the beef of your module .. you could e.g. use the database
           manager from parent::getDbMgr (after checking that one is available
           with parent::hasValidDbMgr() in _checkSettings() of course) to
           fetch some database data and then assign the rows with
           parent::assign('dbRows', $rows); .. */

        // Return -1 to indicate something to RWE::executeModule caller
        return -1;
    }
}

?>