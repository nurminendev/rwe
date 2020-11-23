<?php

/*

RWE fileupload module

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
require_once(RWE_DIR . 'Class.OpBasedModule.php');

/**
 * A module for handling file uploads via HTTP forms.
 *
 * @todo Support the $_FILES['userfile']['errors'] feature of PHP 4.2.0 and later.
 * @todo Test file overwriting, add more settings to control how to overwrite files.
 * @todo imgAllowedDimensions can't be used when open_basedir doesn't allow PHP to
 * go inside the tmp dir.
 */
class Module_fileupload extends OpBasedModule
{
    /*! @privatesection */
    /* ==================================================================== */
    /// Holds value for 'no extension' allowed extension.
    var $mNoExtVal;
    /// Holds allowed file mime types (from user).
    var $mAllowedMimeTypes;
    /// Holds allowed file extensions (from user).
    var $mAllowedExts;
    /// Holds min file size in bytes (from user).
    var $mMinSizeBytes;
    /// Holds max file size in bytes (from user).
    var $mMaxSizeBytes;
    /// Holds allowed image dimensions if uploading an image (from user).
    var $mImgDims;

    /*! @publicsection */
    /* ==================================================================== */

    function Module_fileupload(&$rwe)
    {
        // List of valid settings accepted by this module
        $vs = array(// Common
                    //--------------------------------------------------------
                    'op'                   => null,

                    // upload
                    //--------------------------------------------------------
                    'fileFormName'         => null,
                    'saveTo'               => null,
                    'saveAs'               => null,
                    'overwriteAction'      => null,
                    'allowedMimeTypes'     => null,
                    'allowedExtensions'    => null, // [noext] ..
                    'minSizeBytes'         => null,
                    'maxSizeBytes'         => null,
                    'imgAllowedDimensions' => null,
                    );

        $validops = array('upload', 'checkstate');

        $this->OpBasedModule($rwe, 'fileupload', $vs, $validops);

        $this->mNoExtVal = '[noext]';
    }

    /** Overridden from Module. */
    function reset()
    {
        $this->mAllowedMimeTypes = null;
        $this->mAllowedExts      = null;
        $this->mMinSizeBytes     = null;
        $this->mMaxSizeBytes     = null;
        $this->mImgDims          = null;

        parent::reset();
    }

    /*! @protectedsection */
    /* ==================================================================== */

    /** Checks common settings. */
    function _checkSettings()
    {
        $op = $this->mSettings['op'];
        if(!parent::isValidOp($op)) {
            parent::exception("_checkSettings: invalid op (${op})" .
                              ", valid ops are: " . parent::getValidOpsStr());
        }

        // Check the following for 'upload' op only
        if($op != 'checkstate') {
            // Check that we have fileFormName
            $formname = $this->mSettings['fileFormName'];
            if(empty($formname)) {
                parent::exception('_checkSettings: missing/invalid fileFormName');
            }
            // Check that fileFormName exists in $_FILES
            if(!array_key_exists($formname, $_FILES)) {
                parent::exception("_checkSettings: fileFormName '${formname}' doesn't exist in \$_FILES");
            }
            // Check that we have saveTo
            if(empty($this->mSettings['saveTo'])) {
                parent::exception('_checkSettings: missing/invalid saveTo');
            }
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

        $op = $this->mSettings['op'];

        switch($op) {
        case 'upload':
            return $this->_op_upload();
        case 'checkstate':
            return $this->_op_checkstate();
        default:
            // Should never happen
            return false;
        }
    }

    /*! @protectedsection */
    /* ==================================================================== */

    /** 
     * Gets called on op upload.
     */
    function _op_upload()
    {
        // Gather settings
        //--------------------------------------------------------------------
        $this->_gatherSettings();

        // Check file against gathered settings
        //--------------------------------------------------------------------
        if(!$this->_checkFileAgainstSettings()) {
            // Return false so whoever called us will to go back to
            // do a checkstate
            return false;
        }

        // File is ok, gather settings for moving
        //--------------------------------------------------------------------
        $formname     = $this->mSettings['fileFormName'];
        $saveto       = $this->mSettings['saveTo'];
        $saveas       = $_FILES[$formname]['name'];
        if(!empty($this->mSettings['saveAs'])) {
            $saveas   = $this->mSettings['saveAs'];
        }
        $overwriteact = 0;
        $o            = $this->mSettings['overwriteAction'];
        if(is_numeric($o) && $o >= 0 && $o <= 2) {
            $overwriteact = $o;
        }

        $destfilepath = $saveto . DIRECTORY_SEPARATOR . $saveas;

        // Check if file exists and overwriteAction is != 1
        if(file_exists($destfilepath) && $overwriteact != 1) {
            switch($overwriteact) {
            // 0 == don't overwrite, return succesfully
            case '0':
                return true;
            // 2 == rename old file with '-timestamp' postfix
            case '2':
                rename($destfilepath, $destfilepath . '-' . time());
                break;
            }
        }

        $tmpname = $_FILES[$formname]['tmp_name'];
        if(is_uploaded_file($tmpname)) {
            if(!move_uploaded_file($tmpname, $destfilepath)) {
                parent::exception("_op_upload: move_uploaded_file failed");
            }
        } else {
            $e = array('_op_upload: SECURITY ERROR:',
                       "is_uploaded_file() failed for ${tmpname}");
            parent::exception($e);
        }

        return true;
    }

    /**
     * Gets called on op checkstate.
     */
    function _op_checkstate()
    {
        $iserrors = false;
        RWEUtil::startSessionSafe();
        if(array_key_exists('RWE_Module_fileupload__isErrors', $_SESSION)){
            $iserrors = true;
        }
        if($iserrors) {
            $errors = unserialize($_SESSION['RWE_Module_fileupload__errors']);
            parent::assign('isErrors', 1);
            parent::assign('errors', $errors);
            session_unset();
        }
        return true;
    }

    /** 
     * Checks the incoming file against settings provided by user.
     */
    function _checkFileAgainstSettings()
    {
        $errors   = array();
        $formname = $this->mSettings['fileFormName'];
        $file     = $_FILES[$formname]['name'];
        $tmpfile  = $_FILES[$formname]['tmp_name'];

        // Check allowedMimeTypes
        //--------------------------------------------------------------------
        if(!is_null($this->mAllowedMimeTypes)) {
            $filetype = $_FILES[$formname]['type'];
            $typeok = false;
            foreach($this->mAllowedMimeTypes as $allowedtype) {
                if(strcasecmp($filetype, $allowedtype) == 0) {
                    $typeok = true;
                    break;
                }
            }
            if(!$typeok) {
                $errors[] = 'invalid_mime_type';
            }
        }

        // Check allowedExts
        //--------------------------------------------------------------------
        if(!is_null($this->mAllowedExts)) {
            $matches = array();
            // Extract extension
            if(preg_match("/.+\.([\w\d]+)$/", $file, $matches)) {
                $ext = $matches[1];
                $extok = false;
                // Loop all allowedExts and see if we have a match
                foreach($this->mAllowedExts as $allowedext) {
                    if(strcasecmp($ext, $allowedext) == 0) {
                        $extok = true;
                        break;
                    }
                }
                if(!$extok) {
                    $errors[] = 'invalid_extension';
                }
            } else {
                // Probably a file without extension; error unless we
                // have noExtVal in allowedExtensions
                if(!in_array($this->mNoExtVal, $this->mAllowedExts)) {
                    $errors[] = 'invalid_extension';
                }
            }
        }

        // Check min/maxSizeBytes
        //--------------------------------------------------------------------
        if(!is_null($this->mMinSizeBytes)) {
            $filesize = filesize($tmpfile);
            if($filesize < $this->mMinSizeBytes) {
                $errors[] = 'file_below_minsize';
            }
        }
        if(!is_null($this->mMaxSizeBytes)) {
            $filesize = filesize($tmpfile);
            if($filesize > $this->mMaxSizeBytes) {
                $errors[] = 'file_above_maxsize';
            }
        }

        // Check imgAllowedDimensions
        //--------------------------------------------------------------------
        if(!is_null($this->mImgDims)) {
            $dimerror  = false;
            $minwidth  = $this->mImgDims[0];
            $maxwidth  = $this->mImgDims[1];
            $minheight = $this->mImgDims[2];
            $maxheight = $this->mImgDims[3];

            list($width, $height, $type, $attr) = getimagesize($tmpfile);
            // Check dimensions
            if(($width  < $minwidth) ||
               ($width  > $maxwidth) ||
               ($height < $minheight) ||
               ($height > $maxheight))
            {
                $errors[] = 'invalid_dimensions';
            }
        }

        // Check errors
        //--------------------------------------------------------------------
        if(count($errors) > 0) {
            // Save errors in session for checkstate
            RWEUtil::startSessionSafe();
            $_SESSION['RWE_Module_fileupload__isErrors'] = true;
            $_SESSION['RWE_Module_fileupload__errors']   = serialize($errors);
            return false;
        }

        return true;
    }

    /** 
     * Checks what optional settings we might have and saves them to
     * members variables for Module_fileupload::_checkFileAgainstSettings.
     */
    function _gatherSettings()
    {
        // allowedMimeTypes
        $amt = $this->mSettings['allowedMimeTypes'];
        if(is_array($amt) && count($amt) > 0) {
            $this->mAllowedMimeTypes = $amt;
        } else if(!empty($amt)) {
            $this->mAllowedMimeTypes = array($amt);
        }

        // allowedExtensions
        $ae = $this->mSettings['allowedExtensions'];
        if(is_array($ae) && count($ae) > 0) {
            $this->mAllowedExts = $ae;
        } else if(!empty($ae)) {
            $this->mAllowedExts = array($ae);
        }

        // min/maxSizeBytes
        $mins = $this->mSettings['minSizeBytes'];
        $maxs = $this->mSettings['maxSizeBytes'];
        if(is_numeric($mins) && $mins > 0) {
            $this->mMinSizeBytes = $mins;
        }
        if(is_numeric($maxs) && $maxs > 0) {
            $this->mMaxSizeBytes = $maxs;
        }

        // imgAllowedDimensions
        $id = $this->mSettings['imgAllowedDimensions'];
        if(is_array($id) && count($id) == 4) {
            $ok = true;
            foreach($id as $dimension) {
                if(!is_numeric($dimension) || $dimension < 1) {
                    $ok = false;
                }
            }
            if($ok) {
                $this->mImgDims = $id;
            }
        }
    }
}

?>
