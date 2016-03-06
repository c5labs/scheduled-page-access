#Delayed Publishing for Concrete5#
The packages adds the ability to delay visibility of pages to Guest users by setting the pages Public Date \ Date Added attribute to a future date. The package works by setting the Guest groups permissions with a duration object, the same way that advanced permissions work.

**This package is for concrete 5.7.1+ and works with the simple permissions model only. All installations use the simple permissions model unless you have upgraded to advanced permissions.**

##Installation##

1. Unzip and copy the 'concrete-delayed-publish' folder to your concrete5 installations 'packages' folder. **(Ensure that the folder name is correct)**
2. Login, click on the Settings icon on the right of the top bar, click 'Extend concrete5'.
3. Click on the 'Install' button next to 'Dealyed Pulishing Package'.
4. Create a page and set its Date Added or Public Date to a date / time in the future.
5. Log out, goto the page before the time specified and will not be visible, you will be redirected to the login screen. Visit after the date specified and the page will become visible.