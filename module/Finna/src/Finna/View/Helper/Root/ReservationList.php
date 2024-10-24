<?php

/**
 * Reservation list view helper
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2024.
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
 * @package  View_Helpers
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */

namespace Finna\View\Helper\Root;

use Finna\Db\Entity\FinnaResourceListEntityInterface;
use Finna\ReservationList\ReservationListService;
use VuFind\Auth\ILSAuthenticator;
use VuFind\Db\Entity\UserEntityInterface;
use VuFind\RecordDriver\DefaultRecord;

use function in_array;
use function is_string;

/**
 * Reservation list view helper
 *
 * @category VuFind
 * @package  View_Helpers
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class ReservationList extends \Laminas\View\Helper\AbstractHelper
{
    /**
     * User logged in or null
     *
     * @var UserEntityInterface|null
     */
    protected ?UserEntityInterface $user;

    /**
     * Default values for list config
     *
     * @var array
     */
    protected array $requiredFieldsAndDefaultValues = [
        'Enabled' => false,
        'Recipient' => [],
        'Datasources' => [],
        'Information' => [],
        'LibraryCardSources' => [],
        'Connection' =>  [
            'type' => 'Database',
        ],
        'Identifier' => false,
    ];

    /**
     * Constructor
     *
     * @param ReservationListService $reservationListService Reservation list service
     * @param ILSAuthenticator       $ilsAuthenticator       Authenticator to ILS
     * @param array                  $yamlConfig             ReservationList.yaml as an array
     * @param array                  $configSection          Reservation list section from config.ini
     */
    public function __construct(
        protected ReservationListService $reservationListService,
        protected ILSAuthenticator $ilsAuthenticator,
        protected array $yamlConfig = [],
        protected array $configSection = []
    ) {
    }

    /**
     * Invoke
     *
     * @param ?UserEntityInterface $user User currently logged in or null
     *
     * @return self
     */
    public function __invoke(?UserEntityInterface $user = null): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Ensure that lists have all the required keys defined needed in other places.
     * Sets the list disabled if list identifier is not set or is not a string.
     *
     * @param array $list Properties of the list to ensure
     *
     * @return array
     */
    protected function ensureListKeys(array $list): array
    {
        $merged = array_merge($this->requiredFieldsAndDefaultValues, $list);
        if (!is_string($merged['Identifier'])) {
            $merged['Enabled'] = false;
        }
        return $merged;
    }

    /**
     * Get associative array of [institution => configured lists] where driver matches
     *
     * @param DefaultRecord $driver Record driver
     *
     * @return array
     */
    protected function getAvailableListsForRecord(DefaultRecord $driver): array
    {
        $datasource = $driver->tryMethod('getDatasource');
        if (!$datasource) {
            return [];
        }
        $result = [];
        foreach ($this->yamlConfig['Institutions'] ?? [] as $institution => $settings) {
            $current = [$institution => []];
            foreach ($settings['Lists'] ?? [] as $list) {
                $list = $this->ensureListKeys($list);
                if (
                    $list['Enabled']
                    && in_array($datasource, $list['Datasources'])
                    && $this->checkUserRightsForList($list)
                ) {
                    $current[$institution][] = $list;
                    continue;
                }
            }
            if ($current[$institution]) {
                $result = array_merge($result, $current);
            }
        }
        return $result;
    }

    /**
     * Get list properties defined by institution and list identifier in ReservationList.yaml,
     * institution specified information and
     * formed translation_keys for the list.
     *
     * Institution information contains keys and values:
     *     - name => Example institution name
     *     - address => Example institution address
     *     - postal => Example institution postal
     *     - city => Example institution city
     *     - email => Example institution email
     *
     * Translation keys formed:
     *     - title => list_title_{$institution}_{$listIdentifier},
     *     - description => list_description_{$institution}_{$listIdentifier},
     *
     * @param string $institution    Lists controlling institution
     * @param string $listIdentifier List identifier
     *
     * @return array
     */
    public function getListProperties(
        string $institution,
        string $listIdentifier
    ): array {
        foreach ($this->yamlConfig['Institutions'][$institution]['Lists'] ?? [] as $list) {
            $list = $this->ensureListKeys($list);
            if ($list['Identifier'] === $listIdentifier) {
                return [
                    'properties' => $list,
                    'institution_information' => $this->yamlConfig['Institutions'][$institution]['Information'] ?? [],
                    'translation_keys' => [
                        'title' => "ReservationList::list_title_{$institution}_{$listIdentifier}",
                        'description' => "ReservationList::list_description_{$institution}_{$listIdentifier}",
                    ],
                ];
            }
        }
        return [
            'properties' => $this->requiredFieldsAndDefaultValues,
            'institution_information' => [],
            'translation_keys' => [
                'title' => '',
                'description' => '',
            ],
        ];
    }

    /**
     * Check if the user has proper requirements to order records.
     * Function checks if there is required LibraryCardSources
     * which are used to check if user has an active connection to ils
     * defined in the list.
     *
     * @param array $list List as configuration
     *
     * @return bool
     */
    public function checkUserRightsForList(array $list): bool
    {
        if (!$list['LibraryCardSources']) {
            return true;
        }
        $patron = $this->ilsAuthenticator->storedCatalogLogin();
        if (!$patron) {
            return false;
        }
        return in_array($patron['source'], $list['LibraryCardSources']);
    }

    /**
     * Display buttons which routes the request to proper list procedures
     * Checks if the list should be displayed for logged-in only users.
     *
     * @param DefaultRecord $driver Driver to use for checking available lists
     *
     * @return string
     */
    public function renderReserveTemplate(DefaultRecord $driver): string
    {
        if (!$this->isFunctionalityEnabled()) {
            return '';
        }
        // Collect lists where we could potentially save this:
        $lists = $this->getAvailableListsForRecord($driver);

        // Set up the needed context in the view:
        $view = $this->getView();
        return $view->render('Helpers/reservationlist-reserve.phtml', compact('lists', 'driver'));
    }

    /**
     * Get available reservation lists for user, user must be invoked
     *
     * @return FinnaResourceListEntityInterface[]
     */
    public function getReservationListsForUser(): array
    {
        if (!$this->isFunctionalityEnabled()) {
            return [];
        }
        return $this->reservationListService->getReservationListsForUser($this->user);
    }

    /**
     * Get lists containing record
     *
     * @param DefaultRecord $record Record
     *
     * @return FinnaResourceListEntityInterface[]
     */
    public function getListsContainingRecord(DefaultRecord $record): array
    {
        if (!$this->isFunctionalityEnabled() || !$this->user) {
            return [];
        }
        return $this->reservationListService->getListsContainingRecord($this->user, $record);
    }

    /**
     * Check if reservation lists are enabled
     *
     * @return bool
     */
    public function isFunctionalityEnabled(): bool
    {
        return !empty($this->configSection['enabled']);
    }
}
