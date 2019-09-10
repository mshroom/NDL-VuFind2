<?php

/**
 * Simple JSON-based record collection.
 *
 * PHP version 7
 *
 * Copyright (C) Villanova University 2010.
 * Copyright (C) The National Library of Finland 2015.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Search
 * @author   David Maus <maus@hab.de>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */
namespace FinnaSearch\Backend\Blender\Response\Json;

use VuFindSearch\Response\RecordCollectionInterface;

/**
 * Simple JSON-based record collection.
 *
 * @category VuFind
 * @package  Search
 * @author   David Maus <maus@hab.de>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */
class RecordCollection
    extends \FinnaSearch\Backend\Solr\Response\Json\RecordCollection
{
    /**
     * Configuration
     *
     * @var \Zend\Config\Config
     */
    protected $config;

    /**
     * Constructor
     *
     * @param \Zend\Config\Config $config Configuration
     */
    public function __construct ($config = null) {
        $this->config = $config;
    }

    /**
     * Number of records included from the primary collection when blending
     *
     * @var int
     */
    protected $primaryCount;

    /**
     * Number of records included from the secondary collection when blending
     *
     * @var int
     */
    protected $secondaryCount;

    /**
     * Initialize blended results
     *
     * @param RecordCollectionInterface $primaryCollection   Primary record
     * collection
     * @param RecordCollectionInterface $secondaryCollection Secondary record
     * collection
     * @param int                       $offset              Results list offset
     * @param int                       $limit               Result limit
     * @param int                       $blockSize           Record block size
     */
    public function initBlended(RecordCollectionInterface $primaryCollection,
        RecordCollectionInterface $secondaryCollection, $offset, $limit, $blockSize
    ) {
        $this->response = static::$template;
        $this->response['response']['numFound'] = $primaryCollection->getTotal()
            + $secondaryCollection->getTotal();
        $this->offset = $this->response['response']['start'] = $offset;
        $this->rewind();

        $primaryRecords = $primaryCollection->getRecords();
        $secondaryRecords = $secondaryCollection->getRecords();
        foreach ($primaryRecords as &$record) {
            $record
                ->setSourceIdentifier($record->getSourceIdentifier() . '/p');
        }
        $initialPrimary = $this->config['Results']['boostPosition'] ?? $blockSize;
        $boostRecordCount = $this->config['Results']['boostCount'] ?? 0;
        $records = array_merge(
            array_splice($primaryRecords, 0, $initialPrimary),
            array_splice($secondaryRecords, 0, $boostRecordCount),
            array_splice(
                $primaryRecords, 0, $blockSize - $initialPrimary
            )
        );
        for ($pos = count($records); $pos < $offset + $limit; $pos += $blockSize) {
            $records = array_merge(
                $records,
                array_splice($secondaryRecords, 0, $blockSize),
                array_splice($primaryRecords, 0, $blockSize)
            );
        }

        $this->records = array_slice(
            $records, $offset, $limit
        );

        $this->primaryCount = 0;
        $this->secondaryCount = 0;
        foreach ($this->records as $record) {
            $sid = $record->getSourceIdentifier();
            if (substr($sid, -2) === '/p') {
                ++$this->primaryCount;
                $record->setSourceIdentifier(substr($sid, 0, -2));
            } else {
                ++$this->secondaryCount;
            }
        }

        $this->mergeFacets($primaryCollection, $secondaryCollection);
    }

    /**
     * Get number of records included from the primary collection
     *
     * @return int
     */
    public function getPrimaryCount()
    {
        return $this->primaryCount;
    }

    /**
     * Get number of records included from the secondary collection
     *
     * @return int
     */
    public function getSecondaryCount()
    {
        return $this->secondaryCount;
    }

    /**
     * @param RecordCollectionInterface $primaryCollection   Primary record
     * collection
     * @param RecordCollectionInterface $secondaryCollection Secondary record
     * collection
     *
     * @return void
     */
    protected function mergeFacets($primaryCollection, $secondaryCollection)
    {
        $primary = $primaryCollection->getFacets()->getFieldFacets();
        $secondary = $secondaryCollection->getFacets();
        $facetFields = [];
        foreach ($primary as $key => $value) {
            $facetFields[$key] = $value;
        }
        $this->response['facet_counts']['facet_fields'] = $facetFields;
    }
}
