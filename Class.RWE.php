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

/** @mainpage RWE API Reference
 *
 * <div style="width:100%;text-align:center;">http://www.rakkis.net/projects/</div>
 *
 * @section related Related stuff
 * <ul>
 * <li>TODO list: <a href="todo.html">todo.html</a></li>
 * </ul>
 */

if(!defined('RWE_DIR')) {
    define('RWE_DIR', dirname(__FILE__) . DIRECTORY_SEPARATOR);
}

require_once(RWE_DIR . 'Class.RWEUtil.php');

/** 
 * The main class and user API of the RWE system.
 *
 * @note <b>Your application should set the RWE_DIR constant (with trailing
 * slash) to the full path to the RWE directory before including this
 * file.</b>
 */
class RWE
{
    /*! @privatesection */
    /* ====================================================================== */
    /// Holds the RWE version array.
    /*! @sa RWE::getVersion, RWE::getVersionString */
    var $mVersion;

    /// Holds a reference to the user's database manager (as set with RWE::setDbMgr).
    var $mDbMgr;

    /// Holds a reference to the user's template engine (as set with RWE::setTplEngine).
    var $mTplEngine;

    /// Names of the template engine's assign, display, etc. methods.
    var $mTplEngineMethods;

    /// Module directory or directories.
    var $mModuleDirs;

    /// Module cache.
    var $mModuleCache;

    /// Module caching enabled / disabled.
    var $mModuleCaching;

    /// Currently active module (only set during module execution).
    var $mActiveModule;

    /// Web root directory.
    var $mWebRootDir;

    /// Module filename prefix.
    var $mModuleFileNamePrefix;
    /// Module classname prefix.
    var $mModuleClassNamePrefix;

    /// Name of the "execute module" method.
    /*!
     * @remarks Each module must implement this method. If you try to
     * call RWE::executeModule on a module that doesn't implement this method,
     * an exception will be raised and the exception template displayed.
     */
    var $mModuleExecuteMethod;

    /// User exception template.
    /*! @sa RWE::setExceptionTemplate */
    var $mExceptionTemplate;

    /// Default exception template.
    var $mDefaultExceptionTemplate;

    /// Debug mode on/off.
    var $mDebug;

    /*! @publicsection */
    /* ====================================================================== */

    /** 
     * Constructor.
     */
    function __construct()
    {
        $this->mVersion = '0.1';

        $this->mDbMgr     = null;
        $this->mTplEngine = null;
        $this->mTplEngineMethods = null;

        $this->mModuleDirs   = null;
        $this->mModuleCache  = null;
        $this->mActiveModule = null;

        // Enable module caching by default
        $this->mModuleCaching = true;

        $this->mWebRootDir = null;

        $this->mModuleFileNamePrefix  = 'Module.';
        $this->mModuleClassNamePrefix = 'Module_';
        $this->mModuleExecuteMethod   = 'executeModule';

        $this->mExceptionTemplate        = null;
        $this->mDefaultExceptionTemplate = RWE_DIR . 'Exception.tpl';
        
        $this->mDebug = false;
    }

    /** Get RWE version string. */
    function getVersion() { return $this->mVersion; }

    /**
     * Set a database manager for this RWE instance.
     *
     * @remarks See DB::get for information on how to obtain a database
     * manager. Note that many modules rely on a database manager being
     * available on the RWE instance through which the module was executed.
     *
     * @sa RWE::getDbMgr
     */
    function setDbMgr(&$dbmgr) { $this->mDbMgr = &$dbmgr; }

    /**
     * Returns a reference to the database manager previously set with
     * RWE::setDbMgr, or null if no database manager has been set.
     *
     * @sa RWE::setDbMgr
     */
    function &getDbMgr() { return $this->mDbMgr; }

    /**
     * Returns true if the database manager previously set with RWE::setDbMgr
     * is valid, false otherwise.
     */
    function hasValidDbMgr()
    {
        return (is_object($this->mDbMgr) ||
                is_callable(array($this->mDbMgr, 'connect'))) ? true : false;
    }

    /**
     * Set a template engine for this RWE instance.
     *
     * @remarks RWE doesn't tie you to use any particular template engine;
     * you can use any system you like as long as it implements the few
     * general methods described in the second parameter to this method.
     *
     * @param tplengine The template engine.
     * @param tplenginemethods Essential methods the template engine class must
     * implement that are used by RWE and modules. These are:
     * - assignMethod => method to assign variables to templates; should accept
     *   two parameters: first is the template variable name, second is the
     *   value.
     * - displayMethod => method to display a template file (used by
     *   RWE::exception for displaying user-specified exception templates).
     *   Should accept one parameter which is the template to display (full
     *   path, relative path, some identifier, .. whatever your system uses).
     * - getvarMethod => method to get the value of a previously set template
     *   variable. Should accept one parameter which is the variable name.
     *   Only required if you are using RWE::getModuleTplVars, otherwise
     *   optional.
     */
    function setTplEngine(&$tplengine,
                          $tplenginemethods =
                          array('assignMethod'  => 'assign',
                                'displayMethod' => 'display',
                                'getvarMethod'  => 'get_template_vars',
                                ))
    {
        $this->mTplEngine = &$tplengine;
        $this->mTplEngineMethods = $tplenginemethods;
    }

    /**
     * Returns a reference to the template engine previously set with
     * RWE::setTplEngine, or null if no template engine has been set.
     */
    function &getTplEngine() { return $this->mTplEngine; }

    /** 
     * Get the template engine methods array.
     *
     * @sa RWE::setTplEngine
     */
    function getTplEngineMethods() { return $this->mTplEngineMethods; }

    /** 
     * Checks that the template engine and the methods set in RWE::setTplEngine
     * are valid.
     *
     * @return True if the template engine and its methods are valid, false if
     * not.
     *
     * @note The getvarMethod will only be checked if it's non-empty.
     *
     * @sa RWE::setTplEngine
     */
    function hasValidTplEngine()
    {
        if(!is_object($this->mTplEngine) ||
           empty($this->mTplEngineMethods['assignMethod']) ||
           empty($this->mTplEngineMethods['displayMethod']) ||
           !is_callable(array($this->mTplEngine,
                              $this->mTplEngineMethods['assignMethod'])) ||
           !is_callable(array($this->mTplEngine,
                              $this->mTplEngineMethods['displayMethod'])))
        {
            return false;
        }
        if(!empty($this->mTplEngineMethods['getvarMethod']) &&
           !is_callable(array($this->mTplEngine,
                              $this->mTplEngineMethods['getvarMethod'])))
        {
            return false;
        }
        return true;
    }

    /** 
     * Enable/disable debug mode.
     *
     * @remarks You should always run in debug mode when developing modules, so
     * that RWE won't suppress errors when including your module file.
     * Otherwise you'll just get a blank screen if you try to execute a module
     * that has syntax/other errors.
     */
    function setDebug($flag) { $this->mDebug = $flag; }

    /** Check whether RWE is running in debug mode (true) or not (false). */
    function getDebug() { return $this->mDebug; }

    /** 
     * Enable / disable module caching.
     *
     * @remarks When module caching is enabled all executed modules will be
     * cached when they're executed the first time. All subsequent executions
     * of the same module withing the same script run (ie. through the same RWE
     * instance) will then use the cached instance of the module (instead of
     * loading the module from bottom-up again, ie. searching and opening the
     * module file, instantiating the object, ...). This can provide
     * substantial speedups when executing the same module multiple times
     * in the same script run.
     * @par
     * Module caching is <b>enabled</b> by default.
     *
     * @sa RWE::getModuleCaching, RWE::clearModuleCache
     */
    function setModuleCaching($flag) { $this->mModuleCaching = $flag; }

    /** Check whether module caching is enabled or disabled. */
    function getModuleCaching() { return $this->mModuleCaching; }

    /** 
     * Clears the module cache (destroys all cached module instances).
     * 
     * @sa RWE::setModuleCaching, RWE::getModuleCaching
     */
    function clearModuleCache() {
        if(empty($this->mModuleCache)) {
            return;
        }
        $cachedmods = array_keys($this->mModuleCache);
        foreach($cachedmods as $modname) {
            unset($this->mModuleCache[$modname]);
        }
        $this->mModuleCache = null;
    }

    /** 
     * Set a module directory or directories (overwrites all current
     * directories).
     * 
     * @param moddirs Can be either a string containing a single directory or
     * an array containing multiple directories.
     *
     * @note Module directories are searched recursively, so if you have
     * modules in many directories under one main-dir, you only need to add
     * the main-dir to RWE; the sub-dirs will be searched automatically.
     *
     * @sa RWE::addModuleDir
     */
    function setModuleDirs($moddirs) { $this->mModuleDirs = $moddirs; }

    /** 
     * Add a module directory.
     * 
     * @param dir A string containing full path to the directory to add, with
     * trailing slash.
     *
     * @note Module directories are searched recursively, so if you have
     * modules in many directories under one main-dir, you only need to add
     * the main-dir to RWE; the sub-dirs will be searched automatically.
     */
    function addModuleDir($dir)
    {
        if(empty($dir) || !is_string($dir)) {
            $this->exception('RWE::addModuleDir: tried to add an empty or ' .
                             'a non-string module directory');
        }
        if(empty($this->mModuleDirs)) {
            $this->mModuleDirs = array();
        }
        if(is_array($this->mModuleDirs)) {
            $this->mModuleDirs[] = $dir;
        } else {
            $this->mModuleDirs = array($this->mModuleDirs, $dir);
        }
    }

    /** Get current module directory or directories. */
    function getModuleDirs() { return $this->mModuleDirs; }

    /**
     * Set the web root directory.
     *
     * @note <b>Must include trailing slash.</b>
     *
     * @sa RWE::getWebRootDir
     */
    function setWebRootDir($rootdir) { $this->mWebRootDir = $rootdir; }

    /**
     * Get the web root directory.
     *
     * @sa RWE::setWebRoot
     */
    function getWebRootDir() { return $this->mWebRootDir; }

    /** 
     * A shortcut method that can be used to retrieve the value of a
     * module-assigned template variable (so you don't have to write the
     * RWE_Module_... part).
     *
     * @return The value of the module-assigned template variable, or triggers
     * an exception if no template engine is set (or it is invalid) or if the
     * getvarMethod (set in RWE::setTplEngine) is empty.
     *
     * @remarks The variable that is requested is constructed as:
     * 'RWE_Module_&lt;varpre&gt;__&lt;var&gt;', where 'varpre' and 'var' are
     * the parameters to this method.
     */
    function getModuleTplVars($varpre, $var)
    {
        if(!$this->hasValidTplEngine() ||
           empty($this->mTplEngineMethods['getvarMethod']))
        {
            $this->exception('RWE::getModuleTplVars: invalid template engine ' .
                             'or you didn\'t specify getvarMethod when ' .
                             'calling RWE::setTplEngine');
        }
        $varfull = "RWE_Module_${varpre}__${var}";
        $getvarm = $this->mTplEngineMethods['getvarMethod'];
        return $this->mTplEngine->{$getvarm}($varfull);
    }

    /** 
     * Sets the exception template that gets displayed when using a template
     * engine and an exception (RWE::exception) is triggered.
     *
     * @remarks The exception message and possibly other details will be
     * assigned to this template before displaying; see RWE::exception for
     * details on the assigned variable names.
     *
     * @note Make sure your template engine can properly find this template!
     *
     * @sa RWE::exception
     */
    function setExceptionTemplate($tpl) { $this->mExceptionTemplate = $tpl; }

    /**
     * Get the current exception template, or null if no exception template has
     * been set.
     */
    function getExceptionTemplate() { return $this->mExceptionTemplate; }

    /**
     * Execute an RWE module.
     *
     * @remarks RWE modules are "building-blocks", self-contained pieces of
     * PHP code that often accomplish some small, specific task. A module class
     * typically extends the Module baseclass found in Class.Module.php in the
     * RWE directory. The module class should be placed in a file named
     * Module.<moduleName>.php and put in a module directory, in order for
     * RWE to be able to find it. Module directories can be added/set with
     * RWE::addModuleDir and RWE::setModuleDir.
     * @par
     * See the dummy module in RWE_DIR/modules/dummy/Module.dummy.php for an
     * example of an RWE module.
     *
     * @param modulename The module to execute, without the 'Module_' part
     * (e.g. 'dbdata', 'users', ...).
     * @param settings Settings array to pass to the module.
     *
     * @sa RWE::setModuleCaching, Module
     */
    function executeModule($modulename, $settings)
    {
        if($this->mModuleCaching) {
            $this->mActiveModule = &$this->_getModuleFromCache($modulename);
            $this->mActiveModule->reset();
        } else {
            $this->mActiveModule = &$this->_loadModule($modulename);
        }

        // Execute
        $retval = $this->mActiveModule->{$this->mModuleExecuteMethod}($settings);

        /* unset() will only decrement the reference count on mActiveModule;
           Thus, when caching, the module object will not be destroyed as the
           cache array contains another link to the object instance.
           On non-caching case mActiveModule is the only link to the instance
           so it'll get deleted appropriately. */
        unset($this->mActiveModule);

        return $retval;
    }

    /**
     * Trigger an "exception" (an error) and halt script execution.
     *
     * @remarks If no template engine is set, then this method will assume the
     * script is running in CLI (console) mode and will print the exception
     * message(s) to stdout.
     * @par
     * If a template engine is set, then this method will display an exception
     * template; either one set by the user using the set template engine, or
     * the default template (RWE_DIR/Exception.tpl).
     * @par
     * The following template variables are assigned for the user exception
     * template:
     * - RWE_exceptionMessages: An array of exception messages as passed to
     *   this method. If called with one string parameter (exception('error')),
     *   then the array will contain that string as its only element.
     * - RWE_isModuleActive: Set to 1 if the exception was triggered during
     *   (and from) module execution, otherwise set to 0.
     * - RWE_activeModule: If RWE_isModuleActive is 1, then this variable
     *   contains the name of the module which triggered the exception.
     *
     * @note Calling this method without any parameters is equivalent to
     * calling exception('unknown exception');
     *
     * @sa RWE::setExceptionTemplate, RWE::getExceptionTemplate
     */
    function exception($msgs = null)
    {
        $exceptions  = array('unknown exception');
        $ismodactive = false;
        $actmodname  = '';

        if(is_array($msgs) && !empty($msgs)) {
            $exceptions = $msgs;
        } else if(!empty($msgs)) {
            $exceptions = array($msgs);
        }

        // Get module classname if we were called during module execution
        if(isset($this->mActiveModule)) {
            $c = get_class($this->mActiveModule);
            // Capitalize first letter since get_class returns all lower case
            $c{0} = strtoupper($c{0});
            $ismodactive = true;
            $actmodname  = $c;
        }

        if($this->hasValidTplEngine()) {
            $this->_tplEngineException($exceptions, $ismodactive, $actmodname);
        } else {
            $this->_cliException($exceptions, $ismodactive, $actmodname);
        }

        /* NB: Use die(1) instead of exit(1) -- From www.php.net/exit:
           "When using php as a SHELL scripting language, use die() instead
            of exit(). If using exit in php5, the script will continue to
            processes conditionals and other shell_exec functions after the
            die command." */
        die(1);
    }

    /*! @protectedsection */
    /* ====================================================================== */

    /** 
     * Internal method used by RWE::exception to trigger an exception through
     * a template engine.
     */
    function _tplEngineException($exceptions, $ismodactive, $actmodname)
    {
        if(!$this->hasValidTplEngine()) {
            return false;
        }

        $assignmethod  = $this->mTplEngineMethods['assignMethod'];
        $displaymethod = $this->mTplEngineMethods['displayMethod'];

        $this->mTplEngine->{$assignmethod}('RWE_exceptionMessages',
                                           $exceptions);
        $this->mTplEngine->{$assignmethod}('RWE_isModuleActive', 0);

        if($ismodactive) {
            $this->mTplEngine->{$assignmethod}('RWE_activeModule',
                                               $actmodname);
            $this->mTplEngine->{$assignmethod}('RWE_isModuleActive', 1);
        }

        // Check for user exception template
        if(!empty($this->mExceptionTemplate)) {
            $this->mTplEngine->{$displaymethod}($this->mExceptionTemplate);
        } else {
            $this->_displayDefaultExceptionTemplate($exceptions, $ismodactive,
                                                    $actmodname);
        }
    }

    /** 
     * Internal method used by RWE::exception to trigger an exception into
     * stdout (console).
     */
    function _cliException($exceptions, $ismodactive, $actmodname)
    {
        print('****************************************' .
              "**************************************\n");
        foreach($exceptions as $exception) {
            $exception = strip_tags($exception);
            print("Exception: ${exception}\n");
        }
        if($ismodactive) {
            print("In module: ${actmodname}\n");
        }
        print('****************************************' .
              "**************************************\n");
    }

    /** 
     * Internal method used by RWE::_tplEngineException to display the default
     * exception template.
     *
     * @remarks Since we can't assume any particular template format here,
     * we'll just recreate a minimal template system by reading in the default
     * template (RWE_DIR/Exception.tpl) and replacing the
     * %%%EXCEPTIONDETAILS%%% string in it with the exception details.
     */
    function _displayDefaultExceptionTemplate($exceptions, $ismodactive,
                                              $actmodname)
    {
        $tpl = $this->mDefaultExceptionTemplate;
        if(!file_exists($tpl)) {
            die('RWE::_displayDefaultExceptionTemplate: FATAL ERROR: ' .
                "default exception template ${tpl} not found!");
        }
        $fh = null;
        if($this->mDebug) {
            $fh = fopen($tpl, 'r');
        } else {
            $fh = @fopen($tpl, 'r');
        }
        if(false === $fh) {
            die('RWE::_displayDefaultExceptionTemplate: FATAL ERROR: ' .
                "failed to fopen() ${tpl}! (Try enabling debug mode with " .
                'RWE::setDebug(true) to see why.)');
        }
        $contents = fread($fh, filesize($tpl));
        $exps = array();
        foreach($exceptions as $exception) {
            $exps[] .= "  <b>Exception</b>: ${exception}";
        }
        $exps = implode("<br />\n", $exps);
        if($ismodactive) {
            $exps .= "<br />\n";
            $exps .= "  <b>In module</b>: ${actmodname}";
        }
        $contents = str_replace('%%%EXCEPTIONDETAILS%%%', $exps, $contents);
        echo $contents;
    }

    /**
     * Internal method used by RWE::executeModule to load modules.
     *
     * @param modulename Name of the module to load, without the 'Module_' part
     * (e.g. 'dbdata', 'users', ...).
     *
     * @return Returns a reference to the created module object on success. On
     * failure triggers an exception (and thus never returns).
     *
     * @sa RWE::executeModule
     */
    function &_loadModule($modulename)
    {
        $modclassname = $this->mModuleClassNamePrefix . $modulename;
        $modfilename  = $this->mModuleFileNamePrefix . $modulename . '.php';
        $modfullpath  = null;
        $errorpre     = "Unable to load module '${modulename}', reason";

        if(false === ($modfullpath = $this->_findModuleFullPath($modfilename))) {
            $this->exception("${errorpre}: File '${modfilename}' not found in " .
                             'search path. Did you forget to add its module ' .
                             'directory with RWE::addModuleDir?');
        }

        if($this->mDebug) {
            include_once($modfullpath);
        } else {
            @include_once($modfullpath);
        }

        if(!class_exists($modclassname)) {
            $this->exception("${errorpre}: File exists but either 1) doesn't " .
                             "contain the '${modclassname}' class " .
                             'implementation or 2) the module class is ' .
                             'invalid/contains syntax error(s) (to see ' .
                             'errors, enable debug mode with ' .
                             'RWE::setDebug(true)).');
        }

        $modobj = &$this->_createObject($modclassname);

        // Check that the module object contains valid 'execute' method
        $execmethod = $this->mModuleExecuteMethod;
        if(!is_callable(array($modobj, $execmethod))) {
            $this->exception("${errorpre}: Class exists but is missing or " .
                             "contains invalid '${execmethod}' method.");
        }

        return $modobj;
    }

    /** 
     * Creates the given object and returns a reference to it.
     *
     * @remarks The class must be defined prior to calling this method.
     *
     * @author Yvan Boily @ www.php.net/eval comments.
     */
    function &_createObject($classname) {
        $newobj = eval(sprintf(' return new %s($this);', $classname));
        return $newobj;
    }

    /** 
     * Returns a reference to a module object from the cache.
     * 
     * @remarks The module will be loaded & cached first if not yet in cache.
     *
     * @param modulename Name of the module to retrieve, without the 'Module_'
     * part (e.g. 'dbdata', 'users', ...).
     * 
     * @return 
     */
    function &_getModuleFromCache($modulename)
    {
        if(empty($this->mModuleCache)) {
            $this->mModuleCache = array();
        }

        // Check if module is cached already
        if(array_key_exists($modulename, $this->mModuleCache)) {
            return $this->mModuleCache[$modulename];
        }

        // Module is not cached
        $modobj = &$this->_loadModule($modulename);
        $this->mModuleCache[$modulename] = &$modobj;
        return $modobj;
    }

    /**
     * Finds the full path to a module file. Searches recursively from all
     * module directories and their subdirectories.
     *
     * @param modfilename The module filename to search for.
     *
     * @sa RWE::setModuleDir, RWE::addModuleDir
     */
    function _findModuleFullPath($modfilename)
    {
        if(is_array($this->mModuleDirs)) {
            foreach($this->mModuleDirs as $dir) {
                if(false !== ($found = RWEUtil::findFileRecursive($modfilename, $dir))) {
                    return $found;
                }
            }
        } else {
            if(false !== ($found = RWEUtil::findFileRecursive($modfilename, $this->mModuleDirs))) {
                return $found;
            }

        }
        return false;
    }
}

?>
