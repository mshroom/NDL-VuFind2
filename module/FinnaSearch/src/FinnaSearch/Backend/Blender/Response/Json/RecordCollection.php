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
     * Constructor.
     *
     * @return void
     */
    public function __construct() {
    }

    public function initBlended($primaryCollection, $secondaryCollection, $offset,
        $limit, $blockSize
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
        $records = array_merge(
            array_slice($primaryRecords, 0, 3),
            array_slice($secondaryRecords, 0, 3)
        );
        $max = min($limit, max(count($primaryRecords), count($secondaryRecords)));
        for ($pos = 3; $pos < $max; $pos += 10) {
            $records = array_merge(
                $records,
                array_slice($primaryRecords, $pos, $blockSize)
            );
            $records = array_merge(
                $records,
                array_slice($secondaryRecords, $pos, $blockSize)
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
}
