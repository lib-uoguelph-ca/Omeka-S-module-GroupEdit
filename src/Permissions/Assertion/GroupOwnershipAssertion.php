<?php
namespace GroupEdit\Permissions\Assertion;


use Zend\Permissions\Acl\Acl;
use Zend\Permissions\Acl\Assertion\AssertionInterface;
use Zend\Permissions\Acl\Resource\ResourceInterface;
use Zend\Permissions\Acl\Role\RoleInterface;
use Omeka\Permissions\Assertion\OwnsEntityAssertion;

/**
 * Class GroupOwnershipAssertion
 * @package GroupEdit\Permissions\Assertion
 *
 * Returns true if the user and resource belong to the same group.
 * Otherwise, returns false.
 */
class GroupOwnershipAssertion implements AssertionInterface
{
    public function assert(Acl $acl, RoleInterface $role = null, ResourceInterface $resource = null, $privilege = null)
    {        
        $ownerAssertion = new OwnsEntityAssertion();
        if($ownerAssertion->assert($acl, $role, $resource, $privilege)) {
            return True;
        }

        if (!isset($role->groupedit_groups) || !isset($resource->groupedit_groups)) return;

        if (empty($role->groupedit_groups) || empty($resource->groupedit_groups)) return;

        return !empty(array_intersect($role->groupedit_groups, $resource->groupedit_groups));
    }
}
