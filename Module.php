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
 * The crux of this module is the GroupOwnershipAssertion class, which allows users to edit
 * resources that share the same group as the user.
 *
 * Beyond that, the events added here just populate group data on the $role and $resource variables for that assertion.
 * This approach seems hacky to me, but it's the easiest way to give the assertion the data it needs to make a decision.
 *
 * @copyright Adam Doan, 2018
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

        /*
         * These roles are required to allow group membership to propagate down to newly created items and media.
         */
        $acl->allow(
            $nonAdminRoles,
            [\Group\Entity\GroupResource::class],
            ['read', 'create', 'update', 'delete', 'assign']
        );

        $acl->allow(
            [\Omeka\Permissions\Acl::ROLE_AUTHOR],
            [\Group\Api\Adapter\GroupAdapter::class],
            ['search', 'read']
        );

        // Set up the Group Ownership assertion check for the relevant resources.
        $acl->allow(
            'author',
            [
                'Omeka\Entity\Item',
                'Omeka\Entity\ItemSet',
                'Omeka\Entity\Media'
            ],
            [
                'update',
                'delete',
            ],
            new \GroupEdit\Permissions\Assertion\GroupOwnershipAssertion
        );
    }

    /**
     * Whenever items, item sets, or media are loaded, we add a small bit of data to the record,
     * listing the IDs of the groups that they belong to.
     */
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

    /**
     * Handle the event that's fired whenever a resource is read from the API.
     * This handler does two things:
     *  - Injects the group ids into the user object.
     *  - Injects the group ids into the resource.
     */
    function handleResourceReadPost(Event $event) {

        $user = $this->getUser();
        if(!$user) return;

        $user->groupedit_groups = $this->getUserGroups($user);

        $response = $event->getParam('response');
        $item = $response->getContent();
        $item->groupedit_groups = $this->getResourceGroups($item);

    }

    /**
     * Handle the event that's fired whenever a search is done through the API.
     * This handler does two things:
     *  - Injects the group ids into the user object.
     *  - Injects the group ids into the resources that are returned by the search operation.
     */
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

    /**
     * Gets the current user, if there is one.
     *
     * @return \Omeka\Entity\User or null if the user is not authenticated.
     */
    protected function getUser() {
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');

        $user = $auth->getIdentity();
        return $user;
    }

    /**
     * Given a user, returns an array of group IDs, one for each group the user belongs to.
     *
     * @param \Omeka\Entity\User $user
     * @return array An array of group IDs.
     */
    protected function getUserGroups(\Omeka\Entity\User $user) {
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

    /**
     * Given a resource, returns an array of group IDs, one for each group the resource belongs to.
     *
     * @param Resource $resource
     * @return array
     */
    protected function getResourceGroups(\Omeka\Entity\Resource $resource) {
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


