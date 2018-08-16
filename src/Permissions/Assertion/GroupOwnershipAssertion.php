<?php
namespace GroupEdit\Permissions\Assertion;


use Zend\Permissions\Acl\Acl;
use Zend\Permissions\Acl\Assertion\AssertionInterface;
use Zend\Permissions\Acl\Resource\ResourceInterface;
use Zend\Permissions\Acl\Role\RoleInterface;

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
        if (!isset($role->groupedit_groups) || !isset($resource->groupedit_groups)) return false;

        return !empty(array_intersect($role->groupedit_groups, $resource->groupedit_groups));
    }
}