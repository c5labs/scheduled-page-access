<?php
/**
 * Package Controller File.
 *
 * PHP version 5.4
 *
 * @author   Oliver Green <oliver@c5dev.com>
 * @license  http://www.gnu.org/copyleft/gpl.html GPL3
 * @link     https://c5dev.com/add-ons/scheduled-page-access
 */
namespace Concrete\Package\ScheduledPageAccess;

defined('C5_EXECUTE') or die('Access Denied.');

use Exception;
use Concrete\Core\Support\Facade\Events;
use Group;
use Package;
use Page;
use PermissionAccess;
use PermissionKey;
use Concrete\Core\Permission\Access\Entity\GroupEntity as GroupPermissionAccessEntity;

/**
 * Package Controller Class.
 *
 * @author   Oliver Green <oliver@c5dev.com>
 * @license  http://www.gnu.org/copyleft/gpl.html GPL3
 * @link     https://c5dev.com/add-ons/scheduled-page-access
 */
class Controller extends Package
{
    /**
     * Package handle.
     *
     * @var string
     */
    protected $pkgHandle = 'scheduled-page-access';

    /**
     * Minimum concrete5 version.
     *
     * @var string
     */
    protected $appVersionRequired = '5.7.1';

    /**
     * Package version.
     *
     * @var string
     */
    protected $pkgVersion = '0.9.2';

    /**
     * Keep me updated interest ID.
     *
     * @var string
     */
    public $interest_id = '721a95a7de';

    /**
     * Get the package name.
     *
     * @return string
     */
    public function getPackageName()
    {
        return t('Scheduled Page Access');
    }

    /**
     * Get the package description.
     *
     * @return string
     */
    public function getPackageDescription()
    {
        return t('Makes pages remain hidden until the public date time.');
    }

    /**
     * Returns a boolean value indicating whether the
     * haystack contains the needle.
     *
     * @param  string $haystack
     * @param  string $needle
     * @return bool
     */
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

    /**
     * Get a helper instance.
     *
     * @param  mixed $pkg
     * @return \C5dev\Package\Thanks\PackageInstallHelper
     */
    protected function getHelperInstance($pkg)
    {
        if (! class_exists('\C5dev\Package\Thanks\PackageInstallHelper')) {
            // Require composer
            $filesystem = new Filesystem();
            $filesystem->getRequire(__DIR__.'/vendor/autoload.php');
        }

        return new \C5dev\Package\Thanks\PackageInstallHelper($pkg);
    }

    /**
     * Start-up Hook.
     *
     * @return void
     */
    public function on_start()
    {
        // We wire the page update & add events to check the page permissions so that
        // if the collection public date is in the future we can set the appropriate
        // restrictions on showing the page.
        Events::addListener('on_page_update', function ($event) {
            $this->setPagePermissions($event->getPageObject());
        });

        Events::addListener('on_page_add', function ($event) {
            $this->setPagePermissions($event->getPageObject());
        });

        // Check whether we have just installed the package
        // and should redirect to intermediate 'thank you' page.
        $this->getHelperInstance($this)->checkForPostInstall();
    }

    /**
     * Install hook.
     *
     * @return \Concrete\Core\Package\Package
     */
    public function install()
    {
        // Check that we're not running version 8 and beyond.
        $version = explode('.', APP_VERSION);
        if (intval($version[0]) >= 8) {
            throw new Exception(
                'This addon cannot be installed on concrete5 version 8 or above as scheduled access is a core feature.'
            );
        }

        $pkg = parent::install();

        // Install the 'thank you' page if needed.
        $this->getHelperInstance($pkg)->addThanksPage();

        return $pkg;
    }

    /**
     * Set the correct permissions on a page, depending 
     * on it's public date & time.
     * 
     * @param Page $page
     */
    protected function setPagePermissions(Page $page)
    {
        $public_date = $page->getCollectionDatePublicObject();

        if (! $public_date) {
            return;
        }

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

        $registered_users_granted = false;

        foreach ($items as $item) {
            $is_guest = (
                $item->accessEntity instanceof GroupPermissionAccessEntity
                && '1' === $item->accessEntity->getGroupObject()->getGroupId()
            );

            // The registered users group must be granted view permissions as any non-root
            // users that try to publish pages won't have the correct permissions as by
            // default pages only have the view permission set for guests.
            if ($item->accessEntity instanceof GroupPermissionAccessEntity
                && '2' === $item->accessEntity->getGroupObject()->getGroupId()) {
                $registered_users_granted = true;
            }

            // If the pages public date is in the future, set it to not be visible to
            // the 'Guest' group until the date & time.
            if ($public_date > new \DateTime() && $is_guest) {
                $pd = new \Concrete\Core\Permission\Duration();
                $pd->setStartDate($public_date);
                $pd->setEndDate(\DateTime::createFromFormat('Y-m-d', '2100-12-31'));
                $pd->setRepeatPeriod(\Concrete\Core\Permission\Duration::REPEAT_NONE);
                $pd->setStartDateAllDay(0);
                $pd->setEndDateAllDay(0);
                $pd->save();

                $pa->addListItem($item->accessEntity, $pd);

                continue;
            }

            $pa->addListItem($item->accessEntity);
        }

        if (! $registered_users_granted) {
            // Grant the permissions for registered users to view the site.
            $pa->addListItem(GroupPermissionAccessEntity::getOrCreate(Group::getById(2)));
        }

        $pt->assignPermissionAccess($pa);
    }

    /**
     * Shutdown.
     */
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
}
