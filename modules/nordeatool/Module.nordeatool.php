<?php

/*

RWE nordeatool module

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
 * An RWE module that produces TS-files used in Finnish Nordea bank's web
 * interface.
 */
class Module_nordeatool extends OpBasedModule
{
    /*! @protectedsection */
    /* ====================================================================== */
    /// Name of session var to store records in.
    var $mSessionRecordsVarName;
    /// Name of session var to store payment date in.
    var $mSessionDateVarName;

    /*! @publicsection */
    /* ==================================================================== */

    function Module_nordeatool(&$rwe)
    {
        // List of valid settings accepted by this module
        $vs = array(// Common
                    //--------------------------------------------------------
                    'op'               => null,

                    // writeRecords
                    //--------------------------------------------------------
                    'records'          => null,
                    'outFile'          => null,
                    'paymentTimestamp' => null,
                    'payerBID'         => null,
                    'saveRecords'      => false,
                    );

        $validops = array('writeRecords', 'checkstate');

        $this->OpBasedModule($rwe, 'nordeatool', $vs, $validops);

        $this->mSessionRecordsVarName = 'RWE_Module_nordeatool__records';
        $this->mSessionDateVarName    = 'RWE_Module_nordeatool__date';
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
        case 'writeRecords':
            return $this->_op_writeRecords();
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
     * Gets called on op writeRecords.
     */
    function _op_writeRecords()
    {
        if(empty($this->mSettings['records']) ||
           empty($this->mSettings['outFile']) ||
           empty($this->mSettings['paymentTimestamp']) ||
           empty($this->mSettings['payerBID']) ||
           !is_array($this->mSettings['records']) ||
           !is_numeric($this->mSettings['paymentTimestamp']))
        {
            $e = array('_op_writeRecords: missing one or more of required settings:',
                       'records, outFile, paymentTimestamp, payerBID',
                       );
            parent::exception($e);
        }

        // Save TS-file contents here
        $content = '';

        // Add all records to content
        //--------------------------------------------------------------------
        $numrecords = 0;
        $totaleur   = (float) 0.0;
        foreach($this->mSettings['records'] as $record) {
            $content  .= $this->_getTSRecord($record);
            $totaleur += (float) $record['eur'];
            $numrecords++;
        }
        // Add "sum record"
        $content .= $this->_getTSSumRecord($numrecords, $totaleur);

        // Write content to outFile
        //--------------------------------------------------------------------
        $this->_saveToFile($content);

        // Check if we should save records for checksession
        //--------------------------------------------------------------------
        if($this->mSettings['saveRecords']) {
            RWEUtil::startSessionSafe();
            $session_recordsvar = $this->mSessionRecordsVarName;
            $session_datevar    = $this->mSessionDateVarName;
            $_SESSION[$session_recordsvar] = $this->mSettings['records'];
            $_SESSION[$session_datevar]    = $this->mSettings['paymentTimestamp'];
        }

        return true;
    }

    /**
     * Gets called on op checkstate.
     */
    function _op_checkstate()
    {
        RWEUtil::startSessionSafe();

        // Check that we have records in session
        //--------------------------------------------------------------------
        $session_recordsvar = $this->mSessionRecordsVarName;
        $session_datevar    = $this->mSessionDateVarName;
        if(!array_key_exists($session_recordsvar, $_SESSION) ||
           !array_key_exists($session_datevar, $_SESSION))
        {
            return false;
        }

        // Assign records, payment date .. format EUR values properly
        //--------------------------------------------------------------------
        $records  = $_SESSION[$session_recordsvar];
        $totaleur = (float) 0.0;
        $cnt      = count($records);
        for($i = 0; $i < $cnt; $i++) {
            $eurval = floatval($records[$i]['eur']);
            $records[$i]['eur'] = sprintf('%.2f', $eurval);
            $records[$i]['eur'] = str_replace('.', ',', $records[$i]['eur']);
            $totaleur += $eurval;
        }
        parent::assign('records',          $records);
        parent::assign('totalEUR',         str_replace('.', ',', sprintf('%.2f', $totaleur)));
        parent::assign('numRecords',       $cnt);
        parent::assign('paymentTimestamp', $_SESSION[$session_datevar]);

        return true;
    }

    //------------------------------------------------------------------------
    //------------------------------------------------------------------------

    /** 
     * Writes the produced TS records into a file.
     */
    function _saveToFile($content)
    {
        $outfile = $this->mSettings['outFile'];
        $fh = null;
        if($this->mRWE->getDebug()) {
            $fh = fopen($outfile, 'w');
        } else {
            $fh = @fopen($outfile, 'w');
        }
        if(false === $fh) {
            parent::exception("_saveToFile: failed to open ${outfile}");
        }
        if(false === fwrite($fh, $content)) {
            parent::exception("_saveToFile: fwrite() failed");
        }
        fclose($fh);
    }

    //------------------------------------------------------------------------
    //------------------------------------------------------------------------

    /** 
     * Returns a correctly formatted TS record out of TS data in an
     * associative-array (record_arr).
     */
    function _getTSRecord($record_arr)
    {
        if(!array_key_exists('name', $record_arr) ||
           !array_key_exists('ssno', $record_arr) ||
           !array_key_exists('bano', $record_arr) ||
           !array_key_exists('eur',  $record_arr))
        {
            $e = array('_getTSRecord: record_arr missing one or more of ' .
                       'required keys: name, ssno, bano, eur',
                       );
            parent::exception($e);
        }

        /*

        The format of the TS record row:

        Field  Descr.             Format   Length   Content
        ----------------------------------------------------------------------
        1      RecordID           integer  1        1
        2      Date               integer  6        YYMMDD
        3      Reserved           string   2
        4      Bank account no    integer  14       
        5      Payer business ID  integer  9
        6      Payment subject    integer  2        10
        7      EUR sum            integer  11       9 ints + 2 decimals
        8      Receiver name      string   19
        9      Receiver SS no     integer  11       NNNNNN-XXXX
        10     Reserved           string   5
        Total                              80

        The record row is 80 bytes (characters) long and must end with \r\n.

        */

        $ts   = $this->mSettings['paymentTimestamp'];
        $date = date("ymd", $ts);
        // unhtmlentities() name to get e.g. &auml; back to normal
        $name = RWEUtil::unHTMLEntities($record_arr['name']);
        $ssno = $record_arr['ssno'];
        $bano = $record_arr['bano'];
        $eur  = $record_arr['eur'];
        $pbid = $this->mSettings['payerBID'];

        // Build record
        //--------------------------------------------------------------------
        $record  = '1' . $date . '  ';            // RecordID, Date, Reserved
        $record .= $this->_formatBANO($bano);     // Bank account no
        $record .= $this->_formatPayerBID($pbid); // Payer's business ID
        $record .= '10';                          // Payment subject (salary)
        $record .= $this->_formatEUR($eur);       // EUR sum
        $record .= $this->_formatName($name);     // Receiver
        $record .= $ssno;                         // Receiver's ss no
        $record .= '     ';                       // Reserved

        // Check record length; must be 80
        if(($length = strlen($record)) != 80) {
            $e = array("_getTSRecord: record length is ${length}, should be 80",
                       "<b>RECORD</b>: \"${record}\"");
            parent::exception($e);
        }

        // Terminate
        $record .= "\r\n";

        return $record;
    }

    /** 
     * Returns a correctly formatted TS <b>sum</b> record.
     */
    function _getTSSumRecord($numrecords, $totaleur)
    {
        /*

        The format of the TS sum record row:

        Field  Descr.             Format   Length   Content
        ----------------------------------------------------------------------
        1      RecordID           integer  1        4
        2      Date               integer  6        YYMMDD
        3      Payer's name       string   16       OPTIONAL
        4      Payer business ID  integer  9
        5      Payment subject    integer  2        10
        6      Records' EUR sum   integer  11       9 ints + 2 decimals
        7      Currency code      integer  1        1 = euro
        8      Reserved 18        string   18
        9      Records total      integer  6
        10     Principal info     string   10       OPTIONAL
        Total                              80

        The sum record row is 80 bytes (characters) long and must end with
        \r\n.

        */

        $ts   = $this->mSettings['paymentTimestamp'];
        $date = date("ymd", $ts);
        $pbid = $this->mSettings['payerBID'];
        $nr   = $numrecords;

        // Build sum record
        //--------------------------------------------------------------------
        $sumrecord  = '4' . $date;                     // RecordID, Date
        $sumrecord .= '                ';              // Payer's name
        $sumrecord .= $this->_formatPayerBID($pbid);   // Payer's business ID
        $sumrecord .= '10';                            // Payment subject
        $sumrecord .= $this->_formatEUR($totaleur);    // Records' EUR sum
        $sumrecord .= '1';                             // Currency code
        $sumrecord .= '                  ';            // Reserved
        $sumrecord .= $this->_formatNumRecords($nr);   // Records total
        $sumrecord .= '          ';                    // Principal info

        // Check record length; must be 80
        if(($length = strlen($sumrecord)) != 80) {
            $e = array("_getTSSumRecord: record length is ${length}, should be 80",
                       "<b>RECORD</b>: \"${record}\"");
            parent::exception($e);
        }

        // Terminate
        $sumrecord .= "\r\n";

        return $sumrecord;
    }

    //------------------------------------------------------------------------
    //------------------------------------------------------------------------

    /** 
     * Formats a normal form bank-account number (nnnnnn-nnnn[...]) to TS
     * format.
     */
    function _formatBANO($bano)
    {
        /* Formatting for TS-format bank account no:

        If the first digit of the bank account number is 4 or 5 (OKO, AKTIA),
        then the first digit of the second "group" of digits must be moved
        just after the first group, after which come the "padding" zeroes.

        Otherwise the padding zeroes are added normally between the digit-
        groups.

        E.g.:

        123456-555555 -> 12345600555555

        but:

        411111-987654 -> 41111190087654

        */

        $matches = array();
        $ret     = '';

        if(preg_match("/^(\d{1,1})(\d+)-(\d{1,1})(\d+)$/", $bano, $matches)) {
            $sdigit1  = $matches[1]; // Special digit 1 (group1 first digit)
            $restgrp1 = $matches[2]; // Rest of group1 digits
            $sdigit2  = $matches[3]; // Special digit 2 (group2 first digit)
            $restgrp2 = $matches[4]; // Rest of group2 digits

            // Calc how many paddings are needed
            $length  = strlen($bano) - 1;
            $numpads = 14 - $length;
            $pads    = $this->_getPadding('0', $numpads);

            // Check if first digit is 4 or 5 and act accordingly
            if($sdigit1 == '4' || $sdigit1 == '5') {
                // Move sdigit2 and add padding zeroes
                $ret = "${sdigit1}${restgrp1}${sdigit2}${pads}${restgrp2}";
            } else {
                // Add padding zeroes normally between groups
                $ret = "${sdigit1}${restgrp1}${pads}${sdigit2}${restgrp2}";
            }
        } else {
            parent::exception('_formatBANO: couldn\'t match bank account no with regex');
        }
        return $ret;
    }

    /** 
     * Formats a business ID to TS format.
     */
    function _formatPayerBID($pbid)
    {
        /* Formatting for TS-format business ID:

        Remove dash and prepend so many zeroes that length is 9.

        E.g.: 0149254-5 -> 001492545

        */

        $matches = array();
        $ret     = '';

        if(preg_match("/^(\d+)-(\d{1,1})$/", $pbid, $matches)) {
            $digits1 = $matches[1];
            $digits2 = $matches[2];

            // Calc how many paddings are needed
            $length  = strlen($pbid) - 1;
            $numpads = 9 - $length;
            $pads    = $this->_getPadding('0', $numpads);

            $ret = $pads . $digits1 . $digits2;
        } else {
            parent::exception('_formatPayerBID: couldn\'t match payer business ID with regex');
        }

        return $ret;
    }

    /** 
     * Formats a floating-point EUR value to TS-format.
     */
    function _formatEUR($eur)
    {
        /* Formatting for TS-format EUR value:

        9 decimals (prepend needed zeroes) + 2 decimals.

        E.g.: 1015.30 -> 00000101530

        */

        $matches = array();
        $ret     = '';

        // Floating-point EUR value to string
        $streur  = sprintf('%.2f', $eur);

        if(preg_match("/^(\d+)\.(\d{2,2})$/", $streur, $matches)) {
            $ints     = $matches[1];
            $decimals = $matches[2];

            // Calc how many paddings are needed
            $length  = strlen($ints);
            $numpads = 9 - $length;
            $pads    = $this->_getPadding('0', $numpads);

            $ret = $pads . $ints . $decimals;
        } else {
            parent::exception('_formatEUR: couldn\'t match EUR value with regex');
        }
        return $ret;
    }

    function _formatName($name)
    {
        $ret = '';

        // Calc how many paddings are needed
        $length  = strlen($name);
        $numpads = 19 - $length;
        $pads    = $this->_getPadding(' ', $numpads);

        $ret = $name . $pads;

        return $ret;
    }

    function _formatNumRecords($numrecords)
    {
        $ret = '';

        // Calc how many paddings are needed
        $length  = strlen($numrecords);
        $numpads = 6 - $length;
        $pads    = $this->_getPadding('0', $numpads);

        $ret = $pads . $numrecords;

        return $ret;
    }

    function _getPadding($char, $numchars)
    {
        $ret = '';
        for($i = 0; $i < $numchars; $i++) {
            $ret .= $char;
        }
        return $ret;
    }
}

?>
