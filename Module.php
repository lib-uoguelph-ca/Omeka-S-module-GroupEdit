<?php
namespace GroupEdit;

use Doctrine\ORM\Events;
use Group\Api\Adapter\GroupAdapter;
use Group\Controller\Admin\GroupController;
use Group\Db\Event\Listener\DetachOrphanGroupEntities;
use Group\Entity\Group;
use Group\Entity\GroupResource;
use Group\Entity\GroupUser;
use Group\Form\Element\GroupSelect;
use Group\Form\SearchForm;
use Omeka\Api\Adapter\ItemAdapter;
use Omeka\Api\Adapter\ItemSetAdapter;
use Omeka\Api\Adapter\MediaAdapter;
use Omeka\Api\Adapter\UserAdapter;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Entity\Item;
use Omeka\Entity\ItemSet;
use Omeka\Entity\Media;
use Omeka\Entity\Resource;
use Omeka\Entity\User;
use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Auth;
use Zend\View\Renderer\PhpRenderer;

/**
 * GroupEdit
 *
 * Users who share the same group now have the ability to edit each other's items.
 *
 * @copyright Daniel Berthereau, 2017-2018
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $this->addAclRules();
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {

    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {

    }

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules()
    {
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        $nonAdminRoles = [
            \Omeka\Permissions\Acl::ROLE_AUTHOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
            \Omeka\Permissions\Acl::ROLE_EDITOR
        ];

        $acl->allow(
            $nonAdminRoles,
            [\Group\Entity\GroupResource::class],
            // The right "assign" is used to display the form or not.
            ['read', 'create', 'update', 'delete', 'assign']
        );

        $acl->allow(
            [\Omeka\Permissions\Acl::ROLE_AUTHOR],
            [\Group\Api\Adapter\GroupAdapter::class],
            ['search', 'read']
        );

        $acl->allow(
            'author',
            [
                'Omeka\Entity\Item',
                'Omeka\Entity\ItemSet',
                'Omeka\Entity\Media',
                'Omeka\Entity\ResourceTemplate',
            ],
            [
                'update',
                'delete',
            ],
            new \GroupEdit\Permissions\Assertion\GroupOwnershipAssertion
        );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');

        $adapters = [
            'Omeka\Api\Adapter\MediaAdapter',
            'Omeka\Api\Adapter\ItemAdapter',
            'Omeka\Api\Adapter\ItemSetAdapter',
        ];

        foreach ($adapters as $adapter) {
            $sharedEventManager->attach(
                $adapter,
                'api.read.post',
                [$this, 'handleResourceReadPost']
            );
        }

        foreach ($adapters as $adapter) {
            $sharedEventManager->attach(
                $adapter,
                'api.search.post',
                [$this, 'handleResourceSearchPost']
            );
        }
    }

    function handleResourceReadPost(Event $event) {

        $user = $this->getUser();
        if(!$user) return;

        $user->groupedit_groups = $this->getUserGroups($user);

        $response = $event->getParam('response');
        $item = $response->getContent();
        $item->groupedit_groups = $this->getResourceGroups($item);

    }

    function handleResourceSearchPost(Event $event) {
        $user = $this->getUser();
        if(!$user) return;

        $user->groupedit_groups = $this->getUserGroups($user);

        $response = $event->getParam('response');
        $items = $response->getContent();

        foreach ($items as $item) {
            $item->groupedit_groups = $this->getResourceGroups($item);
        }
    }

    protected function getUser() {
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');

        $user = $auth->getIdentity();
        return $user;
    }

    protected function getUserGroups($user) {
        $services = $this->getServiceLocator();

        $entityManager = $services->get('Omeka\EntityManager');

        $id = $user->getId();

        $groupResourceRepository = $entityManager->getRepository(GroupUser::class);
        $entities = $groupResourceRepository->findBy(array('user' => $id));

        $groups = [];
        foreach ($entities as $entity) {
            $groups[] = $entity->getGroup()->getId();
        }

        return $groups;
    }

    protected function getResourceGroups($resource) {
        $services = $this->getServiceLocator();

        $entityManager = $services->get('Omeka\EntityManager');

        $id = $resource->getId();

        $groupResourceRepository = $entityManager->getRepository(GroupResource::class);
        $entities = $groupResourceRepository->findBy(array('resource' => $id));

        $groups = [];
        foreach ($entities as $entity) {
            $groups[] = $entity->getGroup()->getId();
        }

        return $groups;
    }
}


