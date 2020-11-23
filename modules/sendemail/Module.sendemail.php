<?php

/*

RWE sendemail module

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
 * A simple RWE module interface for PHP's native mail()-function.
 *
 * @todo Add support for more mailer backends.
 */
class Module_sendemail extends Module
{
    /*! @publicsection */
    /* ====================================================================== */

    /** 
     * Constructor.
     */
    function Module_sendemail(&$rwe)
    {
        // List of valid settings accepted by this module
        $vs = array('recipients' => null,
                    'subject'    => null,
                    'headers'    => null,
                    'message'    => null,
                    );

        // Construct Module parent class
        $this->Module($rwe, 'sendemail', $vs);
    }

    /*! @protectedsection */
    /* ====================================================================== */

    /** 
     * Checks all required settings.
     *
     * @remarks Called by executeModule(). You could do this in executeModule
     * too; I like to keep things separated.
     */
    function _checkSettings()
    {
        if(empty($this->mSettings['recipients']) ||
           empty($this->mSettings['subject']) ||
           empty($this->mSettings['message']))
        {
            $e = array('_checkSettings: missing one or more of required settings:',
                       'recipients, subject, message');
            parent::exception($e);
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

    /** Runs this module. */
    function _runModule()
    {
        $recipients = parent::settingToString('recipients');
        $subject    = $this->mSettings['subject'];
        $message    = $this->mSettings['message'];
        $headers    = parent::settingToString('headers', null, false, ' ', "\r\n");

        if($headers !== null) {
            return mail($recipients, $subject, $message, $headers);
        }
        return mail($recipients, $subject, $message);
    }
}

?>