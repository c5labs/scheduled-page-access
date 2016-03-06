<?php
namespace Concrete\Package\ConcreteDelayedPublish;

use Package;
use Page;
use PermissionKey;
use PermissionAccess;
use \Concrete\Core\Permission\Access\Entity\GroupEntity as GroupPermissionAccessEntity;

defined('C5_EXECUTE') or die('Access Denied.');

class Controller extends Package
{
    protected $pkgHandle = 'concrete-delayed-publish';

    protected $appVersionRequired = '5.7.1';

    protected $pkgVersion = '0.9.0';

    public function getPackageName()
    {
        return t("Delayed Publishing Package");
    }

    public function getPackageDescription()
    {
        return t("Allows pages to remain hidden until the public date time.");
    }

    protected function str_contains($haystack, $needle)
    {
        $needles = (array) $needle;

        foreach ($needles as $needle) {
            if (false !== strpos($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function on_start()
    {
        // We wire the page update & add events to check the page permissions so that
        // if the collection public date is in the future we can set the appropriate
        // restrictions on showing the page.
        \Events::addListener('on_page_update', function ($event) {
            $this->setPagePermissions($event->getPageObject());
        });

        \Events::addListener('on_page_add', function ($event) {
            $this->setPagePermissions($event->getPageObject());
        });
    }

    public function __destruct()
    {
        // We also intercept the post request for page permission & attribute updates so that we
        // can enforce the restrictions when the pages permissions have been overwritten.
        $request = \Request::getInstance();

        if ($request->isPost()) {
            $haystack = $request->server->get('REQUEST_URI');

            $needles = [
                'ccm/system/panels/details/page/permissions/save_simple',
                'ccm/system/panels/details/page/attributes/submit',
            ];

            if ($this->str_contains($haystack, $needles)) {
                $page = Page::getById($request->query->get('cID'));
                $this->setPagePermissions($page);
            }
        }
    }

    protected function setPagePermissions(Page $page)
    {
        $public_date = $page->getCollectionDatePublicObject();

        // To prevent the page becoming available to guest users we reset the pages
        // permissions and set a duration object agains the view_page permission for the guest group.
        $page->setPermissionsToManualOverride();

        $pk = PermissionKey::getByHandle('view_page');
        $pk->setPermissionObject($page);

        // Get the existing permissions and clear them from the page.
        $pt = $pk->getPermissionAssignmentObject();
        $items = $pt->getPermissionAccessObject() ? $pt->getPermissionAccessObject()->getAccessListItems() : [];
        $pt->clearPermissionAssignment();

        // Create a new access object.
        $pa = PermissionAccess::create($pk);

        foreach ($items as $item) {
            $group = $item->accessEntity->getGroupObject();

            $pd = null;

            // If the pages public date is in the future, set it to not be visible to
            // the 'Guest' group until the date & time.
            if ($public_date > new \DateTime() && '1' === $group->getGroupId()) {
                $pd = new \Concrete\Core\Permission\Duration();
                $pd->setStartDate($page->getCollectionDatePublicObject());
                $pd->setEndDate(\DateTime::createFromFormat('Y-m-d', '2100-12-31'));
                $pd->setRepeatPeriod(\Concrete\Core\Permission\Duration::REPEAT_NONE);
                $pd->setStartDateAllDay(0);
                $pd->setEndDateAllDay(0);
                $pd->save();
            }

            $pa->addListItem(GroupPermissionAccessEntity::getOrCreate($group), $pd);
        }

        $pt->assignPermissionAccess($pa);
    }
}
