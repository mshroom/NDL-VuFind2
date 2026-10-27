<?php

/**
 * FavoritesService Test Class.
 *
 * PHP version 8
 *
 * Copyright (C) Villanova University 2010-2024.
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
 * along with this program; if not, see
 * <https://www.gnu.org/licenses/>.
 *
 * @category VuFind
 * @package  Tests
 * @author   Minna Rönkä <minna.ronka@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */

namespace FinnaTest\Favorites;

use Finna\Db\Entity\UserEntityInterface;
use Finna\Db\Entity\UserListEntityInterface;
use Finna\Db\Service\ResourceServiceInterface;
use Finna\Db\Service\UserListService;
use Finna\Db\Service\UserListServiceInterface;
use Finna\Db\Service\UserResourceService;
use Finna\Db\Service\UserResourceServiceInterface;
use Finna\Db\Service\UserServiceInterface;
use Finna\Favorites\FavoritesService;
use Finna\Record\Loader;
use PHPUnit\Framework\MockObject\MockObject;
use VuFind\Db\Entity\ResourceEntityInterface;
use VuFind\Db\Service\ResourceTagsService;
use VuFind\Record\ResourcePopulator;
use VuFind\Tags\TagsService;

/**
 * FavoritesService Test Class.
 *
 * @category VuFind
 * @package  Tests
 * @author   Minna Rönkä <minna.ronka@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */
class FavoritesServiceTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Get a FavoritesService object.
     *
     * @param UserListServiceInterface     $listService         Mock list service (optional)
     * @param UserResourceServiceInterface $userResourceService Mock user resource service (optional)
     *
     * @return FavoritesService
     */
    protected function getFavoritesService(
        ?UserListServiceInterface $listService = null,
        ?UserResourceServiceInterface $userResourceService = null
    ): FavoritesService {
        return new FavoritesService(
            $this->createMock(ResourceServiceInterface::class),
            $this->createMock(ResourceTagsService::class),
            $listService ?? $this->createMock(UserListServiceInterface::class),
            $userResourceService ?? $this->createMock(UserResourceServiceInterface::class),
            $this->createMock(UserServiceInterface::class),
            $this->createMock(ResourcePopulator::class),
            $this->createMock(TagsService::class),
            $this->createMock(Loader::class)
        );
    }

    /**
     * Create a mock user object.
     *
     * @return MockObject&UserEntityInterface
     */
    protected function getMockUser(): MockObject&UserEntityInterface
    {
        $user = $this->createMock(UserEntityInterface::class);
        $user->method('getId')->willReturn(1234);
        return $user;
    }

    /**
     * Create a mock list object for mock user.
     *
     * @return MockObject&UserListEntityInterface
     */
    protected function getMockListForMockUser(): MockObject&UserListEntityInterface
    {
        $user = $this->getMockUser();
        $list = $this->createMock(UserListEntityInterface::class);
        $list->method('getUser')->willReturn($user);
        $list->method('getTitle')->willReturn('Mock List Title');
        $list->method('getId')->willReturn(12345);
        return $list;
    }

    /**
     * Create a mock list service expecting to update list timestamp and saving list once.
     *
     * @return MockObject&UserListServiceInterface
     */
    protected function getListServiceExpectingSavingListOnce(): MockObject&UserListServiceInterface
    {
        $list = $this->getMockListForMockUser();
        $list->expects($this->once())->method('setFinnaUpdated');
        $listService = $this->createMock(UserListService::class);
        $listService->method('getUserListById')->with(12345)->willReturn($list);
        $listService->expects($this->once())->method('persistEntity');
        return $listService;
    }

    /**
     * Create a mock resource object.
     *
     * @return MockObject&ResourceEntityInterface
     */
    protected function getMockResource(): MockObject&ResourceEntityInterface
    {
        $resource = $this->createMock(ResourceEntityInterface::class);
        $resource->method('getId')->willReturn(123);
        return $resource;
    }

    /**
     * Test that saving user list updates finnaUpdated timestamp.
     *
     * @return void
     */
    public function testSaveListForUser()
    {
        $user = $this->getMockUser();
        $listService = $this->getListServiceExpectingSavingListOnce();
        $list = $listService->getUserListById(12345);
        $service = $this->getFavoritesService($listService);
        $service->saveListForUser($list, $user);
    }

    /**
     * Test saveMany.
     *
     * @return void
     */
    public function testSaveMany()
    {
        $user = $this->getMockUser();
        $listService = $this->getListServiceExpectingSavingListOnce();
        $service = $this->getFavoritesService($listService, $this->createMock(UserResourceService::class));
        $service->saveMany(['list' => 12345], $user, []);
    }

    /**
     * Test saveResourceToFavorites.
     *
     * @return void
     */
    public function testSaveResourceToFavorites()
    {
        $user = $this->getMockUser();
        $listService = $this->getListServiceExpectingSavingListOnce();
        $list = $listService->getUserListById(12345);
        $service = $this->getFavoritesService($listService);
        $resource = $this->getMockResource();
        $service->saveResourceToFavorites($user, $resource, $list, [], '');
    }

    /**
     * Test updateResourceCustomOrder.
     *
     * @return void
     */
    public function testUpdateResourceCustomOrder()
    {
        $user = $this->getMockUser();
        $listService = $this->getListServiceExpectingSavingListOnce();
        $list = $listService->getUserListById(12345);
        $service = $this->getFavoritesService($listService, $this->createMock(UserResourceService::class));
        $resource = $this->getMockResource();
        $service->updateResourceCustomOrder($user, $resource, $list, 2);
    }

    /**
     * Test removeListResourcesById.
     *
     * @return void
     */
    public function testRemoveListResourcesById()
    {
        $user = $this->getMockUser();
        $listService = $this->getListServiceExpectingSavingListOnce();
        $list = $listService->getUserListById(12345);
        $service = $this->getFavoritesService($listService);
        $service->removeListResourcesById($list, $user, [12345]);
    }

    /**
     * Test saveCustomFavoriteOrder.
     *
     * @return void
     */
    public function testSaveCustomFavoriteOrder()
    {
        $user = $this->getMockUser();
        $listService = $this->getListServiceExpectingSavingListOnce();
        $service = $this->getFavoritesService($listService);
        $service->saveCustomFavoriteOrder($user, 12345, []);
    }

    /**
     * Test addListTag.
     *
     * @return void
     */
    public function testAddListTag()
    {
        $user = $this->getMockUser();
        $listService = $this->getListServiceExpectingSavingListOnce();
        $list = $listService->getUserListById(12345);
        $service = $this->getFavoritesService($listService);
        $service->addListTag('My Test Tag', $list, $user);
    }
}
