<?php

/*

RWE xmlfeeds module

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

/*******************************************************************************
 *******************************************************************************

 RSS 0.91 / RSS 2.0 NATIVE:
 - _getRSSChannelHeader:            <channel> MANDATORY elements
 - _getRSSChannelOptionalElements:  <channel> OPTIONAL  elements         (NON-MODULE)
 - _getRSSImageBlock:               <channel> OPTIONAL  <image> elements (NON-MODULE)
 - _getRSSItems:                    <item>    MANDATORY elements

 RSS 1.0 (RDF) NATIVE:
 - _getRDFChannelBlock:             <channel> MANDATORY elements
 - _getRDFImageBlock:               <channel> OPTIONAL  <image> elements (non-module except if width/height)
 - _getRDFItems:                    <item>    MANDATORY elements (also brief elems for <channel>)

 ATOM 0.3 NATIVE:
 - _getATOMFeedHeader:              <feed>    MANDATORY elements
 - _getATOMEntries:                 <entry>   MANDATORY elements
 - _getATOMFeedOptionalElements     <feed>    OPTIONAL  elements (NON-MODULE)
 - _getATOMEntryOptionalElements    <entry>   OPTIONAL  elements (NON-MODULE)


 RSS 1.0, RSS 2.0, ATOM 0.3 MODULES:
 - _getFeedOptionalModuleElements:        <channel>/<feed> OPTIONAL elements (MODULES)
 - _getFeedFavicon:                       <channel>/<feed> OPTIONAL favicon  (MODULES)
 - _getOptionalItemModuleElementsForRow:  <item>/<entry>   OPTIONAL elements (MODULES)

 ******************************************************************************
 ******************************************************************************/

/**
 * A module for producing xml feeds out of database data.
 *
 * @todo Add modifiedColumn.
 * @todo Add bodyColumn (full posts).
 * @todo Support more RSS and ATOM properties and modules.
 * @todo Check whether any elements could be written natively instead of using modules.
 */
class Module_xmlfeeds extends Module
{
    /*! @protectedsection */
    /* ==================================================================== */
    /// List of valid XML formats.
    var $mValidXMLFormats;
    /// Holds full namespace declarations for shorthand module names.
    var $mNamespaceDeclTbl;

    /*! @privatesection */
    /* ==================================================================== */
    /// Holds the full path to the XML file to save the output to.
    var $mXMLFileFullPath;
    /// Contains optional namespaces that should be added.
    var $mAddNamespaces;
    /// Row cache.
    var $mRowCache;

    /*! @publicsection */
    /* ==================================================================== */

    /** 
     * Constructor.
     */
    function Module_xmlfeeds(&$rwe)
    {
        // List of valid settings accepted by this module
        $vs = array(// General
                    //--------------------------------------------------------
                    'xmlformat'                => null,
                    'saveAs'                   => null,
                    'saveTo'                   => null,
                    'tableName'                => null,
                    'xmlEncoding'              => null,
                    'itemColumns'              => array('titleColumn'       => null,
                                                        'descriptionColumn' => null,
                                                        'linkColumn'        => null,
                                                        // RSS 1.0 / RSS 2.0 / ATOM 0.3
                                                        'posterColumn'      => null,
                                                        'timestampColumn'   => null,
                                                        'categoryColumn'    => null,
                                                        'modifiedColumn'    => null,
                                                        // ATOM 0.3 (required)
                                                        'entryIDSpecificColumn' => null,
                                                        ),
                    'itemsStartpos'            => null,
                    'itemsLimit'               => null,
                    'itemsOrderBy'             => null,
                    'itemsCondition'           => null,
                    'itemLinkPrefix'           => null,
                    'itemLinkPostfix'          => null,
                    'cutDescription'           => false,
                    'cutDescLength'            => 200,
                    'cutDescPostfix'           => '...',
                    'cutDescBreakWord'         => false,

                    // Common to all xmlformats
                    //--------------------------------------------------------
                    'feedTitle'                => null,
                    'feedLink'                 => null,
                    'feedDescription'          => null,
                    'feedLanguage'             => null,
                    'feedCopyright'            => null,
                    'feedImage'                => false,
                    'feedImageTitle'           => null,
                    'feedImageURL'             => null,
                    'feedImageLink'            => null,
                    'feedImageWidth'           => null,
                    'feedImageHeight'          => null,
                    'feedImageDescription'     => null,

                    // RSS 0.91
                    //--------------------------------------------------------
                    'feedWebmaster'            => null,

                    // RSS 1.0
                    //--------------------------------------------------------
                    'feedAboutURL'             => null,

                    // RSS 1.0 / RSS 2.0
                    //--------------------------------------------------------
                    // Only used with timestampColumn
                    'itemDateFormat'           => null,

                    // RSS 0.91 / RSS 1.0 / RSS 2.0
                    //--------------------------------------------------------
                    'feedWriteLastModified'    => false,

                    // RSS 1.0 / RSS 2.0 / ATOM 0.3
                    //--------------------------------------------------------
                    'feedAuthor'               => null,
                    'feedFavicon'              => null,
                    'feedFaviconSize'          => null,
                    'feedFaviconTitle'         => null,

                    // ATOM 0.3
                    //--------------------------------------------------------
                    'entryIDAuthorityName'     => null,
                    );

        $this->Module($rwe, 'xmlfeeds', $vs);

        $this->mValidXMLFormats = array('rss091', 'rss1', 'rss2', 'atom03');
        $this->mTmpFileExt      = '.tmp';

        /* Full namespace declarations for the shorthand keys that can be
           added using Module_xmlfeeds::_addNamespace('<shorthand_key>') */
        $this->mNamespaceDeclTbl = array('dc'     => 'xmlns:dc="http://purl.org/dc/elements/1.1/"',
                                         'rss091' => 'xmlns:rss091="http://purl.org/rss/1.0/modules/rss091#"',
                                         'image'  => 'xmlns:image="http://purl.org/rss/1.0/modules/image/"',
                                         'rdf'    => 'xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"',
                                         );
    }

    /** Overridden from Module. */
    function reset()
    {
        $this->mRowCache      = null;
        $this->mAddNamespaces = array();

        parent::reset();
    }

    /*! @protectedsection */
    /* ==================================================================== */

    /** 
     * Checks all required settings.
     */
    function _checkSettings()
    {
        $xmlformat = $this->mSettings['xmlformat'];

        // Check that xmlformat is valid
        if(!in_array($xmlformat, $this->mValidXMLFormats)) {
            $validxmlformats = implode(', ', $this->mValidXMLFormats);
            parent::exception('_checkSettings: invalid xmlformat ' .
                              "(${xmlformat}), valid xmlformats are: " .
                              "${validxmlformats}");
        }

        if(!parent::hasValidDbMgr()) {
            parent::exception('_checkSettings: no database manager present');
        }

        // Check and reformat tableName so it can be included in a query
        $t = null;
        if(($t = parent::settingToString('tableName', false)) === false) {
            parent::exception('_checkSettings: invalid tableName');
        }
        $this->mSettings['tableName'] = $t;

        $this->mXMLFileFullPath = $this->_getXMLFileFullPath();
        if(!$this->mXMLFileFullPath) {
            // false means user provided invalid/missing saveAs
            parent::exception('_checkSettings: invalid/missing saveAs');
        }

        $fullpath = $this->mXMLFileFullPath;

        if(file_exists($fullpath) && !is_writable($fullpath)) {
            parent::exception('_checkSettings: destination file ' .
                              "${fullpath} is not writable");
        }
        $tmpfile = $fullpath . $this->mTmpFileExt;
        if(file_exists($tmpfile) && !is_writable($tmpfile)) {
            parent::exception('_checkSettings: temporary output file ' .
                              "${tmpfile} is not writable");
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

        // Connect to database; will reuse existing link if already connected
        $dbmgr = &parent::getDbMgr();
        if(!$dbmgr->connect()) {
            $e = array('executeModule: failed to establish database connection:',
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }

        $xmlformat = $this->mSettings['xmlformat'];

        switch($xmlformat) {
        case 'rss091':
            return $this->_xmlformat_rss091();
        case 'rss1':
            return $this->_xmlformat_rss1();
        case 'rss2':
            return $this->_xmlformat_rss2();
        case 'atom03':
            return $this->_xmlformat_atom03();
        default:
            // Should never happen
            return false;
        }
    }

    /*! @protectedsection */
    /* ==================================================================== */

    /**
     * Generates RSS 0.91
     */
    function _xmlformat_rss091()
    {
        // Header
        //--------------------------------------------------------------------
        $content  = $this->_getXMLProlog();
        $content .= "<!DOCTYPE rss PUBLIC \"-//Netscape Communications//DTD RSS 0.91//EN\"\n";
        $content .= "    \"http://my.netscape.com/publish/formats/rss-0.91.dtd\">\n";
        $content .= "<rss version=\"0.91\">\n";
        $content .= "  <channel>\n";
        $content .= $this->_getRSSChannelHeader();

        // Items
        //--------------------------------------------------------------------
        $content .= $this->_getRSSItems();

        // Footer
        //--------------------------------------------------------------------
        $content .= "  </channel>\n";
        $content .= "</rss>\n";

        // Write to file
        //--------------------------------------------------------------------
        $this->_saveToFile($content);

        return true;
    }

    /**
     * Generates RSS 1.0 (RDF).
     */
    function _xmlformat_rss1()
    {
        // Get <channel>, <image> and <item> blocks first as they might add to
        // mAddNamespaces and therefore affect the _addNeededNamespaces() call
        // below.
        //--------------------------------------------------------------------
        $chanblock  = $this->_getRDFChannelBlock();;
        $imageblock = $this->_getRDFImageBlock();
        $items      = $this->_getRDFItems();

        // Header
        //--------------------------------------------------------------------
        $content  = $this->_getXMLProlog();
        $content .= "<rdf:RDF\n";
        $content .= "    xmlns=\"http://purl.org/rss/1.0/\"\n";
        $content .= "    xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\"\n";
        $content .= $this->_addNeededNamespaces();
        $content .= ">\n";
        $content .= $chanblock;
        $content .= $imageblock;

        // Items
        //--------------------------------------------------------------------
        $content .= $items;

        // Footer
        //--------------------------------------------------------------------
        $content .= "</rdf:RDF>\n";

        // Write to file
        //--------------------------------------------------------------------
        $this->_saveToFile($content);

        return true;
    }

    /**
     * Generates RSS 2.0
     */
    function _xmlformat_rss2()
    {
        // Get <channel> and <item> blocks first as they might add to
        // mAddNamespaces and therefore affect the _addNeededNamespaces() call
        // below.
        //--------------------------------------------------------------------
        $chanblock  = $this->_getRSSChannelHeader();
        $items      = $this->_getRSSItems();

        // Header
        //--------------------------------------------------------------------
        $content  = $this->_getXMLProlog();
        $content .= "<rss version=\"2.0\"\n";
        $content .= $this->_addNeededNamespaces();
        $content .= ">\n";
        $content .= "  <channel>\n";
        $content .= $chanblock;

        // Items
        //--------------------------------------------------------------------
        $content .= $items;

        // Footer
        //--------------------------------------------------------------------
        $content .= "  </channel>\n";
        $content .= "</rss>\n";

        // Write to file
        //--------------------------------------------------------------------
        $this->_saveToFile($content);

        return true;
    }

    /**
     * Generates ATOM 0.3
     */
    function _xmlformat_atom03()
    {
        // Get header and <item> blocks first as they might add to
        // mAddNamespaces and therefore affect the _addNeededNamespaces() call
        // header below.
        //--------------------------------------------------------------------
        $feedheader = $this->_getATOMFeedHeader();
        $entries    = $this->_getATOMEntries();

        // Language for xml:lang
        $language   = $this->_getLanguage();

        // Header
        //--------------------------------------------------------------------
        $content  = $this->_getXMLProlog();
        $content .= "<feed version=\"0.3\"\n";
        $content .= "    xmlns=\"http://purl.org/atom/ns#\"\n";
        $content .= $this->_addNeededNamespaces();
        $content .= "    xml:lang=\"${language}\"\n";
        $content .= ">\n";
        $content .= $feedheader;

        // Entries
        //--------------------------------------------------------------------
        $content .= $entries;

        // Footer
        //--------------------------------------------------------------------
        $content .= "</feed>\n";

        // Write to file
        //--------------------------------------------------------------------
        $this->_saveToFile($content);

        return true;

    }



    /*
     * =======================================================================
     *
     * RSS 0.91 / 2.0 utility functions
     *
     * =======================================================================
     */



    /** 
     * Returns elements for the RSS 0.91 / RSS 2.0 &lt;channel&gt; block.
     *
     * @remarks Will look at xmlFormat to see if the format is 'rss2', in which
     * case will also include additional elements requiring modules/namespaces
     * in order to apply all possible settings.
     */
    function _getRSSChannelHeader()
    {
        $xmlformat = $this->mSettings['xmlformat'];
        $chanblock = '';
        $b         = '    '; // Base indentation

        // Check required <channel> sub-elements
        //--------------------------------------------------------------------
        if(!$this->_checkRequiredFeedSettings()) {
            $e = array('_getRSSChannelHeader: missing one or more of ' .
                       'required channel sub-elements:',
                       'feedTitle, feedLink, feedDescription',
                       );
            parent::exception($e);
        }

        // Gather variables
        //--------------------------------------------------------------------
        $title = $this->mSettings['feedTitle'];
        $link  = $this->mSettings['feedLink'];
        $desc  = $this->mSettings['feedDescription'];
        $lang  = $this->_getLanguage();

        // Build block
        //--------------------------------------------------------------------
        $chanblock .= $b . "<title>${title}</title>\n";
        $chanblock .= $b . "<link>${link}</link>\n";
        $chanblock .= $b . "<description>${desc}</description>\n";
        $chanblock .= $b . "<language>${lang}</language>\n";
        // Optional elements
        $chanblock .= $this->_getRSSChannelOptionalElements();
        $chanblock .= ($xmlformat == 'rss2') ? $this->_getFeedOptionalModuleElements($b) : '';
        $chanblock .= $this->_getRSSImageBlock();

        return $chanblock;
    }

    function _getRSSChannelOptionalElements()
    {
        $optelems  = '';
        $b = '    '; // Base indentation

        // feedCopyright
        $copyright = $this->mSettings['feedCopyright'];
        if(!empty($copyright)) {
            $optelems .= $b . "<copyright>${copyright}</copyright>\n";
        }

        // feedWebmaster
        $webmaster = $this->mSettings['feedWebmaster'];
        if(!empty($webmaster)) {
            $optelems .= $b . "<webMaster>${webmaster}</webMaster>\n";
        }

        // feedWriteLastModified
        $writelastmod = $this->mSettings['feedWriteLastModified'];
        if($writelastmod) {
            $lastmod   = date('r', time());
            $optelems .= $b . "<lastBuildDate>${lastmod}</lastBuildDate>\n";
        }

        return $optelems;
    }

    /** 
     * If the feedImage setting is provided, returns the &lt;image&gt; element
     * for RSS 0.91 / RSS 2.0. Otherwise returns an empty string.
     */
    function _getRSSImageBlock()
    {
        $imgblock    = '';
        $hasimage    = $this->mSettings['feedImage'];
        $b           = '    '; // Base indentation
        if($hasimage) {
            // Gather variables
            //----------------------------------------------------------------
            $imgtitle  = $this->mSettings['feedImageTitle'];
            $imgurl    = $this->mSettings['feedImageURL'];
            $imglink   = $this->mSettings['feedImageLink'];
            $imgwidth  = $this->mSettings['feedImageWidth'];
            $imgheight = $this->mSettings['feedImageHeight'];
            $imgdesc   = $this->mSettings['feedImageDescription'];
            // Check required <image> tags
            if(empty($imgtitle) || empty($imgurl) || empty($imglink)) {
                $e = array('_getRSSImageBlock: missing one or more of ' .
                           'required settings (for feedImage):',
                           'feedImageTitle, feedImageURL, feedImageLink',
                           );
                parent::exception($e);
            }
            // Build block
            //----------------------------------------------------------------
            $imgblock .= $b . "<image>\n";
            $imgblock .= $b . "  <title>${imgtitle}</title>\n";
            $imgblock .= $b . "  <url>${imgurl}</url>\n";
            $imgblock .= $b . "  <link>${imglink}</link>\n";
            // Width, default to 88
            if(is_numeric($imgwidth) && $imgwidth > 0) {
                $imgblock .= $b . "  <width>{$imgwidth}</width>\n";
            } else {
                $imgblock .= $b . "  <width>88</width>\n";
            }
            // Height, default to 31
            if(is_numeric($imgheight) && $imgheight > 0) {
                $imgblock .= $b . "  <height>{$imgheight}</height>\n";
            } else {
                $imgblock .= $b . "  <height>31</height>\n";
            }
            // Description, default to '[image]'
            if(!empty($imgdesc)) {
                $imgblock .= $b . "  <description>${imgdesc}</description>\n";
            } else {
                $imgblock .= $b . "  <description>[image]</description>\n";
            }
            $imgblock .= $b . "</image>\n";
        }
        return $imgblock;
    }

    /** 
     * Returns RSS 0.91 / RSS 2.0 &lt;item&gt; elements.
     *
     * @remarks Will look at xmlFormat to see if the format is 'rss2', in which
     * case will also include additional elements requiring modules/namespaces
     * in order to apply all possible settings.
     */
    function _getRSSItems()
    {
        $xmlformat = $this->mSettings['xmlformat'];

        // Fetch items
        //--------------------------------------------------------------------
        $this->_fetchItemsFromDBToCache();

        // Write items to string
        //--------------------------------------------------------------------
        $titlecol = $this->mSettings['itemColumns']['titleColumn'];
        $desccol  = $this->mSettings['itemColumns']['descriptionColumn'];
        $linkcol  = $this->mSettings['itemColumns']['linkColumn'];
        // Get real column names
        $dbmgr    = &parent::getDbMgr();
        $titlecol = $dbmgr->getRealColumnName($titlecol);
        $desccol  = $dbmgr->getRealColumnName($desccol);
        $linkcol  = $dbmgr->getRealColumnName($linkcol);
        // Base indentation
        $b     = '    ';
        $items = '';
        foreach($this->mRowCache as $row) {
            $title  = $this->_xmlEntities($row[$titlecol]);
            // Process link
            $link   = $this->_processLink($row[$linkcol]);
            // Process description
            $desc   = $this->_processDescription($row[$desccol]);
            $items .= $b . "<item>\n";
            $items .= $b . "  <title>${title}</title>\n";
            $items .= $b . "  <link>${link}</link>\n";
            $items .= $b . "  <description>${desc}</description>\n";
            // For RSS 2.0 get additional elements
            $items .= ($xmlformat == 'rss2') ? $this->_getOptionalItemElementsForRow($row, $b . '  ') : '';
            $items .= ($xmlformat == 'rss2') ? $this->_getOptionalItemModuleElementsForRow($row, $b . '  ') : '';
            $items .= $b . "</item>\n";
        }

        return $items;
    }

    function _getOptionalItemElementsForRow($row = null, $base_indentation)
    {
        $xmlformat = $this->mSettings['xmlformat'];
        $optelems  = '';
        $b         = $base_indentation;

        // Check we have rows in cache
        if(is_null($row)) {
            parent::exception('_getOptionalItemElementsForRow: row == null');
        }

        // Get optional columns
        //--------------------------------------------------------------------
        $tstampcol = $this->mSettings['itemColumns']['timestampColumn'];
        $catcol    = $this->mSettings['itemColumns']['categoryColumn'];
        // Get real column names
        $dbmgr     = &parent::getDbMgr();
        $tstampcol = $dbmgr->getRealColumnName($tstampcol);
        $catcol    = $dbmgr->getRealColumnName($catcol);

        // Write elements based on what columns and xmlformat we have
        //--------------------------------------------------------------------
        // timestampColumn
        if(!empty($tstampcol)) {
            $pubdate   = date("r", $row[$tstampcol]);
            $optelems .= $b . "<pubDate>${pubdate}</pubDate>\n";
        }

        // categoryColumn
        if(!empty($catcol)) {
            $category  = $row[$catcol];
            $optelems .= $b . "<category>${category}</category>\n";
        }

        return $optelems;
    }


    /*
     * =======================================================================
     *
     * RSS 1.0 (RDF) utility functions
     *
     * =======================================================================
     */



    /** 
     * Returns the RSS 1.0 &lt;channel&gt; block
     */
    function _getRDFChannelBlock()
    {
        $chanblock  = '';
        $optelems   = '';
        $b          = '  '; // Base indentation

        // Check required <channel> sub-elements
        //--------------------------------------------------------------------
        if(!$this->_checkRequiredFeedSettings()) {
            parent::exception('_getRDFChannelBlock: missing one or more of ' .
                              'required channel sub-elements: feedAboutURL, ' .
                              'feedTitle, feedLink and/or feedDescription');
        }

        // Gather variables
        //--------------------------------------------------------------------
        $abouturl = $this->mSettings['feedAboutURL'];
        $title    = $this->mSettings['feedTitle'];
        $link     = $this->mSettings['feedLink'];
        $desc     = $this->mSettings['feedDescription'];

        // Build block
        //--------------------------------------------------------------------
        $chanblock .= $b . "<channel rdf:about=\"${abouturl}\">\n";
        $chanblock .= $b . "  <title>${title}</title>\n";
        $chanblock .= $b . "  <link>${link}</link>\n";
        $chanblock .= $b . "  <description>${desc}</description>\n";
        $chanblock .= $this->_getFeedOptionalModuleElements($b . '  ');
        $chanblock .= $this->_getRDFImageBlock($b . '  ', true); // true == brief tag
        $chanblock .= $this->_getRDFItems($b . '  ', true); // true == brief items
        $chanblock .= $b . "</channel>\n";

        return $chanblock;
    }

    /** 
     * Returns the RSS 1.0 &lt;image&gt; block (outside of &lt;channel&gt;)
     */
    function _getRDFImageBlock($base_indentation = '  ', $brief = false)
    {
        $imageblock = '';
        $hasimage   = $this->mSettings['feedImage'];
        $b          = $base_indentation;
        if($hasimage) {
            // Gather variables
            //----------------------------------------------------------------
            $imgtitle   = $this->mSettings['feedImageTitle'];
            $imgurl     = $this->mSettings['feedImageURL'];
            $imglink    = $this->mSettings['feedImageLink'];
            $imgwidth   = $this->mSettings['feedImageWidth'];
            $imgheight  = $this->mSettings['feedImageHeight'];
            $imgdesc    = $this->mSettings['feedImageDescription'];

            // Brief: <image rdf:resource> tag inside channel
            //----------------------------------------------------------------
            if($brief) {
                $imageblock = $b . "<image rdf:resource=\"${imgurl}\" />\n";
                return $imageblock;
            }

            //----------------------------------------------------------------
            //----------------------------------------------------------------

            // Check required <image> tags
            if(empty($imgtitle) || empty($imgurl) || empty($imglink)) {
                $e = array('_getRDFImageBlock: missing one or more of ' .
                           'required settings (for feedImage):',
                           'feedImageTitle, feedImageURL, feedImageLink',
                           );
                parent::exception($e);
            }
            // Build block
            //----------------------------------------------------------------
            $imageblock .= $b . "<image rdf:about=\"${imgurl}\">\n";
            $imageblock .= $b . "  <title>${imgtitle}</title>\n";
            $imageblock .= $b . "  <url>${imgurl}</url>\n";
            $imageblock .= $b . "  <link>${imglink}</link>\n";
            // If we have either width or height, and other of them is
            // different than 88 / 31 (respectively), then we need the RSS091
            // module to be able to specify the dimensions. We also need it if
            // we have a description for the image.
            if((is_numeric($imgwidth) && $imgwidth > 0 && $imgwidth != 88) ||
               (is_numeric($imgheight) && $imgheight > 0 && $imgheight != 31) ||
               !empty($imgdesc))
            {
                $this->_addNamespace('rss091');
                // Width, default to 88
                if(is_numeric($imgwidth) && $imgwidth > 0) {
                    $imageblock .= $b . "  <rss091:width>{$imgwidth}</rss091:width>\n";
                } else {
                    $imageblock .= $b . "  <rss091:width>88</rss091:width>\n";
                }
                // Height, default to 31
                if(is_numeric($imgheight) && $imgheight > 0) {
                    $imageblock .= $b . "  <rss091:height>{$imgheight}</rss091:height>\n";
                } else {
                    $imageblock .= $b . "  <rss091:height>31</rss091:height>\n";
                }
                // Description, default to '[image]'
                if(!empty($imgdesc)) {
                    $imageblock .= $b . "  <rss091:description>${imgdesc}</rss091:description>\n";
                } else {
                    $imageblock .= $b . "  <rss091:description>[image]</rss091:description>\n";
                }
            }
            $imageblock .= $b . "</image>\n";
        }
        return $imageblock;
    }

    function _getRDFItems($base_indentation = '  ', $briefitems = false)
    {
        $items = '';
        $b     = $base_indentation;

        // Fetch items
        //--------------------------------------------------------------------
        $this->_fetchItemsFromDBToCache();

        // Write items to string
        //--------------------------------------------------------------------
        $titlecol = $this->mSettings['itemColumns']['titleColumn'];
        $desccol  = $this->mSettings['itemColumns']['descriptionColumn'];
        $linkcol  = $this->mSettings['itemColumns']['linkColumn'];
        // Get real column names
        $dbmgr    = &parent::getDbMgr();
        $titlecol = $dbmgr->getRealColumnName($titlecol);
        $desccol  = $dbmgr->getRealColumnName($desccol);
        $linkcol  = $dbmgr->getRealColumnName($linkcol);

        // Brief items for <channel>
        //--------------------------------------------------------------------
        if($briefitems) {
            $items .= $b . "<items>\n";
            $items .= $b . "  <rdf:Seq>\n";
            foreach($this->mRowCache as $row) {
                // Process link
                $link   = $this->_processLink($row[$linkcol]);
                $items .= $b . "    <rdf:li resource=\"${link}\" />\n";
            }
            $items .= $b . "  </rdf:Seq>\n";
            $items .= $b . "</items>\n";
        }

        // Full items
        //--------------------------------------------------------------------
        else {
            // Return full items, outside <channel>
            foreach($this->mRowCache as $row) {
                $title  = $this->_xmlEntities($row[$titlecol]);
                // Process link
                $link   = $this->_processLink($row[$linkcol]);
                // Process description
                $desc   = $this->_processDescription($row[$desccol]);
                $items .= $b . "<item rdf:about=\"${link}\">\n";
                $items .= $b . "  <title>${title}</title>\n";
                $items .= $b . "  <link>${link}</link>\n";
                $items .= $b . "  <description>${desc}</description>\n";
                // Get additional elements using modules/namespaces
                $items .= $this->_getOptionalItemModuleElementsForRow($row, $b . '  ');
                $items .= $b . "</item>\n";
            }
        }

        return $items;
    }



    /*
     * =======================================================================
     *
     * Common utility functions for all xml formats
     *
     * =======================================================================
     */



    /** 
     * For xmlformats that support modules/namespaces, gets the additional
     * elements that require those based on settings.
     */
    function _getFeedOptionalModuleElements($base_indentation)
    {
        $xmlformat = $this->mSettings['xmlformat'];
        $optelems  = '';
        $b         = $base_indentation;

        // Don't re-apply feedAuthor for ATOM 0.3 (it uses <author>)
        if($xmlformat != 'atom03') {
            // feedAuthor
            $creator = $this->mSettings['feedAuthor'];
            if(!empty($creator)) {
                $optelems .= $b . "<dc:creator>${creator}</dc:creator>\n";
                $this->_addNamespace('dc');
            }
        }

        // These only for RSS 1.0
        if($xmlformat == 'rss1') {
            // feedCopyright
            $copy = $this->mSettings['feedCopyright'];
            if(!empty($copy)) {
                $optelems .= $b . "<dc:rights>${copy}</dc:rights>\n";
                $this->_addNamespace('dc');
            }

            // feedLanguage
            $lang = $this->mSettings['feedLanguage'];
            if(!empty($lang)) {
                $optelems .= $b . "<dc:language>${lang}</dc:language>\n";
                $this->_addNamespace('dc');
            }

            // feedWriteLastModified
            $writelastmod = $this->mSettings['feedWriteLastModified'];
            if($writelastmod) {
                $lastmod = RWEUtil::iso8601Date(time());
                $optelems .= $b . "<dc:date>${lang}</dc:date>\n";
                $this->_addNamespace('dc');
            }
        }

        // feedFavicon
        $optelems .= $this->_getFeedFavicon($b);

        return $optelems;
    }

    function _getFeedFavicon($base_indentation)
    {
        $xmlformat  = $this->mSettings['xmlformat'];
        $b          = $base_indentation;
        $favicoelem = '';
        $favico     = $this->mSettings['feedFavicon'];
        if(!empty($favico)) {
            // Get size, default to small
            $faviconsize = 'small';
            $fisize      = $this->mSettings['feedFaviconSize'];
            if($fisize == 'medium') {
                $faviconsize = 'medium';
            } else if($fisize == 'large') {
                $faviconsize = 'large';
            }
            // Title, default to [favicon]
            $favicotitle = '[favicon]';
            $fititle = $this->mSettings['feedFaviconTitle'];
            if(!empty($fititle)) {
                $favicotitle = $fititle;
            }
            $favicoelem .= $b . "<image:favicon rdf:about=\"${favico}\" image:size=\"${faviconsize}\">\n";
            $favicoelem .= $b . "  <dc:title>${favicotitle}</dc:title>\n";
            $favicoelem .= $b . "</image:favicon>\n";
            $this->_addNamespace('dc');
            $this->_addNamespace('image');
            // Add rdf namespace if we're generating RSS 2.0 or ATOM 0.3
            if($xmlformat == 'rss2' || $xmlformat == 'atom03') {
                $this->_addNamespace('rdf');
            }
        }
        return $favicoelem;
    }

    /** 
     * Fetches rows from database into a cache (mRowCache) to be used later
     * when writing feed items.
     *
     * @remarks If xmlFormat is other than rss091, then - based on provided
     * settings - may also include additional columns requiring custom
     * modules/namespaces.
     */
    function _fetchItemsFromDBToCache()
    {
        $xmlformat = $this->mSettings['xmlformat'];

        // Check if cache is already filled
        if(!empty($this->mRowCache)) {
            return;
        }

        $titlecol  = $this->mSettings['itemColumns']['titleColumn'];
        $desccol   = $this->mSettings['itemColumns']['descriptionColumn'];
        $linkcol   = $this->mSettings['itemColumns']['linkColumn'];
        $postercol = $this->mSettings['itemColumns']['posterColumn'];
        $tstampcol = $this->mSettings['itemColumns']['timestampColumn'];
        $catcol    = $this->mSettings['itemColumns']['categoryColumn'];
        $idspeccol = $this->mSettings['itemColumns']['entryIDSpecificColumn'];

        // Check required itemColumns
        //--------------------------------------------------------------------

        // ATOM 0.3 required
        if($xmlformat == 'atom03' &&
           (empty($titlecol) || empty($desccol) ||
            empty($linkcol)  || empty($tstampcol) ||
            empty($idspeccol)))
        {
            $e = array('_fetchItemsFromDBToCache: missing one or more of ' .
                       'required itemColumns:',
                       'titleColumn, linkColumn, timestampColumn,',
                       'entryIDSpecificColumn',
                       );
            parent::exception($e);
        }
        // Other required
        else if(empty($titlecol) || empty($desccol) || empty($linkcol)) {
            $e = array('_fetchItemsFromDBToCache: missing one or more of ' .
                       'required itemColumns:',
                       'titleColumn, descriptionColumn, linkColumn',
                       );
            parent::exception($e);
        }

        // Gather vars that affect the query to build
        //--------------------------------------------------------------------
        // Defaults
        $startpos  = 0;
        $limit     = 10;
        // itemsStartpos
        $sp = $this->mSettings['itemsStartpos'];
        if(is_numeric($sp) && $sp >= 0) {
            $startpos = $sp;
        }
        // itemsLimit
        $l = $this->mSettings['itemsLimit'];
        if(is_numeric($l) && $l >= 0) {
            $limit = $l;
        }

        // Build, prepare & execute query
        //--------------------------------------------------------------------
        $dbmgr = &parent::getDbMgr();
        $t     = $this->mSettings['tableName'];
        $query = "SELECT ${titlecol}, ${linkcol}";
        // Add timestampColumn and entryIDSpecificColumn in ATOM 0.3, descriptionColumn in others
        if($xmlformat == 'atom03') {
           $query .= ", ${tstampcol}, ${idspeccol}";
        } else {
           $query .= ", ${desccol}";
        }
        // Add optional columns if we have them
        if($xmlformat != 'rss091') {
            // posterColumn
            if(!empty($postercol)) {
                $query .= ", ${postercol}";
            }
            if($xmlformat != 'atom03') {
                // timestampColumn, optional in RSS 1.0 / 2.0
                if(!empty($tstampcol)) {
                    $query .= ", ${tstampcol}";
                }
            } else {
                // descriptionColumn, optional in ATOM 0.3
                if(!empty($desccol)) {
                    $query .= ", ${desccol}";
                }
            }
            // categoryColumn
            if(!empty($catcol)) {
                $query .= ", ${catcol}";
            }
        }
        $query .= " FROM ${t}";
        if(!empty($this->mSettings['itemsCondition'])) {
            $cond   = $this->mSettings['itemsCondition'];
            $query .= " WHERE ${cond}";
        }
        $orderby = parent::settingToString('itemsOrderBy', '', true);
        if(!empty($orderby)) {
            $query .= " ORDER BY ${orderby}";
        }
        $query .= ' ' . $dbmgr->getLimitClause($limit, $startpos);
        $stmt   = $dbmgr->prepare($query);
        if(!$stmt->execute()) {
            $e = array('_fetchItemsFromDBToCache: failed to execute query',
                       "<b>QUERY</b>: ${query}",
                       '<b>REASON</b>: ' . $dbmgr->getLastErrorFullString());
            parent::exception($e);
        }

        // Fill cache
        $this->mRowCache = $stmt->fetchAllAssoc();
    }

    function _getOptionalItemModuleElementsForRow($row = null, $base_indentation)
    {
        $xmlformat = $this->mSettings['xmlformat'];
        $modelems  = '';
        $b         = $base_indentation;

        // Check we have rows in cache
        if(is_null($row)) {
            parent::exception('_getOptionalItemModuleElementsForRow: row == null');
        }

        // Get optional columns
        //--------------------------------------------------------------------
        $postercol = $this->mSettings['itemColumns']['posterColumn'];
        $tstampcol = $this->mSettings['itemColumns']['timestampColumn'];
        $catcol    = $this->mSettings['itemColumns']['categoryColumn'];
        // Get real column names
        $dbmgr     = &parent::getDbMgr();
        $postercol = $dbmgr->getRealColumnName($postercol);
        $tstampcol = $dbmgr->getRealColumnName($tstampcol);
        $catcol    = $dbmgr->getRealColumnName($catcol);

        // Write elements based on what columns and xmlformat we have
        //--------------------------------------------------------------------
        // ATOM 0.3 supports the following natively, don't apply
        if($xmlformat != 'atom03') {
            // posterColumn
            if(!empty($postercol)) {
                $creator   = $row[$postercol];
                $modelems .= $b . "<dc:creator>${creator}</dc:creator>\n";
                $this->_addNamespace('dc');
            }

            // RSS 2.0 supports the following natively, don't apply
            if($xmlformat != 'rss2') {
                // timestampColumn
                if(!empty($tstampcol) && is_numeric($row[$tstampcol])) {
                    $fmt  = $this->mSettings['itemDateFormat'];
                    $date = null;
                    // See which format to add the date in
                    if($fmt == 'datetime') {
                        $date = RWEUtil::iso8601Date($row[$tstampcol]);
                    } else {
                        $date = date('Y-m-d', $row[$tstampcol]);
                    }
                    $modelems .= $b . "<dc:date>${date}</dc:date>\n";
                    $this->_addNamespace('dc');
                }
            }
        }

        // RSS 2.0 supports the following natively, don't apply
        if($xmlformat != 'rss2') {
            // categoryColumn
            if(!empty($catcol)) {
                $subject   = $row[$catcol];
                $modelems .= $b . "<dc:subject>${subject}</dc:subject>\n";
                $this->_addNamespace('dc');
            }
        }

        return $modelems;
    }

    /** 
     * Returns the feed language as given by the user, or 'en-US'
     * if the feedLanguage setting has not been provided.
     */
    function _getLanguage()
    {
        $l = $this->mSettings['feedLanguage'];
        return empty($l) ? 'en-US' : $l;
    }

    function _checkRequiredFeedSettings()
    {
        $xmlformat = $this->mSettings['xmlformat'];
        $ok        = false;

        $abouturl = $this->mSettings['feedAboutURL'];
        $title    = $this->mSettings['feedTitle'];
        $link     = $this->mSettings['feedLink'];
        $desc     = $this->mSettings['feedDescription'];
        $author   = $this->mSettings['feedAuthor'];

        if($xmlformat == 'rss1') {
            $ok = (empty($abouturl) || empty($title) ||
                   empty($link)     || empty($desc)) ? false : true;
        } else if($xmlformat == 'atom03') {
            $ok = (empty($author) || empty($title) ||
                   empty($link)) ? false : true;
        } else {
            $ok = (empty($title) || empty($link) ||
                   empty($desc)) ? false : true;
        }
        return $ok;
    }

    function _getXMLProlog()
    {
        $ret = "<?xml version=\"1.0\" ?>\n";
        // Overwrite if we have encoding
        $xmlenc = $this->mSettings['xmlEncoding'];
        if(!empty($xmlenc)) {
            $ret = "<?xml version=\"1.0\" encoding=\"${xmlenc}\" ?>\n";
        }
        return $ret;
    }


    /*
     * =======================================================================
     *
     * ATOM 0.3 utility functions
     *
     * =======================================================================
     */



    /** 
     * Returns the ATOM 0.3 feed header.
     */
    function _getATOMFeedHeader()
    {
        $feedheader = '';
        $optelems   = '';
        $b          = '  '; // Base indentation

        // Check required <feed> header sub-elements
        //--------------------------------------------------------------------
        if(!$this->_checkRequiredFeedSettings()) {
            $e = array('_getATOMFeedHeader: missing one or more of ' .
                       'required channel sub-elements:',
                       'feedTitle, feedLink, feedDescription, feedAuthor',
                       );
            parent::exception($e);
        }

        // Gather variables
        //--------------------------------------------------------------------
        $title    = $this->mSettings['feedTitle'];
        $link     = $this->mSettings['feedLink'];
        $author   = $this->mSettings['feedAuthor'];
        $modified = RWEUtil::iso8601Date(time(), true);

        // Build block
        //--------------------------------------------------------------------
        $feedheader .= $b . "<title>${title}</title>\n";
        $feedheader .= $b . "<link rel=\"alternate\" type=\"text/html\" href=\"${link}\" />\n";
        $feedheader .= $b . "<author>\n";
        $feedheader .= $b . "  <name>${author}</name>\n";
        $feedheader .= $b . "</author>\n";
        $feedheader .= $b . "<modified>${modified}</modified>\n";
        // Optional elements
        $feedheader .= $this->_getATOMFeedOptionalElements($b);
        $feedheader .= $this->_getFeedOptionalModuleElements($b);

        return $feedheader;
    }

    /** 
     * Returns the &lt;entry&gt; elements for ATOM 0.3.
     */
    function _getATOMEntries()
    {
        $entries = '';
        $b       = '  ';

        // Fetch items
        //--------------------------------------------------------------------
        $this->_fetchItemsFromDBToCache();

        // Get variables
        //--------------------------------------------------------------------
        // Check that we have entryIDAuthorityName
        if(empty($this->mSettings['entryIDAuthorityName'])) {
            parent::exception('_getATOMEntries: missing entryIDAuthorityName');
        }
        $titlecol  = $this->mSettings['itemColumns']['titleColumn'];
        $linkcol   = $this->mSettings['itemColumns']['linkColumn'];
        $tstampcol = $this->mSettings['itemColumns']['timestampColumn'];
        $idspeccol = $this->mSettings['itemColumns']['entryIDSpecificColumn'];
        // Get real column names
        $dbmgr     = &parent::getDbMgr();
        $titlecol  = $dbmgr->getRealColumnName($titlecol);
        $linkcol   = $dbmgr->getRealColumnName($linkcol);
        $tstampcol = $dbmgr->getRealColumnName($tstampcol);
        $idspeccol = $dbmgr->getRealColumnName($idspeccol);

        // Write items to string
        //--------------------------------------------------------------------
        foreach($this->mRowCache as $row) {
            $title    = $this->_xmlEntities($row[$titlecol]);
            // Process link
            $link     = $this->_processLink($row[$linkcol]);
            $issued   = RWEUtil::iso8601Date($row[$tstampcol]);
            $modified = RWEUtil::iso8601Date($row[$tstampcol], true);
            // Build atom:id
            $authname = $this->mSettings['entryIDAuthorityName']; // authorityName (taggingEntity)
            $iddate   = date('Y-m-d', $row[$tstampcol]);          // date          (taggingEntity)
            $tagspec  = $row[$idspeccol];                         // "specific" part
            $taguri   = "tag:${authname},${iddate}:${tagspec}";   // full tag URI
            // Write entry
            $entries .= $b . "<entry>\n";
            $entries .= $b . "  <title>${title}</title>\n";
            $entries .= $b . "  <link rel=\"alternate\" type=\"text/html\" href=\"${link}\" />\n";
            $entries .= $b . "  <id>${taguri}</id>\n";
            $entries .= $b . "  <issued>${issued}</issued>\n";
            $entries .= $b . "  <modified>${modified}</modified>\n";
            // Optional entry elements
            $entries .= $this->_getATOMEntryOptionalElements($row, $b . '  ');
            $entries .= $this->_getOptionalItemModuleElementsForRow($row, $b . '  ');
            $entries .= $b . "</entry>\n";
        }

        return $entries;
    }

    function _getATOMFeedOptionalElements($base_indentation)
    {
        $optelems = '';
        $b = $base_indentation;

        // feedDescription
        $desc = $this->mSettings['feedDescription'];
        if(!empty($desc)) {
            $optelems .= $b . "<tagline>${desc}</tagline>\n";
        }

        return $optelems;
    }

    function _getATOMEntryOptionalElements($row = null, $base_indentation)
    {
        $optelems = '';
        $b = $base_indentation;

        // Check we have rows in cache
        if(is_null($row)) {
            parent::exception('_getATOMEntryOptionalElements: row == null');
        }

        // Get optional columns
        //--------------------------------------------------------------------
        $desccol   = $this->mSettings['itemColumns']['descriptionColumn'];
        $postercol = $this->mSettings['itemColumns']['posterColumn'];

        // descriptionColumn
        if(!empty($desccol)) {
            $dbmgr     = &parent::getDbMgr();
            $desccol   = $dbmgr->getRealColumnName($desccol);
            $summary   = $this->_processDescription($row[$desccol]);
            $optelems .= $b . "<summary>${summary}</summary>\n";
        }

        // posterColumn
        if(!empty($postercol)) {
            $dbmgr     = &parent::getDbMgr();
            $postercol = $dbmgr->getRealColumnName($postercol);
            $author    = $row[$postercol];
            $optelems .= $b . "<author>\n";
            $optelems .= $b . "  <name>${author}</name>\n";
            $optelems .= $b . "</author>\n";
        }
        return $optelems;
    }



    /*
     * =======================================================================
     * =======================================================================
     */



    /** 
     * Writes the produced XML into a file.
     */
    function _saveToFile($content)
    {
        $xmlfile = $this->mXMLFileFullPath;
        $tmpfile = $xmlfile . $this->mTmpFileExt;
        $fh = null;
        if($this->mRWE->getDebug()) {
            $fh = fopen($tmpfile, 'w');
        } else {
            $fh = @fopen($tmpfile, 'w');
        }
        if(false === $fh) {
            parent::exception("_saveToFile: failed to open ${tmpfile}");
        }
        if(false === fwrite($fh, $content)) {
            parent::exception('_saveToFile: fwrite() failed');
        }
        if(false === rename($tmpfile, $xmlfile)) {
            parent::exception("_saveToFile: rename(${tmpfile} => ${xmlfile}) failed");
        }
        fclose($fh);
    }

    /** 
     * Cuts a string at certain length.
     *
     * @author From Smarty's modifier.truncate.php.
     */
    function _cutString($string, $length = 200, $postfix = '...', $break_words = false)
    {
        if($length == 0) {
            return '';
        }
        if(strlen($string) > $length) {
            $length -= strlen($postfix);
            if(!$break_words) {
                $string = preg_replace('/\s+?(\S+)?$/', '', substr($string, 0, $length + 1));
            }
            return substr($string, 0, $length) . $postfix;
        } else {
            return $string;
        }
    }

    /** Gets the full path of the file to write the xml output to. */
    function _getXMLFileFullPath()
    {
        $saveto   = $this->mSettings['saveTo'];
        $saveas   = $this->mSettings['saveAs'];
        $fullpath = null;
        if(!empty($saveto)) {
            $fullpath = $saveto;
        } else {
            $fullpath = $this->mRWE->getWebRootDir();
        }
        if(empty($saveas)) {
            return false;
        }
        return $fullpath . $saveas;
    }

    function _processDescription($desc)
    {
        // Get rid of newlines
        $ret = str_replace("\r\n", ' ', $desc);
        $ret = str_replace("\n", ' ', $ret);
        // Get rid of html tags
        $ret = strip_tags($ret);
        // Get rid of excess whitespace
        $ret = preg_replace("/\s{2,}/", ' ', $ret);
        $ret = preg_replace("/^\s+(.*)\s+$/", '$1', $ret);
        // Cut it if requested by user
        if($this->mSettings['cutDescription']) {
            $length    = $this->mSettings['cutDescLength'];
            $postfix   = $this->mSettings['cutDescPostfix'];
            $breakword = $this->mSettings['cutDescBreakWord'] ? true : false;
            $ret       = $this->_cutString($ret, $length, $postfix, $breakword);
        }
        $ret = $this->_xmlEntities($ret);
        return $ret;
    }

    function _processLink($link)
    {
        $ret     = $link;
        // Add prefix
        $prefix  = $this->mSettings['itemLinkPrefix'];
        if(!empty($prefix)) {
            $ret = $prefix . $ret;
        }
        // Add postfix
        $postfix = $this->mSettings['itemLinkPostfix'];
        if(!empty($postfix)) {
            $ret = $ret . $postfix;
        }
        return $ret;
    }

    /** Returns string so that it contains only XML-allowed html entities. */
    function _xmlEntities($string)
    {
        // We might have "nice" single- or double-quotes from Module_articles,
        // convert those manually first
        $ret = str_replace('&#8217;', "'", $string);
        // "Nice" double quotes to &quot;
        $ret = str_replace('&#8220;', '"', $ret);
        $ret = str_replace('&#8221;', '"', $ret);
        // unhtmlentities all rest
        $ret = RWEUtil::unHTMLEntities($ret);
        // Convert XML allowed entities back
        $ret = str_replace('&', '&amp;', $ret);
        $ret = str_replace("'", '&apos;', $ret);
        $ret = str_replace('"', '&quot;', $ret);
        $ret = str_replace('<', '&lt;', $ret);
        $ret = str_replace('>', '&lt;', $ret);
        return $ret;
    }

    /** 
     * Adds the given shorthand namespace name to the list of namespaces
     * to be added by Module_xmlfeeds::_addNeededNamespaces.
     */
    function _addNamespace($ns)
    {
        // Add only if not already added
        if(!in_array($ns, $this->mAddNamespaces)) {
            $this->mAddNamespaces[] = $ns;
        }
    }

    /** 
     * Returns a string with the namespace declarations specified in
     * mAddNamespaces, with each line prefixed with base_indentation.
     *
     * @remarks The full namespace declarations and their corresponding
     * shorthand key-values are held in mNamespaceDeclTbl and specified
     * in the constructor.
     */
    function _addNeededNamespaces($base_indentation = '    ')
    {
        $b          = $base_indentation;
        $ret        = '';
        $nsdecl_tbl = $this->mNamespaceDeclTbl;
        $namespaces = array_unique($this->mAddNamespaces);
        foreach($namespaces as $ns) {
            if(array_key_exists($ns, $nsdecl_tbl)) {
                $ret .= $b . $nsdecl_tbl[$ns] . "\n";
            }
        }
        return $ret;
    }
}

?>
